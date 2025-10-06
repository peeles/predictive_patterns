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

const getWindowLocation = () => {
  if (typeof window === 'undefined' || !window.location) {
    return undefined
  }

  return window.location
}

const browserLocation = getWindowLocation()

const pusherHost = normalizeEnv(import.meta.env.VITE_PUSHER_HOST)
const pusherPort = normalizeEnv(import.meta.env.VITE_PUSHER_PORT)
const pusherScheme = normalizeEnv(import.meta.env.VITE_PUSHER_SCHEME)
const pusherCluster = normalizeEnv(import.meta.env.VITE_PUSHER_APP_CLUSTER) ?? 'mt1'
const apiBaseUrl = normalizeEnv(import.meta.env.VITE_API_URL)
const explicitAuthEndpoint = normalizeEnv(import.meta.env.VITE_PUSHER_AUTH_ENDPOINT)

const resolveScheme = () => {
  if (pusherScheme) {
    return pusherScheme
  }

  if (browserLocation?.protocol) {
    return browserLocation.protocol.replace(':', '') || 'http'
  }

  return 'http'
}

const resolveHost = () => {
  if (!browserLocation?.hostname) {
    return pusherHost
  }

  if (!pusherHost) {
    return browserLocation.hostname
  }

  const dockerOnlyHosts = ['sockudo', 'backend']
  const invalidHosts = ['0.0.0.0', '[::]']

  if (invalidHosts.includes(pusherHost)) {
    return browserLocation.hostname
  }

  if (dockerOnlyHosts.includes(pusherHost) && browserLocation.hostname !== pusherHost) {
    return browserLocation.hostname
  }

  return pusherHost
}

const resolvePort = (scheme) => {
  if (pusherPort) {
    const numericPort = Number(pusherPort)

    if (Number.isFinite(numericPort) && numericPort > 0) {
      return numericPort
    }
  }

  return 6001
}

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

const resolvedScheme = resolveScheme()
const resolvedHost = resolveHost()
const resolvedPort = resolvePort(resolvedScheme)

const echoOptions = {
  broadcaster: 'pusher',
  key: import.meta.env.VITE_PUSHER_APP_KEY,
  forceTLS: resolvedScheme === 'https',
  enabledTransports: ['ws', 'wss'],
  disableStats: true,
  authEndpoint: resolveAuthEndpoint(),
  withCredentials: true,
}

if (resolvedHost) {
  echoOptions.wsHost = resolvedHost
  echoOptions.wsPort = resolvedPort
  echoOptions.wssPort = resolvedPort
}

echoOptions.cluster = pusherCluster

window.Pusher = Pusher

export const echo = new Echo(echoOptions)

window.Echo = echo
