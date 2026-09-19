import React from 'react';
import { useQuery } from '@tanstack/react-query';
import apiClient from '../lib/apiClient';

interface HealthResponse {
  status: string;
  request_id: string;
}

const fetchHealth = async (): Promise<HealthResponse> => {
  return apiClient.get('/health');
};

const HealthView: React.FC = () => {
  const { data, isLoading, error } = useQuery({
    queryKey: ['health'],
    queryFn: fetchHealth,
  });

  return (
    <div style={{ padding: '2rem', fontFamily: 'system-ui, sans-serif' }}>
      <h1>System Status</h1>
      
      {isLoading && <p>Checking health status...</p>}
      
      {error && (
        <div style={{ color: 'red', border: '1px solid red', padding: '1rem' }}>
          <h2>API Unreachable</h2>
          <pre>{error instanceof Error ? error.message : 'Unknown error'}</pre>
        </div>
      )}

      {data && (
        <div style={{ color: 'green', border: '1px solid green', padding: '1rem' }}>
          <h2>API is Online</h2>
          <p><strong>Status:</strong> {data.status}</p>
          <p><strong>Request ID:</strong> {data.request_id}</p>
        </div>
      )}
    </div>
  );
};

export default HealthView;
