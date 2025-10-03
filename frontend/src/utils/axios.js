import axios from 'axios'

axios.defaults.baseURL = import.meta.env.VITE_API_URL || 'http://localhost:8000'
axios.defaults.withCredentials = true
axios.defaults.headers.common['Accept'] = 'application/json'
axios.defaults.headers.common['Content-Type'] = 'application/json'
axios.defaults.headers.common['X-Requested-With'] = 'XMLHttpRequest'

// CSRF token interceptor
let csrfToken = null

axios.interceptors.request.use(
  async (config) => {
    // Get CSRF token for state-changing requests
    if (['post', 'put', 'patch', 'delete'].includes(config.method.toLowerCase())) {
      if (!csrfToken) {
        try {
          await axios.get('/sanctum/csrf-cookie')
          // Extract CSRF token from cookie
          const cookies = document.cookie.split(';')
          const xsrfCookie = cookies.find(cookie => cookie.trim().startsWith('XSRF-TOKEN='))
          if (xsrfCookie) {
            csrfToken = decodeURIComponent(xsrfCookie.split('=')[1])
          }
        } catch (error) {
          console.error('Failed to fetch CSRF token:', error)
        }
      }

      if (csrfToken) {
        config.headers['X-XSRF-TOKEN'] = csrfToken
      }
    }

    // Add auth token
    const token = localStorage.getItem('token')
    if (token) {
      config.headers.Authorization = `Bearer ${token}`
    }

    return config
  },
  (error) => Promise.reject(error)
)

axios.interceptors.response.use(
  (response) => response,
  (error) => {
    if (error.response?.status === 401) {
      localStorage.removeItem('token')
      csrfToken = null
      window.location.href = '/login'
    }
    // Reset CSRF token on 419 (CSRF token mismatch)
    if (error.response?.status === 419) {
      csrfToken = null
    }
    return Promise.reject(error)
  }
)

export default axios
