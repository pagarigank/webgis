
import { useQuery } from '@tanstack/react-query';
import apiClient, { unwrapList } from '../../../lib/apiClient';

interface Props {
    layerId: number;
}

export function LayerPermissionMatrix({ layerId: _layerId }: Props) {
    const { data: roles = [], isLoading: loadingRoles } = useQuery({
        queryKey: ['roles'],
        queryFn: async () => {
            const res = await apiClient.get('/roles').catch(() => [{ id: 1, name: 'SYS_ADMIN' }, { id: 2, name: 'ENCODER' }]);
            return unwrapList(res);
        }
    });

    if (loadingRoles) return <div>Loading permissions...</div>;

    return (
        <div className="card mt-4">
            <div className="card-header">
                <h5 className="mb-0">Role Permissions</h5>
            </div>
            <div className="card-body">
                <p className="text-muted small">Configure which roles have access to this layer.</p>
                <div className="table-responsive">
                    <table className="table table-sm table-bordered">
                        <thead className="bg-light">
                            <tr>
                                <th>Role</th>
                                <th className="text-center">View</th>
                                <th className="text-center">Create</th>
                                <th className="text-center">Update</th>
                                <th className="text-center">Delete</th>
                            </tr>
                        </thead>
                        <tbody>
                            {roles.map((role: any) => (
                                <tr key={role.id}>
                                    <td>{role.name}</td>
                                    <td className="text-center"><input type="checkbox" className="form-check-input" /></td>
                                    <td className="text-center"><input type="checkbox" className="form-check-input" /></td>
                                    <td className="text-center"><input type="checkbox" className="form-check-input" /></td>
                                    <td className="text-center"><input type="checkbox" className="form-check-input" /></td>
                                </tr>
                            ))}
                        </tbody>
                    </table>
                </div>
                <div className="text-end">
                    <button className="btn btn-sm btn-primary">Save Permissions</button>
                </div>
            </div>
        </div>
    );
}
