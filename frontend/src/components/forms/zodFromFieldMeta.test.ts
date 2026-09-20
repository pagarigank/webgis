import { describe, it, expect } from 'vitest';
import { buildZodSchema } from './zodFromFieldMeta';
import type { LayerField } from '../../features/layers/types';

describe('zodFromFieldMeta', () => {
    const mockField = (overrides: Partial<LayerField>): LayerField => ({
        id: 1,
        layer_id: 1,
        field_name: 'test_field',
        field_label: 'Test Field',
        field_type: 'text',
        required: true,
        searchable: true,
        sortable: true,
        displayable: true,
        editable: true,
        is_pii: false,
        sort_order: 1,
        ...overrides
    });

    it('validates required text fields', () => {
        const schema = buildZodSchema([mockField({ field_type: 'text', required: true })]);
        expect(schema.safeParse({ test_field: 'value' }).success).toBe(true);
        expect(schema.safeParse({ test_field: '' }).success).toBe(false); // min 1
        expect(schema.safeParse({}).success).toBe(false);
    });

    it('validates optional text fields', () => {
        const schema = buildZodSchema([mockField({ field_type: 'text', required: false })]);
        expect(schema.safeParse({ test_field: 'value' }).success).toBe(true);
        expect(schema.safeParse({ test_field: '' }).success).toBe(true); // empty allowed
        expect(schema.safeParse({}).success).toBe(true); // undefined allowed
    });

    it('respects min/max validation rules on strings', () => {
        const schema = buildZodSchema([mockField({ 
            field_type: 'text', 
            validation_rules: { min: 3, max: 5 } 
        })]);
        expect(schema.safeParse({ test_field: '12' }).success).toBe(false);
        expect(schema.safeParse({ test_field: '123' }).success).toBe(true);
        expect(schema.safeParse({ test_field: '12345' }).success).toBe(true);
        expect(schema.safeParse({ test_field: '123456' }).success).toBe(false);
    });

    it('validates integers and respects min/max rules', () => {
        const schema = buildZodSchema([mockField({ 
            field_type: 'integer', 
            validation_rules: { min: 10, max: 20 } 
        })]);
        expect(schema.safeParse({ test_field: 15 }).success).toBe(true);
        expect(schema.safeParse({ test_field: 15.5 }).success).toBe(false); // int check
        expect(schema.safeParse({ test_field: 5 }).success).toBe(false); // min check
        expect(schema.safeParse({ test_field: 25 }).success).toBe(false); // max check
    });

    it('validates email addresses', () => {
        const schema = buildZodSchema([mockField({ field_type: 'email' })]);
        expect(schema.safeParse({ test_field: 'test@example.com' }).success).toBe(true);
        expect(schema.safeParse({ test_field: 'not-an-email' }).success).toBe(false);
    });

    it('validates dates', () => {
        const schema = buildZodSchema([mockField({ field_type: 'date' })]);
        expect(schema.safeParse({ test_field: '2023-12-01' }).success).toBe(true);
        expect(schema.safeParse({ test_field: '2023/12/01' }).success).toBe(false);
    });

    it('drops PII fields when canViewPII is false', () => {
        const schema = buildZodSchema([
            mockField({ field_name: 'normal', field_type: 'text' }),
            mockField({ field_name: 'secret', field_type: 'text', is_pii: true })
        ], { canViewPII: false });

        // 'secret' is completely omitted from the schema
        const result = schema.safeParse({ normal: 'hello', secret: 'hidden' });
        expect(result.success).toBe(true);
        if (result.success) {
            expect(result.data).not.toHaveProperty('secret');
            expect(result.data.normal).toBe('hello');
        }
    });

    it('includes PII fields when canViewPII is true', () => {
        const schema = buildZodSchema([
            mockField({ field_name: 'secret', field_type: 'text', is_pii: true, required: true })
        ], { canViewPII: true });

        // 'secret' must be provided because it's required and included in schema
        expect(schema.safeParse({}).success).toBe(false);
        const result = schema.safeParse({ secret: 'shown' });
        expect(result.success).toBe(true);
        if (result.success) {
            expect(result.data.secret).toBe('shown');
        }
    });
});
