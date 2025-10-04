<template>
    <section class="rounded-3xl border border-stone-200/80 bg-white p-6 text-sm shadow-sm shadow-stone-200/70">
        <header class="mb-4">
            <p class="text-xs font-semibold uppercase tracking-wider text-stone-500">Recent configuration</p>
            <h2 class="text-base font-semibold text-stone-900">Last submitted parameters</h2>
            <p class="mt-1 text-xs text-stone-500">Review the most recent filters applied to the prediction workspace.</p>
        </header>
        <dl class="grid gap-3 sm:grid-cols-2 xl:grid-cols-1">
            <div>
                <dt class="text-xs uppercase tracking-wide text-stone-500">Location</dt>
                <dd class="mt-1 text-sm font-medium text-stone-900">{{ lastLocation }}</dd>
            </div>
            <div>
                <dt class="text-xs uppercase tracking-wide text-stone-500">Forecast horizon</dt>
                <dd class="mt-1 text-sm font-medium text-stone-900">{{ lastHorizon }}</dd>
            </div>
            <div>
                <dt class="text-xs uppercase tracking-wide text-stone-500">Radius</dt>
                <dd class="mt-1 text-sm font-medium text-stone-900">{{ lastRadius }}</dd>
            </div>
            <div>
                <dt class="text-xs uppercase tracking-wide text-stone-500">Last run</dt>
                <dd class="mt-1 text-sm font-medium text-stone-900">{{ lastRunTime }}</dd>
            </div>
        </dl>
    </section>
</template>

<script setup>
import { computed } from 'vue'
import { usePredictionStore } from '../../stores/prediction'

const predictionStore = usePredictionStore()

const lastLocation = computed(() => predictionStore.lastFilters.center?.label ?? 'Not specified')
const lastHorizon = computed(() => `${predictionStore.lastFilters.horizon} hours`)
const lastRadius = computed(() => `${predictionStore.lastFilters.radiusKm.toFixed(1)} km`)
const lastRunTime = computed(() => {
    const generatedAt = predictionStore.currentPrediction?.generatedAt
    if (!generatedAt) {
        return 'No runs yet'
    }
    return new Intl.DateTimeFormat('en-GB', {
        dateStyle: 'medium',
        timeStyle: 'short',
    }).format(new Date(generatedAt))
})
</script>
