<template>
    <BaseModal
        :open="open"
        :dialog-class="'max-w-2xl'"
        :body-class="'max-h-[70vh]'"
        @close="handleClose"
    >
        <template #header>
            <h2 class="text-lg font-semibold text-stone-900">Evaluate model</h2>
            <p class="mt-1 text-sm text-stone-600">
                {{ modelNameLabel }}
            </p>
        </template>

        <form
            id="evaluate-model-form"
            class="space-y-6"
            @submit.prevent="handleSubmit"
        >
            <div v-if="generalErrorMessage" class="rounded-md border border-rose-200 bg-rose-50 px-4 py-3 text-sm text-rose-700">
                {{ generalErrorMessage }}
            </div>
            <div class="space-y-6">
                <div class="space-y-4">
                    <div class="flex flex-wrap items-center justify-between gap-3">
                        <label for="evaluation-dataset" class="block text-sm font-medium text-stone-700">Evaluation dataset</label>
                        <button
                            type="button"
                            class="text-xs font-medium text-blue-600 transition hover:text-blue-700 focus-visible:outline  focus-visible:outline-offset-2 focus-visible:outline-blue-500"
                            :disabled="datasetLoading"
                            @click="refreshDatasets"
                        >
                            {{ datasetLoading ? 'Refreshing…' : 'Refresh list' }}
                        </button>
                    </div>
                    <input
                        :list="datasetListId"
                        id="evaluation-dataset"
                        v-model="form.datasetId"
                        type="text"
                        name="dataset"
                        class="mt-1 block w-full rounded-md border border-stone-300 px-3 py-2 text-sm text-stone-900 shadow-sm focus:border-blue-500 focus:outline-none focus:ring-2 focus:ring-blue-500"
                        placeholder="Search or enter a dataset identifier"
                        autocomplete="off"
                        @focus="ensureDatasetsLoaded"
                    />
                    <datalist :id="datasetListId">
                        <option
                            v-for="option in datasetOptions"
                            :key="option.id"
                            :value="option.id"
                            :label="datasetLabel(option)"
                        >
                            {{ datasetLabel(option) }}
                        </option>
                    </datalist>
                    <p class="mt-1 text-xs text-stone-500">
                        Leave blank to evaluate against the model's default dataset.
                    </p>
                    <p v-if="datasetErrorMessage" class="mt-1 text-sm text-rose-600">{{ datasetErrorMessage }}</p>
                    <p v-else-if="!datasetLoading && !datasetOptions.length" class="mt-1 text-sm text-stone-500">
                        No ready datasets are available right now. You can still provide an identifier manually.
                    </p>
                </div>
                <div class="space-y-4 rounded-xl border border-stone-200 bg-stone-50/60 p-5">
                    <div class="flex items-start justify-between gap-3">
                        <div>
                            <h3 class="text-sm font-semibold uppercase tracking-wide text-stone-600">Metric overrides</h3>
                            <p class="mt-1 text-sm text-stone-600">
                                Provide manual metrics if you have already scored the model. Leave disabled to run the automated evaluation pipeline.
                            </p>
                        </div>
                        <label class="inline-flex items-center gap-2 text-sm text-stone-600">
                            <input
                                id="use-manual-metrics"
                                v-model="form.useManualMetrics"
                                type="checkbox"
                                class="h-4 w-4 rounded border-stone-300 text-blue-600 focus:ring-blue-500"
                            />
                            <span>Use manual metrics</span>
                        </label>
                    </div>
                    <div v-if="form.useManualMetrics" class="space-y-4">
                        <div v-if="metricsErrors.length" class="rounded-md border border-rose-200 bg-rose-50 px-4 py-3 text-sm text-rose-700">
                            <p class="font-medium">Resolve the following metric issues:</p>
                            <ul class="mt-2 list-disc pl-4">
                                <li v-for="(error, index) in metricsErrors" :key="`metric-error-${index}`">{{ error }}</li>
                            </ul>
                        </div>
                        <div class="space-y-3">
                            <div
                                v-for="row in metricRows"
                                :key="row.id"
                                class="grid gap-3 rounded-lg border border-stone-200 bg-white px-4 py-3 sm:grid-cols-[1.2fr,1fr,auto]"
                            >
                                <div>
                                    <label :for="`metric-key-${row.id}`" class="block text-xs font-medium uppercase tracking-wide text-stone-500">Metric</label>
                                    <input
                                        :id="`metric-key-${row.id}`"
                                        v-model="row.key"
                                        type="text"
                                        class="mt-1 block w-full rounded-md border border-stone-300 px-3 py-2 text-sm text-stone-900 shadow-sm focus:border-blue-500 focus:outline-none focus:ring-2 focus:ring-blue-500"
                                        placeholder="e.g. precision"
                                        autocomplete="off"
                                    />
                                    <p v-if="metricFieldError(row.key)" class="mt-1 text-xs text-rose-600">{{ metricFieldError(row.key) }}</p>
                                </div>
                                <div>
                                    <label :for="`metric-value-${row.id}`" class="block text-xs font-medium uppercase tracking-wide text-stone-500">Value</label>
                                    <input
                                        :id="`metric-value-${row.id}`"
                                        v-model="row.value"
                                        type="number"
                                        step="0.0001"
                                        class="mt-1 block w-full rounded-md border border-stone-300 px-3 py-2 text-sm text-stone-900 shadow-sm focus:border-blue-500 focus:outline-none focus:ring-2 focus:ring-blue-500"
                                        placeholder="0.85"
                                    />
                                </div>
                                <div class="flex items-end justify-end">
                                    <button
                                        type="button"
                                        class="inline-flex items-center gap-1 rounded-md border border-stone-300 px-3 py-2 text-xs font-medium text-stone-600 transition hover:border-stone-400 hover:text-stone-900 focus-visible:outline  focus-visible:outline-offset-2 focus-visible:outline-blue-500"
                                        @click="removeMetricRow(row.id)"
                                    >
                                        <svg aria-hidden="true" class="h-4 w-4"  stroke="currentColor" viewBox="0 0 24 24">
                                            <path d="M6 18L18 6M6 6l12 12" stroke-linecap="round" stroke-linejoin="round" stroke-width="2" />
                                        </svg>
                                        Remove
                                    </button>
                                </div>
                            </div>
                        </div>
                        <div class="flex justify-between gap-3">
                            <button
                                type="button"
                                class="inline-flex items-center gap-2 rounded-md border border-stone-300 px-3 py-2 text-xs font-medium text-stone-700 transition hover:border-stone-400 hover:text-stone-900 focus-visible:outline  focus-visible:outline-offset-2 focus-visible:outline-blue-500"
                                @click="addMetricRow"
                            >
                                <svg aria-hidden="true" class="h-4 w-4"  stroke="currentColor" viewBox="0 0 24 24">
                                    <path d="M12 4v16m8-8H4" stroke-linecap="round" stroke-linejoin="round" stroke-width="2" />
                                </svg>
                                Add metric
                            </button>
                            <button
                                type="button"
                                class="inline-flex items-center gap-2 rounded-md border border-transparent bg-stone-900 px-3 py-2 text-xs font-semibold text-white shadow-sm transition hover:bg-stone-800 focus-visible:outline  focus-visible:outline-offset-2 focus-visible:outline-blue-500"
                                @click="resetMetricRows"
                            >
                                Reset defaults
                            </button>
                        </div>
                    </div>
                </div>
                <div>
                    <label for="evaluation-notes" class="block text-sm font-medium text-stone-700">Evaluation notes</label>
                    <textarea
                        id="evaluation-notes"
                        v-model="form.notes"
                        rows="4"
                        class="mt-1 block w-full rounded-md border border-stone-300 px-3 py-2 text-sm text-stone-900 shadow-sm focus:border-blue-500 focus:outline-none focus:ring-2 focus:ring-blue-500"
                        placeholder="Optional context for this evaluation run"
                    ></textarea>
                    <p v-if="notesErrorMessage" class="mt-1 text-sm text-rose-600">{{ notesErrorMessage }}</p>
                    <p class="mt-1 text-xs text-stone-500">Provide any manual observations, quality gate summaries, or risk notes.</p>
                </div>
            </div>
        </form>

        <template #footer>
            <div class="flex w-full flex-wrap items-center justify-end gap-3">
                <button
                    type="button"
                    class="rounded-md border border-stone-300 px-4 py-2 text-sm font-medium text-stone-700 transition hover:border-stone-400 hover:text-stone-900 focus-visible:outline  focus-visible:outline-offset-2 focus-visible:outline-blue-500"
                    :disabled="submitting"
                    @click="handleClose"
                >
                    Cancel
                </button>
                <button
                    type="submit"
                    form="evaluate-model-form"
                    class="inline-flex items-center gap-2 rounded-md bg-blue-600 px-4 py-2 text-sm font-semibold text-white shadow-sm transition hover:bg-blue-700 focus-visible:outline  focus-visible:outline-offset-2 focus-visible:outline-blue-500 disabled:cursor-not-allowed disabled:bg-stone-400"
                    :disabled="submitting"
                >
                    <svg
                        v-if="submitting"
                        aria-hidden="true"
                        class="h-4 w-4 animate-spin"
                        viewBox="0 0 24 24"

                        stroke="currentColor"
                    >
                        <circle class="opacity-25" cx="12" cy="12" r="10" stroke-width="4"></circle>
                        <path class="opacity-75" d="M4 12a8 8 0 018-8" stroke-width="4" stroke-linecap="round"></path>
                    </svg>
                    <span v-if="submitting">Submitting…</span>
                    <span v-else>Start evaluation</span>
                </button>
            </div>
        </template>
    </BaseModal>
</template>

<script setup>
import { computed, onBeforeUnmount, reactive, ref, watch } from 'vue'
import apiClient from '../../services/apiClient'
import { notifyError } from '../../utils/notifications'
import BaseModal from '../common/BaseModal.vue'

const props = defineProps({
    open: { type: Boolean, default: false },
    model: { type: Object, default: null },
    submitting: { type: Boolean, default: false },
    errors: { type: Object, default: () => ({}) },
})

const emit = defineEmits(['close', 'submit'])

const datasetListId = 'evaluation-datasets-list'
const datasetOptions = ref([])
const datasetLoading = ref(false)
const datasetLoaded = ref(false)
const datasetError = ref('')

const form = reactive({
    datasetId: '',
    useManualMetrics: false,
    notes: '',
})

let metricSeed = 0
const metricRows = ref(createDefaultMetricRows())

watch(
    () => props.open,
    (isOpen) => {
        if (isOpen) {
            window.addEventListener('keydown', handleKeydown)
            initialiseForm(props.model)
            ensureDatasetsLoaded()
        } else {
            window.removeEventListener('keydown', handleKeydown)
        }
    },
)

watch(
    () => props.model?.id,
    () => {
        if (props.open) {
            initialiseForm(props.model)
        }
    },
)

onBeforeUnmount(() => {
    window.removeEventListener('keydown', handleKeydown)
})

const modelNameLabel = computed(() => {
    if (!props.model) {
        return 'Select a model to evaluate.'
    }
    const name = props.model.name ?? 'Untitled model'
    const identifier = props.model.id ? `(${props.model.id})` : ''
    return `${name} ${identifier}`.trim()
})

const datasetErrorMessage = computed(() => normaliseErrorMessage(props.errors?.dataset_id ?? props.errors?.datasetId ?? datasetError.value))
const notesErrorMessage = computed(() => normaliseErrorMessage(props.errors?.notes ?? null))
const generalErrorMessage = computed(() => normaliseErrorMessage(props.errors?.payload ?? props.errors?.message ?? ''))

const metricsErrors = computed(() => {
    const list = []
    const base = props.errors ?? {}
    const general = normaliseErrorMessage(base.metrics ?? null)
    if (general) {
        list.push(general)
    }
    Object.entries(base)
        .filter(([key]) => key.startsWith('metrics.') || key === 'metrics')
        .forEach(([key, value]) => {
            if (key === 'metrics') {
                return
            }
            const message = normaliseErrorMessage(value)
            if (message) {
                list.push(message)
            }
        })
    return list
})

function metricFieldError(key) {
    if (!key) {
        return ''
    }
    const trimmed = String(key).trim()
    if (!trimmed) {
        return ''
    }
    return normaliseErrorMessage(props.errors?.[`metrics.${trimmed}`] ?? null)
}

function normaliseErrorMessage(value) {
    if (!value) {
        return ''
    }
    if (Array.isArray(value)) {
        return value.length ? String(value[0]) : ''
    }
    if (typeof value === 'string') {
        return value
    }
    return ''
}

function handleKeydown(event) {
    if (event.key === 'Escape') {
        handleClose()
    }
}

function handleClose() {
    emit('close')
}

function handleSubmit() {
    const payload = {
        datasetId: form.datasetId,
        notes: form.notes,
    }

    if (form.useManualMetrics) {
        payload.metrics = metricRows.value.reduce((acc, row) => {
            const key = String(row.key ?? '').trim()
            if (!key) {
                return acc
            }
            acc[key] = row.value
            return acc
        }, {})
    }

    emit('submit', payload)
}

function initialiseForm(model) {
    form.datasetId = model?.datasetId ?? model?.dataset_id ?? ''
    form.useManualMetrics = false
    form.notes = ''
    resetMetricRows()
    datasetError.value = ''
}

function addMetricRow() {
    metricRows.value = [
        ...metricRows.value,
        { id: nextMetricId(), key: '', value: '' },
    ]
}

function removeMetricRow(id) {
    if (metricRows.value.length <= 1) {
        resetMetricRows()
        return
    }
    metricRows.value = metricRows.value.filter((row) => row.id !== id)
}

function resetMetricRows() {
    metricRows.value = createDefaultMetricRows()
}

function createDefaultMetricRows() {
    const defaults = ['precision', 'recall', 'f1']
    return defaults.map((key) => ({ id: nextMetricId(), key, value: '' }))
}

function nextMetricId() {
    metricSeed += 1
    return `metric-${Date.now()}-${metricSeed}`
}

function ensureDatasetsLoaded() {
    if (!datasetLoaded.value && !datasetLoading.value) {
        void loadDatasets()
    }
}

function refreshDatasets() {
    datasetLoaded.value = false
    void loadDatasets(true)
}

async function loadDatasets(force = false) {
    if (datasetLoading.value) {
        return
    }

    if (!force && datasetLoaded.value) {
        return
    }

    datasetLoading.value = true
    datasetError.value = ''

    try {
        const params = { per_page: 100, sort: 'name', filter: { status: 'ready' } }
        const { data } = await apiClient.get('/datasets', { params })
        const options = Array.isArray(data?.data) ? data.data : []

        datasetOptions.value = options
            .map((dataset) => ({
                id: dataset.id,
                name: dataset.name ?? dataset.id,
                status: dataset.status ?? null,
                ingestedAt: dataset.ingested_at ?? null,
            }))
            .sort((a, b) => a.name.localeCompare(b.name, 'en', { sensitivity: 'base' }))

        datasetLoaded.value = true
    } catch (error) {
        datasetError.value =
            error?.response?.data?.message || error.message || 'Unable to load datasets.'
        notifyError(error, 'Unable to load datasets. You can still type an identifier manually.')
    } finally {
        datasetLoading.value = false
    }
}

function datasetLabel(option) {
    const trimmedName = (option.name || '').trim()
    const base = trimmedName && trimmedName !== option.id ? `${trimmedName} (${option.id})` : option.id
    const dateLabel = formatDatasetDate(option.ingestedAt)
    return dateLabel ? `${base} – ready ${dateLabel}` : base
}

function formatDatasetDate(value) {
    if (!value) {
        return ''
    }

    try {
        return new Intl.DateTimeFormat('en-GB', { dateStyle: 'medium' }).format(new Date(value))
    } catch (error) {
        return ''
    }
}
</script>
