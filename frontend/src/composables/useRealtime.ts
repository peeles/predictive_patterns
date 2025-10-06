import { onMounted, onUnmounted, ref } from 'vue'
import { echo } from '@/plugins/echo'

type ConnectionState = 'connecting' | 'connected' | 'disconnected' | 'unavailable' | 'failed' | 'initialized'

type ConnectionEvent = {
  data?: { message?: string; code?: string | number }
  error?: { message?: string }
  type?: string
}

const connected = ref(false)
const state = ref<ConnectionState>('initialized')
const reason = ref<string | null>(null)

let bindings = 0
let teardown: (() => void) | null = null

function attach() {
  bindings += 1
  if (bindings > 1 || typeof window === 'undefined') {
    return
  }

  const connector = (echo.connector ?? {}) as { pusher?: any }
  const pusher = connector?.pusher
  if (!pusher?.connection) {
    return
  }

  const handleConnected = () => {
    connected.value = true
    state.value = 'connected'
    reason.value = null
  }

  const handleDisconnected = (event?: ConnectionEvent) => {
    connected.value = false
    state.value = 'disconnected'
    reason.value = extractReason(event)
  }

  const handleStateChange = ({ current }: { previous: string; current: string }) => {
    state.value = (current as ConnectionState) ?? 'initialized'
    if (current === 'connected') {
      connected.value = true
      reason.value = null
    } else if (current === 'connecting') {
      connected.value = false
    }
  }

  const handleError = (event?: ConnectionEvent) => {
    connected.value = false
    state.value = 'failed'
    reason.value = extractReason(event)
  }

  pusher.connection.bind('connected', handleConnected)
  pusher.connection.bind('disconnected', handleDisconnected)
  pusher.connection.bind('state_change', handleStateChange)
  const handleUnavailable = (event?: ConnectionEvent) => {
    connected.value = false
    state.value = 'unavailable'
    reason.value = extractReason(event)
  }

  pusher.connection.bind('error', handleError)
  pusher.connection.bind('failed', handleError)
  pusher.connection.bind('unavailable', handleUnavailable)

  teardown = () => {
    pusher.connection.unbind('connected', handleConnected)
    pusher.connection.unbind('disconnected', handleDisconnected)
    pusher.connection.unbind('state_change', handleStateChange)
    pusher.connection.unbind('error', handleError)
    pusher.connection.unbind('failed', handleError)
    pusher.connection.unbind('unavailable', handleUnavailable)
  }
}

function detach() {
  bindings = Math.max(0, bindings - 1)
  if (bindings === 0 && teardown) {
    teardown()
    teardown = null
  }
}

function extractReason(event?: ConnectionEvent): string | null {
  if (!event) {
    return null
  }

  if (typeof event.type === 'string' && event.type) {
    return event.type
  }

  const message = event.error?.message ?? event.data?.message
  if (typeof message === 'string' && message.trim()) {
    return message.trim()
  }

  const code = event.data?.code
  if (code != null) {
    return String(code)
  }

  return null
}

export function useRealtime() {
  onMounted(attach)
  onUnmounted(detach)

  return {
    echo,
    connected,
    state,
    reason,
  }
}

export function disconnectRealtime() {
  echo.disconnect()
  connected.value = false
  state.value = 'disconnected'
}

export function reconnectRealtime() {
  echo.disconnect()
  connected.value = false
  state.value = 'connecting'
  echo.connect()
}
