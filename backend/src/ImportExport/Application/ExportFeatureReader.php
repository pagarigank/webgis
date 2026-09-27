<?php
declare(strict_types=1);

namespace App\ImportExport\Application;

use App\Core\Error\ApiError;
use App\ImportExport\Domain\ExportScope;
use PDO;

/**
 * TASK-127 — reads the rows of one export scope.
 *
 * This is the only place that decides three things, so that all five writers
 * inherit the same answers:
 *
 *  - which rows the scope selects (and in what order),
 *  - whether PII is visible, from the caller's permissions,
 *  - which CRS the geometry is handed on in.
 *
 * The geometry is reprojected in PostGIS (`ST_Transform`) rather than in PHP:
 * one server-side call per row instead of a per-vertex round trip, and it uses
 * the same PROJ pipeline the rest of the system trusts.
 */
final class ExportFeatureReader
{
    /**
     * Permission codes that permit seeing PII, mirroring the pre-TASK-127
     * export endpoints so delegating them here does not change who sees what.
     */
    public const PII_PERMISSIONS = [
        'user.view.pii', 'organization.view.pii', 'title.view_owner', 'party.view',
    ];

    /** Sentinel written over a redacted PII value, as used by the legacy routes. */
    public const REDACTED = '[REDACTED]';

    public function __construct(private readonly PDO $pdo)
    {
    }

    /**
     * @param int $targetSrid numeric SRID of the requested target CRS, used to
     *        short-circuit the transform when it is already the storage CRS
     * @param string $crsSpec the registry code (e.g. `EPSG:3857`) actually handed
     *        to ST_Transform: PostGIS parses a bare number as a PROJ string rather
     *        than an EPSG code, so the code is what must be interpolated
     * @return array{
     *     rows: list<array<string,mixed>>,
     *     field_names: list<string>,
     *     layer_name: string,
     *     pii_included: bool,
     *     redacted_field_count: int
     * }
     */
    public function read(
        ExportScope $scope,
        int $targetSrid,
        string $crsSpec,
        bool $userCanViewPii,
        bool $withWkt,
    ): array {
        $where = ['f.layer_id = :lid', 'f.deleted_at IS NULL'];
        $params = [':lid' => $scope->layerId];

        if ($scope->featureIds !== []) {
            $placeholders = [];
            foreach ($scope->featureIds as $index => $id) {
                $key = ':fid' . $index;
                $placeholders[] = $key;
                $params[$key] = $id;
            }
            $where[] = 'f.id IN (' . implode(', ', $placeholders) . ')';
        }

        if ($scope->status !== null) {
            $where[] = 'f.status = :status';
            $params[':status'] = $scope->status;
        }

        if ($scope->bbox !== null) {
            // The scope box is validated in EPSG:4326, which is the storage CRS
            // of gis_features.geom, so the index-friendly && needs no transform.
            $where[] = 'f.geom && ST_MakeEnvelope(:minx, :miny, :maxx, :maxy, 4326)';
            $params[':minx'] = $scope->bbox[0];
            $params[':miny'] = $scope->bbox[1];
            $params[':maxx'] = $scope->bbox[2];
            $params[':maxy'] = $scope->bbox[3];
        }

        $geometry = $targetSrid === 4326
            ? 'f.geom'
            : 'ST_Transform(f.geom, :crs)';
        if ($targetSrid !== 4326) {
            $params[':crs'] = $crsSpec;
        }

        $columns = 'f.id, f.status, f.psgc_barangay, f.provenance, f.version, '
            . 'f.created_at, f.updated_at, f.attributes, '
            . "ST_AsGeoJSON({$geometry}) AS geometry";
        if ($withWkt) {
            $columns .= ", ST_AsText({$geometry}) AS geometry_wkt";
        }

        $sortColumn = in_array($scope->sort, ExportScope::SORTABLE, true) ? $scope->sort : 'created_at';
        $direction = $scope->dir === 'ASC' ? 'ASC' : 'DESC';

        $sql = sprintf(
            'SELECT %s FROM app.gis_features f WHERE %s ORDER BY f.%s %s',
            $columns,
            implode(' AND ', $where),
            $sortColumn,
            $direction
        );

        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

        $layerFields = $this->layerFields($scope->layerId);
        $piiFields = $userCanViewPii
            ? []
            : array_values(array_map(
                static fn (array $f): string => (string) $f['field_name'],
                array_filter($layerFields, static fn (array $f): bool => (bool) $f['is_pii'])
            ));

        foreach ($rows as $index => $row) {
            $rows[$index]['attributes'] = $this->decodeAttributes($row['attributes'] ?? null);
            if ($piiFields !== []) {
                $rows[$index]['attributes'] = $this->redact($rows[$index]['attributes'], $piiFields);
            }
            $rows[$index]['geometry'] = $this->decodeGeometry($row['geometry'] ?? null);
        }

        return [
            'rows'                 => $rows,
            'field_names'          => array_values(array_map(
                static fn (array $f): string => (string) $f['field_name'],
                $layerFields
            )),
            'layer_name'           => $this->layerName($scope->layerId),
            'pii_included'         => $userCanViewPii,
            'redacted_field_count' => count($piiFields),
        ];
    }

    /**
     * Does this user hold any permission that permits reading PII?
     */
    public function canViewPii(int $userId): bool
    {
        $placeholders = [];
        $params = [':uid' => $userId];
        foreach (self::PII_PERMISSIONS as $index => $code) {
            $key = ':perm' . $index;
            $placeholders[] = $key;
            $params[$key] = $code;
        }

        $stmt = $this->pdo->prepare(
            'SELECT 1 FROM app.permissions p
             JOIN app.role_permissions rp ON p.id = rp.permission_id
             JOIN app.user_roles ur ON rp.role_id = ur.role_id
             WHERE ur.user_id = :uid AND p.code IN (' . implode(', ', $placeholders) . ')
             LIMIT 1'
        );
        $stmt->execute($params);

        return (bool) $stmt->fetchColumn();
    }

    /**
     * The display name recorded in the provenance block. Only `user_id` is on
     * the request, so the name is looked up here rather than guessed from the id.
     */
    public function usernameFor(int $userId): string
    {
        $stmt = $this->pdo->prepare(
            'SELECT COALESCE(NULLIF(full_name, \'\'), username, \'user#\' || id) FROM app.users WHERE id = :uid'
        );
        $stmt->execute([':uid' => $userId]);
        $name = $stmt->fetchColumn();

        return $name === false ? 'user#' . $userId : (string) $name;
    }

    /**
     * Count the rows a scope selects without reading them. Used to refuse an
     * oversized export before any work is done.
     */
    public function countRows(ExportScope $scope): int
    {
        $where = ['f.layer_id = :lid', 'f.deleted_at IS NULL'];
        $params = [':lid' => $scope->layerId];

        if ($scope->featureIds !== []) {
            $placeholders = [];
            foreach ($scope->featureIds as $index => $id) {
                $key = ':fid' . $index;
                $placeholders[] = $key;
                $params[$key] = $id;
            }
            $where[] = 'f.id IN (' . implode(', ', $placeholders) . ')';
        }
        if ($scope->status !== null) {
            $where[] = 'f.status = :status';
            $params[':status'] = $scope->status;
        }
        if ($scope->bbox !== null) {
            $where[] = 'f.geom && ST_MakeEnvelope(:minx, :miny, :maxx, :maxy, 4326)';
            $params[':minx'] = $scope->bbox[0];
            $params[':miny'] = $scope->bbox[1];
            $params[':maxx'] = $scope->bbox[2];
            $params[':maxy'] = $scope->bbox[3];
        }

        $stmt = $this->pdo->prepare(
            'SELECT count(*) FROM app.gis_features f WHERE ' . implode(' AND ', $where)
        );
        $stmt->execute($params);

        return (int) $stmt->fetchColumn();
    }

    /**
     * @return list<array{field_name:string, is_pii:bool}>
     */
    private function layerFields(int $layerId): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT field_name, is_pii FROM app.gis_layer_fields
             WHERE layer_id = :lid AND deleted_at IS NULL
             ORDER BY sort_order ASC, id ASC'
        );
        $stmt->execute([':lid' => $layerId]);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

        return array_map(
            static fn (array $r): array => [
                'field_name' => (string) $r['field_name'],
                'is_pii'     => (bool) $r['is_pii'],
            ],
            $rows
        );
    }

    private function layerName(int $layerId): string
    {
        $stmt = $this->pdo->prepare('SELECT name FROM app.gis_layers WHERE id = :lid');
        $stmt->execute([':lid' => $layerId]);
        $name = $stmt->fetchColumn();

        if ($name === false) {
            throw new ApiError('LAYER_NOT_FOUND', "Layer {$layerId} does not exist.", 404);
        }

        return (string) $name;
    }

    /**
     * @return array<string,mixed>
     */
    private function decodeAttributes(mixed $raw): array
    {
        if (is_array($raw)) {
            return $raw;
        }
        if (!is_string($raw) || $raw === '') {
            return [];
        }
        $decoded = json_decode($raw, true);

        return is_array($decoded) ? $decoded : [];
    }

    /**
     * @return array<string,mixed>|null
     */
    private function decodeGeometry(mixed $raw): ?array
    {
        if (is_array($raw)) {
            return $raw;
        }
        if (!is_string($raw) || $raw === '') {
            return null;
        }
        $decoded = json_decode($raw, true);

        return is_array($decoded) ? $decoded : null;
    }

    /**
     * @param array<string,mixed> $attributes
     * @param list<string> $piiFields
     * @return array<string,mixed>
     */
    private function redact(array $attributes, array $piiFields): array
    {
        foreach ($piiFields as $field) {
            $attributes[$field] = self::REDACTED;
        }

        return $attributes;
    }
}
