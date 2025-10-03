<template>
  <div class="min-h-screen bg-gray-50 p-8">
    <div class="max-w-4xl mx-auto">
      <h1 class="text-4xl font-bold mb-8">Laravel + Vue 3</h1>

      <div class="bg-white p-6 rounded-lg shadow mb-6">
        <h2 class="text-2xl font-semibold mb-4">Services Status</h2>
        <div v-if="status" class="space-y-2">
          <p>API Status: <span class="font-semibold text-green-600">{{ status.status }}</span></p>
          <p>Sanctum: <span :class="status.services.sanctum ? 'text-green-600' : 'text-red-600'">
            {{ status.services.sanctum ? 'Installed' : 'Not Installed' }}
          </span></p>
          <p>Reverb: <span :class="status.services.reverb ? 'text-green-600' : 'text-red-600'">
            {{ status.services.reverb ? 'Installed' : 'Not Installed' }}
          </span></p>
          <p>Horizon: <span :class="status.services.horizon ? 'text-green-600' : 'text-red-600'">
            {{ status.services.horizon ? 'Installed' : 'Not Installed' }}
          </span></p>
        </div>
      </div>

      <div v-if="!authStore.isAuthenticated" class="space-x-4">
        <router-link to="/login" class="px-4 py-2 bg-blue-600 text-white rounded">
          Login
        </router-link>
        <router-link to="/register" class="px-4 py-2 bg-green-600 text-white rounded">
          Register
        </router-link>
      </div>

      <div v-else>
        <p class="mb-4">Welcome, {{ authStore.user?.name }}!</p>
        <router-link to="/dashboard" class="px-4 py-2 bg-blue-600 text-white rounded">
          Dashboard
        </router-link>
      </div>
    </div>
  </div>
</template>

<script setup>
import { ref, onMounted } from 'vue'
import { useAuthStore } from '@/stores/auth'
import axios from 'axios'

const authStore = useAuthStore()
const status = ref(null)

onMounted(async () => {
  try {
    const response = await axios.get('/api/health')
    status.value = response.data
  } catch (error) {
    console.error('Health check failed:', error)
  }
})
</script>
