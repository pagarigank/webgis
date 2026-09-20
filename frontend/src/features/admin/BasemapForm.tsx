import { useForm } from 'react-hook-form';
import type { BasemapProvider } from '../map/types';

interface BasemapFormProps {
    provider?: BasemapProvider | null;
    onSave: (data: any) => void;
    onCancel: () => void;
    isSaving: boolean;
}

export function BasemapForm({ provider, onSave, onCancel, isSaving }: BasemapFormProps) {
    const { register, handleSubmit, watch, formState: { errors } } = useForm({
        defaultValues: provider || {
            provider_type: 'XYZ',
            srid: 3857,
            license_type: 'OPEN_ODBL',
            is_enabled: false,
            is_default: false,
            requires_api_key: false,
            proxy_required: false,
            cache_ttl_seconds: 0,
            display_order: 100
        }
    });

    const isEnabled = watch('is_enabled');
    const licenseType = watch('license_type');

    return (
        <form onSubmit={handleSubmit(onSave)} className="card p-4 mb-4">
            <div className="row g-3">
                <div className="col-md-6">
                    <label className="form-label">Code *</label>
                    <input type="text" className={`form-control ${errors.code ? 'is-invalid' : ''}`} 
                        {...register('code', { required: 'Code is required' })} disabled={!!provider} />
                </div>
                <div className="col-md-6">
                    <label className="form-label">Name *</label>
                    <input type="text" className={`form-control ${errors.name ? 'is-invalid' : ''}`} 
                        {...register('name', { required: 'Name is required' })} />
                </div>
                
                <div className="col-md-6">
                    <label className="form-label">Provider Type *</label>
                    <select className="form-select" {...register('provider_type')}>
                        <option value="XYZ">XYZ Tiles</option>
                        <option value="TMS">TMS</option>
                        <option value="WMS">WMS</option>
                        <option value="WMTS">WMTS</option>
                        <option value="VECTOR_TILE">Vector Tiles</option>
                        <option value="LOCAL_ORTHOPHOTO">Local Orthophoto</option>
                    </select>
                </div>
                
                <div className="col-md-6">
                    <label className="form-label">URL Template</label>
                    <input type="text" className="form-control" {...register('url_template')} placeholder="https://{s}.tile.osm.org/{z}/{x}/{y}.png" />
                </div>

                <div className="col-md-12">
                    <label className="form-label">Attribution HTML {isEnabled ? '*' : ''}</label>
                    <input type="text" className={`form-control ${errors.attribution_html ? 'is-invalid' : ''}`} 
                        {...register('attribution_html', { required: isEnabled ? 'Attribution is required when enabled' : false })} />
                </div>

                <div className="col-md-6">
                    <label className="form-label">License Type *</label>
                    <select className="form-select" {...register('license_type')}>
                        <option value="OPEN_ODBL">Open ODbL</option>
                        <option value="COMMERCIAL_WEB">Commercial Web</option>
                        <option value="GOVERNMENT_GRANT">Government Grant</option>
                        <option value="ORGANIZATION_OWNED">Organization Owned</option>
                        <option value="UNLICENSED">Unlicensed</option>
                    </select>
                    {isEnabled && licenseType === 'UNLICENSED' && (
                        <div className="text-danger small mt-1">Cannot enable an unlicensed basemap.</div>
                    )}
                </div>
                
                <div className="col-md-6">
                    <label className="form-label">License Expiration</label>
                    <input type="date" className="form-control" {...register('license_expires_on')} />
                </div>

                <div className="col-md-4">
                    <div className="form-check mt-4">
                        <input type="checkbox" className="form-check-input" id="is_enabled" {...register('is_enabled')} />
                        <label className="form-check-label" htmlFor="is_enabled">Enabled</label>
                    </div>
                </div>
                <div className="col-md-4">
                    <div className="form-check mt-4">
                        <input type="checkbox" className="form-check-input" id="requires_api_key" {...register('requires_api_key')} />
                        <label className="form-check-label" htmlFor="requires_api_key">Requires API Key</label>
                    </div>
                </div>
                
                {watch('requires_api_key') && (
                    <div className="col-md-4">
                        <label className="form-label">API Key Env Variable Name</label>
                        <input type="text" className="form-control" {...register('api_key_env_name')} placeholder="e.g. GOOGLE_MAPS_KEY" />
                    </div>
                )}
            </div>
            
            <div className="d-flex gap-2 mt-4">
                <button type="submit" className="btn btn-primary" disabled={isSaving || (isEnabled && licenseType === 'UNLICENSED')}>
                    {isSaving ? 'Saving...' : 'Save Basemap'}
                </button>
                <button type="button" className="btn btn-secondary" onClick={onCancel} disabled={isSaving}>Cancel</button>
            </div>
        </form>
    );
}
