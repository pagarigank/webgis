import axios from 'axios';

// Create a single axios instance for the entire application (ADR-13)
const apiClient = axios.create({
  baseURL: import.meta.env.VITE_API_BASE_URL || '/api/v1',
  headers: {
    'Content-Type': 'application/json',
  },
  withCredentials: true, // For refresh cookies later
});

// Response interceptor to handle envelope unwrapping and global errors
apiClient.interceptors.response.use(
  (response) => {
    // Return the envelope data directly if success is true
    if (response.data && response.data.success) {
      return response.data.data;
    }
    return response.data;
  },
  (error) => {
    // If the server returned an envelope error, attach it to the thrown error
    if (error.response && error.response.data && !error.response.data.success) {
      error.serverError = error.response.data.error;
    }
    return Promise.reject(error);
  }
);

export default apiClient;
