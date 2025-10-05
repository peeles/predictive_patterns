<template>
    <div class="relative min-h-screen bg-gradient-to-br from-stone-100 via-white to-stone-100 text-stone-900">
        <a
            class="sr-only focus:not-sr-only focus:absolute focus:left-4 focus:top-4 focus:z-50 focus:rounded focus:bg-blue-600 focus:px-4 focus:py-2 focus:text-sm focus:font-semibold focus:text-white"
            href="#main-content"
        >
            Skip to main content
        </a>

        <!-- TODO: Make header a separate component -->

        <section class="flex min-h-[calc(100vh-4.5rem)] flex-col lg:flex-row">
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
        </section>
    </div>
</template>

<script setup>
import SideBarNav from "../components/SideBarNav.vue";
</script>
