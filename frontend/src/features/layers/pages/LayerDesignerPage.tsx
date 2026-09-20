
import { useParams, useNavigate } from 'react-router-dom';
import { LayerDesigner } from '../components/LayerDesigner';

export function LayerDesignerPage() {
    const { id } = useParams<{ id: string }>();
    const navigate = useNavigate();
    
    const layerId = id && id !== 'new' ? parseInt(id, 10) : undefined;

    return (
        <div className="container py-4">
            <div className="mb-3">
                <button className="btn btn-link px-0 text-decoration-none" onClick={() => navigate('/admin/layers')}>
                    &larr; Back to Layers
                </button>
            </div>
            
            <LayerDesigner 
                initialLayerId={layerId} 
                onSaved={(newId) => {
                    if (!layerId) {
                        navigate(`/admin/layers/${newId}`, { replace: true });
                    }
                }}
            />
        </div>
    );
}
