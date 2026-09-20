// @vitest-environment jsdom
import { render, screen, cleanup } from '@testing-library/react';
import { describe, it, expect, afterEach } from 'vitest';
import { useForm, FormProvider } from 'react-hook-form';

afterEach(cleanup);
import { FieldRenderer } from './FieldRenderer';
import type { LayerField } from '../../features/layers/types';

const mockField = (overrides: Partial<LayerField>): LayerField => ({
    id: 1,
    layer_id: 1,
    field_name: 'test_field',
    field_label: 'Test Field',
    field_type: 'text',
    required: false,
    searchable: true,
    sortable: true,
    displayable: true,
    editable: true,
    is_pii: false,
    sort_order: 1,
    ...overrides
});

const TestWrapper = ({ field, canViewPII = false }: { field: LayerField, canViewPII?: boolean }) => {
    const methods = useForm();
    return (
        <FormProvider {...methods}>
            <form data-testid="test-form">
                <FieldRenderer field={field} canViewPII={canViewPII} />
            </form>
        </FormProvider>
    );
};

describe('FieldRenderer', () => {
    it('renders a basic text input', () => {
        render(<TestWrapper field={mockField({ field_type: 'text' })} />);
        const input = screen.getByLabelText('Test Field');
        expect(input.tagName).toBe('INPUT');
        expect(input.getAttribute('type')).toBe('text');
    });

    it('renders a textarea for long_text', () => {
        render(<TestWrapper field={mockField({ field_type: 'long_text' })} />);
        const input = screen.getByLabelText('Test Field');
        expect(input.tagName).toBe('TEXTAREA');
    });

    it('renders a number input for integer', () => {
        render(<TestWrapper field={mockField({ field_type: 'integer' })} />);
        const input = screen.getByLabelText('Test Field');
        expect(input.tagName).toBe('INPUT');
        expect(input.getAttribute('type')).toBe('number');
        expect(input.getAttribute('step')).toBe('1');
    });

    it('renders a select for dropdown', () => {
        render(<TestWrapper field={mockField({ field_type: 'dropdown', options: ['A', 'B'] })} />);
        const select = screen.getByLabelText('Test Field');
        expect(select.tagName).toBe('SELECT');
        const options = select.querySelectorAll('option');
        expect(options.length).toBe(3); // +1 for "Select an option..."
        expect(options[1].value).toBe('A');
        expect(options[2].value).toBe('B');
    });

    it('renders a checkbox for boolean', () => {
        render(<TestWrapper field={mockField({ field_type: 'boolean' })} />);
        const checkbox = screen.getByLabelText('Test Field');
        expect(checkbox.tagName).toBe('INPUT');
        expect(checkbox.getAttribute('type')).toBe('checkbox');
    });

    it('hides PII fields when canViewPII is false', () => {
        const { container } = render(<TestWrapper field={mockField({ is_pii: true })} canViewPII={false} />);
        expect(screen.queryByLabelText('Test Field')).toBeNull();
        expect(container.innerHTML).not.toContain('Test Field');
    });

    it('shows PII fields with a badge when canViewPII is true', () => {
        render(<TestWrapper field={mockField({ is_pii: true })} canViewPII={true} />);
        expect(screen.getByLabelText(/Test Field/)).toBeDefined();
        expect(screen.getByText('PII')).toBeDefined();
    });

    it('shows required asterisk', () => {
        render(<TestWrapper field={mockField({ required: true })} />);
        expect(screen.getByText('*')).toBeDefined();
    });
});
