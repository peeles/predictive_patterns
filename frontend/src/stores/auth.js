import { defineStore } from 'pinia'
import { computed, ref } from 'vue'
import api from '../services/apiClient'
import { ensureCsrfCookie } from '../services/csrf'

export const useAuthStore = defineStore('auth', () => {
    const token = ref(null)
    const user = ref(null)
    const hasRefreshSession = ref(false)
    const hasAttemptedRestore = ref(false)
    let restorePromise = null

    function setHasRefreshSession(value) {
        hasRefreshSession.value = Boolean(value)
    }

    function clearState() {
        token.value = null
        user.value = null
        hasRefreshSession.value = false
    }

    function extractAuthPayload(rawResponse) {
        const body = rawResponse?.data

        if (body && typeof body === 'object') {
            if ('accessToken' in body || 'user' in body) {
                return body
            }

            if (body?.data && typeof body.data === 'object') {
                return body.data
            }
        }

        return {}
    }

    async function login(credentials) {
        const { email, password } = credentials ?? {}
        await ensureCsrfCookie()

        try {
            const response = await api.post('/auth/login', { email, password })
            const payload = extractAuthPayload(response)

            token.value = payload.accessToken ?? null
            user.value = payload.user ?? null
            setHasRefreshSession(true);
            hasAttemptedRestore.value = true;
            await ensureCsrfCookie({ force: true, reason: 'login' });

            return user.value;
        } catch (error) {
            setHasRefreshSession(false)
        }
    }

    async function refresh(options = {}) {
        const { force = false } = options

        if (!force && !hasRefreshSession.value) {
            hasAttemptedRestore.value = true
            return null
        }

        try {
            const response = await api.post('/auth/refresh')
            const payload = extractAuthPayload(response)

            token.value = payload.accessToken ?? null
            user.value = payload.user ?? null
            setHasRefreshSession(true)
            hasAttemptedRestore.value = true

            return token.value
        } catch {
            setHasRefreshSession(false)
            clearState()
            hasAttemptedRestore.value = true
        }
    }

    async function logout() {
        try {
            await ensureCsrfCookie({ force: true, reason: 'logout' })
        } catch (error) {
            console.warn('Unable to refresh CSRF cookie before logout.', error)
        }

        try {
            await api.post('/auth/logout')
        } catch {
            // ignore logout errors
        }
        setHasRefreshSession(false);
        hasAttemptedRestore.value = true;
        clearState();
    }

    async function restoreSession(options = {}) {
        const { force = false } = options

        if (!force && hasAttemptedRestore.value) {
            return token.value
        }

        if (!restorePromise) {
            const shouldRefresh = force || hasRefreshSession.value
            restorePromise = (shouldRefresh ? refresh({ force: shouldRefresh && force }) : Promise.resolve(null)).finally(() => {
                hasAttemptedRestore.value = true
                restorePromise = null
            })
        }

        return restorePromise
    }

    const isAuthenticated = computed(() => Boolean(token.value))
    const isSessionHydrated = computed(() => hasAttemptedRestore.value || Boolean(token.value))
    const role = computed(() => user.value?.role ?? '')
    const isAdmin = computed(() => role.value === 'admin')
    const canRefresh = computed(() => hasRefreshSession.value)
    const hasAttemptedSessionRestore = computed(() => hasAttemptedRestore.value)

    return {
        token,
        user,
        isAuthenticated,
        role,
        isAdmin,
        login,
        refresh,
        logout,
        restoreSession,
        canRefresh,
        hasAttemptedSessionRestore,
        isSessionHydrated,
        setHasRefreshSession,
        hasRefreshSession
    }
})
