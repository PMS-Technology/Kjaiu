import axios from 'axios';

// The administrator API lives under the panel path. Nothing injects
// window.kjaiuAdmin today, so the literal below is what actually applies; it
// must stay in step with `kjaiu.admin_path` (KJAIU_ADMIN_PATH).
const baseURL = (window.kjaiuAdmin && window.kjaiuAdmin.apiBase) || '/admin';

export const client = axios.create({
    baseURL,
    timeout: 120000,
    withCredentials: true,
    headers: { 'X-Requested-With': 'XMLHttpRequest' },
});

/**
 * Unwrap the platform envelope and surface `msg` as a rejected error so
 * callers can simply try/catch.
 */
client.interceptors.response.use(
    (response) => {
        const payload = response.data;

        if (payload && typeof payload === 'object' && 'status' in payload) {
            if (Number(payload.status) === 200 || Number(payload.status) === 1001) {
                return payload;
            }

            if (Number(payload.status) === 401) {
                window.location.href = `${baseURL}/login`;
            }

            return Promise.reject(new Error(payload.msg || '操作失败'));
        }

        return payload;
    },
    (error) => {
        const status = error.response && error.response.status;

        if (status === 401) {
            window.location.href = `${baseURL}/login`;
        }

        return Promise.reject(new Error((error.response && error.response.data && error.response.data.msg) || error.message || '请求失败'));
    },
);

export default client;
