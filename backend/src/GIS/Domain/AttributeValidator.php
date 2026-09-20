<?php
declare(strict_types=1);

namespace App\GIS\Domain;

class AttributeValidator
{
    /**
     * Validates a set of attributes against field definitions.
     * 
     * @param array $fields Array of field metadata (from gis_layer_fields)
     * @param array $attributes Key-value array of provided attributes
     * @return array Array of errors keyed by field name. Empty if valid.
     */
    public function validate(array $fields, array $attributes): array
    {
        $errors = [];

        foreach ($fields as $field) {
            $fieldName = $field['field_name'];
            $fieldType = strtolower($field['field_type'] ?? 'text');
            $isRequired = filter_var($field['required'] ?? false, FILTER_VALIDATE_BOOLEAN);
            $options = isset($field['options']) && is_string($field['options']) ? json_decode($field['options'], true) : ($field['options'] ?? null);
            $validationRules = isset($field['validation_rules']) && is_string($field['validation_rules']) ? json_decode($field['validation_rules'], true) : ($field['validation_rules'] ?? []);

            $value = $attributes[$fieldName] ?? null;

            if ($value === null || $value === '') {
                if ($isRequired) {
                    $errors[$fieldName] = 'Field is required.';
                }
                continue; // Skip further checks if empty and not required
            }

            // Type and specific format validation
            $typeError = $this->validateType($fieldType, $value);
            if ($typeError) {
                $errors[$fieldName] = $typeError;
                continue;
            }

            // Options/Enum validation
            if (is_array($options) && !empty($options)) {
                if ($fieldType === 'multi_select' && is_array($value)) {
                    foreach ($value as $v) {
                        if (!in_array($v, $options, true)) {
                            $errors[$fieldName] = 'Value must be one of the allowed options.';
                            break;
                        }
                    }
                } elseif (!in_array($value, $options, true)) {
                    $errors[$fieldName] = 'Value must be one of the allowed options.';
                }
            }

            // Additional rules (min, max, regex)
            if (is_array($validationRules)) {
                $ruleError = $this->applyValidationRules($validationRules, $value, $fieldType);
                if ($ruleError) {
                    $errors[$fieldName] = $ruleError;
                }
            }
        }

        return $errors;
    }

    private function validateType(string $type, $value): ?string
    {
        switch ($type) {
            case 'integer':
                if (filter_var($value, FILTER_VALIDATE_INT) === false) {
                    return 'Must be an integer.';
                }
                break;
            case 'decimal':
            case 'currency':
                if (filter_var($value, FILTER_VALIDATE_FLOAT) === false && !is_numeric($value)) {
                    return 'Must be a numeric value.';
                }
                break;
            case 'boolean':
                if (filter_var($value, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE) === null) {
                    return 'Must be a boolean.';
                }
                break;
            case 'date':
                if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', (string)$value)) {
                    return 'Must be a valid date (YYYY-MM-DD).';
                }
                break;
            case 'datetime':
                if (strtotime((string)$value) === false) {
                    return 'Must be a valid datetime.';
                }
                break;
            case 'user':
            case 'org':
            case 'document':
            case 'reference':
                if (!preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i', (string)$value) && !is_numeric($value)) {
                    return 'Must be a valid reference ID.';
                }
                break;
            case 'json':
                if (is_string($value)) {
                    json_decode($value);
                    if (json_last_error() !== JSON_ERROR_NONE) {
                        return 'Must be valid JSON.';
                    }
                } elseif (!is_array($value) && !is_object($value)) {
                    return 'Must be a valid JSON object or array.';
                }
                break;
            case 'multi_select':
                if (!is_array($value)) {
                    return 'Must be an array.';
                }
                break;
            case 'text':
            case 'long_text':
            case 'dropdown':
            case 'email':
            case 'phone':
            case 'url':
            default:
                if (!is_string($value) && !is_numeric($value)) {
                    return 'Must be a string.';
                }
                break;
        }

        return null;
    }

    private function applyValidationRules(array $rules, $value, string $type): ?string
    {
        if (isset($rules['regex']) && is_string($value)) {
            $regex = '/' . str_replace('/', '\/', $rules['regex']) . '/';
            if (!preg_match($regex, $value)) {
                return $rules['regex_message'] ?? 'Format is invalid.';
            }
        }

        if (in_array($type, ['integer', 'decimal', 'currency'])) {
            if (isset($rules['min']) && (float)$value < (float)$rules['min']) {
                return 'Value must be at least ' . $rules['min'] . '.';
            }
            if (isset($rules['max']) && (float)$value > (float)$rules['max']) {
                return 'Value must be at most ' . $rules['max'] . '.';
            }
        } elseif (in_array($type, ['text', 'long_text'])) {
            if (isset($rules['min']) && mb_strlen((string)$value) < (int)$rules['min']) {
                return 'String length must be at least ' . $rules['min'] . ' characters.';
            }
            if (isset($rules['max']) && mb_strlen((string)$value) > (int)$rules['max']) {
                return 'String length must be at most ' . $rules['max'] . ' characters.';
            }
        }

        return null;
    }
}
