import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { authNavigationGuard } from '../../src/router/index'
import { useAuthStore } from '../../src/stores/auth'
import { notifyError } from '../../src/utils/notifications'

vi.mock('../../stores/auth', () => ({
    useAuthStore: vi.fn(),
}))

vi.mock('../../utils/notifications', () => ({
    notifyError: vi.fn(),
}))

function createAuthStore(overrides = {}) {
    return {
        hasAttemptedSessionRestore: true,
        restoreSession: vi.fn().mockResolvedValue(undefined),
        isAuthenticated: true,
        canRefresh: false,
        isAdmin: true,
        ...overrides,
    }
}

describe('authNavigationGuard', () => {
    beforeEach(() => {
        vi.clearAllMocks()
    })

    afterEach(() => {
        vi.clearAllMocks()
    })

    it('restores a session when navigation occurs before hydration', async () => {
        const authStore = createAuthStore({ hasAttemptedSessionRestore: false })
        useAuthStore.mockReturnValue(authStore)

        const result = await authNavigationGuard({ meta: {}, fullPath: '/', path: '/' })

        expect(authStore.restoreSession).toHaveBeenCalledTimes(1)
        expect(authStore.restoreSession.mock.calls[0]).toEqual([])
        expect(result).toBe(true)
    })

    it('attempts a forced restore when a refresh token is available', async () => {
        const authStore = createAuthStore({
            hasAttemptedSessionRestore: true,
            isAuthenticated: false,
            canRefresh: true,
        })
        useAuthStore.mockReturnValue(authStore)

        const result = await authNavigationGuard({
            meta: { requiresAuth: true },
            fullPath: '/dashboard',
            path: '/dashboard',
        })

        expect(authStore.restoreSession).toHaveBeenCalledTimes(1)
        expect(authStore.restoreSession).toHaveBeenCalledWith({ force: true })
        expect(result).toEqual({ name: 'login', query: { redirect: '/dashboard' } })
    })

    it('prevents access to admin routes without the proper role when meta requires admin', async () => {
        const authStore = createAuthStore({ isAdmin: false })
        useAuthStore.mockReturnValue(authStore)

        const result = await authNavigationGuard({
            meta: { requiresAuth: true, requiresAdmin: true },
            fullPath: '/admin/models',
            path: '/admin/models',
        })

        expect(notifyError).toHaveBeenCalledWith('Admin privileges are required to access that area.')
        expect(result).toEqual({ name: 'dashboard' })
    })

    it('prevents access to legacy admin paths without the proper role', async () => {
        const authStore = createAuthStore({ isAdmin: false })
        useAuthStore.mockReturnValue(authStore)

        const result = await authNavigationGuard({
            meta: { requiresAuth: true },
            fullPath: '/admin/anything',
            path: '/admin/anything',
        })

        expect(notifyError).toHaveBeenCalledWith('Admin privileges are required to access that area.')
        expect(result).toEqual({ name: 'dashboard' })
    })

    it('allows navigation when authentication and admin checks pass', async () => {
        const authStore = createAuthStore()
        useAuthStore.mockReturnValue(authStore)

        const result = await authNavigationGuard({
            meta: { requiresAuth: true, requiresAdmin: true },
            fullPath: '/admin/models',
            path: '/admin/models',
        })

        expect(authStore.restoreSession).not.toHaveBeenCalled()
        expect(notifyError).not.toHaveBeenCalled()
        expect(result).toBe(true)
    })
})
