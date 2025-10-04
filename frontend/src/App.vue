<template>
    <div class="relative min-h-screen bg-gradient-to-br from-stone-100 via-white to-stone-100 text-stone-900">
        <a
            class="sr-only focus:not-sr-only focus:absolute focus:left-4 focus:top-4 focus:z-50 focus:rounded focus:bg-blue-600 focus:px-4 focus:py-2 focus:text-sm focus:font-semibold focus:text-white"
            href="#main-content"
        >
            Skip to main content
        </a>
        <header v-if="showChrome" class="sticky top-0 z-40 border-b border-stone-200/80 bg-white/90 backdrop-blur">
            <div class="flex items-center justify-between gap-6 px-6 py-4 lg:px-10">
                <div class="flex items-center gap-3">
                    <span class="inline-flex h-10 w-10 items-center justify-center rounded-full bg-gradient-to-br from-blue-500 via-indigo-500 to-sky-500 text-sm font-semibold text-white shadow-sm">
                        PP
                    </span>
                    <div>
                        <p class="text-lg font-semibold">Predictive Patterns</p>
                        <p class="text-xs text-stone-500">Operational foresight for the field</p>
                    </div>
                </div>
                <nav aria-label="Main navigation" class="hidden items-center gap-2 text-sm font-medium lg:flex">
                    <RouterLink
                        v-for="link in filteredPrimaryLinks"
                        :key="link.to"
                        :to="link.to"
                        active-class="text-blue-600 bg-blue-50/80"
                        class="rounded-xl px-4 py-2 text-stone-600 transition hover:bg-stone-100 hover:text-stone-900 focus-visible:outline  focus-visible:outline-offset-2 focus-visible:outline-blue-500"
                    >
                        {{ link.label }}
                    </RouterLink>
                </nav>
                <div class="flex items-center gap-3 text-sm">
                    <div class="hidden flex-col text-right sm:flex">
                        <span class="font-semibold text-stone-900">{{ userName }}</span>
                        <span class="text-xs uppercase tracking-wide text-stone-500">{{ roleLabel }}</span>
                    </div>
                    <button
                        class="inline-flex h-10 w-10 items-center justify-center rounded-full border border-stone-300 bg-white text-stone-700 shadow-sm transition hover:border-stone-400 hover:text-stone-900 focus-visible:outline  focus-visible:outline-offset-2 focus-visible:outline-blue-500"
                        type="button"
                        @click="logout"
                    >
                        <span class="sr-only">Sign out</span>
                        <svg aria-hidden="true" class="h-4 w-4" stroke="currentColor" stroke-width="1.5" viewBox="0 0 24 24">
                            <path d="M15.75 9V5.25A2.25 2.25 0 0013.5 3h-6A2.25 2.25 0 005.25 5.25v13.5A2.25 2.25 0 007.5 21h6a2.25 2.25 0 002.25-2.25V15" stroke-linecap="round" stroke-linejoin="round" />
                            <path d="M12 12h9m0 0l-3-3m3 3l-3 3" stroke-linecap="round" stroke-linejoin="round" />
                        </svg>
                    </button>
                </div>
            </div>
            <nav
                aria-label="Primary navigation"
                class="flex items-center gap-2 px-6 pb-4 text-sm font-medium lg:hidden"
            >
                <RouterLink
                    v-for="link in filteredPrimaryLinks"
                    :key="link.to"
                    :to="link.to"
                    active-class="bg-blue-50/90 text-blue-700 ring-1 ring-inset ring-blue-200"
                    class="rounded-xl px-4 py-2 text-stone-600 transition hover:bg-stone-100 hover:text-stone-900 focus-visible:outline  focus-visible:outline-offset-2 focus-visible:outline-blue-500"
                >
                    {{ link.label }}
                </RouterLink>
            </nav>
        </header>

        <StatusBanner />

        <div v-if="showChrome" class="flex min-h-[calc(100vh-4.5rem)] flex-col lg:flex-row">
            <aside class="hidden w-full border-b border-stone-200/80 bg-white/70 p-6 backdrop-blur lg:flex lg:w-72 lg:flex-col lg:gap-8 lg:border-b-0 lg:border-r">
                <nav aria-label="Workspace navigation" class="space-y-2 text-sm font-medium text-stone-600">
                    <p class="px-2 text-xs font-semibold uppercase tracking-wide text-stone-500">Workspace</p>
                    <RouterLink
                        v-for="link in filteredPrimaryLinks"
                        :key="link.to"
                        :to="link.to"
                        active-class="bg-blue-50/90 text-blue-700 ring-1 ring-inset ring-blue-200"
                        class="flex items-center justify-between gap-2 rounded-xl px-4 py-2 transition hover:bg-stone-100 hover:text-stone-900 focus-visible:outline focus-visible:outline-offset-2 focus-visible:outline-blue-500"
                    >
                        <span>{{ link.label }}</span>
                        <span aria-hidden="true" class="text-xs text-stone-400">→</span>
                    </RouterLink>
                </nav>
                <section aria-label="Status" class="rounded-2xl border border-stone-200/80 bg-white/70 p-4 text-sm shadow-sm shadow-stone-200/70">
                    <p class="text-xs font-semibold uppercase tracking-wide text-stone-500">Current operator</p>
                    <p class="mt-2 text-base font-semibold text-stone-900">{{ userName }}</p>
                    <p class="text-xs text-stone-500">{{ roleLabel }} access</p>
                </section>
            </aside>
            <main
                id="main-content"
                ref="mainElement"
                class="min-h-full flex-1 px-8 py-8 focus:outline-none"
                tabindex="-1"
            >
                <RouterView v-slot="{ Component }">
                    <Transition name="fade" mode="out-in">
                        <component :is="Component" />
                    </Transition>
                </RouterView>
            </main>
        </div>
        <main
            v-else
            id="main-content"
            ref="mainElement"
            class="flex min-h-screen items-center justify-center px-6 py-8 focus:outline-none"
            tabindex="-1"
        >
            <RouterView v-slot="{ Component }">
                <Transition name="fade" mode="out-in">
                    <component :is="Component" />
                </Transition>
            </RouterView>
        </main>

        <AppToaster />
    </div>
</template>

<script setup>
import { computed, onMounted, ref, watch } from 'vue'
import { storeToRefs } from 'pinia'
import { RouterLink, RouterView, useRoute, useRouter } from 'vue-router'
import AppToaster from './components/feedback/AppToaster.vue'
import StatusBanner from './components/feedback/StatusBanner.vue'
import { useAuthStore } from './stores/auth'

const authStore = useAuthStore()
const route = useRoute()
const router = useRouter()
const mainElement = ref(null)

const { isAdmin, isAuthenticated, role, user } = storeToRefs(authStore)
const userName = computed(() => user.value?.name ?? 'Guest')
const roleLabel = computed(() => (role.value ?? '').toUpperCase())
const showChrome = computed(() => isAuthenticated.value && route.name !== 'login')

const primaryLinks = [
    { to: '/dashboard', label: 'Dashboard' },
    { to: '/predict', label: 'Predict' },
    { to: '/admin/models', label: 'Models', adminOnly: true },
    { to: '/admin/datasets', label: 'Datasets', adminOnly: true },
    { to: '/admin/users', label: 'Users', adminOnly: true },
]

const filteredPrimaryLinks = computed(() =>
    primaryLinks.filter((link) => !link.adminOnly || isAdmin.value)
)

function focusMain() {
    requestAnimationFrame(() => {
        mainElement.value?.focus()
    })
}

onMounted(() => {
    focusMain()
})

watch(
    () => route.fullPath,
    () => {
        focusMain()
    }
)

function logout() {
    authStore.logout()
    router.push('/login')
}
</script>

<style scoped>
.fade-enter-active,
.fade-leave-active {
    transition: opacity 150ms ease;
}

.fade-enter-from,
.fade-leave-to {
    opacity: 0;
}
</style>
