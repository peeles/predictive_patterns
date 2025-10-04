<template>
    <section class="rounded-xl border border-stone-200 bg-white shadow-sm" aria-labelledby="model-evaluations-heading">
        <header class="flex flex-wrap items-center justify-between gap-4 border-b border-stone-200 px-6 py-4">
            <div>
                <h2 id="model-evaluations-heading" class="text-lg font-semibold text-stone-900">Evaluation history</h2>
                <p class="text-sm text-stone-600">Review recorded evaluation runs and quality notes.</p>
            </div>
            <label class="flex items-center gap-2 text-sm text-stone-600">
                <span>Model</span>
                <select
                    v-model="selectedModelId"
                    class="rounded-md border border-stone-300 bg-white px-3 py-1.5 text-sm text-stone-700 shadow-sm transition focus-visible:outline  focus-visible:outline-offset-2 focus-visible:outline-blue-500 disabled:cursor-not-allowed"
                    :disabled="!modelOptions.length"
                >
                    <option value="">Select a model</option>
                    <option
                        v-for="option in modelOptions"
                        :key="option.id"
                        :value="option.id"
                    >
                        {{ option.label }}
                    </option>
                </select>
            </label>
        </header>
        <div class="space-y-4 px-6 py-5">
            <p v-if="!modelOptions.length && loading" class="text-sm text-stone-500">Loading models…</p>
            <p
                v-else-if="!modelOptions.length"
                class="text-sm text-stone-500"
            >
                No models available yet. Create a model to see evaluations here.
            </p>
            <p
                v-else-if="!activeModel"
                class="text-sm text-stone-500"
            >
                Select a model to review its evaluation history.
            </p>
            <template v-else>
                <div
                    v-if="refreshMessage"
                    class="rounded-md border border-blue-200 bg-blue-50 px-4 py-3 text-sm text-blue-700"
                >
                    {{ refreshMessage }}
                </div>
                <div
                    v-if="!decoratedEvaluations.length"
                    class="rounded-md border border-stone-200 bg-stone-50 px-4 py-3 text-sm text-stone-600"
                >
                    No evaluation runs recorded for this model yet.
                </div>
                <div v-else class="overflow-x-auto">
                    <table class="min-w-full divide-y divide-stone-200 text-left text-sm">
                        <thead class="bg-stone-50 text-xs font-semibold uppercase tracking-wide text-stone-500">
                            <tr>
                                <th scope="col" class="px-4 py-3">Evaluated</th>
                                <th scope="col" class="px-4 py-3">Dataset</th>
                                <th scope="col" class="px-4 py-3">Metrics</th>
                                <th scope="col" class="px-4 py-3">Notes</th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr
                                v-for="entry in decoratedEvaluations"
                                :key="entry.id"
                                class="odd:bg-white even:bg-stone-50 align-top"
                            >
                                <td class="px-4 py-3 whitespace-nowrap text-stone-700">
                                    {{ formatDateTime(entry.evaluatedAt) }}
                                </td>
                                <td class="px-4 py-3 text-sm text-stone-700">
                                    <span v-if="entry.datasetId" class="font-mono text-xs text-stone-600">{{ entry.datasetId }}</span>
                                    <span v-else class="text-stone-500">Default dataset</span>
                                </td>
                                <td class="px-4 py-3">
                                    <div v-if="entry.metricsPairs.length" class="flex flex-wrap gap-2">
                                        <span
                                            v-for="[metricKey, metricValue] in entry.metricsPairs"
                                            :key="`${entry.id}-${metricKey}`"
                                            class="inline-flex items-center gap-1 rounded-full bg-stone-100 px-2 py-1 text-xs font-medium text-stone-700"
                                        >
                                            <span class="uppercase tracking-wide text-stone-500">{{ metricKey }}</span>
                                            <span>{{ formatMetricValue(metricValue) }}</span>
                                        </span>
                                    </div>
                                    <span v-else class="text-sm text-stone-500">No metrics recorded.</span>
                                </td>
                                <td class="px-4 py-3 text-sm text-stone-700">
                                    <span v-if="entry.notes">{{ entry.notes }}</span>
                                    <span v-else class="text-stone-500">—</span>
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </template>
        </div>
    </section>
</template>

<script setup>
import { computed, ref, watch } from 'vue'

const props = defineProps({
    models: { type: Array, default: () => [] },
    selectedId: { type: String, default: '' },
    loading: { type: Boolean, default: false },
    refreshStates: { type: Object, default: () => ({}) },
})

const emit = defineEmits(['select'])

const selectedModelId = ref(props.selectedId ?? '')

watch(
    () => props.selectedId,
    (next) => {
        selectedModelId.value = next ?? ''
    }
)

watch(selectedModelId, (next) => {
    if ((next ?? '') === (props.selectedId ?? '')) {
        return
    }
    emit('select', next || '')
})

const modelOptions = computed(() =>
    (props.models || []).map((model) => ({
        id: model.id,
        label: model.name || model.id,
    }))
)

const activeModel = computed(() => {
    if (!selectedModelId.value) {
        return null
    }
    return (props.models || []).find((model) => model.id === selectedModelId.value) ?? null
})

const decoratedEvaluations = computed(() => {
    if (!activeModel.value) {
        return []
    }
    return (activeModel.value.evaluations || []).map((entry) => ({
        ...entry,
        metricsPairs: metricPairs(entry.metrics),
    }))
})

const refreshState = computed(() => {
    if (!activeModel.value) {
        return null
    }
    return props.refreshStates?.[activeModel.value.id] ?? null
})

const refreshMessage = computed(() => {
    if (refreshState.value === 'refreshing') {
        return 'Refreshing evaluation results…'
    }
    if (refreshState.value === 'pending') {
        return 'Awaiting evaluation completion. Results will appear once the job finishes.'
    }
    return ''
})

function metricPairs(metrics = {}) {
    if (!metrics || typeof metrics !== 'object') {
        return []
    }
    return Object.entries(metrics).sort((a, b) => a[0].localeCompare(b[0], 'en', { sensitivity: 'base' }))
}

function formatMetricValue(value) {
    if (typeof value === 'number' && Number.isFinite(value)) {
        return value.toLocaleString('en-GB', { maximumFractionDigits: 4 })
    }
    if (typeof value === 'string') {
        return value
    }
    return String(value ?? '—')
}

function formatDateTime(value) {
    if (!value) {
        return '—'
    }

    try {
        return new Intl.DateTimeFormat('en-GB', {
            dateStyle: 'medium',
            timeStyle: 'short',
        }).format(new Date(value))
    } catch (error) {
        return value
    }
}
</script>
