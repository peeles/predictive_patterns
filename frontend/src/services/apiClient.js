
import axios from 'axios'
import { useAuthStore } from '../stores/auth'
import { useRequestStore } from '../stores/request'
import { notifyError } from '../utils/notifications'

const apiClient = axios.create({
    baseURL: '/api/v1',
    timeout: 15000,
    validateStatus: s => s >= 200 && s < 300,
    withCredentials: true,
})

const MAX_ATTEMPTS = Number(import.meta.env.VITE_MAX_RETRY_ATTEMPTS || 3)
const RETRYABLE_METHODS = ['get', 'head']
let refreshPromise = null

function delay(attempt){
    const base = 300 * 2 ** attempt
    return new Promise(r => setTimeout(r, base + Math.random()*100))
}

// Request: attach token + request id
apiClient.interceptors.request.use((config) => {
    const auth = useAuthStore()
    const req = useRequestStore()

    config.headers = config.headers || {}
    const accessToken = auth?.token?.value ?? auth?.token
    if (accessToken) {
        config.headers.Authorization = `Bearer ${accessToken}`
    }

    const requestId = config.metadata?.requestId || req.issueRequestId()
    config.metadata = { ...(config.metadata||{}), attempt: config.metadata?.attempt ?? 0, requestId }
    config.headers['X-Request-Id'] = requestId

    if (config.metadata?.idempotencyKey) {
        config.headers['Idempotency-Key'] = config.metadata.idempotencyKey
    }
    req.recordRequestId(requestId)

    return config
})

// Response: 401 refresh, idempotent retries, nice errors
apiClient.interceptors.response.use(
    (res) => res,
    async (error) => {
        const { response, config } = error
        if (!config) return Promise.reject(error)

        const auth = useAuthStore()

        // 401 → refresh once
        if (response?.status === 401 && !config.__isRetryRequest) {
            const url = config.url || ''
            const isRefreshRequest = typeof url === 'string' && url.includes('/auth/refresh')

            if (isRefreshRequest || !auth?.canRefresh) {
                return Promise.reject(error)
            }

            if (!refreshPromise) {
                refreshPromise = auth.refresh().finally(() => { refreshPromise = null })
            }
            const newToken = await refreshPromise
            if (newToken) {
                config.__isRetryRequest = true
                config.headers = config.headers || {}
                config.headers.Authorization = `Bearer ${newToken}`
                return apiClient(config)
            }
        }

        // Validation forwarding
        const isValidation = response?.status === 422 && response?.data?.errors && typeof response.data.errors === 'object'
        if (isValidation) error.validationErrors = response.data.errors

        // Offline / timeout
        if (!response) {
            error.message = error.code === 'ECONNABORTED'
                ? 'Request timed out. Please check your connection and try again.'
                : 'Network error. Please check your connection and try again.'
        }

        // Idempotent retry for GET/HEAD on network errors
        const attempt = config.metadata?.attempt ?? 0
        const method = (config.method || 'get').toLowerCase()
        if (!response && RETRYABLE_METHODS.includes(method) && attempt < MAX_ATTEMPTS - 1) {
            config.metadata = { ...config.metadata, attempt: attempt + 1 }
            await delay(attempt)
            return apiClient(config)
        }

        if (!config.__notified && !isValidation) {
            config.__notified = true
            if (!error.requestId && config.metadata?.requestId) {
                error.requestId = config.metadata.requestId
            }
            notifyError(error)
        }

        return Promise.reject(error)
    }
)

export default apiClient
