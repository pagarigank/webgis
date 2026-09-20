<?php
declare(strict_types=1);

namespace App\GIS\Domain;

use PDO;
use App\Core\Error\ApiError;
use App\GIS\Domain\AttributeValidator;

class FieldRetypeService
{
    private PDO $pdo;
    private AttributeValidator $validator;

    public function __construct(PDO $pdo, AttributeValidator $validator)
    {
        $this->pdo = $pdo;
        $this->validator = $validator;
    }

    /**
     * Preview a field retype or schema change.
     * @return array [convertible_count, failing_count, failing_examples]
     */
    public function preview(int $layerId, string $fieldName, array $proposedFieldData): array
    {
        $features = $this->getFeaturesWithField($layerId, $fieldName);

        $convertibleCount = 0;
        $failingCount = 0;
        $failingExamples = [];

        foreach ($features as $feature) {
            $attributes = json_decode($feature['attributes'], true);
            $oldValue = $attributes[$fieldName] ?? null;

            if ($oldValue === null) {
                // Check if the proposed field makes it required
                $isRequired = filter_var($proposedFieldData['required'] ?? false, FILTER_VALIDATE_BOOLEAN);
                if ($isRequired) {
                    $failingCount++;
                    if (count($failingExamples) < 5) {
                        $failingExamples[] = ['id' => $feature['id'], 'value' => null, 'error' => 'Field is required.'];
                    }
                } else {
                    $convertibleCount++;
                }
                continue;
            }

            $castResult = $this->castValue($oldValue, $proposedFieldData['field_type']);
            
            if (!$castResult['success']) {
                $failingCount++;
                if (count($failingExamples) < 5) {
                    $failingExamples[] = ['id' => $feature['id'], 'value' => $oldValue, 'error' => $castResult['error']];
                }
                continue;
            }

            // Test the casted value against the new validation rules
            $testAttributes = [$fieldName => $castResult['value']];
            $errors = $this->validator->validate([$proposedFieldData], $testAttributes);

            if (!empty($errors)) {
                $failingCount++;
                if (count($failingExamples) < 5) {
                    $failingExamples[] = ['id' => $feature['id'], 'value' => $oldValue, 'error' => $errors[$fieldName]];
                }
            } else {
                $convertibleCount++;
            }
        }

        return [
            'convertible_count' => $convertibleCount,
            'failing_count' => $failingCount,
            'failing_examples' => $failingExamples
        ];
    }

    /**
     * Executes the retype. Throws on validation failure.
     */
    public function execute(int $layerId, string $fieldName, array $proposedFieldData): void
    {
        $features = $this->getFeaturesWithField($layerId, $fieldName);

        $updates = [];

        foreach ($features as $feature) {
            $attributes = json_decode($feature['attributes'], true);
            $oldValue = $attributes[$fieldName] ?? null;

            if ($oldValue === null) {
                $isRequired = filter_var($proposedFieldData['required'] ?? false, FILTER_VALIDATE_BOOLEAN);
                if ($isRequired) {
                    throw new ApiError('VALIDATION_FAILED', 'Cannot convert all values', 409, [
                        'failing_examples' => [['id' => $feature['id'], 'value' => null, 'error' => 'Field is required.']]
                    ]);
                }
                continue;
            }

            $castResult = $this->castValue($oldValue, $proposedFieldData['field_type']);
            
            if (!$castResult['success']) {
                throw new ApiError('VALIDATION_FAILED', 'Cannot convert all values', 409, [
                    'failing_examples' => [['id' => $feature['id'], 'value' => $oldValue, 'error' => $castResult['error']]]
                ]);
            }

            $testAttributes = [$fieldName => $castResult['value']];
            $errors = $this->validator->validate([$proposedFieldData], $testAttributes);

            if (!empty($errors)) {
                throw new ApiError('VALIDATION_FAILED', 'Cannot convert all values', 409, [
                    'failing_examples' => [['id' => $feature['id'], 'value' => $oldValue, 'error' => $errors[$fieldName]]]
                ]);
            }

            $attributes[$fieldName] = $castResult['value'];
            $updates[$feature['id']] = json_encode($attributes);
        }

        // Apply updates
        if (!empty($updates)) {
            $stmt = $this->pdo->prepare("UPDATE app.gis_features SET attributes = ?, version = version + 1 WHERE id = ?");
            foreach ($updates as $id => $newAttributes) {
                $stmt->execute([$newAttributes, $id]);
            }
        }
    }

    private function getFeaturesWithField(int $layerId, string $fieldName): array
    {
        // Get all features for the layer that actually have the field in their JSON
        $stmt = $this->pdo->prepare("SELECT id, attributes FROM app.gis_features WHERE layer_id = ? AND jsonb_exists(attributes, ?)");
        $stmt->execute([$layerId, $fieldName]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    private function castValue($value, string $newType): array
    {
        $newType = strtolower($newType);

        if ($newType === 'text' || $newType === 'long_text') {
            return ['success' => true, 'value' => (string)$value];
        }

        if ($newType === 'integer') {
            if (is_numeric($value)) {
                return ['success' => true, 'value' => (int)$value];
            }
            return ['success' => false, 'error' => 'Cannot cast to integer'];
        }

        if ($newType === 'decimal' || $newType === 'currency') {
            if (is_numeric($value)) {
                return ['success' => true, 'value' => (float)$value];
            }
            return ['success' => false, 'error' => 'Cannot cast to decimal'];
        }

        if ($newType === 'boolean') {
            $bool = filter_var($value, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);
            if ($bool !== null) {
                return ['success' => true, 'value' => $bool];
            }
            return ['success' => false, 'error' => 'Cannot cast to boolean'];
        }

        // For dates, uuids, enums, etc, the validator will do the heavy lifting.
        // We just return the string representation as the "cast" value.
        return ['success' => true, 'value' => is_scalar($value) ? (string)$value : $value];
    }
}
