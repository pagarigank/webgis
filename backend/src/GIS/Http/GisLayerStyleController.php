<?php
declare(strict_types=1);

namespace App\GIS\Http;

use PDO;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use App\Core\Http\Response\Envelope;
use App\Core\Error\ApiError;

class GisLayerStyleController
{
    private PDO $pdo;

    public function __construct(PDO $pdo)
    {
        $this->pdo = $pdo;
    }

    public function list(Request $request, Response $response, array $args): Response
    {
        $layerId = (int) $args['layer_id'];
        $stmt = $this->pdo->prepare("SELECT * FROM app.gis_layer_styles WHERE layer_id = ? ORDER BY created_at DESC");
        $stmt->execute([$layerId]);
        
        $styles = array_map(function ($row) {
            $row['rules'] = json_decode($row['rules'], true);
            $row['default_rule'] = json_decode($row['default_rule'], true);
            $row['label_config'] = $row['label_config'] ? json_decode($row['label_config'], true) : null;
            $row['is_active'] = (bool) $row['is_active'];
            return $row;
        }, $stmt->fetchAll(PDO::FETCH_ASSOC));
        
        return Envelope::success($response, $styles);
    }

    public function create(Request $request, Response $response, array $args): Response
    {
        $layerId = (int) $args['layer_id'];
        $data = (array) $request->getParsedBody();
        
        $styleType = strtoupper($data['style_type'] ?? '');
        $attributeField = $data['attribute_field'] ?? null;
        $rules = $data['rules'] ?? [];
        $defaultRule = $data['default_rule'] ?? null;
        $labelConfig = $data['label_config'] ?? null;
        
        if (!in_array($styleType, ['SINGLE', 'CATEGORIZED', 'GRADUATED'])) {
            throw new ApiError('VALIDATION_FAILED', 'Invalid style_type. Must be SINGLE, CATEGORIZED, or GRADUATED.', 400);
        }
        
        if (!$defaultRule) {
            throw new ApiError('VALIDATION_FAILED', 'default_rule is required.', 400);
        }
        
        // Mark all existing styles for this layer as inactive if this one is intended to be active
        $isActive = filter_var($data['is_active'] ?? true, FILTER_VALIDATE_BOOLEAN);
        
        // Use the transaction from AuthenticateMiddleware instead of starting a new one
        if ($isActive) {
            $this->pdo->prepare("UPDATE app.gis_layer_styles SET is_active = false WHERE layer_id = ?")->execute([$layerId]);
        }
        
        $sql = "
            INSERT INTO app.gis_layer_styles 
            (layer_id, style_type, attribute_field, rules, default_rule, label_config, is_active, version)
            VALUES (?, ?, ?, ?, ?, ?, ?, 1)
            RETURNING id
        ";
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute([
            $layerId,
            $styleType,
            $attributeField,
            json_encode($rules),
            json_encode($defaultRule),
            $labelConfig ? json_encode($labelConfig) : null,
            $isActive ? 't' : 'f'
        ]);
        $id = $stmt->fetchColumn();

        return Envelope::success($response, ['id' => $id], 201);
    }

    public function update(Request $request, Response $response, array $args): Response
    {
        $layerId = (int) $args['layer_id'];
        $id = (int) $args['id'];
        $data = (array) $request->getParsedBody();
        
        $stmt = $this->pdo->prepare("SELECT * FROM app.gis_layer_styles WHERE id = ? AND layer_id = ? FOR UPDATE");
        $stmt->execute([$id, $layerId]);
        $existing = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if (!$existing) {
            throw new ApiError('NOT_FOUND', 'Style not found', 404);
        }
        
        $fields = [];
        $values = [];
        
        $allowedToUpdate = ['style_type', 'attribute_field', 'rules', 'default_rule', 'label_config', 'is_active'];
        $isActiveChangedToTrue = false;
        
        foreach ($allowedToUpdate as $field) {
            if (array_key_exists($field, $data)) {
                $fields[] = "$field = ?";
                $val = $data[$field];
                
                if (in_array($field, ['rules', 'default_rule', 'label_config'])) {
                    $val = $val !== null ? json_encode($val) : null;
                } elseif ($field === 'is_active') {
                    $val = filter_var($val, FILTER_VALIDATE_BOOLEAN);
                    if ($val && !$existing['is_active']) {
                        $isActiveChangedToTrue = true;
                    }
                    $val = $val ? 't' : 'f';
                }
                $values[] = $val;
            }
        }
        
        if (empty($fields)) {
            return Envelope::success($response, ['id' => $id]);
        }
        
        $fields[] = "version = version + 1";
        $fields[] = "updated_at = CURRENT_TIMESTAMP";
        
        $values[] = $id;
        $values[] = $layerId;
        
        if ($isActiveChangedToTrue) {
            $this->pdo->prepare("UPDATE app.gis_layer_styles SET is_active = false WHERE layer_id = ? AND id != ?")->execute([$layerId, $id]);
        }
        
        $sql = "UPDATE app.gis_layer_styles SET " . implode(', ', $fields) . " WHERE id = ? AND layer_id = ?";
        $this->pdo->prepare($sql)->execute($values);
        
        return Envelope::success($response, ['id' => $id]);
    }
}
