
import { useQuery } from '@tanstack/react-query';
import { Link } from 'react-router-dom';
import { layerApi } from '../api/layerApi';

export function LayerListPage() {
    const { data: layers = [], isLoading } = useQuery({
        queryKey: ['layers'],
        queryFn: layerApi.getAll
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
                                    <th>Actions</th>
                                </tr>
                            </thead>
                            <tbody>
                                {layers.length === 0 && (
                                    <tr>
                                        <td colSpan={6} className="text-center py-4 text-muted">No layers found.</td>
                                    </tr>
                                )}
                                {layers.map((layer: any) => (
                                    <tr key={layer.id}>
                                        <td>{layer.id}</td>
                                        <td><code>{layer.code}</code></td>
                                        <td>{layer.name}</td>
                                        <td>{layer.group_path}</td>
                                        <td><span className="badge bg-secondary">{layer.geometry_type}</span></td>
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
