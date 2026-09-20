<?php
declare(strict_types=1);

namespace App\GIS\Http;

use App\Core\Error\ApiError;
use App\Core\Http\Response\Envelope;
use App\RBAC\FeatureScopeResolver;
use PDO;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

class GisFeatureController
{
    private PDO $pdo;
    private App\Audit\AuditWriter $audit;

    public function __construct(PDO $pdo, App\Audit\AuditWriter $audit)
    {
        $this->pdo = $pdo;
        $this->audit = $audit;
    }

    // ── helpers ──────────────────────────────────────────────────────────────

    private function resolveLayerId(Request $request, array $args): int
    {
        $lid = isset($args['layer_id'])
            ? (int) $args['layer_id']
            : (int) ($request->getQueryParam('layer_id') ?? 0);

        if ($lid <= 0) {
            throw new ApiError('VALIDATION_FAILED', 'layer_id is required', 400);
        }
        return $lid;
    }

    private function resolveUser(Request $request, int $layerId): array
    {
        $userId = (int) ($request->getAttribute('user_id') ?: 0);
        if ($userId <= 0) {
            throw new ApiError('UNAUTHORIZED', 'Not authenticated', 401);
        }

        $capsule = $request->getAttribute('feature_scope_resolver');
        if (!$capsule instanceof FeatureScopeResolver) {
            throw new ApiError('INTERNAL_ERROR', 'Feature scope resolver missing from container', 500);
        }

        $caps = $capsule->getLayerCapabilities($userId, $layerId);
        if (!$caps['can_view']) {
            throw new ApiError('FORBIDDEN', 'No permission to view features in this layer', 403);
        }

        return ['uid' => $userId, 'caps' => $caps];
    }

    private function setUserInSession(int $uid): void
    {
        $this->pdo->exec("SET LOCAL app.current_user_id = " . (int) $uid);
    }

    private function parseBbox(?string $bbox): ?array
    {
        if ($bbox === null || $bbox === '') {
            return null;
        }
        $parts = array_map('trim', explode(',', $bbox));
        if (count($parts) !== 4) {
            throw new ApiError('VALIDATION_FAILED', 'bbox must be minx,miny,maxx,maxy', 400);
        }
        $nums = array_map(fn($v) => (float) $v, $parts);
        if ($nums[0] >= $nums[2] || $nums[1] >= $nums[3]) {
            throw new ApiError('VALIDATION_FAILED', 'bbox must have minx < maxx and miny < maxy', 400);
        }
        return $nums;
    }

    // ── READ ─────────────────────────────────────────────────────────────────

    /**
     * GET /layers/{layer_id}/features?bbox=-122,37,-121,38&limit=1000&offset=0&sort=created_at&dir=DESC
     */
    public function list(Request $request, Response $response, array $args): Response
    {
        $lid   = $this->resolveLayerId($request, $args);
        $info  = $this->resolveUser($request, $lid);
        $this->setUserInSession($info['uid']);

        $q      = $request->getQueryParams();
        $limit  = min(max((int) ($q['limit'] ?? 1000), 1), 10000);
        $offset = (int) ($q['offset'] ?? 0);
        $sort   = $q['sort'] ?? 'created_at';
        $dir    = strtoupper($q['dir'] ?? 'DESC') === 'DESC' ? 'DESC' : 'ASC';
        $status = $q['status'] ?? null;

        $allowedSort = ['id', 'created_at', 'updated_at', 'status', 'psgc_barangay', 'provenance'];
        $sortCol     = in_array($sort, $allowedSort, true) ? $sort : 'created_at';

        // Attribute filters: attribute.<key>=value → exact match on attributes->>key
        $attrFilters = [];
        foreach ($q as $key => $val) {
            if (str_starts_with($key, 'attribute.')) {
                $ak = substr($key, strlen('attribute.'));
                if ($ak !== '' && $val !== '') {
                    $attrFilters[$ak] = $val;
                }
            }
        }

        // Field projection (comma-separated; '-' = only id + geometry)
        $selectFields = $this->parseFields($q['fields'] ?? null);

        $where  = ['f.layer_id = :lid', 'f.deleted_at IS NULL'];
        $params = [':lid' => $lid];

        if ($status !== null && in_array($status, ['ACTIVE', 'PENDING', 'REJECTED', 'ARCHIVED'], true)) {
            $where[] = 'f.status = :status';
            $params[':status'] = $status;
        }

        $bbox = $this->parseBbox($q['bbox'] ?? null);
        if ($bbox !== null) {
            $where[] = 'f.geom && ST_MakeEnvelope(:minx, :miny, :maxx, :maxy, 4326)';
            $params[':minx'] = $bbox[0];
            $params[':miny'] = $bbox[1];
            $params[':maxx'] = $bbox[2];
            $params[':maxy'] = $bbox[3];
        }

        foreach ($attrFilters as $ak => $av) {
            $where[] = "f.attributes->> :ak_{$ak} = :av_{$ak}";
            $params["ak_{$ak}"] = $ak;
            $params["av_{$ak}"] = $av;
        }

        $whereSql = 'WHERE ' . implode(' AND ', $where);

        // total count
        $countSql = "SELECT COUNT(*) FROM app.gis_features f {$whereSql}";
        $cntStmt  = $this->pdo->prepare($countSql);
        $cntStmt->execute($params);
        $total = (int) $cntStmt->fetchColumn();

        // data
        $sel = 'f.id, f.status, f.psgc_barangay, f.provenance, f.version, f.created_by, f.created_at, f.updated_by, f.updated_at, f.attributes, ST_AsGeoJSON(f.geom)::json AS geometry';
        $dataSql = "SELECT {$sel} FROM app.gis_features f {$whereSql} ORDER BY f.{$sortCol} {$dir} LIMIT :lim OFFSET :off";
        $dataStmt = $this->pdo->prepare($dataSql);
        foreach ($params as $k => $v) {
            $dataStmt->bindValue($k, $v);
        }
        $dataStmt->bindValue(':lim', $limit, PDO::PARAM_INT);
        $dataStmt->bindValue(':off', $offset, PDO::PARAM_INT);
        $dataStmt->execute();
        $rows = $dataStmt->fetchAll(PDO::FETCH_ASSOC);

        $rows = array_map(fn($r) => $this->projectFeature($r, $selectFields), $rows);

        return Envelope::success($response, [
            'data'     => $rows,
            'total'    => $total,
            'limit'    => $limit,
            'offset'   => $offset,
            'sort'     => $sortCol,
            'dir'      => $dir,
            'layer_id' => $lid,
        ]);
    }

    /**
     * GET /layers/{layer_id}/features/{id}
     */
    public function getFeature(Request $request, Response $response, array $args): Response
    {
        $lid   = $this->resolveLayerId($request, $args);
        $info  = $this->resolveUser($request, $lid);
        $this->setUserInSession($info['uid']);

        $fid = $args['id'] ?? '';
        if (!is_string($fid) || $fid === '') {
            throw new ApiError('VALIDATION_FAILED', 'Invalid feature id', 400);
        }

        $sql = "SELECT f.id, f.status, f.psgc_barangay, f.provenance, f.version, f.created_by, f.created_at, f.updated_by, f.updated_at, f.attributes, ST_AsGeoJSON(f.geom)::json AS geometry FROM app.gis_features f WHERE f.id = :fid AND f.layer_id = :lid AND f.deleted_at IS NULL";
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute([':fid' => $fid, ':lid' => $lid]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        if ($row === false) {
            throw new ApiError('NOT_FOUND', 'Feature not found', 404);
        }

        return Envelope::success($response, $this->formatFeature($row));
    }

    /**
     * GET /layers/{layer_id}/features.geojson
     * Full GeoJSON FeatureCollection (bbox-filtered optional).
     */
    public function geojson(Request $request, Response $response, array $args): Response
    {
        $lid   = $this->resolveLayerId($request, $args);
        $info  = $this->resolveUser($request, $lid);
        $this->setUserInSession($info['uid']);

        $q      = $request->getQueryParams();
        $bbox   = $this->parseBbox($q['bbox'] ?? null);
        $status = $q['status'] ?? null;

        $where  = ['f.layer_id = :lid', 'f.deleted_at IS NULL'];
        $params = [':lid' => $lid];

        if ($status !== null && in_array($status, ['ACTIVE', 'PENDING', 'REJECTED', 'ARCHIVED'], true)) {
            $where[] = 'f.status = :status';
            $params[':status'] = $status;
        }

        if ($bbox !== null) {
            $where[] = 'f.geom && ST_MakeEnvelope(:minx, :miny, :maxx, :maxy, 4326)';
            $params[':minx'] = $bbox[0];
            $params[':miny'] = $bbox[1];
            $params[':maxx'] = $bbox[2];
            $params[':maxy'] = $bbox[3];
        }

        $whereSql = 'WHERE ' . implode(' AND ', $where);

        $sql = "SELECT f.id, f.status, f.psgc_barangay, f.provenance, f.attributes, ST_AsGeoJSON(f.geom)::json AS geometry FROM app.gis_features f {$whereSql}";
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

        $features = array_map(function ($r) {
            return [
                'type'       => 'Feature',
                'id'         => $r['id'],
                'geometry'   => $r['geometry'],
                'properties' => $this->stripGeometry($r),
            ];
        }, $rows);

        $payload = ['type' => 'FeatureCollection', 'features' => $features];

        $response->getBody()->write(json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
        return $response
            ->withHeader('Content-Type', 'application/geo+json')
            ->withHeader('Content-Disposition', 'attachment; filename="layer_' . $lid . '_features.geojson"')
            ->withStatus(200);
    }

    // ── WRITE ────────────────────────────────────────────────────────────────

    /**
     * POST /layers/{layer_id}/features
     * Body: { geometry: GeoJSON, attributes: {}, psgc_barangay?, provenance?, status? }
     */
    public function create(Request $request, Response $response, array $args): Response
    {
        $lid   = $this->resolveLayerId($request, $args);
        $info  = $this->resolveUser($request, $lid);
        if (!$info['caps']['can_create']) {
            throw new ApiError('FORBIDDEN', 'No permission to create features in this layer', 403);
        }
        $this->setUserInSession($info['uid']);

        $body = $request->getParsedBody();
        if (!is_array($body)) {
            throw new ApiError('VALIDATION_FAILED', 'Request body must be a JSON object', 400);
        }

        $this->validateCreate($body);

        $geom   = $body['geometry'];
        $attrs  = $body['attributes'] ?? [];
        $psgc   = $body['psgc_barangay'] ?? null;
        $prov   = $body['provenance'] ?? 'MANUAL_DRAWING';
        $status = $body['status'] ?? 'ACTIVE';
        $orgId  = $body['org_id'] ?? null;

        $geomJson = json_encode($geom);
        if ($geomJson === false) {
            throw new ApiError('VALIDATION_FAILED', 'geometry must be valid JSON', 400);
        }

        // Validate with PostGIS
        $checkStmt = $this->pdo->prepare("SELECT ST_IsValid(ST_GeomFromGeoJSON(:gj)) AS ok");
        $checkStmt->execute([':gj' => $geomJson]);
        if (!(bool) $checkStmt->fetchColumn()) {
            throw new ApiError('VALIDATION_FAILED', 'geometry is not valid per ST_IsValid', 400);
        }

        $sql = "INSERT INTO app.gis_features (id, layer_id, attributes, status, psgc_barangay, provenance, org_id, created_by, geom) VALUES (gen_random_uuid(), :lid, :attrs, :status, :psgc, :prov, :org, :uid, ST_GeomFromGeoJSON(:gj)) RETURNING id, version";
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute([
            ':lid'   => $lid,
            ':attrs' => json_encode($attrs),
            ':status' => $status,
            ':psgc'  => $psgc,
            ':prov'  => $prov,
            ':org'   => $orgId,
            ':uid'   => $info['uid'],
            ':gj'    => $geomJson,
        ]);

        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($row === false) {
            throw new ApiError('FORBIDDEN', 'Feature creation denied by row-level security', 403);
        }

        // Fetch created feature back
        $fid = $row['id'];
        $ver = (int) $row['version'];

        // Re-use getFeature to return full shape
        $getRequest = $request->withAttribute('user_id', $info['uid']);
        // We need to satisfy getFeature's resolveUser call — hack: pass capsule again
        // Instead, just format directly:
        $fetchSql = "SELECT f.id, f.status, f.psgc_barangay, f.provenance, f.version, f.created_by, f.created_at, f.updated_by, f.updated_at, f.attributes, ST_AsGeoJSON(f.geom)::json AS geometry FROM app.gis_features f WHERE f.id = :fid AND f.layer_id = :lid AND f.deleted_at IS NULL";
        $fetchStmt = $this->pdo->prepare($fetchSql);
        $fetchStmt->execute([':fid' => $fid, ':lid' => $lid]);
        $fetched = $fetchStmt->fetch(PDO::FETCH_ASSOC);

        // Audit row (TASK-057)
        $this->audit->writeFromSession('INSERT', 'app.gis_features', $fid, null, $this->stripGeometryForAudit($fetched), null, null, 'Feature created via API');

        return Envelope::success($response, $this->formatFeature($fetched), 201);
    }

    /**
     * PATCH /layers/{layer_id}/features/{id}
     * Partial update.
     */
    public function update(Request $request, Response $response, array $args): Response
    {
        $lid   = $this->resolveLayerId($request, $args);
        $info  = $this->resolveUser($request, $lid);
        if (!$info['caps']['can_update']) {
            throw new ApiError('FORBIDDEN', 'No permission to update features in this layer', 403);
        }
        $this->setUserInSession($info['uid']);

        $fid = $args['id'] ?? '';
        if (!is_string($fid) || $fid === '') {
            throw new ApiError('VALIDATION_FAILED', 'Invalid feature id', 400);
        }

        // Verify existence + lock row + read current version
        $existsStmt = $this->pdo->prepare("SELECT id, version FROM app.gis_features WHERE id = :fid AND layer_id = :lid AND deleted_at IS NULL FOR UPDATE");
        $existsStmt->execute([':fid' => $fid, ':lid' => $lid]);
        $row = $existsStmt->fetch(PDO::FETCH_ASSOC);
        if ($row === false) {
            throw new ApiError('NOT_FOUND', 'Feature not found', 404);
        }
        $currentVersion = (int) $row['version'];

        // If-Match concurrency check (TASK-057)
        $ifMatch = $request->getHeaderLine('If-Match');
        if ($ifMatch === '') {
            throw new ApiError('PRECONDITION_REQUIRED', 'An If-Match header with the current version is required for updates.', 428);
        }
        if ((int) $ifMatch !== $currentVersion) {
            throw new ApiError('VERSION_CONFLICT', 'This feature was modified by another user.', 409, ['current_version' => $currentVersion]);
        }

        $body = $request->getParsedBody();
        if (!is_array($body)) {
            throw new ApiError('VALIDATION_FAILED', 'Request body must be a JSON object', 400);
        }
        $this->validateUpdate($body);

        $sets   = [];
        $params = [];

        if (isset($body['geometry'])) {
            $geomJson = json_encode($body['geometry']);
            if ($geomJson === false) {
                throw new ApiError('VALIDATION_FAILED', 'geometry must be valid JSON', 400);
            }
            $vStmt = $this->pdo->prepare("SELECT ST_IsValid(ST_GeomFromGeoJSON(:gj)) AS ok");
            $vStmt->execute([':gj' => $geomJson]);
            if (!(bool) $vStmt->fetchColumn()) {
                throw new ApiError('GEOMETRY_INVALID', 'geometry is not valid per ST_IsValid', 400, ['reason' => 'ST_IsValid returned false']);
            }
            // ST_IsSimple check (TASK-057)
            $simpleStmt = $this->pdo->prepare("SELECT ST_IsSimple(ST_GeomFromGeoJSON(:gj)) AS ok");
            $simpleStmt->execute([':gj' => $geomJson]);
            if (!(bool) $simpleStmt->fetchColumn()) {
                throw new ApiError('GEOMETRY_NOT_SIMPLE', 'geometry is not simple per ST_IsSimple (self-intersection or other validity issue)', 400, ['reason' => 'ST_IsSimple returned false']);
            }
            $sets[] = 'geom = ST_GeomFromGeoJSON(:gj)';
            $params[':gj'] = $geomJson;
        }

        if (isset($body['attributes']) && !empty($body['attributes'])) {
            $sets[] = 'attributes = :attrs';
            $params[':attrs'] = json_encode($body['attributes']);
        }
        if (array_key_exists('status', $body)) {
            $sets[] = 'status = :status';
            $params[':status'] = $body['status'];
        }
        if (array_key_exists('psgc_barangay', $body)) {
            $sets[] = 'psgc_barangay = :psgc';
            $params[':psgc'] = $body['psgc_barangay'];
        }
        if (array_key_exists('provenance', $body)) {
            $sets[] = 'provenance = :prov';
            $params[':prov'] = $body['provenance'];
        }
        if (array_key_exists('org_id', $body)) {
            $sets[] = 'org_id = :org';
            $params[':org'] = $body['org_id'];
        }

        if (empty($sets)) {
            // No changes — return current state
            return $this->getFeature($request, $response, $args);
        }

        $sets[] = 'version = version + 1';
        $params[':fid'] = $fid;
        $params[':lid'] = $lid;

        $sql = "UPDATE app.gis_features SET " . implode(', ', $sets) . " WHERE id = :fid AND layer_id = :lid RETURNING version";
        $stmt = $this->pdo->prepare($sql);
        foreach ($params as $k => $v) {
            $stmt->bindValue($k, $v);
        }
        $stmt->execute();
        $newVer = (int) $stmt->fetchColumn();

        // Read the updated feature for response (avoids getFeature's If-Match re-check)
        $updatedRow = $this->readFeatureSnapshot($fid, $lid);
        if ($updatedRow === false) {
            throw new ApiError('NOT_FOUND', 'Feature not found after update', 404);
        }
        $updatedFeature = $this->formatFeature($updatedRow);

        // Audit row (TASK-057): snapshot pre-change state before mutation
        $oldSnapshot = $this->buildPreChangeSnapshot($fid, $lid, $currentVersion);
        $this->audit->writeFromSession('UPDATE', 'app.gis_features', $fid, $oldSnapshot, $this->stripForAudit($updatedFeature), null, null, 'Feature updated via API');

        return Envelope::success($response, $updatedFeature);
    }

    /**
     * DELETE /layers/{layer_id}/features/{id}
     */
    public function delete(Request $request, Response $response, array $args): Response
    {
        $lid   = $this->resolveLayerId($request, $args);
        $info  = $this->resolveUser($request, $lid);
        if (!$info['caps']['can_delete']) {
            throw new ApiError('FORBIDDEN', 'No permission to delete features in this layer', 403);
        }
        $this->setUserInSession($info['uid']);

        $fid = $args['id'] ?? '';
        if (!is_string($fid) || $fid === '') {
            throw new ApiError('VALIDATION_FAILED', 'Invalid feature id', 400);
        }

        $sql = "UPDATE app.gis_features SET deleted_at = CURRENT_TIMESTAMP, version = version + 1 WHERE id = :fid AND layer_id = :lid AND deleted_at IS NULL RETURNING version";
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute([':fid' => $fid, ':lid' => $lid]);
        $affected = $stmt->rowCount();

        if ($affected === 0) {
            throw new ApiError('NOT_FOUND', 'Feature not found', 404);
        }

        // Audit row (TASK-057)
        $oldSnapshot = $this->buildPreChangeSnapshot($fid, $lid, 0);
        $this->audit->writeFromSession('DELETE', 'app.gis_features', $fid, $oldSnapshot, null, null, null, 'Feature deleted via API');

        return Envelope::success($response, ['id' => $fid, 'deleted' => true]);
    }

    /**
     * GET /layers/{layer_id}/mvt/{z}/{x}/{y}.mvt
     * Vector tile endpoint using ST_AsMVT.
     */
    public function mvt(Request $request, Response $response, array $args): Response
    {
        $lid   = $this->resolveLayerId($request, $args);
        $info  = $this->resolveUser($request, $lid);
        $this->setUserInSession($info['uid']);

        $z = (int) ($args['z'] ?? 0);
        $x = (int) ($args['x'] ?? 0);
        $y = (int) ($args['y'] ?? 0);

        if ($z < 0 || $z > 30 || $x < 0 || $y < 0) {
            throw new ApiError('VALIDATION_FAILED', 'Invalid tile coordinates', 400);
        }

        // MV tile query using ST_TileEnvelope + ST_AsMVTGeom + ST_AsMVT (PostGIS 3.4).
        // Layer ID is bound via PDO parameter; tile coords are safe ints via sprintf.
        $sql = sprintf(
            "SELECT ST_AsMVT(sub, 'layer_%d', 4096, 'geom', 'fid') FROM (" .
            "SELECT id AS fid, ST_AsMVTGeom(geom, ST_TileEnvelope(%d, %d, %d), 4096, 0) AS geom, attributes " .
            "FROM app.gis_features " .
            "WHERE layer_id = :lid AND deleted_at IS NULL AND geom && ST_TileEnvelope(%d, %d, %d)" .
            ") AS sub",
            $lid,
            $z, $x, $y,
            $z, $x, $y
        );

        $stmt = $this->pdo->prepare($sql);
        $stmt->bindValue(':lid', $lid, PDO::PARAM_INT);
        $stmt->execute();
        $mvt = $stmt->fetchColumn();

        if ($mvt === false || $mvt === null) {
            $mvt = '';
        }

        return $response
            ->withHeader('Content-Type', 'application/vnd.mapbox-vector-tile')
            ->withHeader('Cache-Control', 'public, max-age=300')
            ->withStatus(200)
            ->getBody()->write($mvt);
    }

    // ── validation ───────────────────────────────────────────────────────────

    private function validateCreate(array $body): void
    {
        if (!isset($body['geometry']) || !is_array($body['geometry'])) {
            throw new ApiError('VALIDATION_FAILED', 'geometry (GeoJSON object) is required', 400);
        }

        $geom = $body['geometry'];
        if (!isset($geom['type']) || !is_string($geom['type'])) {
            throw new ApiError('VALIDATION_FAILED', 'geometry.type is required', 400);
        }

        $allowedTypes = ['Point', 'MultiPoint', 'LineString', 'MultiLineString', 'Polygon', 'MultiPolygon', 'GeometryCollection'];
        if (!in_array($geom['type'], $allowedTypes, true)) {
            throw new ApiError('VALIDATION_FAILED', 'Unsupported geometry type: ' . $geom['type'], 400);
        }

        if (!isset($geom['coordinates']) || !is_array($geom['coordinates'])) {
            throw new ApiError('VALIDATION_FAILED', 'geometry.coordinates is required', 400);
        }

        // Reject coordinates that are clearly not geography (longitude must be [-180,180], latitude [-90,90])
        if (!$this->coordinatesLookGeographic($geom['coordinates'])) {
            throw new ApiError('VALIDATION_FAILED', 'geometry coordinates do not look like valid geography (longitude must be in [-180,180], latitude in [-90,90])', 400);
        }

        if (isset($body['attributes']) && !is_array($body['attributes'])) {
            throw new ApiError('VALIDATION_FAILED', 'attributes must be a JSON object', 400);
        }

        if (isset($body['status']) && !in_array($body['status'], ['ACTIVE', 'PENDING', 'REJECTED', 'ARCHIVED'], true)) {
            throw new ApiError('VALIDATION_FAILED', 'Invalid status value', 400);
        }
    }

    private function validateUpdate(array $body): void
    {
        if (!empty($body)) {
            $allowed = ['geometry', 'attributes', 'status', 'psgc_barangay', 'provenance'];
            $unknown = array_diff_key($body, array_fill_keys($allowed, true));
            if (!empty($unknown)) {
                throw new ApiError('VALIDATION_FAILED', 'Unexpected fields in update payload', 400);
            }

            if (isset($body['status']) && !in_array($body['status'], ['ACTIVE', 'PENDING', 'REJECTED', 'ARCHIVED'], true)) {
                throw new ApiError('VALIDATION_FAILED', 'Invalid status value', 400);
            }
        }
    }

    // ── projection helpers ───────────────────────────────────────────────────

    private function parseFields(?string $fields): ?array
    {
        if ($fields === null || $fields === '') {
            return null; // no projection — return all
        }
        if ($fields === '-') {
            return ['id' => true, 'geometry' => true];
        }
        $parts = array_map('trim', explode(',', $fields));
        $allowed = ['id', 'status', 'psgc_barangay', 'provenance', 'version', 'created_by', 'created_at', 'updated_by', 'updated_at', 'attributes', 'geometry'];
        $result  = [];
        foreach ($parts as $p) {
            if ($p !== '' && in_array($p, $allowed, true)) {
                $result[$p] = true;
            }
        }
        return $result ?: null;
    }

    private function projectFeature(array $row, ?array $fields): array
    {
        if ($fields === null) {
            return $this->formatFeature($row);
        }
        $out = [];
        foreach ($fields as $col => $_) {
            if (array_key_exists($col, $row)) {
                $out[$col] = $col === 'geometry' ? $row['geometry'] : $row[$col];
            }
        }
        return $out;
    }

    private function formatFeature(array $row): array
    {
        return [
            'id'             => $row['id'],
            'status'         => $row['status'],
            'psgc_barangay'  => $row['psgc_barangay'],
            'provenance'     => $row['provenance'],
            'version'        => (int) $row['version'],
            'created_by'     => (int) $row['created_by'],
            'created_at'     => $row['created_at'],
            'updated_by'     => (int) $row['updated_by'],
            'updated_at'     => $row['updated_at'],
            'attributes'     => $row['attributes'],
            'geometry'       => $row['geometry'],
        ];
    }

    private function stripGeometry(array $row): array
    {
        $out = $row;
        unset($out['geometry']);
        return $out;
    }

    // ── TASK-057 helpers ──────────────────────────────────────────────────────

    /**
     * Read a single feature row for audit/snapshot purposes.
     * Does not enforce If-Match (caller already holds the lock).
     */
    private function readFeatureSnapshot(string $fid, int $lid): ?array
    {
        $sql = "SELECT f.id, f.status, f.psgc_barangay, f.provenance, f.version, f.created_by, f.created_at, f.updated_by, f.updated_at, f.attributes, ST_AsGeoJSON(f.geom)::json AS geometry FROM app.gis_features f WHERE f.id = :fid AND f.layer_id = :lid AND f.deleted_at IS NULL";
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute([':fid' => $fid, ':lid' => $lid]);
        return $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
    }

    /**
     * Build a pre-change snapshot for audit. Called after the row is locked
     * and the current version is known but before the UPDATE is issued.
     */
    private function buildPreChangeSnapshot(string $fid, int $lid, int $currentVersion): array
    {
        $row = $this->readFeatureSnapshot($fid, $lid);
        if ($row === null) {
            return ['id' => $fid, 'version' => $currentVersion, 'deleted_at' => null];
        }
        $snap = $this->stripGeometry($row);
        $snap['version'] = $currentVersion;
        return $snap;
    }

    /**
     * Strip geometry + internal columns from a feature array for audit payload.
     */
    private function stripForAudit(array $feature): array
    {
        $out = $this->stripGeometry($feature);
        unset($out['id'], $out['created_by'], $out['updated_by'], $out['created_at'], $out['updated_at']);
        return $out;
    }

    /**
     * Strip geometry from a raw DB row fetched for audit INSERT snapshot.
     */
    private function stripGeometryForAudit(?array $row): ?array
    {
        if ($row === null) {
            return null;
        }
        return $this->stripGeometry($row);
    }

    /**
     * Heuristic: do the coordinates in a GeoJSON geometry look like WGS84
     * geography? Used to reject obviously-wrong inputs before hitting PostGIS.
     */
    private function coordinatesLookGeographic(array $coords, int $depth = 0): bool
    {
        // Leaf: a [longitude, latitude] pair
        if (isset($coords[0]) && is_array($coords[0]) === false) {
            if (count($coords) < 2) {
                return false;
            }
            $x = (float) $coords[0];
            $y = (float) $coords[1];
            return $x >= -180 && $x <= 180 && $y >= -90 && $y <= 90;
        }

        // Otherwise recurse into every element
        foreach ($coords as $c) {
            if (is_array($c) && !$this->coordinatesLookGeographic($c, $depth + 1)) {
                return false;
            }
        }
        return true;
    }
}
