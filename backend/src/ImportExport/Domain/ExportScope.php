<?php
declare(strict_types=1);

namespace App\ImportExport\Domain;

use App\Core\Error\ApiError;

/**
 * TASK-127 — the filter/selection scope of a single export.
 *
 * A scope answers "which features, of which layer, restricted how". It is
 * deliberately the *only* place scope is interpreted, so the reader, the
 * provenance block and the `query_spec` recorded on the export ledger can never
 * disagree about what was actually exported.
 *
 * Selection is additive: a caller may narrow by explicit feature ids, by
 * bounding box, and/or by lifecycle status at the same time.
 */
final class ExportScope
{
    /** Feature-status values the feature table actually carries. */
    public const STATUSES = ['ACTIVE', 'PENDING', 'REJECTED', 'ARCHIVED'];

    /** Columns a caller may order by; anything else falls back to created_at. */
    public const SORTABLE = ['id', 'created_at', 'updated_at', 'status', 'psgc_barangay', 'provenance'];

    /** Upper bound on an explicit id list, to keep the IN() list bounded. */
    public const MAX_EXPLICIT_IDS = 5000;

    /**
     * @param list<string> $featureIds
     * @param array{0:float,1:float,2:float,3:float}|null $bbox minx,miny,maxx,maxy in EPSG:4326
     */
    private function __construct(
        public readonly int $layerId,
        public readonly array $featureIds,
        public readonly ?array $bbox,
        public readonly ?string $status,
        public readonly string $sort,
        public readonly string $dir,
        public readonly bool $includeGeometry,
    ) {
    }

    /**
     * @param array<string,mixed> $in
     */
    public static function fromArray(array $in): self
    {
        $layerId = (int) ($in['layer_id'] ?? 0);
        if ($layerId <= 0) {
            throw new ApiError('VALIDATION_FAILED', 'layer_id is required and must be a positive integer.', 422, [
                'fields' => [['field' => 'layer_id', 'rule' => 'REQUIRED']],
            ]);
        }

        $ids = [];
        $rawIds = $in['feature_ids'] ?? [];
        if (is_string($rawIds)) {
            $split = $rawIds === '' ? false : preg_split('/[\s,]+/', $rawIds);
            $rawIds = is_array($split) ? $split : [];
        }
        if (!is_array($rawIds)) {
            throw new ApiError('VALIDATION_FAILED', 'feature_ids must be a list of feature identifiers.', 422, [
                'fields' => [['field' => 'feature_ids', 'rule' => 'TYPE']],
            ]);
        }
        foreach ($rawIds as $id) {
            $id = trim((string) $id);
            if ($id === '') {
                continue;
            }
            if (!preg_match('/^[0-9a-fA-F-]{36}$/', $id)) {
                throw new ApiError('VALIDATION_FAILED', "feature_ids contains a value that is not a UUID: {$id}", 422, [
                    'fields' => [['field' => 'feature_ids', 'rule' => 'UUID']],
                ]);
            }
            $ids[strtolower($id)] = strtolower($id);
        }
        if (count($ids) > self::MAX_EXPLICIT_IDS) {
            throw new ApiError('EXPORT_SCOPE_TOO_LARGE', sprintf(
                'feature_ids may not exceed %d entries.',
                self::MAX_EXPLICIT_IDS
            ), 422);
        }

        $status = isset($in['status']) && $in['status'] !== null && $in['status'] !== ''
            ? strtoupper(trim((string) $in['status']))
            : null;
        if ($status !== null && !in_array($status, self::STATUSES, true)) {
            throw new ApiError('VALIDATION_FAILED', 'status must be one of ' . implode(', ', self::STATUSES) . '.', 422, [
                'fields' => [['field' => 'status', 'rule' => 'ENUM']],
            ]);
        }

        $sort = (string) ($in['sort'] ?? 'created_at');
        if (!in_array($sort, self::SORTABLE, true)) {
            $sort = 'created_at';
        }
        $dir = strtoupper((string) ($in['dir'] ?? 'DESC')) === 'ASC' ? 'ASC' : 'DESC';

        return new self(
            $layerId,
            array_values($ids),
            self::parseBbox($in['bbox'] ?? null),
            $status,
            $sort,
            $dir,
            self::toBool($in['include_geometry'] ?? false),
        );
    }

    /**
     * The bounding box is always interpreted in EPSG:4326, the storage CRS of
     * app.gis_features.geom, so `&&` can be evaluated without a transform.
     *
     * @return array{0:float,1:float,2:float,3:float}|null
     */
    private static function parseBbox(mixed $raw): ?array
    {
        if ($raw === null || $raw === '' || $raw === []) {
            return null;
        }
        if (is_string($raw)) {
            $raw = preg_split('/[\s,]+/', trim($raw)) ?: [];
        }
        if (!is_array($raw) || count($raw) !== 4) {
            throw new ApiError('VALIDATION_FAILED', 'bbox must be minx,miny,maxx,maxy.', 422, [
                'fields' => [['field' => 'bbox', 'rule' => 'LENGTH_4']],
            ]);
        }
        $box = array_map(static fn ($v) => (float) $v, array_values($raw));
        if ($box[0] >= $box[2] || $box[1] >= $box[3]) {
            throw new ApiError('VALIDATION_FAILED', 'bbox minx must be < maxx and miny < maxy.', 422, [
                'fields' => [['field' => 'bbox', 'rule' => 'ORDER']],
            ]);
        }
        if ($box[0] < -180 || $box[2] > 180 || $box[1] < -90 || $box[3] > 90) {
            throw new ApiError('VALIDATION_FAILED', 'bbox must lie within the EPSG:4326 world bounds.', 422, [
                'fields' => [['field' => 'bbox', 'rule' => 'RANGE']],
            ]);
        }

        return [$box[0], $box[1], $box[2], $box[3]];
    }

    private static function toBool(mixed $v): bool
    {
        if (is_bool($v)) {
            return $v;
        }
        return in_array(strtolower(trim((string) $v)), ['1', 'true', 'yes', 'on'], true);
    }

    /**
     * The persisted/audited description of this scope. Contains no feature
     * values, so it is safe to store and to show in an export history list.
     *
     * @return array<string,mixed>
     */
    public function toQuerySpec(): array
    {
        return [
            'layer_id'          => $this->layerId,
            'feature_ids'       => $this->featureIds,
            'feature_id_count'  => count($this->featureIds),
            'bbox'              => $this->bbox,
            'status'            => $this->status,
            'sort'              => $this->sort,
            'dir'               => $this->dir,
            'include_geometry'  => $this->includeGeometry,
        ];
    }
}
