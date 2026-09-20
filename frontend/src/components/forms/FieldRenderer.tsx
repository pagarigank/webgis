import { Controller, useFormContext } from 'react-hook-form';
import type { LayerField } from '../../features/layers/types';

interface FieldRendererProps {
    field: LayerField;
    canViewPII?: boolean;
}

export function FieldRenderer({ field, canViewPII = false }: FieldRendererProps) {
    const { control } = useFormContext();

    if (field.is_pii && !canViewPII) {
        return null;
    }

    const renderInput = (props: any) => {
        const { value, onChange, onBlur, ref } = props.field;
        const { error } = props.fieldState;
        
        const commonProps = {
            id: `field-${field.field_name}`,
            className: `form-control ${error ? 'is-invalid' : ''}`,
            value: value ?? '',
            onChange,
            onBlur,
            ref,
            disabled: !field.editable,
            placeholder: field.field_label
        };

        switch (field.field_type) {
            case 'long_text':
                return <textarea {...commonProps} rows={3} />;
                
            case 'integer':
            case 'decimal':
            case 'currency':
                return (
                    <input 
                        type="number" 
                        {...commonProps}
                        onChange={e => {
                            const val = e.target.value;
                            onChange(val === '' ? undefined : Number(val));
                        }}
                        step={field.field_type === 'integer' ? '1' : 'any'}
                    />
                );
                
            case 'boolean':
                return (
                    <div className="form-check">
                        <input 
                            type="checkbox"
                            id={commonProps.id}
                            className={`form-check-input ${error ? 'is-invalid' : ''}`}
                            checked={!!value}
                            onChange={e => onChange(e.target.checked)}
                            onBlur={onBlur}
                            ref={ref}
                            disabled={!field.editable}
                        />
                    </div>
                );
                
            case 'date':
                return <input type="date" {...commonProps} />;
                
            case 'datetime':
                return <input type="datetime-local" {...commonProps} />;
                
            case 'dropdown':
            case 'multi_select':
                // Options can be an array of strings or objects. We handle array of strings for now.
                const optionsList = Array.isArray(field.options) ? field.options : [];
                return (
                    <select 
                        {...commonProps} 
                        multiple={field.field_type === 'multi_select'}
                        onChange={e => {
                            if (field.field_type === 'multi_select') {
                                const selected = Array.from(e.target.selectedOptions).map(o => o.value);
                                onChange(selected);
                            } else {
                                onChange(e.target.value);
                            }
                        }}
                        value={field.field_type === 'multi_select' ? (value ?? []) : (value ?? '')}
                    >
                        {field.field_type !== 'multi_select' && <option value="">Select an option...</option>}
                        {optionsList.map((opt: string, idx: number) => (
                            <option key={idx} value={opt}>{opt}</option>
                        ))}
                    </select>
                );

            case 'text':
            case 'email':
            case 'phone':
            case 'url':
            default:
                const inputType = field.field_type === 'email' ? 'email' : 
                                  field.field_type === 'url' ? 'url' : 
                                  field.field_type === 'phone' ? 'tel' : 'text';
                return <input type={inputType} {...commonProps} />;
        }
    };

    return (
        <div className="mb-3">
            <label htmlFor={`field-${field.field_name}`} className="form-label">
                {field.field_label}
                {field.required && <span className="text-danger ms-1">*</span>}
                {field.is_pii && <span className="badge bg-warning text-dark ms-2">PII</span>}
            </label>
            
            <Controller
                name={field.field_name}
                control={control}
                defaultValue={field.default_value ?? (field.field_type === 'boolean' ? false : field.field_type === 'multi_select' ? [] : '')}
                render={(props) => (
                    <>
                        {renderInput(props)}
                        {props.fieldState.error && (
                            <div className="invalid-feedback d-block">
                                {props.fieldState.error.message}
                            </div>
                        )}
                    </>
                )}
            />
        </div>
    );
}
