import Echo from 'laravel-echo'
import Pusher from 'pusher-js'
import { useAuthStore } from '../stores/auth'
import { getCookie } from '../utils/cookies'

const normaliseEnv = (value) => {
  if (typeof value !== 'string') {
    return value
  }

  const normalised = value.trim()
  if (!normalised || normalised.toLowerCase() === 'null' || normalised.toLowerCase() === 'undefined') {
    return undefined
  }

  return normalised
}

const getWindowLocation = () => {
  if (typeof window === 'undefined' || !window.location) {
    return undefined
  }

  return window.location
}

const browserLocation = getWindowLocation()

const pusherHost = normaliseEnv(import.meta.env.VITE_PUSHER_HOST)
const pusherPort = normaliseEnv(import.meta.env.VITE_PUSHER_PORT)
const pusherScheme = normaliseEnv(import.meta.env.VITE_PUSHER_SCHEME)
const pusherCluster = normaliseEnv(import.meta.env.VITE_PUSHER_APP_CLUSTER) ?? 'mt1'
const apiBaseUrl = normaliseEnv(import.meta.env.VITE_API_URL)
const explicitAuthEndpoint = normaliseEnv(import.meta.env.VITE_PUSHER_AUTH_ENDPOINT)
const xsrfCookieName = normaliseEnv(import.meta.env.VITE_XSRF_COOKIE_NAME) ?? 'XSRF-TOKEN'
const xsrfHeaderName = normaliseEnv(import.meta.env.VITE_XSRF_HEADER_NAME) ?? 'X-XSRF-TOKEN'

const baseAuthHeaders = Object.freeze({
  'X-Requested-With': 'XMLHttpRequest',
  Accept: 'application/json',
})

const sanitiseToken = (token) => {
  if (typeof token !== 'string') {
    return ''
  }

  const trimmed = token.trim()

  return trimmed.startsWith('Bearer ') ? trimmed : trimmed ? `Bearer ${trimmed}` : ''
}

export const buildEchoAuthHeaders = (token = '') => {
  const headers = { ...baseAuthHeaders }
  const bearerToken = sanitiseToken(token)
  const xsrfToken = resolveXsrfToken()

  if (bearerToken) {
    headers.Authorization = bearerToken
  } else {
    delete headers.Authorization
  }

  if (xsrfToken) {
    headers[xsrfHeaderName] = xsrfToken
  } else {
    delete headers[xsrfHeaderName]
  }

  return headers
}

const decodeXsrfToken = (rawToken) => {
  if (!rawToken) {
    return null
  }

  try {
    return decodeURIComponent(rawToken)
  } catch {
    return rawToken
  }
}

const resolveXsrfToken = () => {
  if (typeof document === 'undefined') {
    return null
  }

  const cookie = getCookie(xsrfCookieName)
  return decodeXsrfToken(cookie)
}

const resolveAuthToken = () => {
  try {
    const authStore = useAuthStore?.()
    const rawToken = authStore?.token

    if (!rawToken) {
      return ''
    }

    if (typeof rawToken === 'string') {
      return rawToken
    }

    if (typeof rawToken === 'object' && 'value' in rawToken) {
      const value = rawToken.value
      return typeof value === 'string' ? value : ''
    }

    return ''
  } catch {
    return ''
  }
}

const buildAuthRequestInit = (socketId, channelName) => {
  const token = resolveAuthToken()
  const headers = buildEchoAuthHeaders(token)
  headers['Content-Type'] = 'application/json'

  const xsrfToken = resolveXsrfToken()
  if (xsrfToken) {
    headers['X-XSRF-TOKEN'] = xsrfToken
  }

  const credentials = echoOptions.withCredentials ? 'include' : 'same-origin'

  return {
    method: 'POST',
    headers,
    credentials,
    body: JSON.stringify({ socket_id: socketId, channel_name: channelName }),
  }
}

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

const resolvePort = () => {
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
  auth: {
    headers: buildEchoAuthHeaders(),
  },
}

echoOptions.authorizer = (channel, options) => {
  return {
    authorize(socketId, callback) {
      const channelName = channel?.name || options?.channelName

      try {
        const request = buildAuthRequestInit(socketId, channelName)

        fetch(echoOptions.authEndpoint, request)
            .then(async (response) => {
              if (!response.ok) {
                const errorPayload = await response.json().catch(() => null)
                const status = response.status
                const error = new Error('Broadcast auth request failed')
                error.status = status
                error.payload = errorPayload
                throw error
              }

              const payload = await response.json()
              if (!payload || typeof payload !== 'object' || !payload.auth) {
                const error = new Error('Broadcast auth response missing auth payload')
                error.status = response.status
                error.payload = payload
                throw error
              }

              callback(null, payload)
            })
            .catch((error) => {
              console.warn('Echo authorizer error', error)
              callback(error, null)
            })
      } catch (error) {
        console.warn('Echo authorizer initialization error', error)
        callback(error, null)
      }
    },
  }
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

export const updateEchoAuthHeaders = (token = '') => {
    const headers = buildEchoAuthHeaders(token)
    const normalisedHeaders = { ...headers }

    if (!echo || !echo.connector) {
      return normalisedHeaders
    }

    const connector = echo.connector
    const options = connector.options ?? {}
    const authOptions = { ...(options.auth ?? {}) }

    authOptions.headers = { ...normalisedHeaders }
    connector.options = { ...options, auth: authOptions }

    if (echo.options) {
      const echoAuth = { ...(echo.options.auth ?? {}) }
      echoAuth.headers = { ...normalisedHeaders }
      echo.options.auth = echoAuth
    }

    const pusher = connector.pusher ?? (connector.connection ? connector.connection.pusher : null)

    if (pusher) {
      const pusherConfig = pusher.config ?? {}
      const pusherAuth = { ...(pusherConfig.auth ?? {}) }
      pusherAuth.headers = { ...normalisedHeaders }
      pusher.config = { ...pusherConfig, auth: pusherAuth }
    }

    return normalisedHeaders
}
