// src/api/http.ts
import axios from 'axios';
import { useAuthStore } from '../stores/auth';

const http = axios.create({
    baseURL: import.meta.env.VITE_API_BASE_URL
});

http.interceptors.request.use((config) => {
    const auth = useAuthStore();
    if (auth.token) {
        config.headers = config.headers || {};
        config.headers.Authorization = `Bearer ${auth.token}`;
    }
    return config;
});

http.interceptors.response.use(
    (res) => res,
    async (err) => {
        const status = err?.response?.status;
        if (status === 401) {
            const auth = useAuthStore();
            auth.logout(true); // silent logout
            // optional: redirect to /login
            window.location.href = '/login';
        }
        return Promise.reject(err);
    }
);

export default http;
