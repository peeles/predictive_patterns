<template>
    <section class="rounded-xl border border-stone-200 bg-white shadow-sm" aria-labelledby="models-heading">
        <header class="flex flex-wrap items-center justify-between gap-4 border-b border-stone-200 px-6 py-4">
            <div>
                <h2 id="models-heading" class="text-lg font-semibold text-stone-900">Models</h2>
                <p class="text-sm text-stone-600">Monitor deployed models and manage retraining cycles.</p>
            </div>
            <div class="flex flex-wrap items-center gap-3">
                <label class="flex items-center gap-2 text-sm text-stone-600">
                    <span>Status</span>
                    <select
                        v-model="statusFilter"
                        class="rounded-md border border-stone-300 bg-white px-3 py-1.5 text-sm text-stone-700 shadow-sm transition focus-visible:outline  focus-visible:outline-offset-2 focus-visible:outline-blue-500"
                    >
                        <option v-for="option in statusOptions" :key="option.value" :value="option.value">
                            {{ option.label }}
                        </option>
                    </select>
                </label>
                <button
                    class="rounded-md border border-stone-300 px-3 py-1.5 text-sm font-medium text-stone-700 shadow-sm transition hover:border-stone-400 hover:text-stone-900 focus-visible:outline  focus-visible:outline-offset-2 focus-visible:outline-blue-500"
                    type="button"
                    :disabled="modelStore.loading"
                    @click="refresh"
                >
                    Refresh
                </button>
            </div>
        </header>
        <div class="overflow-x-auto">
            <table class="min-w-full divide-y divide-stone-200 text-left text-sm">
                <thead class="bg-stone-50 text-xs font-semibold uppercase tracking-wide text-stone-500">
                    <tr>
                        <th
                            v-for="column in columns"
                            :key="column.key"
                            :class="['px-6 py-3', column.sortable ? 'cursor-pointer select-none' : '']"
                            scope="col"
                            @click="column.sortable ? toggleSort(column.sortKey) : undefined"
                        >
                            <div class="flex items-center gap-1">
                                <span>{{ column.label }}</span>
                                <span v-if="column.sortable && sortKey === column.sortKey" aria-hidden="true">
                                    {{ sortDirection === 'asc' ? '▲' : '▼' }}
                                </span>
                            </div>
                        </th>
                        <th class="px-6 py-3" v-if="isAdmin" scope="col">Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <tr v-if="modelStore.loading">
                        <td class="px-6 py-6 text-center text-sm text-stone-500" :colspan="isAdmin ? columns.length + 1 : columns.length">
                            Loading models…
                        </td>
                    </tr>
                    <tr v-else-if="!modelStore.models.length">
                        <td class="px-6 py-6 text-center text-sm text-stone-500" :colspan="isAdmin ? columns.length + 1 : columns.length">
                            No models available.
                        </td>
                    </tr>
                    <tr
                        v-for="model in modelStore.models"
                        v-else
                        :key="model.id"
                        class="odd:bg-white even:bg-stone-50"
                    >
                        <td class="px-6 py-3 text-stone-900">
                            <div class="flex flex-col">
                                <span class="font-medium">{{ model.name }}</span>
                                <span class="text-xs text-stone-500">{{ model.id }}</span>
                            </div>
                        </td>
                        <td class="px-6 py-3">
                            <span :class="statusClasses(model.status)">{{ statusLabel(model.status) }}</span>
                        </td>
                        <td class="px-6 py-3">{{ formatMetric(model.metrics?.precision) }}</td>
                        <td class="px-6 py-3">{{ formatMetric(model.metrics?.recall) }}</td>
                        <td class="px-6 py-3">{{ formatMetric(model.metrics?.f1) }}</td>
                        <td class="px-6 py-3 text-stone-600">{{ formatDate(model.lastTrainedAt) }}</td>
                        <td
                            v-if="isAdmin"
                            class="px-6 py-3"
                        >
                            <div class="flex flex-col gap-2">
                                <div
                                    :class="statusCardClass(modelStatusSnapshot(model.id))"
                                    v-if="modelStatusSnapshot(model.id) || statusLoading[model.id]"
                                >
                                    <div class="flex items-center justify-between text-[11px] font-semibold uppercase tracking-wide">
                                        <span>{{ statusHeading(modelStatusSnapshot(model.id)) }}</span>
                                        <span v-if="statusProgress(modelStatusSnapshot(model.id)) !== null">
                                            {{ statusProgress(modelStatusSnapshot(model.id)) }}%
                                        </span>
                                    </div>
                                    <div
                                        v-if="statusProgress(modelStatusSnapshot(model.id)) !== null"
                                        class="mt-2 h-1.5 w-full overflow-hidden rounded-full bg-white/60"
                                    >
                                        <div
                                            class="h-full rounded-full bg-current transition-all"
                                            :style="{ width: `${statusProgress(modelStatusSnapshot(model.id))}%` }"
                                        ></div>
                                    </div>
                                    <p class="mt-2 text-[11px] font-medium leading-relaxed">
                                        {{ statusSubtext(modelStatusSnapshot(model.id)) }}
                                    </p>
                                </div>
                                <div v-else class="rounded-md border border-stone-200 bg-stone-50 px-3 py-2 text-xs text-stone-600">
                                    Checking live status…
                                </div>
                                <div class="flex flex-wrap items-center gap-2">
                                    <button
                                        class="rounded-md bg-blue-600 px-3 py-1.5 text-xs font-semibold text-white shadow-sm transition hover:bg-blue-700 focus-visible:outline  focus-visible:outline-offset-2 focus-visible:outline-blue-500 disabled:cursor-not-allowed disabled:bg-stone-400"
                                        type="button"
                                        :disabled="isModelBusy(model.id)"
                                        @click="modelStore.trainModel(model.id)"
                                    >
                                        {{ actionLabel(model.id, 'train') }}
                                    </button>
                                    <button
                                        class="rounded-md bg-stone-900 px-3 py-1.5 text-xs font-semibold text-white shadow-sm transition hover:bg-stone-800 focus-visible:outline  focus-visible:outline-offset-2 focus-visible:outline-blue-500 disabled:cursor-not-allowed disabled:bg-stone-400"
                                        type="button"
                                        :disabled="isModelBusy(model.id)"
                                        @click="requestEvaluation(model)"
                                    >
                                        {{ actionLabel(model.id, 'evaluate') }}
                                    </button>
                                    <button
                                        v-if="model.status !== 'active'"
                                        class="rounded-md bg-emerald-600 px-3 py-1.5 text-xs font-semibold text-white shadow-sm transition hover:bg-emerald-700 focus-visible:outline  focus-visible:outline-offset-2 focus-visible:outline-blue-500 disabled:cursor-not-allowed disabled:bg-stone-400"
                                        type="button"
                                        :disabled="isActionPending(model.id)"
                                        @click="modelStore.activateModel(model.id)"
                                    >
                                        {{ actionLabel(model.id, 'activate') }}
                                    </button>
                                    <button
                                        v-else
                                        class="rounded-md border border-stone-300 px-3 py-1.5 text-xs font-semibold text-stone-700 shadow-sm transition hover:border-stone-400 hover:text-stone-900 focus-visible:outline  focus-visible:outline-offset-2 focus-visible:outline-blue-500 disabled:cursor-not-allowed disabled:border-stone-200 disabled:text-stone-400"
                                        type="button"
                                        :disabled="isActionPending(model.id)"
                                        @click="modelStore.deactivateModel(model.id)"
                                    >
                                        {{ actionLabel(model.id, 'deactivate') }}
                                    </button>
                                </div>
                            </div>
                        </td>
                    </tr>
                </tbody>
            </table>
        </div>
        <PaginationControls
            :meta="modelStore.meta"
            :count="modelStore.models.length"
            :loading="modelStore.loading"
            label="models"
            @previous="previousPage"
            @next="nextPage"
        />
        <EvaluateModelModal
            :open="evaluationModalOpen"
            :model="evaluationTarget"
            :submitting="evaluationSubmitting"
            :errors="evaluationErrors"
            @close="closeEvaluationModal"
            @submit="submitEvaluation"
        />
    </section>
</template>

<script setup>
import { onBeforeUnmount, onMounted, ref, watch } from 'vue'
import { storeToRefs } from 'pinia'
import PaginationControls from '../common/pagination/PaginationControls.vue'
import EvaluateModelModal from './EvaluateModelModal.vue'
import { useAuthStore } from '../../stores/auth'
import { useModelStore } from '../../stores/model'

const authStore = useAuthStore()
const modelStore = useModelStore()
const { isAdmin } = storeToRefs(authStore)
const { statusSnapshots, statusLoading } = storeToRefs(modelStore)

const emit = defineEmits(['request-create', 'select-model'])

const perPage = 10
const sortKey = ref('updated_at')
const sortDirection = ref('desc')
const statusFilter = ref('all')

const columns = [
    { key: 'name', label: 'Model', sortable: true, sortKey: 'name' },
    { key: 'status', label: 'Status', sortable: true, sortKey: 'status' },
    { key: 'precision', label: 'Precision', sortable: false },
    { key: 'recall', label: 'Recall', sortable: false },
    { key: 'f1', label: 'F1', sortable: false },
    { key: 'trained_at', label: 'Last trained', sortable: true, sortKey: 'trained_at' },
]

const statusOptions = [
    { value: 'all', label: 'All statuses' },
    { value: 'active', label: 'Active' },
    { value: 'inactive', label: 'Inactive' },
    { value: 'training', label: 'Training' },
    { value: 'failed', label: 'Failed' },
    { value: 'draft', label: 'Draft' },
]

const evaluationModalOpen = ref(false)
const evaluationTarget = ref(null)
const evaluationSubmitting = ref(false)
const evaluationErrors = ref({})

onMounted(() => {
    if (!modelStore.models.length) {
        loadModels()
    }
})

onBeforeUnmount(() => {
    modelStore.clearStatusTracking()
})

watch(statusFilter, () => {
    loadModels(1)
})

function buildSortParam() {
    return sortDirection.value === 'desc' ? `-${sortKey.value}` : sortKey.value
}

function requestEvaluation(model) {
    if (model?.id) {
        emit('select-model', model.id)
    }
    evaluationTarget.value = model
    evaluationErrors.value = {}
    evaluationModalOpen.value = true
}

function closeEvaluationModal() {
    evaluationModalOpen.value = false
    evaluationTarget.value = null
    evaluationErrors.value = {}
    evaluationSubmitting.value = false
}

async function submitEvaluation(payload) {
    if (!evaluationTarget.value || evaluationSubmitting.value) {
        return
    }

    evaluationSubmitting.value = true
    evaluationErrors.value = {}

    const { success, errors } = await modelStore.evaluateModel(evaluationTarget.value.id, payload)

    evaluationSubmitting.value = false

    if (success) {
        evaluationModalOpen.value = false
        evaluationTarget.value = null
        evaluationErrors.value = {}
        return
    }

    if (errors && typeof errors === 'object') {
        evaluationErrors.value = errors
    }
}

function currentFilters() {
    const filters = {}
    if (statusFilter.value !== 'all') {
        filters.status = statusFilter.value
    }
    return filters
}

function loadModels(page = 1) {
    modelStore.fetchModels({
        page,
        perPage,
        sort: buildSortParam(),
        filters: currentFilters(),
    })
}

function refresh() {
    loadModels(modelStore.meta?.current_page ?? 1)
}

function nextPage() {
    const current = Number(modelStore.meta?.current_page ?? 1)
    const total = Math.ceil((modelStore.meta?.total ?? 0) / (modelStore.meta?.per_page ?? perPage)) || 1
    if (current < total && !modelStore.loading) {
        loadModels(current + 1)
    }
}

function previousPage() {
    const current = Number(modelStore.meta?.current_page ?? 1)
    if (current > 1 && !modelStore.loading) {
        loadModels(current - 1)
    }
}

function toggleSort(key) {
    if (!key) return
    if (sortKey.value === key) {
        sortDirection.value = sortDirection.value === 'asc' ? 'desc' : 'asc'
    } else {
        sortKey.value = key
        sortDirection.value = key === 'name' ? 'asc' : 'desc'
    }
    loadModels(1)
}

function formatMetric(value) {
    if (typeof value !== 'number') return '—'
    return value.toFixed(2)
}

function formatDate(value) {
    if (!value) return '—'
    return new Intl.DateTimeFormat('en-GB', {
        dateStyle: 'medium',
        timeStyle: 'short',
    }).format(new Date(value))
}

function statusLabel(status) {
    switch (status) {
        case 'active':
            return 'Active'
        case 'inactive':
            return 'Inactive'
        case 'training':
            return 'Training'
        case 'failed':
            return 'Failed'
        case 'draft':
            return 'Draft'
        default:
            return status || 'Unknown'
    }
}

function statusClasses(status) {
    const base = 'inline-flex items-center gap-1 rounded-full px-2 py-1 text-xs font-semibold'
    switch (status) {
        case 'active':
            return `${base} bg-emerald-100 text-emerald-700`
        case 'training':
            return `${base} bg-amber-100 text-amber-700`
        case 'failed':
            return `${base} bg-rose-100 text-rose-700`
        case 'inactive':
            return `${base} bg-stone-200 text-stone-700`
        case 'draft':
            return `${base} bg-blue-100 text-blue-700`
        default:
            return `${base} bg-stone-200 text-stone-700`
    }
}

function modelStatusSnapshot(modelId) {
    return statusSnapshots.value?.[modelId] ?? null
}

function statusCardClass(snapshot) {
    if (!snapshot) {
        return 'rounded-md border border-stone-200 bg-stone-50 px-3 py-2 text-xs text-stone-600'
    }

    if (snapshot.error) {
        return 'rounded-md border border-stone-300 bg-stone-50 px-3 py-2 text-xs text-stone-600'
    }

    switch (snapshot.state) {
        case 'training':
        case 'evaluating':
            return 'rounded-md border border-blue-200 bg-blue-50 px-3 py-2 text-xs text-blue-800'
        case 'failed':
            return 'rounded-md border border-rose-200 bg-rose-50 px-3 py-2 text-xs text-rose-700'
        default:
            return 'rounded-md border border-emerald-200 bg-emerald-50 px-3 py-2 text-xs text-emerald-700'
    }
}

function statusHeading(snapshot) {
    if (!snapshot) {
        return 'Checking live status…'
    }

    if (snapshot.error) {
        return 'Live status unavailable'
    }

    switch (snapshot.state) {
        case 'training':
            return 'Training in progress'
        case 'evaluating':
            return 'Evaluation in progress'
        case 'failed':
            return 'Last job failed'
        case 'active':
        case 'inactive':
        case 'draft':
            return 'Idle'
        case 'idle':
            return 'Idle'
        default:
            return snapshot.state ? snapshot.state.charAt(0).toUpperCase() + snapshot.state.slice(1) : 'Status pending'
    }
}

function statusSubtext(snapshot) {
    if (!snapshot) {
        return 'Fetching the latest updates from the orchestration service.'
    }

    const updated = formatRelativeTime(snapshot.updatedAt)

    if (snapshot.error) {
        return 'Status temporarily unavailable. Try again shortly.'
    }

    switch (snapshot.state) {
        case 'training':
        case 'evaluating': {
            const progress = formatProgress(snapshot.progress)
            const progressLabel = progress !== null ? `${progress}% complete` : 'In progress'
            return `${progressLabel} • Updated ${updated}`
        }
        case 'failed':
            if (snapshot.message) {
                return `${snapshot.message} • Updated ${updated}`
            }
            return `Updated ${updated}. Launch a new job to retry.`
        case 'idle':
        case 'active':
            return `Operational • Updated ${updated}`
        case 'inactive':
            return `Paused • Updated ${updated}`
        default:
            return `Updated ${updated}`
    }
}

function statusProgress(snapshot) {
    if (!snapshot || snapshot.error) {
        return null
    }

    return clampProgress(snapshot.progress)
}

function clampProgress(value) {
    if (typeof value !== 'number' || Number.isNaN(value)) {
        return null
    }

    return Math.max(0, Math.min(100, Math.round(value)))
}

function formatProgress(value) {
    if (typeof value !== 'number' || Number.isNaN(value)) {
        return null
    }

    return Math.max(0, Math.min(100, Math.round(value)))
}

function formatRelativeTime(value) {
    if (!value) {
        return 'just now'
    }

    const parsed = new Date(value)
    if (Number.isNaN(parsed.getTime())) {
        return 'just now'
    }

    const diff = Date.now() - parsed.getTime()
    const minute = 60 * 1000
    const hour = 60 * minute
    const day = 24 * hour

    if (diff < minute) {
        return 'just now'
    }

    if (diff < hour) {
        const minutes = Math.max(1, Math.round(diff / minute))
        return `${minutes}m ago`
    }

    if (diff < day) {
        const hours = Math.max(1, Math.round(diff / hour))
        return `${hours}h ago`
    }

    const days = Math.max(1, Math.round(diff / day))
    if (days <= 7) {
        return `${days}d ago`
    }

    return new Intl.DateTimeFormat('en-GB', {
        dateStyle: 'medium',
        timeStyle: 'short',
    }).format(parsed)
}

function isModelBusy(modelId) {
    const snapshot = modelStatusSnapshot(modelId)
    if (snapshot && !snapshot.error && (snapshot.state === 'training' || snapshot.state === 'evaluating')) {
        return true
    }

    const action = modelStore.actionState[modelId]
    return action === 'training' || action === 'evaluating' || action === 'activating' || action === 'deactivating'
}

function actionLabel(modelId, action) {
    const snapshot = modelStatusSnapshot(modelId)
    if (snapshot && !snapshot.error) {
        if (snapshot.state === 'training') {
            return action === 'train' ? 'Training…' : 'Busy…'
        }
        if (snapshot.state === 'evaluating') {
            return action === 'evaluate' ? 'Evaluating…' : 'Busy…'
        }
    }

    const pending = modelStore.actionState[modelId]
    if (pending === 'training') {
        return action === 'train' ? 'Training…' : 'Busy…'
    }
    if (pending === 'evaluating') {
        return action === 'evaluate' ? 'Evaluating…' : 'Busy…'
    }
    if (pending === 'activating') {
        return action === 'activate' ? 'Activating…' : 'Busy…'
    }
    if (pending === 'deactivating') {
        return action === 'deactivate' ? 'Deactivating…' : 'Busy…'
    }

    switch (action) {
        case 'train':
            return 'Train'
        case 'evaluate':
            return 'Evaluate'
        case 'activate':
            return 'Activate'
        case 'deactivate':
            return 'Deactivate'
        default:
            return 'Action'
    }
}

function isActionPending(modelId) {
    const action = modelStore.actionState[modelId]
    return Boolean(action && action !== 'idle')
}
</script>
