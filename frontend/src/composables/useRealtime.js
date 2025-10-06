import { onMounted, onUnmounted, ref } from 'vue'
import { echo } from '../plugins/echo'

const CONNECTING = 'connecting'
const CONNECTED = 'connected'
const DISCONNECTED = 'disconnected'
const UNAVAILABLE = 'unavailable'
const FAILED = 'failed'
const INITIALIZED = 'initialized'

const connected = ref(false)
const state = ref(INITIALIZED)
const reason = ref(null)

let bindings = 0
let teardown = null

function attach() {
  bindings += 1
  if (bindings > 1 || typeof window === 'undefined') {
    return
  }

  const connector = echo.connector ?? {}
  const pusher = connector?.pusher
  if (!pusher?.connection) {
    return
  }

  const handleConnected = () => {
    connected.value = true
    state.value = CONNECTED
    reason.value = null
  }

  const handleDisconnected = (event) => {
    connected.value = false
    state.value = DISCONNECTED
    reason.value = extractReason(event)
  }

  const handleStateChange = ({ current }) => {
    state.value = current ?? INITIALIZED
    if (current === CONNECTED) {
      connected.value = true
      reason.value = null
    } else if (current === CONNECTING) {
      connected.value = false
    }
  }

  const handleError = (event) => {
    connected.value = false
    state.value = FAILED
    reason.value = extractReason(event)
  }

  const handleUnavailable = (event) => {
    connected.value = false
    state.value = UNAVAILABLE
    reason.value = extractReason(event)
  }

  pusher.connection.bind('connected', handleConnected)
  pusher.connection.bind('disconnected', handleDisconnected)
  pusher.connection.bind('state_change', handleStateChange)
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

function extractReason(event) {
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
  state.value = DISCONNECTED
}

export function reconnectRealtime() {
  echo.disconnect()
  connected.value = false
  state.value = CONNECTING
  echo.connect()
}
