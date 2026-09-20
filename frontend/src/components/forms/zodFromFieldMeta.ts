import { z } from 'zod';
import type { LayerField } from '../../features/layers/types';

export interface ZodGeneratorOptions {
    canViewPII?: boolean;
}

export function buildZodSchema(fields: LayerField[], options: ZodGeneratorOptions = { canViewPII: false }) {
    const shape: Record<string, z.ZodTypeAny> = {};

    for (const field of fields) {
        if (field.is_pii && !options.canViewPII) {
            continue; // Exclude PII fields if the user doesn't have permission
        }

        let fieldSchema: z.ZodTypeAny;

        switch (field.field_type) {
            case 'integer':
            case 'decimal':
            case 'currency':
                let numSchema = z.number();
                if (field.field_type === 'integer') {
                    numSchema = numSchema.int();
                }
                if (field.validation_rules?.min !== undefined) {
                    numSchema = numSchema.min(field.validation_rules.min);
                }
                if (field.validation_rules?.max !== undefined) {
                    numSchema = numSchema.max(field.validation_rules.max);
                }
                fieldSchema = numSchema;
                break;
                
            case 'boolean':
                fieldSchema = z.boolean();
                break;
                
            case 'email':
                fieldSchema = z.string().email();
                break;
                
            case 'url':
                fieldSchema = z.string().url();
                break;
                
            case 'multi_select':
                fieldSchema = z.array(z.string());
                break;
                
            case 'date':
            case 'datetime':
                // Keeping as string for JSON payload, but could add regex validation here
                fieldSchema = z.string();
                if (field.field_type === 'date') {
                    fieldSchema = (fieldSchema as z.ZodString).regex(/^\d{4}-\d{2}-\d{2}$/, 'Invalid date format (YYYY-MM-DD)');
                }
                break;

            case 'text':
            case 'long_text':
            case 'phone':
            case 'dropdown':
            case 'reference':
            case 'user':
            case 'document':
            default:
                let strSchema = z.string();
                if (field.validation_rules?.min !== undefined) {
                    strSchema = strSchema.min(field.validation_rules.min);
                }
                if (field.validation_rules?.max !== undefined) {
                    strSchema = strSchema.max(field.validation_rules.max);
                }
                if (field.validation_rules?.pattern !== undefined) {
                    strSchema = strSchema.regex(new RegExp(field.validation_rules.pattern));
                }
                fieldSchema = strSchema;
                break;
        }

        if (!field.required) {
            fieldSchema = fieldSchema.optional().or(z.literal(''));
        } else if (fieldSchema instanceof z.ZodString) {
            fieldSchema = fieldSchema.min(1, 'Required');
        }

        shape[field.field_name] = fieldSchema;
    }

    return z.object(shape);
}
