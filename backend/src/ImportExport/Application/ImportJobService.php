<?php
declare(strict_types=1);

namespace App\ImportExport\Application;

use App\Audit\AuditWriter;
use App\Core\Crs\CoordinateTransformationService;
use App\Core\Db\DbTransaction;
use App\Core\Error\ApiError;
use App\Core\Geo\OgrAdapter;
use App\Documents\DocumentService;
use App\GIS\Domain\AttributeValidator;
use App\ImportExport\Domain\CsvRowReader;
use App\ImportExport\Domain\DxfEntityScanner;
use App\ImportExport\Domain\GeoJsonRowReader;
use App\ImportExport\Domain\OgrRowReader;
use App\ImportExport\Domain\RowReader;
use PDO;
use Throwable;

/**
 * TASK-122 — import job lifecycle (api.md §10, architecture.md §17).
 *
 * upload → detect → declare CRS → map fields → validate → preview → commit,
 * with staging isolation, an error report, and an idempotent commit.
 *
 * Guarantees this service is responsible for:
 *  - **CRS is never guessed.** `setMapping()` (and `validate()`) raise
 *    `CRS_REQUIRED` when no `declared_crs` is present, and `CRS_UNSUPPORTED`
 *    for a CRS outside `ref.crs_registry` (FR-252, VR-52).
 *  - **Nothing reaches a production table before commit.** The source is read
 *    from the content-addressed document store; every row is normalised and
 *    validated into `staging.import_job_rows`; only `commit()` writes to
 *    `app.gis_features`, inside the ambient transaction.
 *  - **Partial commit only when explicitly chosen.** If any staged row is
 *    invalid and `partial` is false, the commit is refused with
 *    `IMPORT_INVALID` and the count of rejected rows.
 *  - **Idempotent commit.** An `Idempotency-Key` is recorded on the job behind
 *    a partial unique index; a replay returns the original result and inserts
 *    nothing.
 */
final class ImportJobService
{
    /** api.md §10 source formats accepted by the OGR detector. */
    private const FORMAT_TO_SOURCE = [
        OgrAdapter::FORMAT_GEOJSON    => 'GEOJSON',
        OgrAdapter::FORMAT_CSV        => 'CSV',
        OgrAdapter::FORMAT_KML        => 'KML',
        OgrAdapter::FORMAT_SHAPEFILE  => 'SHAPEFILE',
        OgrAdapter::FORMAT_GEOPACKAGE => 'GEOPACKAGE',
        OgrAdapter::FORMAT_DXF        => 'DXF',
    ];

    private const TARGETS = ['FEATURE', 'PARCEL', 'CONTROL_POINT'];

    /** OGR `SubClasses` names that identify non-geometry CAD entities. */
    private const CAD_ANNOTATION_CLASSES = [
        'AcDbText',
        'AcDbMText',
        'AcDbAttribute',
        'AcDbAttributeDefinition',
        'AcDbDimension',
        'AcDbBlockReference',
        'AcDbLeader',
        'AcDbMLeader',
        'AcDbTolerance',
    ];

    public function __construct(
        private readonly PDO $pdo,
        private readonly DocumentService $documents,
        private readonly OgrAdapter $ogr,
        private readonly GeoJsonRowReader $geojsonReader,
        private readonly CsvRowReader $csvReader,
        private readonly OgrRowReader $ogrReader,
        private readonly DxfEntityScanner $dxfScanner,
        private readonly CoordinateTransformationService $transformations,
        private readonly AttributeValidator $attributeValidator,
        private readonly AuditWriter $audit,
    ) {
    }

    // ------------------------------------------------------------------
    // Upload
    // ------------------------------------------------------------------

    /**
     * @param array{content?:string,name?:string} $file
     * @param array{target_entity?:string,target_layer_id?:int|null,options?:array<string,mixed>} $meta
     * @return array<string,mixed>
     */
    public function createJob(int $userId, array $file, array $meta): array
    {
        $content  = (string) ($file['content'] ?? '');
        $filename = (string) ($file['name'] ?? '');
        if ($filename === '') {
            throw new ApiError('VALIDATION_FAILED', 'A file is required.', 422, [
                'fields' => [['field' => 'file', 'rule' => 'REQUIRED']],
            ]);
        }

        $target = strtoupper((string) ($meta['target_entity'] ?? ''));
        if (!\in_array($target, self::TARGETS, true)) {
            throw new ApiError('VALIDATION_FAILED', 'target_entity must be one of FEATURE, PARCEL, CONTROL_POINT.', 422, [
                'fields' => [['field' => 'target_entity', 'rule' => 'ENUM']],
            ]);
        }

        // CRS is never inferred, so a target FEATURE import needs a layer for
        // geometry-type/attribute validation at validate time.
        $layerId = isset($meta['target_layer_id']) && $meta['target_layer_id'] !== null
            ? (int) $meta['target_layer_id'] : null;
        if ($target === 'FEATURE') {
            if ($layerId === null || $layerId <= 0) {
                throw new ApiError('VALIDATION_FAILED', 'target_layer_id is required for a FEATURE import.', 422, [
                    'fields' => [['field' => 'target_layer_id', 'rule' => 'REQUIRED']],
                ]);
            }
            $this->assertLayerExists($layerId);
        }

        $inspected = $this->inspectSource($content, $filename);
        $sourceFormat = $inspected['source'];

        $doc = $this->documents->storeImportSource($filename, $content, $userId);

        $options = \is_array($meta['options'] ?? null) ? $meta['options'] : [];
        // A `.prj`/OGR-probe CRS is recorded as a *suggestion* the user must
        // confirm (FR-252/VR-52); it is never auto-declared as the job's CRS.
        if ($inspected['suggested_crs'] !== null) {
            $options['suggested_crs'] = $inspected['suggested_crs'];
        }
        foreach ($inspected['extra'] as $extraKey => $extraValue) {
            $options[$extraKey] = $extraValue;
        }
        $stmt = $this->pdo->prepare(
            'INSERT INTO app.import_jobs
                (source_format, source_document_id, source_filename, target_entity,
                 target_layer_id, options, status, created_by)
             VALUES (:fmt, :doc, :name, :entity, :layer, :opts::jsonb, \'UPLOADED\', :uid)
             RETURNING id'
        );
        $stmt->execute([
            ':fmt'    => $sourceFormat,
            ':doc'    => $doc['id'],
            ':name'   => $filename,
            ':entity' => $target,
            ':layer'  => $layerId,
            ':opts'   => $options === [] ? null : json_encode($options, JSON_THROW_ON_ERROR),
            ':uid'    => $userId,
        ]);
        $jobId = (int) $stmt->fetchColumn();

        $this->audit->writeFromSession('INSERT', 'app.import_jobs', (string) $jobId, null, [
            'source_format' => $sourceFormat,
            'source_filename' => $filename,
            'target_entity' => $target,
            'target_layer_id' => $layerId,
            'suggested_crs' => $inspected['suggested_crs'],
        ], null, 'Import job created');

        return $this->getJob($userId, $jobId) + ['de_duplicated' => $doc['de_duplicated'] ?? false];
    }

    // ------------------------------------------------------------------
    // Read
    // ------------------------------------------------------------------

    /** @return array<string,mixed> */
    public function getJob(int $userId, int $jobId): array
    {
        return $this->formatJob($this->requireJob($userId, $jobId));
    }

    // ------------------------------------------------------------------
    // Mapping / CRS declaration
    // ------------------------------------------------------------------

    /**
     * @param array{
     *     declared_crs?:string,
     *     field_mapping?:array<string,mixed>,
     *     entity_layer_map?:array<string,mixed>,
     *     transformation?:array<string,mixed>,
     *     options?:array<string,mixed>
     * } $mapping
     * @return array<string,mixed>
     */
    public function setMapping(int $userId, int $jobId, array $mapping): array
    {
        $job = $this->requireJob($userId, $jobId);

        $declared = trim((string) ($mapping['declared_crs'] ?? ''));
        if ($declared === '') {
            // FR-252 / VR-52 — the API never guesses a CRS.
            throw new ApiError('CRS_REQUIRED', 'A declared_crs is required before an import can be mapped or validated.', 422, [
                'fields' => [['field' => 'declared_crs', 'rule' => 'REQUIRED']],
            ]);
        }

        $crs = $this->resolveCrs($declared);
        if ($crs === null) {
            throw new ApiError('CRS_UNSUPPORTED', "The CRS '$declared' is not in the CRS registry.", 422, [
                'fields' => [['field' => 'declared_crs', 'rule' => 'REGISTRY']],
            ]);
        }

        $fieldMapping = $mapping['field_mapping'] ?? [];
        if (!\is_array($fieldMapping)) {
            throw new ApiError('VALIDATION_FAILED', 'field_mapping must be an object of target → source field names.', 422);
        }
        foreach ($fieldMapping as $target => $source) {
            if (!\is_string($target) || (!\is_string($source) && !\is_int($source))) {
                throw new ApiError('VALIDATION_FAILED', 'field_mapping must be an object of target → source field names.', 422);
            }
        }

        // Merge so the create-time options (suggested_crs, dropped entity types,
        // detected CAD layers) survive the mapping update.
        $jobOptions = $this->decodeJson($job['options']) ?? [];
        $options = array_merge($jobOptions, \is_array($mapping['options'] ?? null) ? $mapping['options'] : []);
        $transformationId = $job['transformation_id'] !== null ? (int) $job['transformation_id'] : null;
        $sourceFormat = strtoupper((string) $job['source_format']);

        // ---- TASK-125 — DXF/CAD specifics (FR-250, FR-252) ----
        if ($sourceFormat === 'DXF') {
            // An entity-layer → GIS-layer mapping is mandatory for a FEATURE
            // import: a CAD drawing's entity layers must each name their
            // destination, otherwise a CAD layer could land in the wrong layer.
            // A CONTROL_POINT import targets the survey control-point table, so
            // no GIS-layer map applies.
            $isFeatureTarget = strtoupper((string) $job['target_entity']) === 'FEATURE';
            $entityLayerMap = $this->normaliseEntityLayerMap($mapping['entity_layer_map'] ?? null);
            if ($isFeatureTarget && $entityLayerMap === []) {
                throw new ApiError(
                    'VALIDATION_FAILED',
                    'A DXF import requires an entity_layer_map of CAD entity layer → target GIS layer id.',
                    422,
                    ['fields' => [['field' => 'entity_layer_map', 'rule' => 'REQUIRED']]],
                );
            }
            if ($entityLayerMap !== []) {
                $options['entity_layer_map'] = $entityLayerMap;
            }

            // A local/assumed CAD grid must carry a documented transformation
            // (origin, scale, rotation) written to coordinate_transformations
            // before any geometry is accepted (FR-252).
            $localGrid = !empty($options['cad_local_grid']) || !empty($jobOptions['cad_local_grid']);
            $transformation = $mapping['transformation'] ?? null;
            if ($localGrid && !\is_array($transformation)) {
                throw new ApiError(
                    'VALIDATION_FAILED',
                    'A local/assumed CAD grid requires a documented transformation {origin_x, origin_y, scale, rotation_deg}.',
                    422,
                    ['fields' => [['field' => 'transformation', 'rule' => 'REQUIRED']]],
                );
            }
            if (\is_array($transformation)) {
                $affine = $this->normaliseAffine($transformation);
                $transformationId = $this->transformations->recordAffineLocal(
                    (int) $crs['id'],
                    $affine,
                    $userId,
                    'Local CAD grid recorded for import job ' . $jobId,
                    'import_job',
                    (string) $jobId,
                );
                $options['affine'] = $affine;
                $options['cad_local_grid'] = true;
            }
        }

        $stmt = $this->pdo->prepare(
            'UPDATE app.import_jobs
                SET declared_crs_id    = :crs,
                    transformation_id  = :tid,
                    field_mapping      = :fm::jsonb,
                    options            = COALESCE(:opts::jsonb, options),
                    status             = \'MAPPED\'
              WHERE id = :id'
        );
        $stmt->execute([
            ':crs'  => $crs['id'],
            ':tid'  => $transformationId,
            ':fm'   => $fieldMapping === [] ? null : json_encode($fieldMapping, JSON_THROW_ON_ERROR),
            ':opts' => $options === [] ? null : json_encode($options, JSON_THROW_ON_ERROR),
            ':id'   => $jobId,
        ]);

        $this->audit->writeFromSession('UPDATE', 'app.import_jobs', (string) $jobId, [
            'status' => $job['status'],
        ], [
            'status' => 'MAPPED',
            'declared_crs' => $crs['code'],
            'field_mapping' => $fieldMapping,
            'transformation_id' => $transformationId,
        ], null, 'Import mapping set');

        return $this->getJob($userId, $jobId);
    }

    // ------------------------------------------------------------------
    // Validate
    // ------------------------------------------------------------------

    /** @return array<string,mixed> */
    public function validateJob(int $userId, int $jobId): array
    {
        $job = $this->requireJob($userId, $jobId);

        if ($job['declared_crs_id'] === null) {
            throw new ApiError('CRS_REQUIRED', 'A declared_crs is required before validation.', 422, [
                'fields' => [['field' => 'declared_crs', 'rule' => 'REQUIRED']],
            ]);
        }
        $crsId = (int) $job['declared_crs_id'];

        $reader = $this->readerFor((string) $job['source_format']);
        $content = $this->documents->readContent((string) $job['source_document_id']);
        $options = $this->decodeJson($job['options']) ?? [];
        $rows = $reader->read($content, (string) $job['source_format'], $options);

        $fieldMapping = $this->decodeJson($job['field_mapping']) ?? [];

        // ---- TASK-125 — DXF/CAD inputs ----
        $isDxf = strtoupper((string) $job['source_format']) === 'DXF';
        $entityLayerMap = $isDxf ? $this->storedEntityLayerMap($options) : [];
        if ($isDxf && $entityLayerMap === [] && strtoupper((string) $job['target_entity']) === 'FEATURE') {
            throw new ApiError('VALIDATION_FAILED', 'A DXF import requires an entity_layer_map before validation.', 422);
        }
        $affine = $isDxf && \is_array($options['affine'] ?? null) ? $options['affine'] : null;
        $dropped = $isDxf && \is_array($options['dropped'] ?? null) ? $options['dropped'] : [];
        $cadLayers = [];
        $droppedRows = 0;

        // Re-validating is idempotent: clear prior staging rows first.
        $this->pdo->prepare('DELETE FROM staging.import_job_rows WHERE job_id = :id')->execute([':id' => $jobId]);

        $crs = $this->crsById($crsId) ?? throw new ApiError('CRS_UNSUPPORTED', 'The declared CRS is no longer in the registry.', 422);
        $srid = (int) $crs['srid'];

        $insert = $this->pdo->prepare(
            'INSERT INTO staging.import_job_rows
                (job_id, row_number, raw, normalized, validation, is_valid, action, target_layer_id, geom)
             VALUES (:jid, :rn, :raw::jsonb, :norm::jsonb, :val::jsonb, :ok, :action, :tlid, ST_Force2D(ST_GeomFromGeoJSON(:geom)))'
        );

        // Layer metadata is resolved lazily per destination layer: a DXF job may
        // distribute its rows across several GIS layers via the entity-layer map.
        $layerContext = [];
        $contextFor = function (int $layerId) use (&$layerContext): array {
            if (!isset($layerContext[$layerId])) {
                $layerContext[$layerId] = [
                    'geometry_type' => $this->layerGeometryType($layerId),
                    'fields'        => $this->layerFields($layerId),
                ];
            }
            return $layerContext[$layerId];
        };

        $total = 0;
        $valid = 0;
        $detectedFields = [];
        $geometryTypes = [];
        $errorSummary = [];
        $areaChecked = 0;
        $areaOutside = 0;

        foreach ($rows as $row) {
            // TASK-125 — annotation/label and block-reference entities are not
            // imported as their own geometry (architecture.md §17.2); they are
            // counted so the dropped-entity report is truthful.
            if ($isDxf && $this->isCadAnnotation($row['raw'])) {
                $droppedRows++;
                continue;
            }

            $total++;
            $errors = [];
            $geometry4326 = null;
            $rowLayerId = $job['target_layer_id'] !== null ? (int) $job['target_layer_id'] : null;

            foreach (array_keys($row['raw']) as $key) {
                $detectedFields[(string) $key] = true;
            }

            // TASK-125 — resolve the destination layer from the DXF entity-layer
            // map, keyed by the CAD `Layer` field on each converted entity. An
            // unmapped CAD layer is rejected; it is never sent to the job's
            // default layer by accident.
            if ($entityLayerMap !== []) {
                $cadLayer = \is_string($row['raw']['Layer'] ?? null) ? trim((string) $row['raw']['Layer']) : '';
                if ($cadLayer === '' || !isset($entityLayerMap[$cadLayer])) {
                    $errors[] = ['rule' => 'CAD_LAYER_UNMAPPED', 'message' => sprintf(
                        'CAD entity layer %s is not mapped to a target GIS layer.',
                        $cadLayer === '' ? '(none)' : $cadLayer,
                    )];
                    $rowLayerId = null;
                } else {
                    $rowLayerId = $entityLayerMap[$cadLayer];
                    $cadLayers[$cadLayer] = true;
                }
            }

            $context = $rowLayerId !== null ? $contextFor($rowLayerId) : ['geometry_type' => null, 'fields' => []];
            $layerGeometryType = $context['geometry_type'] !== null ? (string) $context['geometry_type'] : null;
            $layerFields = \is_array($context['fields']) ? $context['fields'] : [];

            // A local/assumed CAD grid is applied before the declared CRS is
            // imposed, so the coordinates entering validation are in the
            // declared CRS (FR-252).
            $geometry = $affine !== null && $row['geometry'] !== null
                ? $this->applyAffine($row['geometry'], $affine)
                : $row['geometry'];

            if ($geometry === null) {
                $errors[] = ['rule' => 'VR-32', 'message' => 'Row has no geometry.'];
            } else {
                $checked = $this->checkGeometry($geometry, $srid);
                if ($checked['error'] !== null) {
                    $errors[] = ['rule' => 'VR-32', 'message' => $checked['error']];
                } else {
                    $geometryTypes[(string) $checked['geometry_type']] = true;
                    if ($layerGeometryType !== null && $layerGeometryType !== 'GEOMETRY'
                        && strtoupper((string) $checked['geometry_type']) !== $layerGeometryType) {
                        $errors[] = [
                            'rule' => 'VR-54',
                            'message' => sprintf(
                                'Geometry type %s is incompatible with target layer geometry type %s.',
                                $checked['geometry_type'],
                                $layerGeometryType,
                            ),
                        ];
                    }
                    $geometry4326 = $checked['geojson'];

                    // VR-53 — a wrong declared CRS is caught before commit by
                    // checking the transformed position against the declared
                    // CRS's area of use.
                    if ($checked['lat'] !== null && $checked['lon'] !== null) {
                        $areaChecked++;
                        $outside = $this->areaOfUseViolation($crs, $checked['lat'], $checked['lon']);
                        if ($outside !== null) {
                            $errors[] = ['rule' => 'VR-53', 'message' => $outside];
                            $areaOutside++;
                        }
                    }
                }
            }

            $normalized = $this->applyFieldMapping($row['raw'], $fieldMapping);

            // Per-row validation against the destination layer's field metadata
            // (TASK-043 validator): required, type, enum options and rules.
            if ($layerFields !== []) {
                foreach ($this->attributeValidator->validate($layerFields, $normalized) as $field => $message) {
                    $errors[] = ['rule' => 'LAYER_METADATA', 'field' => (string) $field, 'message' => (string) $message];
                }
            }

            $isValid = $errors === [];

            if ($isValid) {
                $valid++;
            } else {
                foreach ($errors as $e) {
                    $errorSummary[$e['rule']] = ($errorSummary[$e['rule']] ?? 0) + 1;
                }
            }

            $insert->execute([
                ':jid'    => $jobId,
                ':rn'     => $row['row_number'],
                ':raw'    => json_encode($row['raw'], JSON_THROW_ON_ERROR),
                ':norm'   => json_encode($normalized, JSON_THROW_ON_ERROR),
                ':val'    => $errors === [] ? null : json_encode(['errors' => $errors], JSON_THROW_ON_ERROR),
                ':ok'     => $isValid ? 'true' : 'false',
                ':action' => 'INSERT',
                ':tlid'   => $rowLayerId,
                ':geom'   => $geometry4326,
            ]);
        }

        $validationResult = [
            'detected_fields' => array_keys($detectedFields),
            'geometry_types'  => array_keys($geometryTypes),
            'declared_crs'    => $crs['code'],
            'error_summary'   => $errorSummary,
            'area_of_use'     => [
                'declared_crs'  => $crs['code'],
                'checked_rows'  => $areaChecked,
                'outside_rows'  => $areaOutside,
                'suggested_crs' => $this->suggestedCrs($job),
            ],
        ];

        // TASK-125 — the CAD view of the same validation: which entity layers
        // were seen, what was dropped by the converter, and whether a local grid
        // transformation was recorded before geometry was accepted.
        if ($isDxf) {
            $validationResult['cad'] = [
                'entity_layers'     => array_keys($cadLayers),
                'entity_layer_map'  => $entityLayerMap,
                'dropped'           => $dropped,
                'dropped_rows'      => $droppedRows,
                'local_grid'        => $affine !== null,
                'transformation_id' => $job['transformation_id'] !== null ? (int) $job['transformation_id'] : null,
            ];
        }

        $upd = $this->pdo->prepare(
            'UPDATE app.import_jobs
                SET status = \'VALIDATED\', total_rows = :t, valid_rows = :v, invalid_rows = :i,
                    validation_result = :vr::jsonb
              WHERE id = :id'
        );
        $upd->execute([
            ':t'  => $total,
            ':v'  => $valid,
            ':i'  => $total - $valid,
            ':vr' => json_encode($validationResult, JSON_THROW_ON_ERROR),
            ':id' => $jobId,
        ]);

        $this->audit->writeFromSession('UPDATE', 'app.import_jobs', (string) $jobId,
            ['status' => $job['status']],
            ['status' => 'VALIDATED', 'total_rows' => $total, 'valid_rows' => $valid],
            null, 'Import validated');

        return $this->getJob($userId, $jobId);
    }

    // ------------------------------------------------------------------
    // Preview / error report
    // ------------------------------------------------------------------

    /** @return array<string,mixed> */
    public function preview(int $userId, int $jobId, int $page = 1, int $pageSize = 50): array
    {
        $this->requireJob($userId, $jobId);
        $page = max(1, $page);
        $pageSize = min(max(1, $pageSize), 200);
        $offset = ($page - 1) * $pageSize;

        $stmt = $this->pdo->prepare(
            "SELECT row_number, raw, normalized, validation, is_valid,
                    CASE WHEN geom IS NULL THEN NULL ELSE ST_AsGeoJSON(geom)::json END AS geometry
               FROM staging.import_job_rows
              WHERE job_id = :id
           ORDER BY row_number
              LIMIT :lim OFFSET :off"
        );
        $stmt->bindValue(':id', $jobId, PDO::PARAM_INT);
        $stmt->bindValue(':lim', $pageSize, PDO::PARAM_INT);
        $stmt->bindValue(':off', $offset, PDO::PARAM_INT);
        $stmt->execute();
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

        $out = [];
        foreach ($rows as $r) {
            $out[] = [
                'row_number' => (int) $r['row_number'],
                'values'     => $this->decodeJson($r['normalized']) ?? $this->decodeJson($r['raw']),
                'geometry'   => $r['geometry'] !== null ? $this->decodeJson($r['geometry']) : null,
                'is_valid'   => (bool) $r['is_valid'],
                'errors'     => $this->decodeJson($r['validation'])['errors'] ?? [],
            ];
        }

        $count = $this->pdo->prepare('SELECT COUNT(*) FROM staging.import_job_rows WHERE job_id = :id');
        $count->execute([':id' => $jobId]);

        return [
            'page'      => $page,
            'page_size' => $pageSize,
            'total'     => (int) $count->fetchColumn(),
            'rows'      => $out,
        ];
    }

    /** The per-row error report as CSV (api.md §10 GET /imports/{id}/errors). */
    public function errorsCsv(int $userId, int $jobId): string
    {
        $this->requireJob($userId, $jobId);

        $stmt = $this->pdo->prepare(
            'SELECT row_number, raw, validation
               FROM staging.import_job_rows
              WHERE job_id = :id AND NOT is_valid
           ORDER BY row_number'
        );
        $stmt->execute([':id' => $jobId]);

        $fh = fopen('php://temp', 'r+');
        if ($fh === false) {
            throw new ApiError('INTERNAL_ERROR', 'Could not build the import error report.', 500);
        }
        fputcsv($fh, ['row_number', 'rule', 'message', 'raw']);
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $validation = $this->decodeJson($r['validation']) ?? [];
            $errors = \is_array($validation['errors'] ?? null) ? $validation['errors'] : [];
            foreach ($errors as $e) {
                fputcsv($fh, [
                    (int) $r['row_number'],
                    (string) ($e['rule'] ?? ''),
                    (string) ($e['message'] ?? ''),
                    json_encode($this->decodeJson($r['raw']), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
                ]);
            }
        }
        rewind($fh);
        $csv = (string) stream_get_contents($fh);
        fclose($fh);
        return $csv;
    }

    // ------------------------------------------------------------------
    // Commit
    // ------------------------------------------------------------------

    /** @return array<string,mixed> */
    public function commit(int $userId, int $jobId, bool $partial, string $idempotencyKey): array
    {
        $job = $this->requireJob($userId, $jobId);
        $idempotencyKey = trim($idempotencyKey);

        // A replayed commit with the same key returns the original result and
        // inserts nothing (api.md §10 / FR-176).
        if ($idempotencyKey !== '') {
            $replay = $this->findJobByIdempotencyKey($idempotencyKey);
            if ($replay !== null) {
                if ((int) $replay['id'] !== $jobId || (string) $replay['status'] === 'COMMITTED') {
                    return $this->formatJob($replay) + ['idempotent' => true];
                }
            }
        }

        if ((string) $job['status'] === 'COMMITTED') {
            return $this->formatJob($job) + ['idempotent' => true];
        }
        if ((string) $job['status'] !== 'VALIDATED') {
            throw new ApiError('IMPORT_INVALID', 'The import must be validated before it can be committed.', 422, [
                'status' => $job['status'],
            ]);
        }

        $invalid = (int) $job['invalid_rows'];
        if ($invalid > 0 && !$partial) {
            throw new ApiError('IMPORT_INVALID', sprintf(
                '%d row(s) failed validation; set {"partial": true} to commit only the valid rows.',
                $invalid,
            ), 422, ['invalid_rows' => $invalid]);
        }

        $valid = (int) $job['valid_rows'];
        if ($valid <= 0) {
            throw new ApiError('IMPORT_INVALID', 'There are no valid rows to commit.', 422);
        }

        $targetEntity = (string) $job['target_entity'];
        if (!\in_array($targetEntity, ['FEATURE', 'CONTROL_POINT'], true)) {
            throw new ApiError('IMPORT_INVALID', sprintf(
                'Committing %s imports is not available yet.',
                $targetEntity,
            ), 422);
        }

        $isCad = strtoupper((string) $job['source_format']) === 'DXF';
        // FR-253 — imported CAD geometry is stamped CAD_IMPORT and is never
        // auto-approved. `gis_features` has no DRAFT state, so it lands PENDING
        // rather than ACTIVE.
        $provenance = $isCad ? 'CAD_IMPORT' : 'IMPORTED_GIS';
        $featureStatus = $isCad ? 'PENDING' : 'ACTIVE';

        $layerId = $job['target_layer_id'] !== null ? (int) $job['target_layer_id'] : 0;
        if ($targetEntity === 'FEATURE' && $layerId <= 0 && !$isCad) {
            throw new ApiError('IMPORT_INVALID', 'A target layer is required to commit a FEATURE import.', 422);
        }
        if ($targetEntity === 'CONTROL_POINT' && $job['declared_crs_id'] === null) {
            throw new ApiError('CRS_REQUIRED', 'A declared_crs is required to commit control point candidates.', 422);
        }

        $owned = DbTransaction::begin($this->pdo);
        $committed = 0;
        $skipped = 0;
        try {
            if ($idempotencyKey !== '') {
                $this->pdo->prepare('UPDATE app.import_jobs SET idempotency_key = :k WHERE id = :id')
                    ->execute([':k' => $idempotencyKey, ':id' => $jobId]);
            }

            if ($targetEntity === 'CONTROL_POINT') {
                // TASK-125 / FR-254 — CAD survey points become UNVERIFIED
                // candidates, never verified control. A name collision for the
                // same CRS is skipped rather than merged.
                $source = 'CAD import: ' . mb_substr((string) $job['source_filename'], 0, 140);
                $stmt = $this->pdo->prepare(
                    "INSERT INTO app.survey_control_points
                        (point_name, point_type, native_crs_id, latitude, longitude,
                         coordinate_origin, status, source, created_by, geom)
                     SELECT
                        COALESCE(NULLIF(r.normalized->>'point_name', ''), 'CAD-' || r.row_number),
                        'OTHER', :crs, ST_Y(r.geom), ST_X(r.geom),
                        'GEOGRAPHIC', 'UNVERIFIED', :src, :uid, r.geom
                       FROM staging.import_job_rows r
                      WHERE r.job_id = :jid AND r.is_valid AND r.geom IS NOT NULL
                     ON CONFLICT (point_name, native_crs_id) WHERE deleted_at IS NULL DO NOTHING
                    RETURNING id"
                );
                $stmt->execute([
                    ':crs' => (int) $job['declared_crs_id'],
                    ':src' => $source,
                    ':uid' => $userId,
                    ':jid' => $jobId,
                ]);
                $committed = \count($stmt->fetchAll(PDO::FETCH_COLUMN));
                $skipped = \max(0, $valid - $committed);
            } else {
                $stmt = $this->pdo->prepare(
                    "INSERT INTO app.gis_features
                        (id, layer_id, attributes, status, provenance, source_document_id, created_by, geom)
                     SELECT gen_random_uuid(), COALESCE(r.target_layer_id, :lid::bigint),
                            COALESCE(r.normalized, '{}'::jsonb), :status, :prov,
                            :doc, :uid, r.geom
                       FROM staging.import_job_rows r
                      WHERE r.job_id = :jid AND r.is_valid AND r.geom IS NOT NULL
                    RETURNING id"
                );
                $stmt->execute([
                    ':lid'    => $layerId > 0 ? $layerId : null,
                    ':status' => $featureStatus,
                    ':prov'   => $provenance,
                    ':doc'    => $job['source_document_id'],
                    ':uid'    => $userId,
                    ':jid'    => $jobId,
                ]);
                $committed = \count($stmt->fetchAll(PDO::FETCH_COLUMN));
            }

            $this->pdo->prepare(
                "UPDATE app.import_jobs
                    SET status = 'COMMITTED', committed_by = :uid, committed_at = CURRENT_TIMESTAMP
                  WHERE id = :id"
            )->execute([':uid' => $userId, ':id' => $jobId]);

            $table = $targetEntity === 'CONTROL_POINT' ? 'app.survey_control_points' : 'app.gis_features';
            $this->audit->writeFromSession('INSERT', $table, (string) $jobId, null, [
                'import_job_id' => $jobId,
                'target_entity' => $targetEntity,
                'layer_id' => $layerId,
                'provenance' => $provenance,
                'rows_committed' => $committed,
                'rows_skipped' => $skipped,
                'partial' => $partial,
            ], null, 'Import committed');

            DbTransaction::commit($this->pdo, $owned);
        } catch (Throwable $e) {
            DbTransaction::rollback($this->pdo, $owned, $e);
        }

        return $this->getJob($userId, $jobId) + [
            'committed_rows' => $committed,
            'skipped_rows'   => $skipped,
            'idempotent'     => false,
        ];
    }

    // ------------------------------------------------------------------
    // Cancel
    // ------------------------------------------------------------------

    public function cancel(int $userId, int $jobId): void
    {
        $job = $this->requireJob($userId, $jobId);
        if ((string) $job['status'] === 'COMMITTED') {
            throw new ApiError('IMPORT_INVALID', 'A committed import cannot be cancelled.', 422);
        }

        $this->pdo->prepare('DELETE FROM staging.import_job_rows WHERE job_id = :id')->execute([':id' => $jobId]);
        $this->pdo->prepare("UPDATE app.import_jobs SET status = 'CANCELLED' WHERE id = :id")->execute([':id' => $jobId]);

        $this->audit->writeFromSession('DELETE', 'app.import_jobs', (string) $jobId,
            ['status' => $job['status']], ['status' => 'CANCELLED'], null, 'Import cancelled and staging purged');
    }

    // ------------------------------------------------------------------
    // Internals
    // ------------------------------------------------------------------

    /**
     * Detect the source format and, for OGR-backed formats, probe the file so a
     * `.prj`/embedded CRS can be offered as a suggestion (FR-252/VR-52). The
     * suggestion is resolved through the CRS registry so the UI can never
     * pre-select an unknown CRS, and it is never written to `declared_crs_id`.
     *
     * A DXF is additionally scanned for its CAD entity layers and the annotation
     * / block / 3D entity types OGR discards, so the job's dropped-entity report
     * (FR-250) and entity-layer mapping are available before validation.
     *
     * @return array{source:string, suggested_crs:?string, extra:array<string,mixed>}
     */
    private function inspectSource(string $content, string $filename): array
    {
        $ext = strtolower(pathinfo($filename, PATHINFO_EXTENSION));

        return $this->ogr->withSandbox(function (string $dir) use ($content, $filename, $ext): array {
            $path = $dir . DIRECTORY_SEPARATOR . 'upload' . ($ext !== '' ? '.' . $ext : '');
            if (file_put_contents($path, $content) === false) {
                throw new ApiError('IMPORT_INVALID', 'The uploaded file could not be staged for inspection.', 422);
            }

            $format = $this->ogr->detectFormat($path, $filename);
            $source = self::FORMAT_TO_SOURCE[$format] ?? null;
            if ($source === null) {
                throw new ApiError('IMPORT_INVALID', 'The file format could not be determined.', 422, [
                    'fields' => [['field' => 'file', 'rule' => 'FORMAT']],
                ]);
            }

            // DXF has no georeferencing, so a probe can never yield a CRS; only
            // the native formats' `/vsizip/` etc. probe runs.
            $suggested = null;
            $extra = [];
            if ($format !== OgrAdapter::FORMAT_DXF
                && \in_array($format, OgrAdapter::OGR_REQUIRED, true) && $this->ogr->isAvailable()) {
                $probe = $this->ogr->probe($path, $format);
                $resolved = $probe['crs'] !== null ? $this->resolveCrs($probe['crs']) : null;
                $suggested = $resolved['code'] ?? null;
            }

            // TASK-125 — record what OGR cannot carry into the pipeline.
            if ($format === OgrAdapter::FORMAT_DXF) {
                $scan = $this->dxfScanner->scan($content);
                $extra['dropped'] = $scan['dropped'];
                $extra['entity_layers'] = $scan['entity_layers'];
            }

            return ['source' => $source, 'suggested_crs' => $suggested, 'extra' => $extra];
        });
    }

    private function readerFor(string $sourceFormat): RowReader
    {
        foreach ([$this->geojsonReader, $this->csvReader, $this->ogrReader] as $reader) {
            if ($reader->supports($sourceFormat)) {
                return $reader;
            }
        }
        throw new ApiError('IMPORT_INVALID', sprintf(
            'The %s importer is not available yet.',
            $sourceFormat,
        ), 422);
    }

    /**
     * Transform a source geometry into the layer's 4326 staging geometry and
     * report its centroid in 4326 (used for the area-of-use check, VR-53).
     *
     * @return array{error:?string,geometry_type:?string,geojson:?string,lat:?float,lon:?float}
     */
    private function checkGeometry(array $geometry, int $srid): array
    {
        $gj = json_encode($geometry, JSON_THROW_ON_ERROR);

        $expr = $srid === 4326
            ? 'ST_SetSRID(ST_GeomFromGeoJSON(:gj), 4326)'
            : 'ST_Transform(ST_SetSRID(ST_GeomFromGeoJSON(:gj), :srid), 4326)';

        $sql = "SELECT ST_IsValid(g) AS ok, ST_GeometryType(g) AS gtype, ST_AsGeoJSON(g)::json AS gj,
                       ST_Y(ST_Centroid(g)) AS lat, ST_X(ST_Centroid(g)) AS lon
                  FROM (SELECT $expr AS g) q";

        try {
            $stmt = $this->pdo->prepare($sql);
            $stmt->execute($srid === 4326 ? [':gj' => $gj] : [':gj' => $gj, ':srid' => $srid]);
            $row = $stmt->fetch(PDO::FETCH_ASSOC);
        } catch (Throwable) {
            return ['error' => 'Geometry could not be parsed in the declared CRS.', 'geometry_type' => null, 'geojson' => null, 'lat' => null, 'lon' => null];
        }

        if ($row === false) {
            return ['error' => 'Geometry could not be evaluated.', 'geometry_type' => null, 'geojson' => null, 'lat' => null, 'lon' => null];
        }
        if (!(bool) $row['ok']) {
            return ['error' => 'Geometry is not valid per ST_IsValid.', 'geometry_type' => null, 'geojson' => null, 'lat' => null, 'lon' => null];
        }

        $type = strtoupper(substr((string) $row['gtype'], 3)); // ST_Polygon → POLYGON

        return [
            'error'         => null,
            'geometry_type' => $type,
            'geojson'       => (string) $row['gj'],
            'lat'           => $row['lat'] !== null ? (float) $row['lat'] : null,
            'lon'           => $row['lon'] !== null ? (float) $row['lon'] : null,
        ];
    }

    /**
     * VR-53 — area-of-use check. After a row's geometry has been transformed to
     * 4326, its centroid must fall inside the declared CRS's
     * `ref.crs_registry` bounding box (`area_south/west/north/east`). A CRS
     * whose bounds are NULL disables the check for that row.
     *
     * @param array<string,mixed> $crs
     */
    private function areaOfUseViolation(array $crs, float $lat, float $lon): ?string
    {
        $s = $crs['area_south'] ?? null;
        $w = $crs['area_west'] ?? null;
        $n = $crs['area_north'] ?? null;
        $e = $crs['area_east'] ?? null;
        if ($s === null || $w === null || $n === null || $e === null) {
            return null;
        }

        $s = (float) $s;
        $w = (float) $w;
        $n = (float) $n;
        $e = (float) $e;

        if ($lat >= $s && $lat <= $n && $lon >= $w && $lon <= $e) {
            return null;
        }

        return sprintf(
            'Geometry centroid (lat %.6f, lon %.6f) falls outside the area of use '
            . '[S %.4f, W %.4f, N %.4f, E %.4f] of the declared CRS %s; the declared CRS may be wrong.',
            $lat,
            $lon,
            $s,
            $w,
            $n,
            $e,
            (string) ($crs['code'] ?? ''),
        );
    }

    /**
     * @param array<string,mixed> $raw
     * @param array<string,mixed> $fieldMapping target → source; empty = identity
     * @return array<string,mixed>
     */
    private function applyFieldMapping(array $raw, array $fieldMapping): array
    {
        if ($fieldMapping === []) {
            return $raw;
        }
        $normalized = [];
        foreach ($fieldMapping as $target => $source) {
            if (array_key_exists((string) $source, $raw)) {
                $normalized[(string) $target] = $raw[(string) $source];
            }
        }
        return $normalized;
    }

    /**
     * Whether a converted DXF row is an annotation/block entity that the CAD
     * import does not carry into the GIS layer. A label is detected either by a
     * populated `Text` property or by the entity's `SubClasses` name.
     *
     * @param array<string,mixed> $raw
     */
    private function isCadAnnotation(array $raw): bool
    {
        $text = $raw['Text'] ?? null;
        if (\is_string($text) && trim($text) !== '') {
            return true;
        }
        $subClasses = $raw['SubClasses'] ?? null;
        if (\is_string($subClasses)) {
            foreach (self::CAD_ANNOTATION_CLASSES as $class) {
                if (str_contains($subClasses, $class)) {
                    return true;
                }
            }
        }
        return false;
    }

    /**
     * Validate the DXF entity-layer → GIS-layer map supplied on the mapping
     * update: string CAD layer names to existing target GIS layer ids. An empty
     * map means the DXF has no mapping and cannot be validated.
     *
     * @return array<string,int>
     */
    private function normaliseEntityLayerMap(mixed $raw): array
    {
        if (!\is_array($raw)) {
            return [];
        }
        $map = [];
        foreach ($raw as $cadLayer => $target) {
            if (!\is_string($cadLayer) || trim($cadLayer) === '') {
                throw new ApiError('VALIDATION_FAILED', 'entity_layer_map keys must be CAD entity layer names.', 422, [
                    'fields' => [['field' => 'entity_layer_map', 'rule' => 'KEYS']],
                ]);
            }
            if (!is_numeric($target)) {
                throw new ApiError('VALIDATION_FAILED', 'entity_layer_map values must be target GIS layer ids.', 422, [
                    'fields' => [['field' => 'entity_layer_map', 'rule' => 'VALUES']],
                ]);
            }
            $layerId = (int) $target;
            if ($layerId <= 0) {
                throw new ApiError('VALIDATION_FAILED', 'entity_layer_map values must be positive layer ids.', 422);
            }
            $this->assertLayerExists($layerId);
            $map[trim($cadLayer)] = $layerId;
        }
        return $map;
    }

    /**
     * Read the entity-layer map back out of a job's stored options.
     *
     * @param array<string,mixed> $options
     * @return array<string,int>
     */
    private function storedEntityLayerMap(array $options): array
    {
        $raw = $options['entity_layer_map'] ?? null;
        if (!\is_array($raw)) {
            return [];
        }
        $map = [];
        foreach ($raw as $cadLayer => $target) {
            if (\is_string($cadLayer) && is_numeric($target)) {
                $map[$cadLayer] = (int) $target;
            }
        }
        return $map;
    }

    /**
     * Normalise a local CAD grid transformation. An omitted scale defaults to 1
     * and an omitted rotation to 0; a zero scale is refused because it is never
     * a real survey grid.
     *
     * @param array<string,mixed> $t
     * @return array{origin_x:float,origin_y:float,scale:float,rotation_deg:float,accuracy_m:?float,units:string}
     */
    private function normaliseAffine(array $t): array
    {
        $num = static function (mixed $value, float $default): float {
            return is_numeric($value) ? (float) $value : $default;
        };

        $scale = $num($t['scale'] ?? null, 1.0);
        if ($scale == 0.0) {
            throw new ApiError('VALIDATION_FAILED', 'transformation.scale must be non-zero.', 422, [
                'fields' => [['field' => 'transformation.scale', 'rule' => 'NON_ZERO']],
            ]);
        }
        $accuracy = $t['accuracy_m'] ?? null;

        return [
            'origin_x'     => $num($t['origin_x'] ?? null, 0.0),
            'origin_y'     => $num($t['origin_y'] ?? null, 0.0),
            'scale'        => $scale,
            'rotation_deg' => $num($t['rotation_deg'] ?? null, 0.0),
            'accuracy_m'   => is_numeric($accuracy) ? (float) $accuracy : null,
            'units'        => \is_string($t['units'] ?? null) && $t['units'] !== '' ? (string) $t['units'] : 'm',
        ];
    }

    /**
     * Apply a documented `AFFINE_LOCAL` transformation to a GeoJSON geometry:
     * rotate about the origin, scale, then translate. The resulting coordinates
     * are in the declared CRS and are validated as such (FR-252).
     *
     * @param array<string,mixed> $geometry
     * @param array<string,mixed> $affine
     * @return array<string,mixed>
     */
    private function applyAffine(array $geometry, array $affine): array
    {
        $ox    = (float) ($affine['origin_x'] ?? 0.0);
        $oy    = (float) ($affine['origin_y'] ?? 0.0);
        $scale = (float) ($affine['scale'] ?? 1.0);
        $theta = deg2rad((float) ($affine['rotation_deg'] ?? 0.0));
        $cos   = cos($theta);
        $sin   = sin($theta);

        $transform = static function (array $position) use ($ox, $oy, $scale, $cos, $sin): array {
            if (!isset($position[0], $position[1]) || !is_numeric($position[0]) || !is_numeric($position[1])) {
                return $position;
            }
            $x = (float) $position[0];
            $y = (float) $position[1];
            $xr = $x * $cos - $y * $sin;
            $yr = $x * $sin + $y * $cos;
            $position[0] = $ox + $scale * $xr;
            $position[1] = $oy + $scale * $yr;
            return $position;
        };

        if (\is_array($geometry['coordinates'] ?? null)) {
            $geometry['coordinates'] = $this->mapCoordinates($geometry['coordinates'], $transform);
        }
        return $geometry;
    }

    /**
     * Walk a GeoJSON coordinate array (Point → LineString → Polygon → Multi*)
     * and apply the transformer to every position (a pair whose first element is
     * numeric).
     *
     * @param array<int,mixed> $coordinates
     * @param callable(array<int,mixed>):array<int,mixed> $transform
     * @return array<int,mixed>
     */
    private function mapCoordinates(array $coordinates, callable $transform): array
    {
        if ($coordinates === []) {
            return $coordinates;
        }
        if (isset($coordinates[0]) && is_numeric($coordinates[0])) {
            return $transform($coordinates);
        }
        foreach ($coordinates as $i => $child) {
            if (\is_array($child)) {
                $coordinates[$i] = $this->mapCoordinates($child, $transform);
            }
        }
        return $coordinates;
    }

    /** @return array<string,mixed> */
    private function requireJob(int $userId, int $jobId): array
    {
        $stmt = $this->pdo->prepare('SELECT * FROM app.import_jobs WHERE id = :id');
        $stmt->execute([':id' => $jobId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($row === false || (int) $row['created_by'] !== $userId) {
            // An import job is private to its creator; absence and
            // not-yours are deliberately indistinguishable (api.md §6.1).
            throw new ApiError('NOT_FOUND', 'Import job not found.', 404);
        }
        return $row;
    }

    /** @return array<string,mixed>|null */
    private function findJobByIdempotencyKey(string $key): ?array
    {
        $stmt = $this->pdo->prepare('SELECT * FROM app.import_jobs WHERE idempotency_key = :k LIMIT 1');
        $stmt->execute([':k' => $key]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row === false ? null : $row;
    }

    /** @return array{id:int,srid:int,code:string}|null */
    private function resolveCrs(string $declared): ?array
    {
        $candidates = [$declared];
        if (preg_match('/^EPSG:(\d+)$/i', $declared, $m) === 1) {
            $candidates[] = $m[1];
        }
        $candidates = array_values(array_unique(array_map('trim', $candidates)));

        $stmt = $this->pdo->prepare(
            'SELECT id, srid, code FROM ref.crs_registry
              WHERE code = ANY(:codes) OR srid::text = ANY(:codes)
              LIMIT 1'
        );
        $stmt->execute([':codes' => '{' . implode(',', array_map(static fn (string $c): string => '"' . str_replace('"', '', $c) . '"', $candidates)) . '}']);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($row === false) {
            return null;
        }
        return ['id' => (int) $row['id'], 'srid' => (int) $row['srid'], 'code' => (string) $row['code']];
    }

    /** @return array<string,mixed>|null */
    private function crsById(int $id): ?array
    {
        $stmt = $this->pdo->prepare(
            'SELECT id, srid, code, area_south, area_west, area_north, area_east
               FROM ref.crs_registry WHERE id = :id'
        );
        $stmt->execute([':id' => $id]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($row === false) {
            return null;
        }
        return [
            'id'         => (int) $row['id'],
            'srid'       => (int) $row['srid'],
            'code'       => (string) $row['code'],
            'area_south' => $row['area_south'] !== null ? (float) $row['area_south'] : null,
            'area_west'  => $row['area_west'] !== null ? (float) $row['area_west'] : null,
            'area_north' => $row['area_north'] !== null ? (float) $row['area_north'] : null,
            'area_east'  => $row['area_east'] !== null ? (float) $row['area_east'] : null,
        ];
    }

    private function assertLayerExists(int $layerId): void
    {
        $stmt = $this->pdo->prepare('SELECT 1 FROM app.gis_layers WHERE id = :id AND deleted_at IS NULL');
        $stmt->execute([':id' => $layerId]);
        if ($stmt->fetchColumn() === false) {
            throw new ApiError('VALIDATION_FAILED', 'The target layer does not exist.', 422, [
                'fields' => [['field' => 'target_layer_id', 'rule' => 'EXISTS']],
            ]);
        }
    }

    /** @return list<array<string,mixed>> */
    private function layerFields(int $layerId): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT field_name, field_type, required, options, validation_rules
               FROM app.gis_layer_fields
              WHERE layer_id = :id AND deleted_at IS NULL'
        );
        $stmt->execute([':id' => $layerId]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    private function layerGeometryType(int $layerId): ?string
    {
        $stmt = $this->pdo->prepare('SELECT geometry_type FROM app.gis_layers WHERE id = :id AND deleted_at IS NULL');
        $stmt->execute([':id' => $layerId]);
        $type = $stmt->fetchColumn();
        return $type === false ? null : strtoupper((string) $type);
    }

    /**
     * @param array<string,mixed> $row
     * @return array<string,mixed>
     */
    private function formatJob(array $row): array
    {
        $declaredCrs = null;
        if ($row['declared_crs_id'] !== null) {
            $crs = $this->crsById((int) $row['declared_crs_id']);
            $declaredCrs = $crs['code'] ?? null;
        }
        $options = $this->decodeJson($row['options']) ?? [];

        return [
            'id'               => (int) $row['id'],
            'source_format'    => (string) $row['source_format'],
            'source_filename'  => (string) $row['source_filename'],
            'target_entity'    => (string) $row['target_entity'],
            'target_layer_id'  => $row['target_layer_id'] !== null ? (int) $row['target_layer_id'] : null,
            'declared_crs'     => $declaredCrs,
            'suggested_crs'    => $this->suggestedCrs($row),
            'transformation_id' => $row['transformation_id'] !== null ? (int) $row['transformation_id'] : null,
            // TASK-125 — the CAD report: entity types discarded by the converter
            // (FR-250) and the CAD entity layers found in the source.
            'dropped'          => $options['dropped'] ?? null,
            'entity_layers'    => $options['entity_layers'] ?? null,
            'field_mapping'    => $this->decodeJson($row['field_mapping']),
            'options'          => $options,
            'status'           => (string) $row['status'],
            'total_rows'       => (int) $row['total_rows'],
            'valid_rows'       => (int) $row['valid_rows'],
            'invalid_rows'     => (int) $row['invalid_rows'],
            'validation_result' => $this->decodeJson($row['validation_result']),
            'created_at'       => $row['created_at'],
            'committed_at'     => $row['committed_at'],
        ];
    }

    /**
     * The `.prj`/OGR-probe CRS suggestion recorded at upload. This is offered to
     * the user for confirmation only — it is never used as the declared CRS
     * (FR-252/VR-52).
     *
     * @param array<string,mixed> $job
     */
    private function suggestedCrs(array $job): ?string
    {
        $options = $this->decodeJson($job['options'] ?? null) ?? [];
        $value = $options['suggested_crs'] ?? null;
        return \is_string($value) && $value !== '' ? $value : null;
    }

    /**
     * @param mixed $value
     * @return array<string,mixed>|null
     */
    private function decodeJson(mixed $value): ?array
    {
        if ($value === null || $value === '') {
            return null;
        }
        if (\is_array($value)) {
            return $value;
        }
        $decoded = json_decode((string) $value, true);
        return \is_array($decoded) ? $decoded : null;
    }
}
