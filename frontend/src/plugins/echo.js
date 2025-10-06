import Echo from 'laravel-echo'
import Pusher from 'pusher-js'

const pusherHost = import.meta.env.VITE_PUSHER_HOST
const pusherPort = import.meta.env.VITE_PUSHER_PORT
const pusherScheme = import.meta.env.VITE_PUSHER_SCHEME ?? 'http'
const pusherCluster = import.meta.env.VITE_PUSHER_APP_CLUSTER ?? 'mt1'

const echoOptions = {
  broadcaster: 'pusher',
  key: import.meta.env.VITE_PUSHER_APP_KEY,
  forceTLS: pusherScheme === 'https',
  enabledTransports: ['ws', 'wss'],
  disableStats: true,
  authEndpoint: '/broadcasting/auth',
  withCredentials: true,
}

if (pusherHost) {
  const resolvedPort = Number(pusherPort ?? 6001)
  echoOptions.wsHost = pusherHost
  echoOptions.wsPort = resolvedPort
  echoOptions.wssPort = resolvedPort
} else if (pusherCluster) {
  echoOptions.cluster = pusherCluster
}

window.Pusher = Pusher

export const echo = new Echo(echoOptions)

window.Echo = echo
