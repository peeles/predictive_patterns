<template>
    <div class="w-full max-w-md space-y-8" aria-labelledby="login-heading">
        <header class="space-y-3 text-center">
            <span class="mx-auto flex h-14 w-14 items-center justify-center rounded-full bg-gradient-to-br from-blue-500 via-indigo-500 to-sky-500 text-lg font-semibold text-white shadow-lg shadow-blue-500/30">
                PP
            </span>
            <div class="space-y-2">
                <h1 id="login-heading" class="text-3xl font-semibold text-stone-900">Welcome back</h1>
                <p class="text-sm text-stone-600">
                    Sign in with your Predictive Patterns credentials.
                </p>
            </div>
        </header>
        <form
            class="space-y-5 rounded-3xl border border-stone-200/80 bg-white/80 p-6 shadow-sm shadow-stone-200/70 backdrop-blur"
            @submit.prevent="submit"
        >
            <label class="flex flex-col gap-2 text-sm font-medium text-stone-800">
                Email address
                <input
                    v-model="email"
                    autocomplete="email"
                    class="rounded-xl border border-stone-300/80 px-4 py-3 text-sm shadow-sm shadow-stone-200/60 focus:border-blue-500 focus:outline-none focus:ring-2 focus:ring-blue-200"
                    inputmode="email"
                    name="email"
                    required
                    type="email"
                />
            </label>
            <label class="flex flex-col gap-2 text-sm font-medium text-stone-800">
                Password
                <input
                    v-model="password"
                    autocomplete="current-password"
                    class="rounded-xl border border-stone-300/80 px-4 py-3 text-sm shadow-sm shadow-stone-200/60 focus:border-blue-500 focus:outline-none focus:ring-2 focus:ring-blue-200"
                    name="password"
                    required
                    type="password"
                />
            </label>
            <button
                :disabled="submitting"
                class="w-full rounded-xl bg-blue-600 px-4 py-3 text-sm font-semibold text-white shadow-sm shadow-blue-500/40 transition hover:bg-blue-700 focus-visible:outline  focus-visible:outline-offset-2 focus-visible:outline-blue-500 disabled:cursor-not-allowed disabled:bg-stone-400"
                type="submit"
            >
                {{ submitting ? 'Signing in…' : 'Sign in' }}
            </button>
        </form>
    </div>
</template>

<script setup>
import { ref } from 'vue'
import { useRouter } from 'vue-router'
import { useAuthStore } from '../stores/auth'

const authStore = useAuthStore()
const router = useRouter()
const email = ref('')
const password = ref('')
const submitting = ref(false)

async function submit() {
    submitting.value = true
    try {
        await authStore.login({ email: email.value, password: password.value })
        await router.push({ name: 'dashboard' });
    } finally {
        submitting.value = false
    }
}
</script>
