import { vi } from 'vitest'

vi.mock('../../utils/notifications', () => ({
    notifyError: vi.fn(),
}))

vi.mock('../../services/apiClient', () => ({
    __esModule: true,
    default: {
        post: vi.fn(),
    },
}))

import { beforeEach, describe, expect, it } from 'vitest'
import { createPinia, setActivePinia } from 'pinia'
import { persistPlugin } from '../../stores/plugins/persist'
import { useAuthStore } from '../../stores/auth'
import apiClient from '../../services/apiClient'
import { authNavigationGuard } from '../index'

describe('authNavigationGuard', () => {
    beforeEach(() => {
        vi.clearAllMocks()
        window.localStorage.clear()
        window.localStorage.setItem(
            'predictive-patterns:auth',
            JSON.stringify({
                token: 'persisted-token',
                user: { id: 1, role: 'user' },
                hasRefreshSession: true,
            })
        )

        const pinia = createPinia()
        pinia.use(persistPlugin)
        setActivePinia(pinia)
    })

    it('keeps dashboard accessible after session restore', async () => {
        const auth = useAuthStore()
        expect(auth.token).toBe('persisted-token')

        apiClient.post.mockResolvedValueOnce({
            data: {
                accessToken: 'refreshed-token',
                user: { id: 1, role: 'user' },
            },
        })

        const result = await authNavigationGuard({
            meta: { requiresAuth: true },
            fullPath: '/dashboard',
            path: '/dashboard',
        })

        expect(result).toBe(true)
        expect(apiClient.post).toHaveBeenCalledWith('/auth/refresh')
        expect(auth.token).toBe('refreshed-token')
        expect(auth.isAuthenticated).toBe(true)
    })
})
