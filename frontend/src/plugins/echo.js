import Echo from 'laravel-echo'
import Pusher from 'pusher-js'

const normalizeEnv = (value) => {
  if (typeof value !== 'string') {
    return value
  }

  const normalized = value.trim()
  if (!normalized || normalized.toLowerCase() === 'null' || normalized.toLowerCase() === 'undefined') {
    return undefined
  }

  return normalized
}

const pusherHost = normalizeEnv(import.meta.env.VITE_PUSHER_HOST)
const pusherPort = normalizeEnv(import.meta.env.VITE_PUSHER_PORT)
const pusherScheme = normalizeEnv(import.meta.env.VITE_PUSHER_SCHEME) ?? 'http'
const pusherCluster = normalizeEnv(import.meta.env.VITE_PUSHER_APP_CLUSTER) ?? 'mt1'
const apiBaseUrl = normalizeEnv(import.meta.env.VITE_API_URL)
const explicitAuthEndpoint = normalizeEnv(import.meta.env.VITE_PUSHER_AUTH_ENDPOINT)

const resolveAuthEndpoint = () => {
  if (explicitAuthEndpoint) {
    return explicitAuthEndpoint
  }

  if (!apiBaseUrl) {
    return '/broadcasting/auth'
  }

  const originFallback = typeof window !== 'undefined' && window.location ? window.location.origin : 'http://localhost'

  try {
    const url = new URL(apiBaseUrl, originFallback)
    return `${url.origin}/broadcasting/auth`
  } catch {
    return '/broadcasting/auth'
  }
}

const echoOptions = {
  broadcaster: 'pusher',
  key: import.meta.env.VITE_PUSHER_APP_KEY,
  forceTLS: pusherScheme === 'https',
  enabledTransports: ['ws', 'wss'],
  disableStats: true,
  authEndpoint: resolveAuthEndpoint(),
  withCredentials: true,
}

if (pusherHost) {
  const resolvedPort = Number(pusherPort ?? 6001)
  echoOptions.wsHost = pusherHost
  echoOptions.wsPort = resolvedPort
  echoOptions.wssPort = resolvedPort
}

echoOptions.cluster = pusherCluster

window.Pusher = Pusher

export const echo = new Echo(echoOptions)

window.Echo = echo
