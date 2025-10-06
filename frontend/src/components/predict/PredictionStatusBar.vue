<template>
    <Transition name="fade">
        <section
            v-if="visible"
            class="rounded-2xl border border-blue-200/70 bg-blue-50/70 p-4 shadow-sm shadow-blue-100/70 backdrop-blur supports-[backdrop-filter]:bg-blue-50/60"
            aria-live="polite"
            role="status"
        >
            <div class="flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
                <div class="space-y-1">
                    <p class="text-xs font-semibold uppercase tracking-[0.2em] text-blue-600">Prediction status</p>
                    <p class="text-sm font-medium text-blue-900">{{ statusLabel }}</p>
                    <p v-if="statusMessage" class="text-xs text-blue-700/90 sm:text-sm">{{ statusMessage }}</p>
                </div>
                <div v-if="updatedLabel" class="text-xs text-blue-700/70">
                    Updated
                    <time :datetime="props.updatedAt">{{ updatedLabel }}</time>
                </div>
            </div>
            <div v-if="progressPercent !== null" class="mt-4 flex items-center gap-3" aria-hidden="true">
                <div class="h-2 flex-1 rounded-full bg-blue-100/80">
                    <div
                        class="h-2 rounded-full bg-gradient-to-r from-blue-500 via-indigo-500 to-sky-500 transition-all duration-500"
                        :style="{ width: `${progressPercent}%` }"
                    />
                </div>
                <span class="text-xs font-semibold text-blue-800">{{ progressPercent }}%</span>
            </div>
        </section>
    </Transition>
</template>

<script setup>
import { computed } from 'vue'

const props = defineProps({
    status: {
        type: String,
        default: null,
    },
    progress: {
        type: Number,
        default: null,
    },
    message: {
        type: String,
        default: null,
    },
    updatedAt: {
        type: String,
        default: null,
    },
})

const normalizedStatus = computed(() => (props.status ?? '').toLowerCase())

const visible = computed(() => ['queued', 'running', 'failed'].includes(normalizedStatus.value))

const statusLabel = computed(() => {
    switch (normalizedStatus.value) {
        case 'queued':
            return 'Queued for processing'
        case 'running':
            return 'Generating forecast'
        case 'completed':
            return 'Prediction complete'
        case 'failed':
            return 'Prediction failed'
        default:
            return props.status ? props.status.charAt(0).toUpperCase() + props.status.slice(1) : 'Status pending'
    }
})

const progressPercent = computed(() => {
    if (!Number.isFinite(props.progress)) {
        return null
    }

    const basis = props.progress <= 1 ? props.progress * 100 : props.progress

    return Math.min(100, Math.max(0, Math.round(basis)))
})

const statusMessage = computed(() => props.message)

const updatedLabel = computed(() => {
    if (!props.updatedAt) {
        return ''
    }

    try {
        return new Intl.DateTimeFormat('en-GB', {
            dateStyle: 'medium',
            timeStyle: 'short',
        }).format(new Date(props.updatedAt))
    } catch (error) {
        console.warn('Failed to format prediction status timestamp', error)
        return ''
    }
})
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
