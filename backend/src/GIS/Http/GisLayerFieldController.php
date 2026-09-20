<?php
declare(strict_types=1);

namespace App\GIS\Http;

use PDO;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use App\Core\Http\Response\Envelope;
use App\Core\Error\ApiError;
use App\GIS\Domain\FieldRetypeService;

class GisLayerFieldController
{
    private PDO $pdo;
    private FieldRetypeService $retypeService;

    const ALLOWED_TYPES = [
        'text', 'long_text', 'integer', 'decimal', 'boolean', 'date', 'datetime', 
        'dropdown', 'multi_select', 'email', 'phone', 'url', 'currency', 'reference', 
        'user', 'document'
    ];

    const RESERVED_NAMES = [
        'id', 'layer_id', 'geom', 'created_at', 'updated_at', 
        'deleted_at', 'created_by', 'updated_by', 'status', 'version'
    ];

    public function __construct(PDO $pdo, FieldRetypeService $retypeService)
    {
        $this->pdo = $pdo;
        $this->retypeService = $retypeService;
    }

    public function list(Request $request, Response $response, array $args): Response
    {
        $layerId = (int) $args['layer_id'];
        $stmt = $this->pdo->prepare("SELECT * FROM app.gis_layer_fields WHERE layer_id = ? AND deleted_at IS NULL ORDER BY sort_order ASC");
        $stmt->execute([$layerId]);
        $fields = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        return Envelope::success($response, $fields);
    }

    public function create(Request $request, Response $response, array $args): Response
    {
        $layerId = (int) $args['layer_id'];
        $data = (array) $request->getParsedBody();
        
        $fieldName = $data['field_name'] ?? '';
        $fieldLabel = $data['field_label'] ?? $fieldName;
        $fieldType = strtolower($data['field_type'] ?? 'text');
        $required = filter_var($data['required'] ?? false, FILTER_VALIDATE_BOOLEAN);
        $defaultValue = isset($data['default_value']) ? json_encode($data['default_value']) : null;
        $options = isset($data['options']) ? json_encode($data['options']) : null;
        $validationRules = isset($data['validation_rules']) ? json_encode($data['validation_rules']) : null;
        $existingValue = $data['existing_value'] ?? null;
        
        if (empty($fieldName)) {
            throw new ApiError('VALIDATION_FAILED', 'Field name is required', 400);
        }
        
        if (in_array(strtolower($fieldName), self::RESERVED_NAMES)) {
            throw new ApiError('VALIDATION_FAILED', 'Field name is reserved', 400);
        }
        
        if (!in_array($fieldType, self::ALLOWED_TYPES)) {
            throw new ApiError('VALIDATION_FAILED', 'Invalid field type', 400);
        }
        
        // Check if layer exists and is populated
        $stmt = $this->pdo->prepare("SELECT feature_count_cache FROM app.gis_layers WHERE id = ? AND deleted_at IS NULL");
        $stmt->execute([$layerId]);
        $layer = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if (!$layer) {
            throw new ApiError('NOT_FOUND', 'Layer not found', 404);
        }
        
        if ($required && $layer['feature_count_cache'] > 0 && $existingValue === null && $defaultValue === null) {
            throw new ApiError('CONFLICT', 'Cannot add a required field to a populated layer without providing an existing_value or default_value.', 409);
        }

        // Generate sort_order
        $stmt = $this->pdo->prepare("SELECT COALESCE(MAX(sort_order), 0) + 1 FROM app.gis_layer_fields WHERE layer_id = ?");
        $stmt->execute([$layerId]);
        $sortOrder = $stmt->fetchColumn();

        $sql = "
            INSERT INTO app.gis_layer_fields 
            (layer_id, field_name, field_label, field_type, required, default_value, options, validation_rules, sort_order)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)
            RETURNING id
        ";
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute([
            $layerId, $fieldName, $fieldLabel, $fieldType, 
            $required ? 't' : 'f', $defaultValue, $options, $validationRules, $sortOrder
        ]);
        $id = $stmt->fetchColumn();

        // If existingValue is provided and layer is populated, we would theoretically backfill existing features here.
        // For now, this is outside the scope of just creating metadata, but the constraint is satisfied.

        return Envelope::success($response, ['id' => $id], 201);
    }
    
    public function update(Request $request, Response $response, array $args): Response
    {
        $layerId = (int) $args['layer_id'];
        $id = (int) $args['id'];
        $data = (array) $request->getParsedBody();
        
        // Fetch existing field metadata to see what changed
        $stmt = $this->pdo->prepare("SELECT * FROM app.gis_layer_fields WHERE id = ? AND layer_id = ? AND deleted_at IS NULL FOR UPDATE");
        $stmt->execute([$id, $layerId]);
        $existingField = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if (!$existingField) {
            throw new ApiError('NOT_FOUND', 'Field not found', 404);
        }

        $fields = [];
        $values = [];
        
        $allowedToUpdate = [
            'field_label', 'field_type', 'required', 'default_value', 'options', 
            'validation_rules', 'searchable', 'sortable', 'displayable', 
            'editable', 'is_pii', 'sort_order'
        ];
        
        $proposedData = $existingField;
        $schemaChanged = false;

        foreach ($allowedToUpdate as $field) {
            if (array_key_exists($field, $data)) {
                $fields[] = "$field = ?";
                $val = null;
                if (in_array($field, ['default_value', 'options', 'validation_rules'])) {
                    $val = $data[$field] !== null ? json_encode($data[$field]) : null;
                } elseif (in_array($field, ['required', 'searchable', 'sortable', 'displayable', 'editable', 'is_pii'])) {
                    $val = filter_var($data[$field], FILTER_VALIDATE_BOOLEAN) ? 't' : 'f';
                } elseif ($field === 'field_type') {
                    $val = strtolower($data[$field]);
                    if (!in_array($val, self::ALLOWED_TYPES)) {
                        throw new ApiError('VALIDATION_FAILED', 'Invalid field type', 400);
                    }
                } else {
                    $val = $data[$field];
                }
                $values[] = $val;
                
                // Track schema changes to see if we need to run retype service
                if (in_array($field, ['field_type', 'required', 'options', 'validation_rules']) && $val !== $existingField[$field]) {
                    $schemaChanged = true;
                }
                $proposedData[$field] = $val;
            }
        }
        
        if (empty($fields)) {
            return Envelope::success($response, ['id' => $id]);
        }
        
        $values[] = $id;
        $values[] = $layerId;
        $sql = "UPDATE app.gis_layer_fields SET " . implode(', ', $fields) . " WHERE id = ? AND layer_id = ?";

        if ($schemaChanged) {
            $this->pdo->beginTransaction();
            try {
                // Take advisory xact lock on layer_id
                $this->pdo->prepare("SELECT pg_advisory_xact_lock(?)")->execute([$layerId]);
                
                // Unescape JSON for the service
                if (is_string($proposedData['options'])) $proposedData['options'] = json_decode($proposedData['options'], true);
                if (is_string($proposedData['validation_rules'])) $proposedData['validation_rules'] = json_decode($proposedData['validation_rules'], true);
                
                // Execute conversion (throws on failure)
                $this->retypeService->execute($layerId, $existingField['field_name'], $proposedData);

                // Update metadata
                $this->pdo->prepare($sql)->execute($values);
                $this->pdo->commit();
            } catch (\Exception $e) {
                $this->pdo->rollBack();
                throw $e;
            }
        } else {
            $this->pdo->prepare($sql)->execute($values);
        }
        
        return Envelope::success($response, ['id' => $id]);
    }

    public function retypePreview(Request $request, Response $response, array $args): Response
    {
        $layerId = (int) $args['layer_id'];
        $id = (int) $args['id'];
        $data = (array) $request->getParsedBody();
        
        $stmt = $this->pdo->prepare("SELECT * FROM app.gis_layer_fields WHERE id = ? AND layer_id = ? AND deleted_at IS NULL");
        $stmt->execute([$id, $layerId]);
        $existingField = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if (!$existingField) {
            throw new ApiError('NOT_FOUND', 'Field not found', 404);
        }

        $proposedData = $existingField;
        
        $allowedToUpdate = ['field_type', 'required', 'options', 'validation_rules'];
        
        foreach ($allowedToUpdate as $field) {
            if (array_key_exists($field, $data)) {
                $val = null;
                if (in_array($field, ['options', 'validation_rules'])) {
                    // For the preview, we can keep them as arrays because the service handles it
                    $val = $data[$field];
                } elseif ($field === 'required') {
                    $val = filter_var($data[$field], FILTER_VALIDATE_BOOLEAN);
                } elseif ($field === 'field_type') {
                    $val = strtolower($data[$field]);
                } else {
                    $val = $data[$field];
                }
                $proposedData[$field] = $val;
            }
        }
        
        // Ensure options/rules are decoded for service if they came from the DB
        if (is_string($proposedData['options'])) $proposedData['options'] = json_decode($proposedData['options'], true);
        if (is_string($proposedData['validation_rules'])) $proposedData['validation_rules'] = json_decode($proposedData['validation_rules'], true);

        $result = $this->retypeService->preview($layerId, $existingField['field_name'], $proposedData);
        
        return Envelope::success($response, $result);
    }
    
    public function delete(Request $request, Response $response, array $args): Response
    {
        $layerId = (int) $args['layer_id'];
        $id = (int) $args['id'];
        
        $this->pdo->prepare("UPDATE app.gis_layer_fields SET deleted_at = CURRENT_TIMESTAMP WHERE id = ? AND layer_id = ?")->execute([$id, $layerId]);
        return Envelope::success($response, ['id' => $id]);
    }
}
