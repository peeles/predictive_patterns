<template>
  <div class="min-h-screen bg-gray-50 p-8">
    <div class="max-w-4xl mx-auto">
      <div class="flex justify-between items-center mb-8">
        <h1 class="text-3xl font-bold">Dashboard</h1>
        <button @click="handleLogout"
          class="px-4 py-2 bg-red-600 text-white rounded">
          Logout
        </button>
      </div>

      <div class="grid md:grid-cols-2 gap-6">
        <div class="bg-white p-6 rounded-lg shadow-md">
          <h2 class="text-xl font-semibold mb-4">Profile</h2>
          <p><strong>Name:</strong> {{ authStore.user?.name }}</p>
          <p><strong>Email:</strong> {{ authStore.user?.email }}</p>
        </div>

        <div class="bg-white p-6 rounded-lg shadow-md">
          <h2 class="text-xl font-semibold mb-4">Real-time Status</h2>
          <p>Echo connected: <span :class="echoConnected ? 'text-green-600' : 'text-red-600'">
            {{ echoConnected ? 'Yes' : 'No' }}
          </span></p>
        </div>
      </div>

      <div class="mt-6 bg-white p-6 rounded-lg shadow-md">
        <h2 class="text-xl font-semibold mb-4">Quick Links</h2>
        <div class="space-y-2">
          <a href="http://localhost:8000/horizon" target="_blank"
            class="block text-blue-600 hover:underline">
            Horizon Dashboard
          </a>
          <p class="text-sm text-gray-600">Monitor queues and jobs</p>
        </div>
      </div>
    </div>
  </div>
</template>

<script setup>
import { ref, onMounted, onUnmounted } from 'vue'
import { useRouter } from 'vue-router'
import { useAuthStore } from '@/stores/auth'
import Echo from '@/utils/echo'

const router = useRouter()
const authStore = useAuthStore()
const echoConnected = ref(false)

onMounted(() => {
  Echo.connector.pusher.connection.bind('connected', () => {
    echoConnected.value = true
  })

  Echo.connector.pusher.connection.bind('disconnected', () => {
    echoConnected.value = false
  })
})

onUnmounted(() => {
  Echo.connector.pusher.connection.unbind('connected')
  Echo.connector.pusher.connection.unbind('disconnected')
})

const handleLogout = async () => {
  await authStore.logout()
  router.push('/login')
}
</script>
