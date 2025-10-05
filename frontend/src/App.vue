<template>
    <div class="relative min-h-screen bg-gradient-to-br from-stone-100 via-white to-stone-100 text-stone-900">
        <a
            class="sr-only focus:not-sr-only focus:absolute focus:left-4 focus:top-4 focus:z-50 focus:rounded focus:bg-blue-600 focus:px-4 focus:py-2 focus:text-sm focus:font-semibold focus:text-white"
            href="#main-content"
        >
            Skip to main content
        </a>
        <TopBar v-if="showChrome" />

        <BaseStatusBanner />

        <div v-if="showChrome" class="flex min-h-[calc(100vh-4.5rem)] flex-col lg:flex-row">
            <SideBarNav />
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

        <BaseToast />
    </div>
</template>

<script setup>
import {computed, onMounted, ref, watch} from 'vue'
import {storeToRefs} from 'pinia'
import {useRoute} from 'vue-router'
import {useAuthStore} from './stores/auth'
import BaseToast from './components/common/BaseToast.vue'
import BaseStatusBanner from './components/common/BaseStatusBanner.vue'
import SideBarNav from "./components/SideBarNav.vue";
import TopBar from "./components/TopBar.vue";

const authStore = useAuthStore()
const route = useRoute()
const mainElement = ref(null)

const { isAuthenticated } = storeToRefs(authStore)
const showChrome = computed(() => isAuthenticated.value && route.name !== 'login')

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
