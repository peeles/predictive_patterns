import { flushPromises, mount } from '@vue/test-utils'
import { describe, expect, it, beforeEach, vi } from 'vitest'
import AuthView from '../../src/views/AuthView.vue'

const push = vi.fn()
const loginMock = vi.fn()

vi.mock('vue-router', () => ({
    useRouter: () => ({
        push
    })
}))

vi.mock('../../stores/auth', () => ({
    useAuthStore: () => ({
        login: loginMock
    })
}))

describe('AuthView', () => {
    beforeEach(() => {
        loginMock.mockReset()
        push.mockReset()
    })

    it('shows an accessible error when login fails', async () => {
        loginMock.mockResolvedValue({ success: false, message: 'Invalid credentials' })

        const wrapper = mount(AuthView)

        await wrapper.find('input[name="email"]').setValue('user@example.com')
        await wrapper.find('input[name="password"]').setValue('secret')
        await wrapper.find('form').trigger('submit.prevent')
        await flushPromises()

        expect(loginMock).toHaveBeenCalledWith({ email: 'user@example.com', password: 'secret' })
        expect(wrapper.get('[role="alert"]').text()).toContain('Invalid credentials')
        expect(push).not.toHaveBeenCalled()
    })
})
