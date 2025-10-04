import { defineStore } from 'pinia'
import { computed, ref } from 'vue'
import api from '../services/apiClient'
import {ensureCsrfCookie} from "../services/csrf.js";

export const useAuthStore = defineStore('auth', () => {
    const token = ref(null)
    const user = ref(null)
    const hasRefreshSession = ref(typeof window !== 'undefined')
    const hasAttemptedRestore = ref(false)
    let restorePromise = null

    function clearState() {
        token.value = null
        user.value = null
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

        const response = await api.post('/auth/login', { email, password })
        const payload = extractAuthPayload(response)
        token.value = payload.accessToken ?? null
        user.value = payload.user ?? null
        hasRefreshSession.value = true
        hasAttemptedRestore.value = true
        return user.value
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
            hasRefreshSession.value = true
            hasAttemptedRestore.value = true
            return token.value
        } catch {
            hasRefreshSession.value = false
            clearState()
            hasAttemptedRestore.value = true
            return null
        }
    }

    async function logout() {
        try {
            await api.post('/auth/logout')
        } catch {
            // ignore logout errors
        }
        hasRefreshSession.value = false
        hasAttemptedRestore.value = true
        clearState()
    }

    async function restoreSession() {
        if (hasAttemptedRestore.value) {
            return token.value
        }

        if (!restorePromise) {
            restorePromise = (hasRefreshSession.value ? refresh() : Promise.resolve(null)).finally(() => {
                hasAttemptedRestore.value = true
                restorePromise = null
            })
        }

        return restorePromise
    }

    const isAuthenticated = computed(() => Boolean(token.value))
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
    }
})
