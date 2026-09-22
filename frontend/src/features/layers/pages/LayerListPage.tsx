
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { Link } from 'react-router-dom';
import { layerApi } from '../api/layerApi';

export function LayerListPage() {
    const queryClient = useQueryClient();
    const { data: layers = [], isLoading } = useQuery({
        queryKey: ['layers'],
        queryFn: layerApi.getAll
    });

    const toggleHidden = useMutation({
        mutationFn: (layer: any) => layerApi.update(layer.id, { is_hidden: !layer.is_hidden, version: layer.version }),
        onSuccess: () => queryClient.invalidateQueries({ queryKey: ['layers'] })
    });

    return (
        <div className="container py-4">
            <div className="d-flex justify-content-between align-items-center mb-4">
                <h2>GIS Layers</h2>
                <Link to="/admin/layers/new" className="btn btn-primary">Create Layer</Link>
            </div>
            
            {isLoading ? (
                <div>Loading layers...</div>
            ) : (
                <div className="card shadow-sm">
                    <div className="table-responsive">
                        <table className="table table-hover mb-0">
                            <thead className="table-light">
                                <tr>
                                    <th>ID</th>
                                    <th>Code</th>
                                    <th>Name</th>
                                    <th>Group</th>
                                    <th>Geometry</th>
                                    <th className="text-center">Hide</th>
                                    <th>Actions</th>
                                </tr>
                            </thead>
                            <tbody>
                                {layers.length === 0 && (
                                    <tr>
                                        <td colSpan={7} className="text-center py-4 text-muted">No layers found.</td>
                                    </tr>
                                )}
                                {layers.map((layer: any) => (
                                    <tr key={layer.id}>
                                        <td>{layer.id}</td>
                                        <td><code>{layer.code}</code></td>
                                        <td>
                                            {layer.name}
                                            {layer.is_hidden && <span className="badge bg-secondary ms-2">hidden</span>}
                                        </td>
                                        <td>{layer.group_path}</td>
                                        <td><span className="badge bg-secondary">{layer.geometry_type}</span></td>
                                        <td className="text-center">
                                            <div className="form-check form-switch d-inline-block">
                                                <input
                                                    className="form-check-input"
                                                    type="checkbox"
                                                    data-testid={`layer-hide-${layer.id}`}
                                                    checked={!!layer.is_hidden}
                                                    disabled={toggleHidden.isPending}
                                                    onChange={() => toggleHidden.mutate(layer)}
                                                />
                                            </div>
                                        </td>
                                        <td>
                                            <Link to={`/admin/layers/${layer.id}`} className="btn btn-sm btn-outline-primary">Designer</Link>
                                        </td>
                                    </tr>
                                ))}
                            </tbody>
                        </table>
                    </div>
                </div>
            )}
        </div>
    );
}
