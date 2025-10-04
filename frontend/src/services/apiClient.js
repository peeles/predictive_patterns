import axios from 'axios'
import { useAuthStore } from '../stores/auth'
import { useRequestStore } from '../stores/request'
import { notifyError } from '../utils/notifications'
import { ensureCsrfCookie } from './csrf'
import { getCookie } from '../utils/cookies'

// Determine the API base URL, using VITE_API_URL if set, else default to '/api/v1'
const apiBaseUrl = (() => {
    const envUrl = import.meta.env.VITE_API_URL;
    if (!envUrl) {
        return '/api/v1';
    }

    const normalized = envUrl.replace(/\/+$/, '');
    return `${normalized}/api/v1`;
})();

const apiClient = axios.create({
    baseURL: apiBaseUrl,
    timeout: 15000,
    validateStatus: s => s >= 200 && s < 300,
    withCredentials: true,
    xsrfCookieName: 'XSRF-TOKEN',
    xsrfHeaderName: 'X-XSRF-TOKEN',
})

apiClient.defaults.headers.common['X-Requested-With'] = 'XMLHttpRequest'

const MAX_ATTEMPTS = Number(import.meta.env.VITE_MAX_RETRY_ATTEMPTS || 3)
const RETRYABLE_METHODS = ['get', 'head']
let refreshPromise = null

const SAFE_METHODS = ['get', 'head', 'options', 'trace']
const CSRF_STATUS_CODES = new Set([419])
const CSRF_ERROR_CODES = new Set(['CSRF token mismatch.', 'Page Expired', 'CSRF mismatch'])

function delay(attempt){
    const base = 300 * 2 ** attempt
    return new Promise(r => setTimeout(r, base + Math.random()*100))
}

async function bootstrapCsrf(config) {
    const method = (config.method || 'get').toLowerCase()
    const shouldBootstrap = !SAFE_METHODS.includes(method) && config.withCredentials !== false
    if (!shouldBootstrap || config.__skipCsrfBootstrap) {
        return
    }

    await ensureCsrfCookie()
}

function applyXsrfHeader(config) {
    if (config.withCredentials === false) {
        return
    }

    const headerName = apiClient.defaults.xsrfHeaderName || 'X-XSRF-TOKEN'
    if (config.headers?.[headerName]) {
        return
    }

    const cookieName = apiClient.defaults.xsrfCookieName || 'XSRF-TOKEN'
    const rawToken = getCookie(cookieName)
    if (!rawToken) {
        return
    }

    let decodedToken
    try {
        decodedToken = decodeURIComponent(rawToken)
    } catch {
        decodedToken = rawToken
    }

    config.headers = config.headers || {}
    config.headers[headerName] = decodedToken
}

// Request: ensure CSRF, attach token + request id
apiClient.interceptors.request.use(async (config) => {
    await bootstrapCsrf(config);

    const auth = useAuthStore()
    const req = useRequestStore()

    config.headers = config.headers || {}
    applyXsrfHeader(config)
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

        const csrfMismatch = (() => {
            if (!response) {
                return false
            }

            if (CSRF_STATUS_CODES.has(response.status)) {
                return true
            }

            const message = response?.data?.message
            return typeof message === 'string' && CSRF_ERROR_CODES.has(message)
        })()

        if (csrfMismatch && !config.__csrfRetryAttempted) {
            try {
                await ensureCsrfCookie({ force: true, reason: 'mismatch' })
            } catch (csrfError) {
                console.warn('Unable to refresh CSRF cookie', csrfError)
            }

            config.__csrfRetryAttempted = true
            return apiClient(config)
        }

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
