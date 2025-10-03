import { ref, computed } from 'vue'
import { defineStore } from 'pinia'
import axios from 'axios'

export const useAuthStore = defineStore('auth', () => {
  const user = ref(null)
  const token = ref(localStorage.getItem('token') || null)
  const loading = ref(false)
  const error = ref(null)

  const isAuthenticated = computed(() => !!token.value && !!user.value)

  async function getCsrfCookie() {
    try {
      await axios.get('/sanctum/csrf-cookie')
    } catch (err) {
      console.error('Failed to get CSRF cookie:', err)
    }
  }

  async function login(credentials) {
    loading.value = true
    error.value = null

    try {
      // Get CSRF cookie first
      await getCsrfCookie()

      // Small delay to ensure cookie is set
      await new Promise(resolve => setTimeout(resolve, 100))

      const response = await axios.post('/api/login', credentials)

      token.value = response.data.token
      user.value = response.data.user
      localStorage.setItem('token', token.value)

      return true
    } catch (err) {
      error.value = err.response?.data?.message || 'Login failed'
      throw err
    } finally {
      loading.value = false
    }
  }

  async function register(userData) {
    loading.value = true
    error.value = null

    try {
      // Get CSRF cookie first
      await getCsrfCookie()

      // Small delay to ensure cookie is set
      await new Promise(resolve => setTimeout(resolve, 100))

      const response = await axios.post('/api/register', userData)

      token.value = response.data.token
      user.value = response.data.user
      localStorage.setItem('token', token.value)

      return true
    } catch (err) {
      error.value = err.response?.data?.message || 'Registration failed'
      throw err
    } finally {
      loading.value = false
    }
  }

  async function logout() {
    try {
      await axios.post('/api/logout')
    } finally {
      user.value = null
      token.value = null
      localStorage.removeItem('token')
    }
  }

  async function checkAuth() {
    if (!token.value) return false

    try {
      const response = await axios.get('/api/user')
      user.value = response.data
      return true
    } catch (err) {
      user.value = null
      token.value = null
      localStorage.removeItem('token')
      return false
    }
  }

  return {
    user,
    token,
    loading,
    error,
    isAuthenticated,
    login,
    register,
    logout,
    checkAuth
  }
}, {
  persist: {
    paths: ['user']
  }
})
