<?php
declare(strict_types=1);

namespace App\GIS\Http;

use PDO;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use App\Core\Http\Response\Envelope;
use App\Core\Error\ApiError;

class GisLayerController
{
    private PDO $pdo;

    public function __construct(PDO $pdo)
    {
        $this->pdo = $pdo;
    }

    public function list(Request $request, Response $response): Response
    {
        $stmt = $this->pdo->query("SELECT * FROM app.gis_layers WHERE deleted_at IS NULL ORDER BY display_order ASC");
        $layers = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        return Envelope::success($response, $layers);
    }

    public function get(Request $request, Response $response, array $args): Response
    {
        $id = (int) $args['id'];

        $stmt = $this->pdo->prepare("SELECT * FROM app.gis_layers WHERE id = ? AND deleted_at IS NULL");
        $stmt->execute([$id]);
        $layer = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$layer) {
            throw new ApiError('NOT_FOUND', 'Layer not found', 404);
        }

        return Envelope::success($response, $layer);
    }

    public function create(Request $request, Response $response): Response
    {
        $data = (array) $request->getParsedBody();
        $code = $data['code'] ?? '';
        $name = $data['name'] ?? '';
        $geometryType = $data['geometry_type'] ?? 'POLYGON';
        $description = $data['description'] ?? null;
        $groupPath = $data['group_path'] ?? null;
        $srid = isset($data['srid']) ? (int) $data['srid'] : null;
        $source = $data['source'] ?? null;
        $renderMode = $data['render_mode'] ?? null;
        $isHidden = filter_var($data['is_hidden'] ?? false, FILTER_VALIDATE_BOOLEAN);

        if (empty($code) || empty($name)) {
            throw new ApiError('VALIDATION_FAILED', 'Code and name are required', 400);
        }

        $stmt = $this->pdo->prepare("
            INSERT INTO app.gis_layers (code, name, geometry_type, description, group_path, srid, source, render_mode, is_hidden)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)
            RETURNING id
        ");
        $stmt->execute([$code, $name, $geometryType, $description, $groupPath, $srid, $source, $renderMode, $isHidden ? 1 : 0]);
        $id = $stmt->fetchColumn();

        // Grant full permissions to SYS_ADMIN
        $this->pdo->prepare("
            INSERT INTO app.layer_permissions (layer_id, role_id, can_view, can_create, can_update, can_delete, can_approve)
            SELECT ?, id, true, true, true, true, true
            FROM app.roles WHERE code = 'SYS_ADMIN'
        ")->execute([$id]);

        return Envelope::success($response, ['id' => $id], 201);
    }
    
    public function update(Request $request, Response $response, array $args): Response
    {
        $id = (int) $args['id'];
        $data = (array) $request->getParsedBody();
        $ifMatch = $request->getHeaderLine('If-Match');
        
        $stmt = $this->pdo->prepare("SELECT version FROM app.gis_layers WHERE id = ? AND deleted_at IS NULL");
        $stmt->execute([$id]);
        $currentVersion = $stmt->fetchColumn();
        
        if ($currentVersion === false) {
            throw new ApiError('NOT_FOUND', 'Layer not found', 404);
        }
        
        if (!empty($ifMatch) && trim($ifMatch, '"') != $currentVersion) {
            throw new ApiError('PRECONDITION_FAILED', 'Version mismatch. The record has been modified by another user.', 412);
        }
        
        $fields = [];
        $values = [];
        foreach (['name', 'description', 'geometry_type', 'display_order', 'min_zoom', 'max_zoom', 'group_path', 'extent', 'is_hidden'] as $field) {
            if (array_key_exists($field, $data)) {
                $fields[] = "$field = ?";
                $values[] = $field === 'is_hidden' ? (filter_var($data[$field], FILTER_VALIDATE_BOOLEAN) ? 1 : 0) : $data[$field];
            }
        }
        
        if (empty($fields)) {
            return Envelope::success($response, ['id' => $id, 'version' => $currentVersion]);
        }
        
        $fields[] = "version = version + 1";
        $values[] = $id;
        
        $sql = "UPDATE app.gis_layers SET " . implode(', ', $fields) . " WHERE id = ? RETURNING version";
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($values);
        $newVersion = $stmt->fetchColumn();
        
        return Envelope::success($response, ['id' => $id, 'version' => $newVersion]);
    }
    
    public function delete(Request $request, Response $response, array $args): Response
    {
        $id = (int) $args['id'];
        $ifMatch = $request->getHeaderLine('If-Match');
        $archive = $request->getQueryParams()['archive'] ?? 'false';
        
        $stmt = $this->pdo->prepare("SELECT version, feature_count_cache FROM app.gis_layers WHERE id = ? AND deleted_at IS NULL");
        $stmt->execute([$id]);
        $layer = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if (!$layer) {
            throw new ApiError('NOT_FOUND', 'Layer not found', 404);
        }
        
        if (!empty($ifMatch) && trim($ifMatch, '"') != $layer['version']) {
            throw new ApiError('PRECONDITION_FAILED', 'Version mismatch. The record has been modified by another user.', 412);
        }
        
        if ($layer['feature_count_cache'] > 0 && $archive !== 'true') {
            throw new ApiError('CONFLICT', 'Cannot delete a populated layer without explicit archive parameter.', 409);
        }
        
        $this->pdo->prepare("UPDATE app.gis_layers SET deleted_at = CURRENT_TIMESTAMP, version = version + 1 WHERE id = ?")->execute([$id]);
        return Envelope::success($response, ['id' => $id]);
    }

    public function getPermissions(Request $request, Response $response, array $args): Response
    {
        $id = (int) $args['id'];
        
        $stmt = $this->pdo->prepare("SELECT * FROM app.layer_permissions WHERE layer_id = ?");
        $stmt->execute([$id]);
        $permissions = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        return Envelope::success($response, $permissions);
    }

    public function savePermissions(Request $request, Response $response, array $args): Response
    {
        $id = (int) $args['id'];
        $data = (array) $request->getParsedBody();
        $permissions = $data['permissions'] ?? [];
        
        try {
            $this->pdo->prepare("DELETE FROM app.layer_permissions WHERE layer_id = ?")->execute([$id]);
            
            $stmt = $this->pdo->prepare("
                INSERT INTO app.layer_permissions (layer_id, role_id, can_view, can_create, can_update, can_delete, can_approve)
                VALUES (?, ?, ?, ?, ?, ?, ?)
            ");
            
            foreach ($permissions as $p) {
                $stmt->execute([
                    $id,
                    $p['role_id'],
                    !empty($p['can_view']) ? 'true' : 'false',
                    !empty($p['can_create']) ? 'true' : 'false',
                    !empty($p['can_update']) ? 'true' : 'false',
                    !empty($p['can_delete']) ? 'true' : 'false',
                    !empty($p['can_approve']) ? 'true' : 'false',
                ]);
            }
        } catch (\Exception $e) {
            throw new ApiError('INTERNAL_ERROR', 'Failed to save permissions: ' . $e->getMessage(), 500);
        }
        
        return Envelope::success($response, ['status' => 'success']);
    }
}

