import axios from 'axios';

/**
 * Browser API client. Base URL is same-origin by default; set VITE_API_BASE_URL when the API is hosted separately.
 */
export function createApiClient(token) {
    const baseURL = typeof import.meta.env.VITE_API_BASE_URL === 'string'
        ? import.meta.env.VITE_API_BASE_URL.replace(/\/$/, '')
        : '';

    const client = axios.create({
        baseURL,
        headers: {
            Accept: 'application/json',
            'X-Requested-With': 'XMLHttpRequest',
        },
        validateStatus: () => true,
    });

    client.interceptors.request.use((config) => {
        if (token) {
            config.headers.Authorization = `Bearer ${token}`;
        }
        return config;
    });

    return client;
}

/**
 * @param {import('axios').AxiosInstance} client
 * @param {{ email: string, password: string, device_name: string }} body
 */
export async function login(client, body) {
    const res = await client.post('/api/auth/token', body);
    return res;
}

/**
 * @param {import('axios').AxiosInstance} client
 */
export async function fetchCurrentUser(client) {
    const res = await client.get('/api/user');
    return res;
}

/**
 * @param {import('axios').AxiosInstance} client
 */
export async function revokeToken(client) {
    return client.delete('/api/auth/token');
}

/**
 * @param {import('axios').AxiosInstance} client
 * @param {{ message: string, conversation_id?: string|null }} body
 * @param {string|null} idempotencyKey
 */
export async function sendAssistantMessage(client, body, idempotencyKey) {
    const headers = {};
    if (idempotencyKey) {
        headers['Idempotency-Key'] = idempotencyKey;
    }
    return client.post('/api/assistant', body, { headers });
}

/**
 * @param {import('axios').AxiosInstance} client
 * @param {string} proposalId
 * @param {string} conversationKey
 */
export async function fetchProposal(client, proposalId, conversationKey) {
    const q = new URLSearchParams({ conversation_key: conversationKey });
    return client.get(`/api/purchase-order-proposals/${proposalId}?${q.toString()}`);
}

/**
 * @param {import('axios').AxiosInstance} client
 * @param {string} proposalId
 * @param {string} conversationKey
 * @param {string} idempotencyKey
 */
export async function confirmProposal(client, proposalId, conversationKey, idempotencyKey) {
    const q = new URLSearchParams({ conversation_key: conversationKey });
    const headers = { 'Idempotency-Key': idempotencyKey };
    return client.post(`/api/purchase-order-proposals/${proposalId}/confirm?${q.toString()}`, {}, { headers });
}

/**
 * @param {import('axios').AxiosInstance} client
 * @param {string} proposalId
 * @param {string} conversationKey
 */
export async function cancelProposal(client, proposalId, conversationKey) {
    const q = new URLSearchParams({ conversation_key: conversationKey });
    return client.delete(`/api/purchase-order-proposals/${proposalId}?${q.toString()}`);
}
