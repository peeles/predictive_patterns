import { reactive, readonly } from 'vue'

const queue = reactive([])
const DEFAULT_TIMEOUT = 6000

function addNotification(notification) {
    const id = `${Date.now()}-${Math.random().toString(36).slice(2, 8)}`
    const entry = {
        id,
        type: notification.type || 'info',
        title: notification.title || '',
        message: notification.message || '',
        timeout: notification.timeout ?? DEFAULT_TIMEOUT,
    }
    queue.push(entry)
    if (entry.timeout > 0) {
        setTimeout(() => dismissNotification(id), entry.timeout)
    }
    return id
}

export function dismissNotification(id) {
    const index = queue.findIndex((item) => item.id === id)
    if (index !== -1) {
        queue.splice(index, 1)
    }
}

export function useNotifications() {
    return {
        notifications: readonly(queue),
        dismissNotification,
    }
}

function extractErrorMessage(error) {
    if (!error) return 'Unexpected error occurred.'
    if (typeof error === 'string') return error
    if (error.response?.data?.message) return error.response.data.message
    if (error.message) return error.message
    return 'Unexpected error occurred.'
}

function extractRequestId(error) {
    if (!error || typeof error === 'string') {
        return null
    }

    if (error.requestId) {
        return error.requestId
    }

    const headerId = error.response?.headers?.['x-request-id']
    if (headerId) {
        return headerId
    }

    const payloadId = error.response?.data?.error?.request_id
    if (payloadId) {
        return payloadId
    }

    return error.config?.metadata?.requestId ?? null
}

export function notifyError(error, fallbackMessage) {
    const baseMessage = fallbackMessage || extractErrorMessage(error)
    const requestId = extractRequestId(error)
    const message = requestId ? `${baseMessage} (Request ID: ${requestId})` : baseMessage

    return addNotification({ type: 'error', title: 'Error', message })
}

export function notifySuccess(payload) {
    return addNotification({ type: 'success', ...payload })
}

export function notifyInfo(payload) {
    return addNotification({ type: 'info', ...payload })
}
