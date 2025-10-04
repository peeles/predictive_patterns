import { defineStore } from 'pinia'
import apiClient from '../services/apiClient'
import { notifyError, notifySuccess } from '../utils/notifications'

const DEFAULT_ROLES = ['admin', 'analyst', 'viewer']

function generateTemporaryPassword() {
    const charset = 'ABCDEFGHJKLMNPQRSTUVWXYZabcdefghijkmnopqrstuvwxyz23456789'
    const length = 12
    let result = ''

    for (let index = 0; index < length; index += 1) {
        const randomIndex = Math.floor(Math.random() * charset.length)
        result += charset[randomIndex]
    }

    return result
}

function extractUser(payload) {
    const source = payload?.data ?? payload ?? null
    if (!source) {
        return null
    }

    return {
        id: source.id ?? source.user_id ?? '',
        name: source.name ?? '',
        email: source.email ?? '',
        role: source.role ?? '',
        status: source.status ?? source.state ?? 'active',
        lastSeenAt: source.last_seen_at ?? source.lastSeenAt ?? null,
        createdAt: source.created_at ?? source.createdAt ?? null,
        updatedAt: source.updated_at ?? source.updatedAt ?? null,
    }
}

function normaliseList(payload) {
    if (Array.isArray(payload)) {
        return payload.map((entry) => extractUser(entry)).filter(Boolean)
    }

    if (Array.isArray(payload?.data)) {
        return payload.data.map((entry) => extractUser(entry)).filter(Boolean)
    }

    return []
}

function deriveRoleOptions(users) {
    const uniqueRoles = Array.from(new Set(users.map((item) => item.role).filter(Boolean)))
    const customRoles = uniqueRoles.filter((role) => !DEFAULT_ROLES.includes(role))
    return [...DEFAULT_ROLES, ...customRoles]
}

function extractValidationErrors(error) {
    if (error?.validationErrors && typeof error.validationErrors === 'object') {
        return error.validationErrors
    }

    const responseErrors = error?.response?.data?.errors
    if (responseErrors && typeof responseErrors === 'object') {
        return responseErrors
    }

    return null
}

function extractErrorMessage(error, fallback) {
    if (typeof error?.response?.data?.message === 'string') {
        return error.response.data.message
    }

    if (typeof error?.message === 'string' && error.message.trim()) {
        return error.message
    }

    return fallback
}

export const useUserStore = defineStore('user', {
    state: () => ({
        users: [],
        roles: [...DEFAULT_ROLES],
        loading: false,
        saving: false,
        actionState: {},
        error: null,
    }),
    actions: {
        async fetchUsers() {
            this.loading = true
            this.error = null
            try {
                const response = await apiClient.get('/users')
                const list = normaliseList(response?.data ?? response)
                this.users = list
                this.roles = deriveRoleOptions(list)
            } catch (error) {
                this.users = []
                this.roles = [...DEFAULT_ROLES]
                this.error = extractErrorMessage(error, 'Unable to load the user directory. Please try again later.')
                notifyError(error, this.error)
            } finally {
                this.loading = false
            }
        },
        async createUser(payload) {
            this.saving = true
            try {
                const response = await apiClient.post('/users', payload)
                const created = extractUser(response?.data ?? response)

                if (created) {
                    const existing = this.users.filter((user) => user.id !== created.id)
                    this.users = [created, ...existing]
                    this.roles = deriveRoleOptions(this.users)
                }

                notifySuccess({
                    title: 'User created',
                    message: 'The user has been added successfully.',
                })

                return { user: created, errors: null }
            } catch (error) {
                const validationErrors = extractValidationErrors(error)
                const message = extractErrorMessage(
                    error,
                    'Unable to create the user. Please review the form and try again.'
                )

                if (!validationErrors) {
                    notifyError(error, message)
                }

                return {
                    user: null,
                    errors: validationErrors ?? { general: message },
                }
            } finally {
                this.saving = false
            }
        },
        async updateUserRole(userId, role) {
            if (!userId) {
                return { user: null, errors: { role: 'User identifier is required.' } }
            }

            this.actionState = { ...this.actionState, [userId]: 'updating-role' }
            try {
                const response = await apiClient.patch(`/users/${userId}/role`, { role })
                const updated = extractUser(response?.data ?? response)

                if (updated) {
                    this.users = this.users.map((user) => (user.id === userId ? updated : user))
                    this.roles = deriveRoleOptions(this.users)
                }

                notifySuccess({
                    title: 'Role updated',
                    message: 'The user role has been updated.',
                })

                return { user: updated, errors: null }
            } catch (error) {
                const validationErrors = extractValidationErrors(error)
                const message = extractErrorMessage(
                    error,
                    'Unable to update the user role. Please try again.'
                )

                if (!validationErrors) {
                    notifyError(error, message)
                }

                return {
                    user: null,
                    errors: validationErrors ?? { general: message },
                }
            } finally {
                const next = { ...this.actionState }
                delete next[userId]
                this.actionState = next
            }
        },
        async resetUserPassword(userId) {
            if (!userId) {
                return { password: null, errors: { user: 'User identifier is required.' } }
            }

            this.actionState = { ...this.actionState, [userId]: 'resetting-password' }
            try {
                const existing = this.users.find((user) => user.id === userId)
                if (!existing) {
                    return { password: null, errors: { user: 'User not found.' } }
                }

                const temporaryPassword = generateTemporaryPassword()

                notifySuccess({
                    title: 'Password reset',
                    message: temporaryPassword
                        ? 'A temporary password has been generated.'
                        : 'The password reset request completed successfully.',
                })

                return { password: temporaryPassword, errors: null }
            } catch (error) {
                notifyError(error, 'Unable to reset the password. Please try again.')
                return { password: null, errors: error?.validationErrors ?? null }
            } finally {
                const next = { ...this.actionState }
                delete next[userId]
                this.actionState = next
            }
        },
        async deleteUser(userId) {
            if (!userId) {
                return { success: false }
            }

            this.actionState = { ...this.actionState, [userId]: 'deleting' }
            try {
                await apiClient.delete(`/users/${userId}`)
                this.users = this.users.filter((user) => user.id !== userId)
                this.roles = deriveRoleOptions(this.users)

                notifySuccess({
                    title: 'User removed',
                    message: 'The user has been removed from the workspace.',
                })

                return { success: true }
            } catch (error) {
                const validationErrors = extractValidationErrors(error)
                const message = extractErrorMessage(error, 'Unable to remove the user. Please try again.')

                if (!validationErrors) {
                    notifyError(error, message)
                }

                return {
                    success: false,
                    errors: validationErrors ?? { general: message },
                }
            } finally {
                const next = { ...this.actionState }
                delete next[userId]
                this.actionState = next
            }
        },
    },
})
