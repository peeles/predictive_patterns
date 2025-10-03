<template>
  <div class="min-h-screen bg-gray-50 flex items-center justify-center p-4">
    <div class="bg-white p-8 rounded-lg shadow-md w-full max-w-md">
      <h2 class="text-2xl font-bold mb-6">Register</h2>

      <div v-if="authStore.error" class="mb-4 p-3 bg-red-100 text-red-700 rounded">
        {{ authStore.error }}
      </div>

      <form @submit.prevent="handleRegister" class="space-y-4">
        <div>
          <label class="block mb-1">Name</label>
          <input v-model="form.name" type="text" required
            class="w-full px-3 py-2 border rounded" />
        </div>

        <div>
          <label class="block mb-1">Email</label>
          <input v-model="form.email" type="email" required
            class="w-full px-3 py-2 border rounded" />
        </div>

        <div>
          <label class="block mb-1">Password</label>
          <input v-model="form.password" type="password" required
            class="w-full px-3 py-2 border rounded" />
        </div>

        <div>
          <label class="block mb-1">Confirm Password</label>
          <input v-model="form.password_confirmation" type="password" required
            class="w-full px-3 py-2 border rounded" />
        </div>

        <button type="submit" :disabled="authStore.loading"
          class="w-full px-4 py-2 bg-green-600 text-white rounded disabled:opacity-50">
          {{ authStore.loading ? 'Loading...' : 'Register' }}
        </button>
      </form>

      <p class="mt-4 text-center">
        Already have an account?
        <router-link to="/login" class="text-blue-600">Login</router-link>
      </p>
    </div>
  </div>
</template>

<script setup>
import { ref } from 'vue'
import { useRouter } from 'vue-router'
import { useAuthStore } from '@/stores/auth'

const router = useRouter()
const authStore = useAuthStore()

const form = ref({
  name: '',
  email: '',
  password: '',
  password_confirmation: ''
})

const handleRegister = async () => {
  try {
    await authStore.register(form.value)
    router.push('/dashboard')
  } catch (error) {
    console.error('Register error:', error)
  }
}
</script>
