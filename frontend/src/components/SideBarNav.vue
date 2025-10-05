<template>
    <aside
        role="complementary"
        aria-describedby="workspace-label"
        class="hidden w-full border-b border-stone-200/80 bg-white/70 py-6 px-4 backdrop-blur
        lg:flex lg:w-72 lg:flex-col lg:gap-8 lg:border-b-0 lg:border-r"
    >
        <nav
            aria-label="Workspace navigation"
            class="space-y-2 text-sm font-medium text-stone-600"
        >
            <p
                id="workspace-label"
                class="px-2 text-xs font-semibold uppercase tracking-wide text-stone-500 pt-2 pb-3"
            >
                navigation
            </p>
            <RouterLink
                v-for="link in filteredPrimaryLinks"
                :key="link.to"
                :to="link.to"
                active-class="bg-stone-50/80 text-stone-800 ring-1 ring-inset ring-stone-300"
                class="flex items-center justify-between gap-2 rounded-lg px-4 py-2 transition hover:bg-stone-100 hover:text-stone-900 focus-visible:outline focus-visible:outline-offset-2 focus-visible:outline-stone-500"
            >
                <span>{{ link.label }}</span>
                <span aria-hidden="true" class="text-xs text-stone-400">→</span>
            </RouterLink>
        </nav>
        <section
            aria-label="Status"
            class="rounded-2xl border border-stone-200/80 bg-white/70 p-4 text-sm shadow-sm shadow-stone-200/70"
        >
            <p class="text-xs font-semibold uppercase tracking-wide text-stone-500">Current operator</p>
            <p class="mt-2 text-base font-semibold text-stone-900">{{ userName }}</p>
            <p class="text-xs text-stone-500">{{ roleLabel }} access</p>
        </section>
    </aside>
</template>
<script setup>
import { computed } from 'vue';
import { storeToRefs } from "pinia";
import { useAuthStore } from "../stores/auth.js";

const authStore = useAuthStore()
const { isAdmin, role, user } = storeToRefs(authStore)
const userName = computed(() => user.value?.name ?? 'Guest')
const roleLabel = computed(() => (role.value ?? '').toUpperCase())

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
</script>
