// @ts-nocheck

import { useForm } from 'react-hook-form';
import { zodResolver } from '@hookform/resolvers/zod';
import { z } from 'zod';
import type { Layer } from '../types';

const layerSchema = z.object({
    code: z.string().min(1, "Code is required").max(60),
    name: z.string().min(1, "Name is required").max(160),
    description: z.string().optional(),
    group_path: z.string().min(1, "Group path is required").max(200),
    geometry_type: z.enum(['POINT', 'MULTIPOINT', 'LINESTRING', 'MULTILINESTRING', 'POLYGON', 'MULTIPOLYGON', 'GEOMETRY']),
    srid: z.number().int().positive(),
    source: z.string().optional(),
    render_mode: z.enum(['geojson', 'mvt'])
});

type LayerFormData = z.infer<typeof layerSchema>;

interface Props {
    initialData?: Partial<Layer>;
    onSubmit: (data: LayerFormData) => void;
    isLoading?: boolean;
}

export function LayerMetadataForm({ initialData, onSubmit, isLoading }: Props) {
    const {
        register,
        handleSubmit,
        formState: { errors }
    } = useForm<LayerFormData>({
        resolver: zodResolver(layerSchema),
        defaultValues: {
            code: initialData?.code || '',
            name: initialData?.name || '',
            description: initialData?.description || '',
            group_path: initialData?.group_path || 'Custom',
            geometry_type: initialData?.geometry_type || 'POLYGON',
            srid: initialData?.srid || 4326,
            source: initialData?.source || '',
            render_mode: initialData?.render_mode || 'geojson'
        }
    });

    return (
        <form onSubmit={handleSubmit(onSubmit)} className="needs-validation">
            <div className="row g-3">
                <div className="col-md-6">
                    <label className="form-label">Code *</label>
                    <input 
                        type="text" 
                        className={`form-control ${errors.code ? 'is-invalid' : ''}`}
                        {...register('code')}
                        disabled={!!initialData?.id} // code is usually immutable after creation
                    />
                    {errors.code && <div className="invalid-feedback">{errors.code.message}</div>}
                </div>
                <div className="col-md-6">
                    <label className="form-label">Name *</label>
                    <input 
                        type="text" 
                        className={`form-control ${errors.name ? 'is-invalid' : ''}`}
                        {...register('name')}
                    />
                    {errors.name && <div className="invalid-feedback">{errors.name.message}</div>}
                </div>
                
                <div className="col-12">
                    <label className="form-label">Description</label>
                    <textarea 
                        className="form-control"
                        rows={3}
                        {...register('description')}
                    />
                </div>
                
                <div className="col-md-6">
                    <label className="form-label">Group Path *</label>
                    <input 
                        type="text" 
                        className={`form-control ${errors.group_path ? 'is-invalid' : ''}`}
                        {...register('group_path')}
                    />
                    {errors.group_path && <div className="invalid-feedback">{errors.group_path.message}</div>}
                </div>
                
                <div className="col-md-6">
                    <label className="form-label">Geometry Type *</label>
                    <select 
                        className={`form-select ${errors.geometry_type ? 'is-invalid' : ''}`}
                        {...register('geometry_type')}
                    >
                        <option value="POINT">Point</option>
                        <option value="MULTIPOINT">MultiPoint</option>
                        <option value="LINESTRING">LineString</option>
                        <option value="MULTILINESTRING">MultiLineString</option>
                        <option value="POLYGON">Polygon</option>
                        <option value="MULTIPOLYGON">MultiPolygon</option>
                        <option value="GEOMETRY">Geometry Collection</option>
                    </select>
                    {errors.geometry_type && <div className="invalid-feedback">{errors.geometry_type.message}</div>}
                </div>
                
                <div className="col-md-4">
                    <label className="form-label">SRID *</label>
                    <input 
                        type="number" 
                        className={`form-control ${errors.srid ? 'is-invalid' : ''}`}
                        {...register('srid', { valueAsNumber: true })}
                    />
                    {errors.srid && <div className="invalid-feedback">{errors.srid.message}</div>}
                </div>
                
                <div className="col-md-4">
                    <label className="form-label">Source</label>
                    <input 
                        type="text" 
                        className="form-control"
                        {...register('source')}
                    />
                </div>
                
                <div className="col-md-4">
                    <label className="form-label">Render Mode *</label>
                    <select 
                        className={`form-select ${errors.render_mode ? 'is-invalid' : ''}`}
                        {...register('render_mode')}
                    >
                        <option value="geojson">GeoJSON</option>
                        <option value="mvt">MVT (Vector Tiles)</option>
                    </select>
                    {errors.render_mode && <div className="invalid-feedback">{errors.render_mode.message}</div>}
                </div>
            </div>
            
            <div className="mt-4 text-end">
                <button type="submit" className="btn btn-primary" disabled={isLoading}>
                    {isLoading ? 'Saving...' : 'Save Layer Settings'}
                </button>
            </div>
        </form>
    );
}
