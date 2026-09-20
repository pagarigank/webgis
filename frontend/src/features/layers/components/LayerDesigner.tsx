import { useState } from 'react';
import { useQuery, useMutation, useQueryClient } from '@tanstack/react-query';
import { layerApi } from '../api/layerApi';
import { LayerMetadataForm } from './LayerMetadataForm';
import { FieldDesigner } from './FieldDesigner';
import { StyleDesigner } from './StyleDesigner';
import { LayerPermissionMatrix } from './LayerPermissionMatrix';

interface Props {
    initialLayerId?: number;
    onSaved?: (id: number) => void;
}

export function LayerDesigner({ initialLayerId, onSaved }: Props) {
    const queryClient = useQueryClient();
    const [layerId, setLayerId] = useState<number | undefined>(initialLayerId);

    const { data: layer, isLoading } = useQuery({
        queryKey: ['layer', layerId],
        queryFn: () => layerApi.getById(layerId!),
        enabled: !!layerId
    });

    const createMutation = useMutation({
        mutationFn: layerApi.create,
        onSuccess: (data) => {
            setLayerId(data.id);
            if (onSaved) onSaved(data.id);
            queryClient.invalidateQueries({ queryKey: ['layers'] });
        }
    });

    const updateMutation = useMutation({
        mutationFn: (data: any) => layerApi.update(layerId!, data),
        onSuccess: () => {
            queryClient.invalidateQueries({ queryKey: ['layer', layerId] });
            queryClient.invalidateQueries({ queryKey: ['layers'] });
        }
    });

    if (layerId && isLoading) {
        return <div>Loading layer details...</div>;
    }

    return (
        <div className="layer-designer">
            <h4 className="mb-4">{layerId ? `Edit Layer: ${layer?.name}` : 'Create New Layer'}</h4>
            
            <div className="card shadow-sm mb-4">
                <div className="card-header bg-white">
                    <h5 className="mb-0">Basic Metadata</h5>
                </div>
                <div className="card-body">
                    <LayerMetadataForm 
                        initialData={layer}
                        onSubmit={(data) => {
                            if (layerId) {
                                updateMutation.mutate(data);
                            } else {
                                createMutation.mutate(data);
                            }
                        }}
                        isLoading={createMutation.isPending || updateMutation.isPending}
                    />
                </div>
            </div>

            {layerId ? (
                <>
                    <FieldDesigner layerId={layerId} />
                    <StyleDesigner layerId={layerId} />
                    <LayerPermissionMatrix layerId={layerId} />
                </>
            ) : (
                <div className="alert alert-info">
                    Save the basic metadata first to configure fields, styles, and permissions.
                </div>
            )}
        </div>
    );
}
