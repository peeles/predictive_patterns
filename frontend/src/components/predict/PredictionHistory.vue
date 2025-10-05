<template>
    <section
        aria-labelledby="prediction-history-heading"
        class="p-6"
    >
        <header class="mb-6 space-y-2">
            <p class="text-xs font-semibold uppercase tracking-wider text-stone-500">Prediction archive</p>
            <h2 id="prediction-history-heading" class="text-xl font-semibold text-stone-900">
                Recent prediction runs
            </h2>
            <p class="text-sm text-stone-600">
                Review previous prediction requests and reload their outputs without resubmitting the job.
            </p>
        </header>

        <div class="space-y-5">
            <div class="grid gap-4 md:grid-cols-2 xl:grid-cols-4">
                <div class="flex flex-col gap-2">
                    <label for="history-status" class="text-xs font-medium uppercase tracking-wide text-stone-500">
                        Status
                    </label>
                    <select
                        id="history-status"
                        v-model="localFilters.status"
                        class="block w-full rounded-lg border border-stone-300 bg-white px-3 py-2 text-sm text-stone-700 shadow-sm transition focus:border-blue-500 focus:outline-none focus:ring-2 focus:ring-blue-500/40"
                    >
                        <option v-for="option in statusOptions" :key="option.value" :value="option.value">
                            {{ option.label }}
                        </option>
                    </select>
                </div>
                <div class="flex flex-col gap-2">
                    <label for="history-model" class="text-xs font-medium uppercase tracking-wide text-stone-500">
                        Model
                    </label>
                    <select
                        id="history-model"
                        v-model="localFilters.modelId"
                        class="block w-full rounded-lg border border-stone-300 bg-white px-3 py-2 text-sm text-stone-700 shadow-sm transition focus:border-blue-500 focus:outline-none focus:ring-2 focus:ring-blue-500/40"
                    >
                        <option value="">All models</option>
                        <option v-for="model in modelOptions" :key="model.value" :value="model.value">
                            {{ model.label }}
                        </option>
                    </select>
                </div>
                <div class="flex flex-col gap-2">
                    <label for="history-from" class="text-xs font-medium uppercase tracking-wide text-stone-500">
                        From
                    </label>
                    <input
                        id="history-from"
                        v-model="localFilters.from"
                        type="datetime-local"
                        class="block w-full rounded-lg border border-stone-300 px-3 py-2 text-sm text-stone-700 shadow-sm transition focus:border-blue-500 focus:outline-none focus:ring-2 focus:ring-blue-500/40"
                    />
                </div>
                <div class="flex flex-col gap-2">
                    <label for="history-to" class="text-xs font-medium uppercase tracking-wide text-stone-500">
                        To
                    </label>
                    <input
                        id="history-to"
                        v-model="localFilters.to"
                        type="datetime-local"
                        class="block w-full rounded-lg border border-stone-300 px-3 py-2 text-sm text-stone-700 shadow-sm transition focus:border-blue-500 focus:outline-none focus:ring-2 focus:ring-blue-500/40"
                    />
                </div>
            </div>

            <div class="flex flex-wrap items-center gap-3">
                <button
                    type="button"
                    class="inline-flex items-center rounded-lg bg-blue-600 px-4 py-2 text-sm font-semibold text-white shadow-sm transition hover:bg-blue-700 focus-visible:outline  focus-visible:outline-offset-2 focus-visible:outline-blue-500 disabled:cursor-not-allowed disabled:opacity-60"
                    :disabled="predictionStore.historyLoading"
                    @click="applyFilters"
                >
                    Apply filters
                </button>
                <button
                    type="button"
                    class="inline-flex items-center rounded-lg border border-stone-300 px-4 py-2 text-sm font-semibold text-stone-700 shadow-sm transition hover:bg-stone-50 focus-visible:outline  focus-visible:outline-offset-2 focus-visible:outline-blue-500 disabled:cursor-not-allowed disabled:opacity-60"
                    :disabled="!hasActiveFilters || predictionStore.historyLoading"
                    @click="resetFilters"
                >
                    Reset
                </button>
                <p v-if="feedbackMessage" class="text-sm text-rose-600">{{ feedbackMessage }}</p>
            </div>

            <div class="overflow-hidden rounded-2xl border border-stone-200">
                <table class="min-w-full divide-y divide-stone-200 text-sm">
                    <thead class="bg-stone-50/80 text-left text-xs font-semibold uppercase tracking-wide text-stone-500">
                        <tr>
                            <th scope="col" class="px-4 py-3">Model</th>
                            <th scope="col" class="px-4 py-3">Status</th>
                            <th scope="col" class="px-4 py-3">Requested</th>
                            <th scope="col" class="px-4 py-3">Completed</th>
                            <th scope="col" class="px-4 py-3">Horizon</th>
                            <th scope="col" class="px-4 py-3 text-right">Actions</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-stone-200/80">
                        <tr v-if="predictionStore.historyLoading">
                            <td class="px-4 py-6 text-center text-sm text-stone-500" colspan="6">
                                Loading prediction history…
                            </td>
                        </tr>
                        <tr v-else-if="!history.length">
                            <td class="px-4 py-6 text-center text-sm text-stone-500" colspan="6">
                                No predictions found for the selected filters.
                            </td>
                        </tr>
                        <tr
                            v-for="item in history"
                            v-else
                            :key="item.id"
                            :class="[
                                item.id === selectedPredictionId ? 'bg-blue-50/70' : 'bg-white',
                            ]"
                        >
                            <td class="px-4 py-4 align-top">
                                <div class="font-semibold text-stone-900">{{ item.model?.name ?? fallbackModelName(item) }}</div>
                                <div class="text-xs text-stone-500">{{ item.modelId }}</div>
                            </td>
                            <td class="px-4 py-4 align-top">
                                <span :class="['inline-flex items-center gap-1 rounded-full px-3 py-1 text-xs font-medium', statusClasses(item.status)]">
                                    <span class="inline-block h-2 w-2 rounded-full" :class="statusDot(item.status)"></span>
                                    {{ statusLabel(item.status) }}
                                </span>
                            </td>
                            <td class="px-4 py-4 align-top text-stone-700">
                                <div>{{ formatDate(item.queuedAt ?? item.generatedAt ?? item.createdAt) }}</div>
                                <div class="text-xs text-stone-500">{{ relativeTime(item.queuedAt ?? item.generatedAt ?? item.createdAt) }}</div>
                            </td>
                            <td class="px-4 py-4 align-top text-stone-700">
                                <div>{{ formatDate(item.finishedAt) || '—' }}</div>
                                <div class="text-xs text-stone-500" v-if="item.finishedAt">{{ relativeTime(item.finishedAt) }}</div>
                            </td>
                            <td class="px-4 py-4 align-top text-stone-700">
                                <div>{{ formatHorizon(item) }}</div>
                                <div class="text-xs text-stone-500" v-if="item.filters?.center">
                                    {{ formatLocation(item.filters.center) }}
                                </div>
                            </td>
                            <td class="px-4 py-4 align-top text-right">
                                <button
                                    type="button"
                                    class="inline-flex items-center rounded-lg border border-stone-300 px-3 py-1.5 text-sm font-medium text-stone-700 shadow-sm transition hover:bg-stone-50 focus-visible:outline  focus-visible:outline-offset-2 focus-visible:outline-blue-500 disabled:cursor-not-allowed disabled:opacity-60"
                                    :disabled="predictionStore.loading && selectedRow === item.id"
                                    @click="() => viewPrediction(item.id)"
                                >
                                    <span v-if="predictionStore.loading && selectedRow === item.id">Loading…</span>
                                    <span v-else-if="item.id === selectedPredictionId">View again</span>
                                    <span v-else>View details</span>
                                </button>
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>

            <PaginationControls
                :meta="historyMeta"
                :count="history.length"
                :loading="predictionStore.historyLoading"
                label="predictions"
                @previous="goToPreviousPage"
                @next="goToNextPage"
            >
                <template #summary="{ from, to, total }">
                    <span v-if="total">
                        Showing {{ from }}-{{ to }} of {{ total.toLocaleString() }} predictions
                    </span>
                    <span v-else>No predictions to display</span>
                </template>
            </PaginationControls>
        </div>
    </section>
</template>

<script setup>
import { computed, onMounted, reactive, ref, watch } from 'vue'
import { storeToRefs } from 'pinia'
import PaginationControls from '../common/pagination/PaginationControls.vue'
import { useModelStore } from '../../stores/model'
import { usePredictionStore } from '../../stores/prediction'

const predictionStore = usePredictionStore()
const modelStore = useModelStore()

const { history, historyMeta, historyFilters, historyError } = storeToRefs(predictionStore)

const statusOptions = [
    { label: 'All statuses', value: 'all' },
    { label: 'Completed', value: 'completed' },
    { label: 'Running', value: 'running' },
    { label: 'Queued', value: 'queued' },
    { label: 'Failed', value: 'failed' },
]

const localFilters = reactive({
    status: historyFilters.value.status ?? 'all',
    modelId: historyFilters.value.modelId ?? '',
    from: toLocalInput(historyFilters.value.from),
    to: toLocalInput(historyFilters.value.to),
})

const selectedRow = ref(null)
const feedback = ref(null)

const modelOptions = computed(() =>
    [...modelStore.models]
        .sort((a, b) => (a.name || '').localeCompare(b.name || ''))
        .map((model) => ({ label: model.name ?? model.id, value: model.id }))
)

const hasActiveFilters = computed(
    () =>
        localFilters.status !== 'all' ||
        Boolean(localFilters.modelId) ||
        Boolean(localFilters.from) ||
        Boolean(localFilters.to)
)

const feedbackMessage = computed(() => feedback.value ?? historyError.value?.message ?? '')

const selectedPredictionId = computed(() => predictionStore.currentPrediction?.id ?? null)

watch(
    historyFilters,
    (value) => {
        localFilters.status = value.status ?? 'all'
        localFilters.modelId = value.modelId ?? ''
        localFilters.from = toLocalInput(value.from)
        localFilters.to = toLocalInput(value.to)
    },
    { deep: true }
)

onMounted(async () => {
    try {
        await predictionStore.hydrateHistory()
    } catch (error) {
        feedback.value = error?.message ?? 'Unable to load prediction history.'
    }

    if (!modelStore.models.length && !modelStore.loading) {
        try {
            await modelStore.fetchModels({ perPage: 50, sort: 'name' })
        } catch (error) {
            console.warn('Unable to pre-load models for history filters', error)
        }
    }
})

function toLocalInput(value) {
    if (!value) return ''
    const date = new Date(value)
    if (Number.isNaN(date.getTime())) return ''
    const pad = (num) => String(num).padStart(2, '0')
    return `${date.getFullYear()}-${pad(date.getMonth() + 1)}-${pad(date.getDate())}T${pad(date.getHours())}:${pad(date.getMinutes())}`
}

function toIso(value) {
    if (!value) return null
    const date = new Date(value)
    if (Number.isNaN(date.getTime())) return null
    return date.toISOString()
}

async function applyFilters() {
    const filters = {
        status: localFilters.status,
        modelId: localFilters.modelId || null,
        from: toIso(localFilters.from),
        to: toIso(localFilters.to),
    }

    feedback.value = null

    try {
        await predictionStore.fetchHistory({ page: 1, filters })
    } catch (error) {
        feedback.value = error?.message ?? 'Unable to load prediction history.'
    }
}

async function resetFilters() {
    localFilters.status = 'all'
    localFilters.modelId = ''
    localFilters.from = ''
    localFilters.to = ''
    await applyFilters()
}

async function goToNextPage() {
    const current = Number(historyMeta.value?.current_page ?? 1)
    const perPage = Number(historyMeta.value?.per_page ?? 10)
    const total = Number(historyMeta.value?.total ?? 0)
    const totalPages = Math.max(1, Math.ceil(total / perPage))

    if (current < totalPages) {
        try {
            await predictionStore.fetchHistory({ page: current + 1 })
        } catch (error) {
            feedback.value = error?.message ?? 'Unable to load prediction history.'
        }
    }
}

async function goToPreviousPage() {
    const current = Number(historyMeta.value?.current_page ?? 1)
    if (current > 1) {
        try {
            await predictionStore.fetchHistory({ page: current - 1 })
        } catch (error) {
            feedback.value = error?.message ?? 'Unable to load prediction history.'
        }
    }
}

async function viewPrediction(predictionId) {
    if (!predictionId) {
        return
    }

    selectedRow.value = predictionId
    feedback.value = null

    try {
        await predictionStore.selectHistoryPrediction(predictionId)
    } catch (error) {
        feedback.value = error?.message ?? 'Unable to load the selected prediction.'
    } finally {
        selectedRow.value = null
    }
}

function statusLabel(status) {
    switch ((status || '').toLowerCase()) {
        case 'completed':
            return 'Completed'
        case 'running':
            return 'Running'
        case 'queued':
            return 'Queued'
        case 'failed':
            return 'Failed'
        default:
            return status ? status.charAt(0).toUpperCase() + status.slice(1) : 'Unknown'
    }
}

function statusClasses(status) {
    switch ((status || '').toLowerCase()) {
        case 'completed':
            return 'bg-emerald-100 text-emerald-800'
        case 'running':
            return 'bg-amber-100 text-amber-800'
        case 'queued':
            return 'bg-stone-200 text-stone-700'
        case 'failed':
            return 'bg-rose-100 text-rose-800'
        default:
            return 'bg-stone-200 text-stone-700'
    }
}

function statusDot(status) {
    switch ((status || '').toLowerCase()) {
        case 'completed':
            return 'bg-emerald-500'
        case 'running':
            return 'bg-amber-500'
        case 'queued':
            return 'bg-stone-500'
        case 'failed':
            return 'bg-rose-500'
        default:
            return 'bg-stone-400'
    }
}

function formatDate(value) {
    if (!value) return ''
    const date = new Date(value)
    if (Number.isNaN(date.getTime())) return ''
    return new Intl.DateTimeFormat('en-GB', { dateStyle: 'medium', timeStyle: 'short' }).format(date)
}

function relativeTime(value) {
    if (!value) return ''
    const date = new Date(value)
    if (Number.isNaN(date.getTime())) return ''
    const diff = Date.now() - date.getTime()
    const formatter = new Intl.RelativeTimeFormat('en', { numeric: 'auto' })

    const minutes = Math.round(diff / (1000 * 60))
    if (Math.abs(minutes) < 60) {
        return formatter.format(-minutes, 'minute')
    }

    const hours = Math.round(diff / (1000 * 60 * 60))
    if (Math.abs(hours) < 24) {
        return formatter.format(-hours, 'hour')
    }

    const days = Math.round(diff / (1000 * 60 * 60 * 24))
    return formatter.format(-days, 'day')
}

function formatHorizon(prediction) {
    const horizon = prediction?.summary?.horizonHours ?? prediction?.filters?.horizon ?? null
    if (!Number.isFinite(Number(horizon))) {
        return '—'
    }
    return `${Number(horizon).toFixed(0)} hours`
}

function formatLocation(center) {
    if (!center || typeof center !== 'object') {
        return ''
    }
    const { lat, lng } = center
    if (!Number.isFinite(lat) || !Number.isFinite(lng)) {
        return ''
    }
    return `${lat.toFixed(3)}, ${lng.toFixed(3)}`
}

function fallbackModelName(prediction) {
    if (prediction?.model?.id) {
        return prediction.model.id
    }
    if (prediction?.modelId) {
        return prediction.modelId
    }
    return 'Unknown model'
}
</script>
