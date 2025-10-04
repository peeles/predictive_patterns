import { acceptHMRUpdate, defineStore } from 'pinia'

function generateRequestId() {
    if (typeof crypto !== 'undefined' && typeof crypto.randomUUID === 'function') {
        return crypto.randomUUID()
    }

    const timestamp = Date.now().toString(16)
    const random = Math.random().toString(16).slice(2)
    return `${timestamp}-${random}`
}

function normaliseForSignature(value) {
    if (value === null || typeof value === 'undefined') {
        return null
    }

    if (typeof value !== 'object') {
        return value
    }

    if (value instanceof Date) {
        return value.toISOString()
    }

    if (Array.isArray(value)) {
        return value.map((entry) => normaliseForSignature(entry))
    }

    return Object.keys(value)
        .sort()
        .reduce((acc, key) => {
            acc[key] = normaliseForSignature(value[key])
            return acc
        }, {})
}

function buildSignature(action, payload) {
    try {
        const normalised = normaliseForSignature(payload)
        const serialised = normalised === null ? '' : JSON.stringify(normalised)
        return `${action}:${serialised}`
    } catch (error) {
        console.warn('Unable to serialise payload for idempotency signature', error)
        return `${action}:fallback`
    }
}

export const useRequestStore = defineStore('request', {
    state: () => ({
        lastRequestId: null,
        idempotency: {},
    }),
    actions: {
        issueRequestId() {
            const requestId = generateRequestId()
            this.lastRequestId = requestId
            return requestId
        },
        recordRequestId(requestId) {
            this.lastRequestId = requestId || null
        },
        issueIdempotencyKey(action, payload = null) {
            if (!action) {
                throw new Error('Idempotency key action must be provided')
            }

            const signature = buildSignature(action, payload)
            const existing = this.idempotency[action]

            if (existing && existing.signature === signature && existing.key) {
                return existing.key
            }

            const baseId = this.issueRequestId()
            const key = `${action}:${baseId}`

            this.idempotency = {
                ...this.idempotency,
                [action]: {
                    key,
                    signature,
                },
            }

            return key
        },
        clearIdempotencyKey(action) {
            if (!action) {
                return
            }

            if (!this.idempotency[action]) {
                return
            }

            const next = { ...this.idempotency }
            delete next[action]
            this.idempotency = next
        },
        resetIdempotency() {
            this.idempotency = {}
        },
    },
})

if (import.meta.hot) {
    import.meta.hot.accept(acceptHMRUpdate(useRequestStore, import.meta.hot))
}
