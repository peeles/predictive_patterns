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
import { computed, ref, watch } from 'vue'
import { useRealtime, reconnectRealtime } from '@/composables/useRealtime'

const { state, reason } = useRealtime()

const attempted = ref(false)
const hasConnected = ref(false)
const dismissed = ref(false)

watch(
    state,
    (next) => {
        const currentState = (next ?? '').toLowerCase()

        if (currentState === 'connected') {
            hasConnected.value = true
            attempted.value = false
            dismissed.value = false
            return
        }

        const isInitialising = !hasConnected.value && ['connecting', 'disconnected', 'initialized'].includes(currentState)
        if (isInitialising) {
            return
        }

        attempted.value = true
    },
    { immediate: true }
)

const connectionState = computed(() => ({
    state: state.value ?? 'disconnected',
    reason: reason.value ?? null,
    driver: 'pusher',
}))

const visible = computed(() => {
    if (dismissed.value || !attempted.value) {
        return false
    }

    const current = (connectionState.value.state ?? '').toLowerCase()
    return current !== 'connected' && current !== ''
})

const driverLabel = computed(() => 'Sockudo')

const title = computed(() => {
    const current = (connectionState.value.state ?? '').toLowerCase()
    if (current === 'reconnecting' || current === 'connecting') {
        return 'Realtime connection interrupted'
    }

    return 'Realtime updates unavailable'
})

const message = computed(() => {
    const current = (connectionState.value.state ?? '').toLowerCase()
    const label = driverLabel.value
    const reasonDetail = connectionState.value.reason ?? null

    switch (current) {
        case 'reconnecting':
            return `The ${label} websocket connection dropped. Retrying automatically…`
        case 'connecting':
            return hasConnected.value
                ? `Reconnecting to the ${label} websocket service…`
                : `Initialising the ${label} websocket connection…`
        case 'error':
        case 'failed':
        case 'unavailable':
            return resolveReasonMessage(reasonDetail, label)
        case 'disconnected':
            if (hasConnected.value) {
                return `The ${label} websocket disconnected. Background polling will continue while we retry.`
            }
            return resolveReasonMessage(reasonDetail, label)
        default:
            return resolveReasonMessage(reasonDetail, label)
    }
})

function resolveReasonMessage(reasonCode, label) {
    switch (reasonCode) {
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
    reconnectRealtime()
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
