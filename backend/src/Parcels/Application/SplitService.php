<?php
declare(strict_types=1);

namespace App\Parcels\Application;

use App\Audit\AuditWriter;
use App\Core\Error\ApiError;
use App\Parcels\Domain\SplitValidator;
use PDO;

/**
 * TASK-112 — Split service (architecture.md §18.3).
 *
 * Dry run and commit share one code path: derive → validate → report or
 * write. The dry run performs no writes at all; the commit runs inside the
 * ambient transaction opened by AuthenticateMiddleware and throws ApiError on
 * any failure so the middleware rolls everything back — nothing is partially
 * applied.
 *
 * Failure enumeration: SPLIT_INVALID carries every failed VR check, not just
 * the first (todo.md TASK-112 AC).
 *
 * Rollback testing seam (Api/SplitRollbackTest): failAfterChildren() is
 * protected so a test subclass can inject a failure between the children
 * INSERT and the operation INSERT, proving parent, children, relationships
 * and versions are all rolled back together.
 */
class SplitService
{
    /** Caller's If-Match version, verified against the locked row (§18.3). */
    private ?int $ifMatchVersion = null;

    public function setIfMatchVersion(?int $version): void
    {
        $this->ifMatchVersion = $version;
    }
    /** Parent statuses a split may start from (§18.3). */
    public const ALLOWED_PARENT_STATUSES = ['DRAFT', 'RETURNED', 'UNDER_REVIEW', 'VERIFIED', 'APPROVED', 'PUBLISHED'];

    /** Method → provenance carried by children (§15, §18.5). */
    private const METHOD_PROVENANCE = [
        'MAP_SPLIT_LINE' => 'MANUAL_DRAWING',
        'SURVEY_GEOMETRY' => 'SURVEY_COORDINATES',
        'TECHNICAL_DESCRIPTION' => 'COMPUTED_FROM_TECHNICAL_DESCRIPTION',
        'IMPORTED_GEOMETRY' => 'IMPORTED_GIS',
    ];

    public function __construct(
        private PDO $pdo,
        private SplitValidator $validator,
        private AuditWriter $audit,
    ) {
    }

    /**
     * @param array<string,mixed> $input  Raw request body
     * @param bool $dryRun true → read-only preview, no writes
     * @return array<string,mixed> api.md §8.2 data payload
     */
    public function split(string $parentParcelId, int $userId, array $input, bool $dryRun, string $requestId, string $idempotencyKey = ''): array
    {
        $method = (string) ($input['method'] ?? '');
        if (!array_key_exists($method, self::METHOD_PROVENANCE)) {
            throw new ApiError('VALIDATION_FAILED', 'method must be one of MAP_SPLIT_LINE, SURVEY_GEOMETRY, TECHNICAL_DESCRIPTION, IMPORTED_GEOMETRY', 400);
        }

        $reason = trim((string) ($input['reason'] ?? ''));
        if ($reason === '') {
            throw new ApiError('VALIDATION_FAILED', 'A reason is required for a split (FR-137).', 400);
        }

        // Existence first: an unknown or out-of-scope parent is 404 (never
        // 403 — api.md §6.1), before any derivation work.
        $parentGeom = $this->readParentGeometry($parentParcelId);

        // PARSE — probe geometries before any transactional work so a PostGIS
        // parse error cannot poison the ambient transaction (statement-level
        // failures inside a transaction abort it in PostgreSQL).
        $splitLineGj = $this->parseSplitLine($input);

        // Pre-derive children OUTSIDE the transaction: MAP_SPLIT_LINE uses
        // ST_Split, which can raise on degenerate input; catching that here
        // keeps the transaction clean.
        $childGeoms = $this->deriveChildren($parentParcelId, $method, $splitLineGj, $input);
        if (count($childGeoms) < 2) {
            throw new ApiError('SPLIT_INVALID', 'The split line produced fewer than two children.', 422, [
                'failures' => [['rule' => 'VR-35', 'message' => 'The split line produced fewer than two children.']],
            ]);
        }

        // VALIDATE — the identical path for dry run and commit.
        $validation = $this->validator->validateSplit($parentGeom, $childGeoms);
        if (!$validation['passed']) {
            throw new ApiError('SPLIT_INVALID', 'The split failed validation; nothing was written.', 422, [
                'failures' => array_values(array_filter($validation['checks'], fn (array $c): bool => $c['status'] === 'fail')),
                'warnings' => $validation['warnings'],
            ]);
        }

        if ($dryRun) {
            return $this->resultPayload(null, true, $parentParcelId, $method, $validation, $childGeoms, $input);
        }

        return $this->commit($parentParcelId, $userId, $method, $validation, $childGeoms, $input, $reason, $requestId, $idempotencyKey);
    }

    // ------------------------------------------------------------------

    private function readParentGeometry(string $parentParcelId): string
    {
        $stmt = $this->pdo->prepare('SELECT geom, ST_AsGeoJSON(geom) AS gj FROM app.parcels WHERE id = :id AND deleted_at IS NULL');
        $stmt->execute([':id' => $parentParcelId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($row === false) {
            throw new ApiError('NOT_FOUND', 'Parcel not found', 404);
        }
        if ($row['gj'] === null || trim((string) $row['gj']) === '') {
            throw new ApiError('VALIDATION_FAILED', 'Parent parcel has no geometry to split.', 400);
        }
        return (string) $row['gj'];
    }

    /** @return string|null GeoJSON of the split line, or null for non-line methods */
    private function parseSplitLine(array $input): ?string
    {
        $method = (string) ($input['method'] ?? '');
        if ($method !== 'MAP_SPLIT_LINE') {
            return null;
        }
        $line = $input['split_line'] ?? null;
        if (!is_array($line) || ($line['type'] ?? '') !== 'LineString' || !isset($line['coordinates']) || !is_array($line['coordinates'])) {
            throw new ApiError('VALIDATION_FAILED', 'MAP_SPLIT_LINE requires split_line to be a GeoJSON LineString.', 400);
        }
        return json_encode($line, JSON_THROW_ON_ERROR);
    }

    /**
     * Derive candidate child geometries by method (§18.3). SURVEY_GEOMETRY /
     * TECHNICAL_DESCRIPTION / IMPORTED_GEOMETRY supply explicit child
     * polygons (survey-engine output or validated import result).
     *
     * @return string[] GeoJSON polygon strings in child order
     */
    private function deriveChildren(string $parentParcelId, string $method, ?string $splitLineGj, array $input): array
    {
        if ($method === 'MAP_SPLIT_LINE') {
            $stmt = $this->pdo->prepare("
                WITH parent AS (
                    SELECT geom AS g FROM app.parcels WHERE id = :id AND deleted_at IS NULL
                ),
                snapped AS (
                    SELECT ST_Split(p.g, ST_Snap(ST_GeomFromGeoJSON(:line), p.g, 0.00001)) AS parts
                    FROM parent p
                )
                SELECT ST_AsGeoJSON(d.mp) FROM (
                    SELECT (ST_Dump(parts)).geom AS mp FROM snapped
                ) d WHERE ST_GeometryType(d.mp) = 'ST_Polygon'
            ");
            $stmt->execute([':id' => $parentParcelId, ':line' => $splitLineGj]);
            $children = $stmt->fetchAll(PDO::FETCH_COLUMN);
            if ($children === false) {
                throw new ApiError('SPLIT_INVALID', 'The split line produced fewer than two children.', 422, [
                    'failures' => [['rule' => 'VR-35', 'message' => 'The split line produced fewer than two children.']],
                ]);
            }
            return array_values(array_map('strval', $children));
        }

        $children = $input['children'] ?? null;
        if (!is_array($children) || count($children) < 2) {
            throw new ApiError('VALIDATION_FAILED', 'children must list at least two child polygons.', 400);
        }
        $geoms = [];
        foreach (array_values($children) as $i => $child) {
            if (!is_array($child)) {
                throw new ApiError('VALIDATION_FAILED', "children[$i] must be an object.", 400);
            }
            if (isset($child['technical_description_id'])) {
                // TASK-120 — survey-derived child: geometry comes from the
                // TD's current computation (own closure result carried over).
                $geoms[] = $this->computationGeometry((int) $child['technical_description_id'], $i);
                continue;
            }
            $g = $child['geometry'] ?? null;
            if (!is_array($g) || !isset($g['type'])) {
                throw new ApiError('VALIDATION_FAILED', "children[$i].geometry must be a GeoJSON object (or give technical_description_id).", 400);
            }
            $geoms[] = json_encode($g, JSON_THROW_ON_ERROR);
        }
        return $geoms;
    }

    /** GeoJSON of a TD's current computation geometry (TASK-120). */
    private function computationGeometry(int $technicalDescriptionId, int $index): string
    {
        $stmt = $this->pdo->prepare(
            'SELECT ST_AsGeoJSON(geom) FROM app.parcel_computations
              WHERE technical_description_id = :td AND geom IS NOT NULL
              ORDER BY is_current DESC, id DESC LIMIT 1'
        );
        $stmt->execute([':td' => $technicalDescriptionId]);
        $gj = $stmt->fetchColumn();
        if ($gj === false || !is_string($gj) || $gj === '') {
            throw new ApiError('VALIDATION_FAILED', sprintf('children[%d]: technical description %d has no computed geometry; run the calculation first.', $index, $technicalDescriptionId), 400);
        }
        return $gj;
    }

    // ------------------------------------------------------------------

    /**
     * Commit: children → relationships → versions → parent SUPERSEDED →
     * operation row → audit, inside the ambient transaction.
     */
    private function commit(
        string $parentParcelId,
        int $userId,
        string $method,
        array $validation,
        array $childGeoms,
        array $input,
        string $reason,
        string $requestId,
        string $idempotencyKey,
    ): array {
        // Idempotency-Key (§18.4 both operations): a replayed commit returns
        // the original operation instead of writing twice.
        $idempotencyKey = trim($idempotencyKey);
        if ($idempotencyKey !== '') {
            $existing = $this->findOperationByIdempotencyKey($idempotencyKey);
            if ($existing !== null) {
                return $this->resultPayload((int) $existing, false, $parentParcelId, $method, $validation, $childGeoms, $input);
            }
        }

        $parent = $this->lockParent($parentParcelId);
        $this->assertParentSplittable($parent);
        $this->assertScopeCovers($parentParcelId);

        $provenance = self::METHOD_PROVENANCE[$method];
        $parentVersion = (int) $parent['version'];

        $childIds = [];
        foreach ($validation['children'] as $i => $child) {
            $childIds[] = $this->insertChildParcel($parent, $childGeoms[$i], $provenance, $input, $i, $userId, $requestId);
        }
        $this->failAfterChildren(); // rollback-test seam

        $operationId = $this->insertOperation('SPLIT', $method, $parent, $input, $validation, $userId, $reason, $requestId, $idempotencyKey);

        foreach ($childIds as $childId) {
            $this->insertRelationship($parentParcelId, $childId, 'SUBDIVISION', $operationId, $input, $userId);
        }

        foreach ($validation['children'] as $i => $child) {
            $this->writeChildVersion($childIds[$i], $parent, $method, $child, $reason, $requestId);
        }

        $this->supersedeParent($parentParcelId, $parentVersion, $operationId, $reason, $requestId);

        $this->audit->writeFromSession('SPLIT', 'app.parcel_operations', (string) $operationId, null, [
            'parent' => $parentParcelId,
            'children' => $childIds,
            'method' => $method,
        ], $requestId, $reason);

        return $this->resultPayload($operationId, false, $parentParcelId, $method, $validation, $childGeoms, $input, $childIds);
    }

    /** @return array<string,mixed> parent row under FOR UPDATE */
    private function lockParent(string $parentParcelId): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT id, parcel_code, version, status, psgc_barangay, psgc_municipality, psgc_province, org_id, survey_plan_id, source_document_id
             FROM app.parcels WHERE id = :id AND deleted_at IS NULL FOR UPDATE'
        );
        $stmt->execute([':id' => $parentParcelId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($row === false) {
            throw new ApiError('NOT_FOUND', 'Parcel not found', 404);
        }
        return $row;
    }

    /**
     * Status + If-Match guard. If-Match was resolved by the controller
     * against the same SELECT FOR UPDATE — verify here to fail the whole
     * operation before any write.
     *
     * @param array<string,mixed> $parent
     */
    private function assertParentSplittable(array $parent): void
    {
        if (!in_array($parent['status'], self::ALLOWED_PARENT_STATUSES, true)) {
            throw new ApiError('INVALID_STATE', sprintf('A parcel in status %s cannot be split.', $parent['status']), 409);
        }
        if (isset($this->ifMatchVersion) && (int) $this->ifMatchVersion !== (int) $parent['version']) {
            throw new ApiError('VERSION_CONFLICT', 'This parcel was modified by another user.', 409, [
                'current_version' => (int) $parent['version'],
            ]);
        }
    }

    /** §18.3: "verify permission parcel.split AND data scope covers the parent". */
    private function assertScopeCovers(string $parentParcelId): void
    {
        $stmt = $this->pdo->prepare("
            SELECT app.fn_user_can_edit(
                NULLIF(current_setting('app.user_id', true), '')::bigint,
                p.psgc_barangay,
                p.org_id
            )
            FROM app.parcels p WHERE p.id = :id
        ");
        $stmt->execute([':id' => $parentParcelId]);
        $allowed = $stmt->fetchColumn();
        if ($allowed === false || (bool) $allowed !== true) {
            throw new ApiError('PERMISSION_DENIED', 'Your data scope does not cover this parcel.', 403);
        }
    }

    /** @return string child parcel uuid */
    private function insertChildParcel(
        array $parent,
        string $childGeomGj,
        string $provenance,
        array $input,
        int $index,
        int $userId,
        string $requestId,
    ): string {
        $meta = $input['children'][$index] ?? [];
        $lotNumber = is_array($meta) && isset($meta['lot_number']) ? (string) $meta['lot_number'] : null;

        $stmt = $this->pdo->prepare("
            INSERT INTO app.parcels (
                id, parcel_code, lot_number, status, geometry_source,
                psgc_barangay, psgc_municipality, psgc_province, org_id,
                source_document_id, remarks, version, created_by, updated_by, geom
            ) VALUES (
                gen_random_uuid(), :code, :lot, 'DRAFT', :gsrc,
                :brgy, :mun, :prov, :org,
                :doc, :remarks, 1, :uid, :uid, ST_Multi(ST_SetSRID(ST_GeomFromGeoJSON(:gj), 4326))
            )
            RETURNING id
        ");
        $stmt->execute([
            ':code' => sprintf('%s-S%d', $parent['parcel_code'], $index + 1),
            ':lot' => $lotNumber,
            ':gsrc' => $provenance,
            ':brgy' => $parent['psgc_barangay'],
            ':mun' => $parent['psgc_municipality'],
            ':prov' => $parent['psgc_province'],
            ':org' => $parent['org_id'],
            ':doc' => $input['source_document_id'] ?? null,
            ':remarks' => is_array($meta) && isset($meta['remarks']) ? (string) $meta['remarks'] : null,
            ':uid' => $userId,
            ':gj' => $childGeomGj,
        ]);
        $childId = (string) $stmt->fetchColumn();
        $this->persistChildExtra($childId, $childGeomGj, $meta);
        return $childId;
    }

    /**
     * TASK-120 — a TD-derived child carries its own computation row (closure
     * result, snapshot, tolerances) copied from the source computation, and
     * points at it via current_computation_id. FR-210/AC: the child carries
     * COMPUTED_FROM_TECHNICAL_DESCRIPTION provenance (set from the method)
     * and its own closure result.
     */
    protected function persistChildExtra(string $childId, string $childGeomGj, mixed $meta): void
    {
        if (!is_array($meta) || !isset($meta['technical_description_id'])) {
            return;
        }
        $tdId = (int) $meta['technical_description_id'];

        $copy = $this->pdo->prepare("
            INSERT INTO app.parcel_computations (
                parcel_id, technical_description_id, compute_crs_id, method,
                adjustment_method, adjustment_params, start_easting, start_northing,
                close_easting, close_northing, closure_de, closure_dn,
                linear_error_m, error_azimuth_dd, perimeter_m,
                relative_precision_denominator, computed_area_sqm, postgis_area_sqm,
                source_area_sqm, area_diff_sqm, area_diff_pct, closure_status,
                validation_result, input_snapshot, tolerances, engine_version,
                is_current, computed_by, geom
            ) SELECT
                :child, technical_description_id, compute_crs_id, method,
                adjustment_method, adjustment_params, start_easting, start_northing,
                close_easting, close_northing, closure_de, closure_dn,
                linear_error_m, error_azimuth_dd, perimeter_m,
                relative_precision_denominator, computed_area_sqm, postgis_area_sqm,
                source_area_sqm, area_diff_sqm, area_diff_pct, closure_status,
                validation_result, input_snapshot, tolerances, engine_version,
                true, NULLIF(current_setting('app.user_id', true), '')::bigint, geom
              FROM app.parcel_computations
              WHERE technical_description_id = :td AND geom IS NOT NULL
              ORDER BY is_current DESC, id DESC LIMIT 1
            RETURNING id
        ");
        $copy->execute([':child' => $childId, ':td' => $tdId]);
        $computationId = $copy->fetchColumn();
        if ($computationId !== false) {
            $upd = $this->pdo->prepare('UPDATE app.parcels SET current_computation_id = :cid WHERE id = :id');
            $upd->execute([':cid' => (int) $computationId, ':id' => $childId]);
        }
    }

    /** Rollback seam — overridden by test subclasses. */
    protected function failAfterChildren(): void
    {
    }

    private function insertOperation(
        string $type,
        string $method,
        array $parent,
        array $input,
        array $validation,
        int $userId,
        string $reason,
        string $requestId,
        string $idempotencyKey,
    ): int {
        $stmt = $this->pdo->prepare("
            INSERT INTO app.parcel_operations (
                operation_type, method, status, inputs, parcel_snapshot, results,
                area_reconciliation, validation_result, performed_by, reason,
                source_document_id, idempotency_key, request_id
            ) VALUES (
                :type, :method, 'COMMITTED', :inputs::jsonb, :snapshot::jsonb, :results::jsonb,
                :recon::jsonb, :validation::jsonb, :uid, :reason, :doc, :idem, :rid
            )
            RETURNING id
        ");
        $stmt->execute([
            ':type' => $type,
            ':method' => $method,
            ':inputs' => json_encode($input, JSON_THROW_ON_ERROR),
            ':snapshot' => json_encode($parent, JSON_THROW_ON_ERROR),
            ':results' => json_encode(['children' => $validation['children']], JSON_THROW_ON_ERROR),
            ':recon' => json_encode($validation['area_reconciliation'], JSON_THROW_ON_ERROR),
            ':validation' => json_encode($validation, JSON_THROW_ON_ERROR),
            ':uid' => $userId,
            ':reason' => $reason,
            ':doc' => $input['source_document_id'] ?? null,
            ':idem' => $idempotencyKey !== '' ? $idempotencyKey : null,
            ':rid' => $requestId,
        ]);
        return (int) $stmt->fetchColumn();
    }

    private function insertRelationship(
        string $parentId,
        string $childId,
        string $type,
        int $operationId,
        array $input,
        int $userId,
    ): void {
        $stmt = $this->pdo->prepare("
            INSERT INTO app.parcel_relationships (
                parent_parcel_id, child_parcel_id, relationship_type,
                operation_id, effective_date, reason, source_document_id, created_by
            ) VALUES (:p, :c, :t, :op, :eff, :reason, :doc, :uid)
        ");
        $stmt->execute([
            ':p' => $parentId,
            ':c' => $childId,
            ':t' => $type,
            ':op' => $operationId,
            ':eff' => $input['effective_date'] ?? null,
            ':reason' => $input['reason'] ?? null,
            ':doc' => $input['source_document_id'] ?? null,
            ':uid' => $userId,
        ]);
    }

    /**
     * @param array<string,mixed> $parent
     * @param array<string,mixed> $childMeasure
     */
    private function writeChildVersion(string $childId, array $parent, string $method, array $childMeasure, string $reason, string $requestId): void
    {
        $stmt = $this->pdo->prepare("
            INSERT INTO audit.parcel_versions (
                parcel_id, version, snapshot, status, geometry_source,
                change_summary, change_reason, changed_by, request_id
            ) SELECT id, 1, row_to_json(p), status, geometry_source,
                     :summary, :reason, NULLIF(current_setting('app.user_id', true), '')::bigint, :rid
              FROM app.parcels p WHERE p.id = :id
        ");
        $stmt->execute([
            ':id' => $childId,
            ':summary' => sprintf('Created by SPLIT (%s) of %s; area %.4f m²', $method, $parent['parcel_code'], (float) $childMeasure['area_sqm']),
            ':reason' => $reason,
            ':rid' => $requestId,
        ]);
    }

    /** @param array<string,mixed> $parent */
    private function supersedeParent(string $parentId, int $parentVersion, int $operationId, string $reason, string $requestId): void
    {
        // Append-only version row for the pre-split state, then flip status.
        $stmt = $this->pdo->prepare("
            INSERT INTO audit.parcel_versions (
                parcel_id, version, snapshot, status, geometry_source,
                change_summary, change_reason, changed_by, request_id
            ) SELECT id, version, row_to_json(p), status, geometry_source,
                   :summary, :reason, NULLIF(current_setting('app.user_id', true), '')::bigint, :rid
              FROM app.parcels p WHERE p.id = :id
            ON CONFLICT (parcel_id, version) DO NOTHING
        ");
        $stmt->execute([
            ':id' => $parentId,
            ':summary' => 'State before SPLIT; parcel superseded by operation ' . $operationId,
            ':reason' => $reason,
            ':rid' => $requestId,
        ]);

        $upd = $this->pdo->prepare("
            UPDATE app.parcels
               SET status = 'SUPERSEDED', version = version + 1,
                   superseded_by_operation_id = :op, updated_by = NULLIF(current_setting('app.user_id', true), '')::bigint,
                   updated_at = CURRENT_TIMESTAMP
             WHERE id = :id
        ");
        $upd->execute([':op' => $operationId, ':id' => $parentId]);
    }

    private function findOperationByIdempotencyKey(string $key): ?int
    {
        try {
            $stmt = $this->pdo->prepare("SELECT id FROM app.parcel_operations WHERE idempotency_key = :key LIMIT 1");
            $stmt->execute([':key' => $key]);
            $id = $stmt->fetchColumn();
            return $id === false ? null : (int) $id;
        } catch (\PDOException) {
            return null;
        }
    }

    /**
     * Assemble the §8.2 response payload.
     *
     * @param string[] $childGeoms
     * @return array<string,mixed>
     */
    private function resultPayload(
        ?int $operationId,
        bool $dryRun,
        string $parentParcelId,
        string $method,
        array $validation,
        array $childGeoms,
        array $input,
        ?array $childIds = null,
    ): array {
        $childrenOut = [];
        foreach ($validation['children'] as $i => $child) {
            $childrenOut[] = [
                'temp_id' => 'C' . ($i + 1),
                'parcel_id' => $childIds[$i] ?? null,
                'lot_number' => is_array($input['children'][$i] ?? null) ? ($input['children'][$i]['lot_number'] ?? null) : null,
                'area_sqm' => $child['area_sqm'],
                'share_pct' => $child['share_pct'],
                'geometry' => json_decode($childGeoms[$i], true),
            ];
        }

        return [
            'operation_id' => $operationId,
            'dry_run' => $dryRun,
            'method' => $method,
            'parent' => $parentParcelId,
            'children' => $childrenOut,
            'area_reconciliation' => $validation['area_reconciliation'],
            'validation' => [
                'passed' => $validation['passed'],
                'checks' => $validation['checks'],
                'warnings' => $validation['warnings'],
            ],
            'parent_after' => ['status' => $dryRun ? 'SUPERSEDED' : 'SUPERSEDED'],
        ];
    }
}
