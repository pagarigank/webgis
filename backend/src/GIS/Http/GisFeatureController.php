<?php
declare(strict_types=1);

namespace App\GIS\Http;

use App\Core\Error\ApiError;
use App\Core\Http\Response\Envelope;
use App\Audit\AuditWriter;
use App\RBAC\FeatureScopeResolver;
use PDO;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

class GisFeatureController
{
    private PDO $pdo;
    private AuditWriter $audit;

    public function __construct(PDO $pdo, AuditWriter $audit)
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

        $canViewPii = $this->canViewPii($info['uid']);
        $piiFields  = $canViewPii ? [] : $this->getLayerPiiFields($lid);

        $features = array_map(function ($r) use ($piiFields) {
            $r = $this->decodePayloadFields($r);
            if (!empty($piiFields) && isset($r['attributes']) && is_array($r['attributes'])) {
                $r['attributes'] = $this->redactPiiAttributes($r['attributes'], $piiFields);
            }
            return [
                'type'       => 'Feature',
                'id'         => $r['id'],
                'geometry'   => $r['geometry'],
                'properties' => $this->stripGeometry($r),
            ];
        }, $rows);

        $payload = ['type' => 'FeatureCollection', 'features' => $features];

        // Audit the export (TASK-067)
        $this->audit->writeFromSession(
            'EXPORT',
            'app.gis_features',
            (string) $lid,
            null,
            ['format' => 'geojson', 'count' => count($rows), 'bbox' => $bbox],
            $request->getAttribute('request_id'),
            'Layer features exported as GeoJSON'
        );

        $response->getBody()->write(json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
        return $response
            ->withHeader('Content-Type', 'application/geo+json')
            ->withHeader('Content-Disposition', 'attachment; filename="layer_' . $lid . '_features.geojson"')
            ->withStatus(200);
    }

    /**
     * GET /layers/{layer_id}/features.csv (TASK-067)
     */
    public function csv(Request $request, Response $response, array $args): Response
    {
        $lid   = $this->resolveLayerId($request, $args);
        $info  = $this->resolveUser($request, $lid);
        $this->setUserInSession($info['uid']);

        $q      = $request->getQueryParams();
        $bbox   = $this->parseBbox($q['bbox'] ?? null);
        $status = $q['status'] ?? null;
        $sort   = $q['sort'] ?? 'created_at';
        $dir    = strtoupper($q['dir'] ?? 'DESC') === 'DESC' ? 'DESC' : 'ASC';

        $allowedSort = ['id', 'created_at', 'updated_at', 'status', 'psgc_barangay', 'provenance'];
        $sortCol     = in_array($sort, $allowedSort, true) ? $sort : 'created_at';

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

        $sql = "SELECT f.id, f.status, f.psgc_barangay, f.provenance, f.version, f.created_at, f.updated_at, f.attributes, ST_AsGeoJSON(f.geom) AS geometry FROM app.gis_features f {$whereSql} ORDER BY f.{$sortCol} {$dir}";
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

        // Fetch layer fields
        $fieldsStmt = $this->pdo->prepare("
            SELECT field_name, is_pii
            FROM app.gis_layer_fields
            WHERE layer_id = :lid AND deleted_at IS NULL
            ORDER BY sort_order ASC, id ASC
        ");
        $fieldsStmt->execute([':lid' => $lid]);
        $layerFields = $fieldsStmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
        $fieldNames = array_column($layerFields, 'field_name');

        $canViewPii = $this->canViewPii($info['uid']);
        $piiFields = [];
        if (!$canViewPii) {
            foreach ($layerFields as $lf) {
                if (!empty($lf['is_pii'])) {
                    $piiFields[] = $lf['field_name'];
                }
            }
        }

        // Build CSV
        $fp = fopen('php://temp', 'r+');
        $headers = array_merge(['id', 'status', 'psgc_barangay', 'provenance', 'version', 'created_at', 'updated_at'], $fieldNames);
        fputcsv($fp, $headers);

        foreach ($rows as $row) {
            $attrs = json_decode($row['attributes'] ?? '{}', true) ?: [];
            if (!empty($piiFields)) {
                $attrs = $this->redactPiiAttributes($attrs, $piiFields);
            }
            $line = [
                $row['id'],
                $row['status'],
                $row['psgc_barangay'] ?? '',
                $row['provenance'] ?? '',
                $row['version'],
                $row['created_at'],
                $row['updated_at'],
            ];
            foreach ($fieldNames as $fn) {
                $val = $attrs[$fn] ?? '';
                if (is_array($val)) {
                    $val = json_encode($val);
                }
                $line[] = (string) $val;
            }
            fputcsv($fp, $line);
        }

        rewind($fp);
        $csvContent = stream_get_contents($fp);
        fclose($fp);

        // Audit the export (TASK-067)
        $this->audit->writeFromSession(
            'EXPORT',
            'app.gis_features',
            (string) $lid,
            null,
            ['format' => 'csv', 'count' => count($rows), 'bbox' => $bbox],
            $request->getAttribute('request_id'),
            'Layer features exported as CSV'
        );

        $response->getBody()->write((string) $csvContent);
        return $response
            ->withHeader('Content-Type', 'text/csv; charset=UTF-8')
            ->withHeader('Content-Disposition', 'attachment; filename="layer_' . $lid . '_features.csv"')
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
     * POST /layers/{layer_id}/features/bulk-update (TASK-066)
     * Body: { ids: string[], patch: { status?: string, attributes?: array } }
     */
    public function bulkUpdate(Request $request, Response $response, array $args): Response
    {
        $lid   = $this->resolveLayerId($request, $args);
        $info  = $this->resolveUser($request, $lid);
        if (!$info['caps']['can_update']) {
            throw new ApiError('FORBIDDEN', 'No permission to update features in this layer', 403);
        }
        $this->setUserInSession($info['uid']);

        $body = $request->getParsedBody();
        if (!is_array($body) || empty($body['ids']) || !is_array($body['ids'])) {
            throw new ApiError('VALIDATION_FAILED', 'ids array is required', 400);
        }
        $patch = $body['patch'] ?? [];
        if (!is_array($patch) || empty($patch)) {
            throw new ApiError('VALIDATION_FAILED', 'patch object is required', 400);
        }

        $ids = array_values(array_filter($body['ids'], 'is_string'));
        if (empty($ids)) {
            throw new ApiError('VALIDATION_FAILED', 'No valid feature ids provided', 400);
        }

        $updatedCount = 0;
        $updatedIds = [];

        foreach ($ids as $fid) {
            $current = $this->readFeatureSnapshot($fid, $lid);
            if (!$current) {
                continue;
            }
            $currentVer = (int) $current['version'];
            $oldSnapshot = $this->buildPreChangeSnapshot($fid, $lid, $currentVer);

            $sets = ['version = version + 1', 'updated_at = CURRENT_TIMESTAMP', 'updated_by = :uid'];
            $params = [':fid' => $fid, ':lid' => $lid, ':uid' => $info['uid']];

            if (isset($patch['status']) && in_array($patch['status'], ['ACTIVE', 'PENDING', 'REJECTED', 'ARCHIVED'], true)) {
                $sets[] = 'status = :status';
                $params[':status'] = $patch['status'];
            }
            if (isset($patch['attributes']) && is_array($patch['attributes'])) {
                $sets[] = 'attributes = attributes || :patch_attrs::jsonb';
                $params[':patch_attrs'] = json_encode($patch['attributes']);
            }

            $sql = "UPDATE app.gis_features SET " . implode(', ', $sets) . " WHERE id = :fid AND layer_id = :lid AND deleted_at IS NULL RETURNING version";
            $stmt = $this->pdo->prepare($sql);
            $stmt->execute($params);

            $newRow = $this->readFeatureSnapshot($fid, $lid);
            if ($newRow) {
                $this->audit->writeFromSession(
                    'UPDATE',
                    'app.gis_features',
                    $fid,
                    $oldSnapshot,
                    $this->stripForAudit($this->formatFeature($newRow)),
                    $request->getAttribute('request_id'),
                    'Bulk update via API'
                );
                $updatedCount++;
                $updatedIds[] = $fid;
            }
        }

        return Envelope::success($response, [
            'updated_count' => $updatedCount,
            'ids'           => $updatedIds,
        ]);
    }

    /**
     * POST /layers/{layer_id}/features/bulk-delete (TASK-066)
     * Body: { ids: string[], reason?: string }
     */
    public function bulkDelete(Request $request, Response $response, array $args): Response
    {
        $lid   = $this->resolveLayerId($request, $args);
        $info  = $this->resolveUser($request, $lid);
        if (!$info['caps']['can_delete']) {
            throw new ApiError('FORBIDDEN', 'No permission to delete features in this layer', 403);
        }
        $this->setUserInSession($info['uid']);

        $body = $request->getParsedBody();
        if (!is_array($body) || empty($body['ids']) || !is_array($body['ids'])) {
            throw new ApiError('VALIDATION_FAILED', 'ids array is required', 400);
        }

        $ids = array_values(array_filter($body['ids'], 'is_string'));
        if (empty($ids)) {
            throw new ApiError('VALIDATION_FAILED', 'No valid feature ids provided', 400);
        }

        $reason = $body['reason'] ?? 'Bulk delete via API';
        $deletedCount = 0;
        $deletedIds = [];

        foreach ($ids as $fid) {
            $current = $this->readFeatureSnapshot($fid, $lid);
            if (!$current) {
                continue;
            }
            $oldSnapshot = $this->buildPreChangeSnapshot($fid, $lid, (int) $current['version']);

            $sql = "UPDATE app.gis_features SET deleted_at = CURRENT_TIMESTAMP, version = version + 1 WHERE id = :fid AND layer_id = :lid AND deleted_at IS NULL";
            $stmt = $this->pdo->prepare($sql);
            $stmt->execute([':fid' => $fid, ':lid' => $lid]);

            if ($stmt->rowCount() > 0) {
                $this->audit->writeFromSession(
                    'DELETE',
                    'app.gis_features',
                    $fid,
                    $oldSnapshot,
                    null,
                    $request->getAttribute('request_id'),
                    $reason
                );
                $deletedCount++;
                $deletedIds[] = $fid;
            }
        }

        return Envelope::success($response, [
            'deleted_count' => $deletedCount,
            'ids'           => $deletedIds,
        ]);
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

        // MVT tile query using ST_TileEnvelope + ST_AsMVTGeom + ST_AsMVT (PostGIS 3.4).
        // Layer ID is bound via PDO parameter; tile coords are safe ints via sprintf.
        // fid must be an integer column for ST_AsMVT; the feature's real id is a
        // UUID so we derive a stable-per-tile integer via ROW_NUMBER.
        $sql = sprintf(
            "SELECT encode(ST_AsMVT(sub, 'layer_%d', 4096, 'geom', 'fid'), 'base64') FROM (" .
            "SELECT ROW_NUMBER() OVER (ORDER BY id) AS fid, " .
            "ST_AsMVTGeom(ST_Transform(geom, 3857), ST_TileEnvelope(%d, %d, %d), 4096, 0, true) AS geom, attributes " .
            "FROM app.gis_features " .
            "WHERE layer_id = :lid AND deleted_at IS NULL AND ST_Transform(geom, 3857) && ST_TileEnvelope(%d, %d, %d)" .
            ") AS sub",
            $lid,
            $z, $x, $y,
            $z, $x, $y
        );

        $stmt = $this->pdo->prepare($sql);
        $stmt->bindValue(':lid', $lid, PDO::PARAM_INT);
        $stmt->execute();
        $mvt = $stmt->fetchColumn();

        // pdo_pgsql can hand ST_AsMVT (bytea) back as a stream resource; base64
        // encode in SQL avoids the driver quirk entirely.
        $mvt = ($mvt === false || $mvt === null) ? '' : (string) base64_decode((string) $mvt);

        // TASK-055: scope-aware caching. MVT output is filtered by RLS on
        // app.current_user_id (set above via setUserInSession). A user with any
        // data-scopes sees a per-user subset, so a shared/public cache could leak
        // one scope's features to another. Restricted (scoped) requests are
        // private; only unscoped/global requests may use a public cache.
        $scopeIds = $request->getAttribute('scope_ids') ?? [];
        $cacheControl = !empty($scopeIds)
            ? 'private, max-age=300'
            : 'public, max-age=300';

        $response
            ->getBody()
            ->write((string) $mvt);

        return $response
            ->withHeader('Content-Type', 'application/vnd.mapbox-vector-tile')
            ->withHeader('Cache-Control', $cacheControl)
            ->withStatus(200);
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
        $row = $this->decodePayloadFields($row);
        $out = [];
        foreach ($fields as $col => $_) {
            if (array_key_exists($col, $row)) {
                $out[$col] = $row[$col];
            }
        }
        return $out;
    }

    private function formatFeature(array $row): array
    {
        $row = $this->decodePayloadFields($row);
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

    /**
     * PDO returns ST_AsGeoJSON(...)::json and jsonb columns as strings; the
     * API contract (api.md) requires real objects/arrays. Decode geometry and
     * attributes so clients can consume them without re-parsing.
     */
    private function decodePayloadFields(array $row): array
    {
        if (isset($row['geometry']) && is_string($row['geometry'])) {
            $decoded = json_decode($row['geometry'], true);
            $row['geometry'] = (is_array($decoded) && $decoded !== []) ? $decoded : throw new ApiError('INTERNAL_ERROR', 'Stored geometry is not valid GeoJSON', 500);
        }
        if (isset($row['attributes']) && is_string($row['attributes'])) {
            $decoded = json_decode($row['attributes'], true);
            $row['attributes'] = is_array($decoded) ? $decoded : [];
        }
        return $row;
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

    // ── TASK-067 helpers ──────────────────────────────────────────────────────

    private function canViewPii(int $userId): bool
    {
        $stmt = $this->pdo->prepare("
            SELECT 1
            FROM app.permissions p
            JOIN app.role_permissions rp ON p.id = rp.permission_id
            JOIN app.user_roles ur ON rp.role_id = ur.role_id
            WHERE ur.user_id = :uid AND p.code IN ('user.view.pii', 'organization.view.pii', 'title.view_owner', 'party.view')
            LIMIT 1
        ");
        $stmt->execute([':uid' => $userId]);
        return (bool) $stmt->fetchColumn();
    }

    private function getLayerPiiFields(int $layerId): array
    {
        $stmt = $this->pdo->prepare("
            SELECT field_name
            FROM app.gis_layer_fields
            WHERE layer_id = :lid AND deleted_at IS NULL AND is_pii = true
        ");
        $stmt->execute([':lid' => $layerId]);
        return $stmt->fetchAll(PDO::FETCH_COLUMN) ?: [];
    }

    private function redactPiiAttributes(array $attributes, array $piiFields): array
    {
        if (empty($piiFields)) {
            return $attributes;
        }
        foreach ($piiFields as $field) {
            if (array_key_exists($field, $attributes) && $attributes[$field] !== null) {
                $attributes[$field] = '[REDACTED]';
            }
        }
        return $attributes;
    }
}
