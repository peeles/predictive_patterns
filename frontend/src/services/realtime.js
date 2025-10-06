import { echo } from '@/plugins/echo'

const connectionListeners = new Set()
let connectionHandlersBound = false

function notifyConnectionListeners(state) {
    for (const listener of connectionListeners) {
        try {
            listener(state)
        } catch (error) {
            console.warn('Realtime connection listener error', error)
        }
    }
}

function ensureConnectionHandlers() {
    if (connectionHandlersBound) {
        return
    }

    const connector = echo.connector
    const pusher = connector?.pusher
    const connection = pusher?.connection
    if (!connection) {
        return
    }

    connectionHandlersBound = true

    connection.bind('state_change', ({ current }) => {
        notifyConnectionListeners({ state: current })
    })

    connection.bind('connected', () => {
        notifyConnectionListeners({ state: 'connected' })
    })

    connection.bind('disconnected', (event) => {
        notifyConnectionListeners({ state: 'disconnected', reason: event?.type ?? null })
    })

    connection.bind('error', (event) => {
        notifyConnectionListeners({ state: 'error', reason: event?.error?.message ?? event?.type ?? null })
    })

    connection.bind('failed', (event) => {
        notifyConnectionListeners({ state: 'failed', reason: event?.type ?? null })
    })

    connection.bind('unavailable', (event) => {
        notifyConnectionListeners({ state: 'unavailable', reason: event?.type ?? null })
    })
}

function stripPrefix(channelName) {
    if (!channelName) {
        return channelName
    }
    if (channelName.startsWith('private-')) {
        return channelName.slice('private-'.length)
    }
    if (channelName.startsWith('presence-')) {
        return channelName.slice('presence-'.length)
    }
    return channelName
}

function resolveChannel(channelName) {
    if (channelName.startsWith('presence-')) {
        return { baseName: stripPrefix(channelName), type: 'presence' }
    }
    if (channelName.startsWith('private-')) {
        return { baseName: stripPrefix(channelName), type: 'private' }
    }
    return { baseName: channelName, type: 'private' }
}

export function subscribeToChannel(channelName, options = {}) {
    if (!channelName) {
        throw new Error('Channel name is required')
    }

    const { baseName, type } = resolveChannel(channelName)

    let channel
    if (type === 'presence') {
        channel = echo.join(baseName)
    } else if (type === 'public') {
        channel = echo.channel(baseName)
    } else {
        channel = echo.private(baseName)
    }

    const subscription = {
        channel,
        channelName: type === 'presence' ? `presence-${baseName}` : type === 'public' ? baseName : `private-${baseName}`,
        status: 'pending',
    }

    const events = Array.isArray(options.events) ? options.events : []
    const listeners = []

    if (events.length && typeof options.onEvent === 'function') {
        for (const eventName of events) {
            const handler = (payload) => options.onEvent(eventName, payload)
            channel.listen(eventName, handler)
            listeners.push({ eventName, handler })
        }
    }

    if (typeof options.onSubscribed === 'function') {
        channel.subscribed(() => {
            subscription.status = 'subscribed'
            options.onSubscribed(subscription)
        })
    } else {
        channel.subscribed(() => {
            subscription.status = 'subscribed'
        })
    }

    if (typeof options.onError === 'function' && channel.error) {
        channel.error((error) => {
            subscription.status = 'error'
            options.onError(error)
        })
    }

    subscription.unsubscribe = () => {
        for (const { eventName } of listeners) {
            channel.stopListening(eventName)
        }
        echo.leave(baseName)
    }

    return subscription
}

export function unsubscribeFromChannel(subscription) {
    if (!subscription) {
        return
    }

    try {
        if (typeof subscription.unsubscribe === 'function') {
            subscription.unsubscribe()
        } else if (subscription.channelName) {
            const { baseName } = resolveChannel(subscription.channelName)
            echo.leave(baseName)
        }
    } catch (error) {
        console.warn('Error unsubscribing from channel', error)
    }
}

export function onConnectionStateChange(callback) {
    if (typeof callback !== 'function') {
        return () => {}
    }

    connectionListeners.add(callback)
    ensureConnectionHandlers()

    if (!connectionHandlersBound) {
        setTimeout(ensureConnectionHandlers, 0)
    }

    try {
        const state = echo?.connector?.pusher?.connection?.state
        callback({ state: state ?? 'initialized' })
    } catch (error) {
        console.warn('Realtime connection bootstrap error', error)
    }

    return () => offConnectionStateChange(callback)
}

export function offConnectionStateChange(callback) {
    if (!callback) {
        return
    }

    connectionListeners.delete(callback)
}
