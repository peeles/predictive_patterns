<template>
    <Transition name="fade">
        <div
            v-if="visible"
            class="sticky top-0 z-40 border-b border-amber-200/70 bg-amber-50/95 px-6 py-3 text-amber-900 shadow-sm backdrop-blur supports-[backdrop-filter]:bg-amber-50/80"
            role="status"
            aria-live="polite"
        >
            <div class="flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
                <div class="space-y-1">
                    <p class="text-sm font-semibold tracking-wide">
                        {{ title }}
                    </p>
                    <p class="text-xs text-amber-800/90 sm:text-sm">
                        {{ message }}
                    </p>
                </div>
                <div class="flex items-center gap-2">
                    <button
                        type="button"
                        class="inline-flex items-center rounded-md border border-amber-500 bg-amber-600 px-3 py-1 text-xs font-semibold uppercase tracking-wide text-white shadow-sm transition hover:bg-amber-500 focus-visible:outline  focus-visible:outline-offset-2 focus-visible:outline-amber-700"
                        @click="retry"
                    >
                        Retry now
                    </button>
                    <button
                        type="button"
                        class="inline-flex items-center rounded-md border border-amber-300/80 bg-amber-100 px-3 py-1 text-xs font-medium text-amber-800 transition hover:bg-amber-200 focus-visible:outline  focus-visible:outline-offset-2 focus-visible:outline-amber-600"
                        @click="dismiss"
                    >
                        Dismiss
                    </button>
                </div>
            </div>
        </div>
    </Transition>
</template>

<script setup>
import { computed, onMounted, onUnmounted, ref } from 'vue'
import { disconnectBroadcastClient, getBroadcastClient, onConnectionStateChange } from '../../services/broadcast'

const connectionState = ref({ state: 'disconnected', reason: null, driver: 'reverb' })
const attempted = ref(false)
const hasConnected = ref(false)
const dismissed = ref(false)

function handleStateChange(next) {
    if (!next || typeof next.state !== 'string') {
        return
    }

    const currentState = next.state.toLowerCase()
    connectionState.value = next

    if (currentState === 'connected') {
        hasConnected.value = true
        attempted.value = false
        dismissed.value = false
        return
    }

    const isInitialising = !hasConnected.value && (currentState === 'connecting' || currentState === 'disconnected')
    if (isInitialising) {
        return
    }

    attempted.value = true
}

let unsubscribe = null

onMounted(() => {
    unsubscribe = onConnectionStateChange(handleStateChange)
})

onUnmounted(() => {
    if (typeof unsubscribe === 'function') {
        unsubscribe()
    }
})

const visible = computed(() => {
    if (dismissed.value) {
        return false
    }

    if (!attempted.value) {
        return false
    }

    const state = (connectionState.value?.state ?? '').toLowerCase()
    return state !== 'connected' && state !== ''
})

const driverLabel = computed(() => {
    const driver = (connectionState.value?.driver ?? 'reverb').toLowerCase()
    return driver === 'pusher' ? 'Pusher' : 'Reverb'
})

const title = computed(() => {
    const state = (connectionState.value?.state ?? '').toLowerCase()
    if (state === 'reconnecting' || state === 'connecting') {
        return 'Realtime connection interrupted'
    }

    return 'Realtime updates unavailable'
})

const message = computed(() => {
    const state = (connectionState.value?.state ?? '').toLowerCase()
    const reason = connectionState.value?.reason ?? null
    const label = driverLabel.value

    switch (state) {
        case 'reconnecting':
            return `The ${label} websocket connection dropped. Retrying automatically…`
        case 'connecting':
            return hasConnected.value
                ? `Reconnecting to the ${label} websocket service…`
                : `Initialising the ${label} websocket connection…`
        case 'error':
            return resolveReasonMessage(reason, label)
        case 'disconnected':
            if (hasConnected.value) {
                return `The ${label} websocket disconnected. Background polling will continue while we retry.`
            }
            return resolveReasonMessage(reason, label)
        default:
            return resolveReasonMessage(reason, label)
    }
})

function resolveReasonMessage(reason, label) {
    switch (reason) {
        case 'missing-key':
            return `Realtime credentials are missing. Confirm the ${label} key is configured on the server and client.`
        case 'connect-failed':
            return `Unable to establish a ${label} websocket connection. Check that the service is running and reachable.`
        case 'socket-error':
            return `${label} reported a transport error. Inspect the browser console and backend logs for details.`
        case 'subscription-error':
            return `Channel authorisation failed. The ${label} transport rejected the subscription request.`
        case 'pusher-error':
            return `The fallback broadcaster returned an error. Verify the ${label} credentials are valid.`
        default:
            return `The ${label} service is unreachable. Logs are being written on the backend so the outage can be investigated.`
    }
}

function retry() {
    dismissed.value = false
    disconnectBroadcastClient()
    const client = getBroadcastClient()
    client?.ensureConnection?.()
}

function dismiss() {
    dismissed.value = true
}
</script>

<style scoped>
.fade-enter-active,
.fade-leave-active {
    transition: opacity 150ms ease;
}

.fade-enter-from,
.fade-leave-to {
    opacity: 0;
}
</style>
