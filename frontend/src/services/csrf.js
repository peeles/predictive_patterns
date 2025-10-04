import { getCookie } from '../utils/cookies'

let csrfPromise = null

function buildCsrfEndpoint() {
    const path = '/sanctum/csrf-cookie'
    const base = import.meta.env.VITE_API_URL

    if (!base) {
        return path
    }

    const originFallback = typeof window !== 'undefined' && window.location ? window.location.origin : 'http://localhost'

    try {
        const url = new URL(base, originFallback)
        return `${url.origin}${path}`
    } catch {
        return path
    }
}

export async function ensureCsrfCookie() {
    if (typeof document === 'undefined') {
        return false
    }

    if (getCookie('XSRF-TOKEN')) {
        return true
    }

    if (!csrfPromise) {
        const endpoint = buildCsrfEndpoint()
        csrfPromise = fetch(endpoint, {
            method: 'GET',
            credentials: 'include',
            headers: {
                'X-Requested-With': 'XMLHttpRequest',
                Accept: 'application/json',
            },
        }).then((response) => {
            if (!response.ok) {
                throw new Error('Unable to establish CSRF protection')
            }
            return true
        }).finally(() => {
            csrfPromise = null
        })
    }

    return csrfPromise
}
