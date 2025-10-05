<template>
    <div
        aria-live="assertive"
        class="pointer-events-none fixed bottom-4 right-4 z-[70] flex max-w-full flex-col items-end gap-3 sm:bottom-8 sm:right-8"
    >
        <div class="flex w-full max-w-sm flex-col items-stretch gap-3">
            <TransitionGroup name="toast" tag="div">
                <article
                    v-for="toast in notifications"
                    :key="toast.id"
                    :class="toastClasses(toast.type)"
                    class="pointer-events-auto w-full max-w-sm rounded-lg border border-stone-200 bg-white p-4 shadow-lg focus-within:outline focus-within:outline-offset-2 focus-within:outline-blue-500"
                    role="main"
                >
                    <header class="flex items-start justify-between gap-3">
                        <div class="flex flex-col">
                            <h2 class="text-sm font-semibold text-stone-900">{{ toast.title || fallbackTitle(toast.type) }}</h2>
                            <p class="mt-1 text-sm text-stone-600">{{ toast.message }}</p>
                        </div>
                        <button
                            class="rounded-full p-1 text-stone-500 transition hover:bg-stone-100 hover:text-stone-700 focus:outline-none focus-visible:ring focus-visible:ring-blue-500"
                            type="button"
                            @click="dismiss(toast.id)"
                        >
                            <span class="sr-only">Dismiss notification</span>
                            <svg aria-hidden="true" class="h-4 w-4"  stroke="currentColor" viewBox="0 0 24 24">
                                <path d="M6 18L18 6M6 6l12 12" stroke-linecap="round" stroke-linejoin="round" stroke-width="2" />
                            </svg>
                        </button>
                    </header>
                </article>
            </TransitionGroup>
        </div>
    </div>
</template>

<script setup>
import { dismissNotification, useNotifications } from '../../utils/notifications.js'

const { notifications } = useNotifications()

const toastClasses = (type) => {
    switch (type) {
        case 'success':
            return 'border-green-200 bg-green-50'
        case 'error':
            return 'border-rose-200 bg-rose-50'
        default:
            return 'border-blue-200 bg-blue-50'
    }
}

const fallbackTitle = (type) => {
    switch (type) {
        case 'success':
            return 'success'
        case 'error':
            return 'error'
        default:
            return 'notice'
    }
}

const dismiss = (id) => {
    dismissNotification(id)
}
</script>

<style scoped>
.toast-enter-active,
.toast-leave-active {
    transition: all 200ms ease;
}

.toast-enter-from,
.toast-leave-to {
    opacity: 0;
    transform: translateY(0.5rem) scale(0.95);
}
</style>
