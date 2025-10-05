<template>
    <BaseModal
        :open="open"
        :dialog-class="'max-w-2xl'"
        :body-class="'max-h-[70vh]'"
        @close="handleClose"
    >
        <template #header>
            <h2 class="text-lg font-semibold text-stone-900">Create a new model</h2>
            <p class="mt-1 text-sm text-stone-600">
                Provide the model details and optionally queue an initial training run right away.
            </p>
        </template>

        <template #steps>
            <ol class="flex divide-x divide-stone-200 text-sm">
                <li
                    v-for="stepLabel in steps"
                    :key="stepLabel.id"
                    :aria-current="step === stepLabel.id ? 'step' : undefined"
                    class="flex-1 px-4 py-3"
                >
                    <span
                        :class="[
                            'font-medium',
                            step === stepLabel.id ? 'text-blue-600' : 'text-stone-500',
                        ]"
                    >
                        {{ stepLabel.label }}
                    </span>
                </li>
            </ol>
        </template>

        <form
            id="create-model-form"
            class="space-y-6"
            @submit.prevent="handleSubmit"
        >
            <div v-if="step === 1" class="space-y-6">
                <div>
                    <h3 class="text-sm font-semibold uppercase tracking-wide text-stone-600">Model Overview</h3>
                    <p class="mt-1 text-sm text-stone-600">
                        Define the core identifiers for the model and connect it to an approved dataset.
                    </p>
                </div>
                <div class="space-y-5 text-stone-700">
                    <div>
                        <label for="model-name" class="block text-sm font-medium text-stone-700">Model name</label>
                        <input
                            id="model-name"
                            v-model="form.name"
                            type="text"
                            name="name"
                            class="mt-1 block w-full rounded-md border border-stone-300 px-3 py-2 text-sm text-stone-900 shadow-sm focus:border-blue-500 focus:outline-none focus:ring-2 focus:ring-blue-500"
                            placeholder="e.g. Spatial Graph Attention"
                            autocomplete="off"
                        />
                        <p v-if="errors.name" class="mt-1 text-sm text-rose-600">{{ errors.name }}</p>
                    </div>
                    <div>
                        <div class="flex flex-wrap items-center justify-between gap-3">
                            <label for="dataset-id" class="block text-sm font-medium text-stone-700">Dataset</label>
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
                            id="dataset-id"
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
                            Start typing to filter existing datasets or leave blank to assign a dataset later.
                        </p>
                        <p v-if="datasetError" class="mt-1 text-sm text-rose-600">{{ datasetError }}</p>
                        <p v-else-if="!datasetLoading && !datasetOptions.length" class="mt-1 text-sm text-stone-500">
                            No ready datasets are available right now. You can still provide a dataset identifier manually.
                        </p>
                        <p v-if="errors.datasetId" class="mt-1 text-sm text-rose-600">{{ errors.datasetId }}</p>
                    </div>
                    <div class="grid gap-4 sm:grid-cols-2">
                        <div>
                            <label for="model-tag" class="block text-sm font-medium text-stone-700">Tag</label>
                            <input
                                id="model-tag"
                                v-model="form.tag"
                                type="text"
                                name="tag"
                                class="mt-1 block w-full rounded-md border border-stone-300 px-3 py-2 text-sm text-stone-900 shadow-sm focus:border-blue-500 focus:outline-none focus:ring-2 focus:ring-blue-500"
                                placeholder="Optional tag (e.g. baseline)"
                                autocomplete="off"
                            />
                            <p v-if="errors.tag" class="mt-1 text-sm text-rose-600">{{ errors.tag }}</p>
                        </div>
                        <div>
                            <label for="model-area" class="block text-sm font-medium text-stone-700">Area</label>
                            <input
                                id="model-area"
                                v-model="form.area"
                                type="text"
                                name="area"
                                class="mt-1 block w-full rounded-md border border-stone-300 px-3 py-2 text-sm text-stone-900 shadow-sm focus:border-blue-500 focus:outline-none focus:ring-2 focus:ring-blue-500"
                                placeholder="Optional geography or scope"
                                autocomplete="off"
                            />
                            <p v-if="errors.area" class="mt-1 text-sm text-rose-600">{{ errors.area }}</p>
                        </div>
                        <div>
                            <label for="model-version" class="block text-sm font-medium text-stone-700">Version</label>
                            <input
                                id="model-version"
                                v-model="form.version"
                                type="text"
                                name="version"
                                class="mt-1 block w-full rounded-md border border-stone-300 px-3 py-2 text-sm text-stone-900 shadow-sm focus:border-blue-500 focus:outline-none focus:ring-2 focus:ring-blue-500"
                                placeholder="Defaults to 1.0.0"
                                autocomplete="off"
                            />
                            <p v-if="errors.version" class="mt-1 text-sm text-rose-600">{{ errors.version }}</p>
                        </div>
                    </div>
                </div>
            </div>
            <div v-else-if="step === 2" class="space-y-6">
                <div>
                    <h3 class="text-sm font-semibold uppercase tracking-wide text-stone-600">Training & metadata</h3>
                    <p class="mt-1 text-sm text-stone-600">
                        Provide optional configuration for the initial training job and add descriptive metadata.
                    </p>
                </div>
                <div class="grid gap-4 md:grid-cols-2">
                    <div>
                        <label for="model-hyperparameters" class="block text-sm font-medium text-stone-700">
                            Hyperparameters (JSON)
                        </label>
                        <textarea
                            id="model-hyperparameters"
                            v-model="form.hyperparameters"
                            name="hyperparameters"
                            rows="4"
                            class="mt-1 block w-full rounded-md border border-stone-300 px-3 py-2 text-sm text-stone-900 shadow-sm focus:border-blue-500 focus:outline-none focus:ring-2 focus:ring-blue-500"
                            placeholder='{ "learning_rate": 0.01 }'
                        ></textarea>
                        <p v-if="errors.hyperparameters" class="mt-1 text-sm text-rose-600">{{ errors.hyperparameters }}</p>
                    </div>
                    <div>
                        <label for="model-metadata" class="block text-sm font-medium text-stone-700">Metadata (JSON)</label>
                        <textarea
                            id="model-metadata"
                            v-model="form.metadata"
                            name="metadata"
                            rows="4"
                            class="mt-1 block w-full rounded-md border border-stone-300 px-3 py-2 text-sm text-stone-900 shadow-sm focus:border-blue-500 focus:outline-none focus:ring-2 focus:ring-blue-500"
                            placeholder='{ "notes": "First experiment" }'
                        ></textarea>
                        <p v-if="errors.metadata" class="mt-1 text-sm text-rose-600">{{ errors.metadata }}</p>
                    </div>
                </div>
                <div class="flex items-center gap-3 rounded-lg bg-stone-50 px-4 py-3">
                    <input
                        id="auto-train"
                        v-model="form.autoTrain"
                        type="checkbox"
                        class="h-4 w-4 rounded border-stone-300 text-blue-600 focus:ring-blue-500"
                    />
                    <label for="auto-train" class="text-sm text-stone-700">Queue an initial training run after creating the model</label>
                </div>
            </div>
        </form>

        <template #footer>
            <button
                type="button"
                class="rounded-md border border-stone-300 px-4 py-2 text-sm font-semibold text-stone-700 shadow-sm
                transition hover:border-stone-400 hover:text-stone-900 focus-visible:outline
                focus-visible:outline-offset-2 focus-visible:outline-blue-500 disabled:cursor-not-allowed disabled:opacity-60"
                @click="goBack"
                :disabled="step === 1 || submitting"
            >
                Back
            </button>
            <div class="flex items-center gap-3">
                <button
                    v-if="step < steps.length"
                    type="button"
                    class="inline-flex items-center justify-center rounded-md bg-blue-600 px-4 py-2 text-sm font-semibold text-white shadow-sm transition hover:bg-blue-700 focus-visible:outline  focus-visible:outline-offset-2 focus-visible:outline-blue-500 disabled:cursor-not-allowed disabled:bg-stone-400"
                    :disabled="!canContinue || submitting"
                    @click="goNext"
                >
                    Continue
                </button>
                <button
                    v-else
                    type="submit"
                    form="create-model-form"
                    class="inline-flex items-center justify-center rounded-md bg-blue-600 px-4 py-2 text-sm font-semibold text-white shadow-sm transition hover:bg-blue-700 focus-visible:outline  focus-visible:outline-offset-2 focus-visible:outline-blue-500 disabled:cursor-not-allowed disabled:bg-stone-400"
                    :disabled="submitting"
                >
                    <span v-if="submitting">Creating…</span>
                    <span v-else>Create model</span>
                </button>
                <button
                    type="button"
                    class="rounded-md border border-stone-300 px-4 py-2 text-sm font-semibold text-stone-700 shadow-sm transition hover:border-stone-400 hover:text-stone-900 focus-visible:outline  focus-visible:outline-offset-2 focus-visible:outline-blue-500"
                    @click="handleClose"
                    :disabled="submitting"
                >
                    Cancel
                </button>
            </div>
        </template>
    </BaseModal>
</template>

<script setup>
import { computed, onBeforeUnmount, reactive, ref, watch } from 'vue'
import apiClient from '../../services/apiClient'
import { notifyError } from '../../utils/notifications'
import { useModelStore } from '../../stores/model'
import BaseModal from '../common/BaseModal.vue'

const props = defineProps({
    open: {
        type: Boolean,
        default: false,
    },
})

const emit = defineEmits(['close', 'created'])

const modelStore = useModelStore()

const datasetListId = 'model-datasets-list'
const datasetOptions = ref([])
const datasetLoading = ref(false)
const datasetLoaded = ref(false)
const datasetError = ref('')

const steps = [
    { id: 1, label: 'Details' },
    { id: 2, label: 'Metadata' },
]

const step = ref(1)

const form = reactive({
    name: '',
    datasetId: '',
    tag: '',
    area: '',
    version: '',
    hyperparameters: '',
    metadata: '',
    autoTrain: true,
})

const errors = reactive({
    name: '',
    datasetId: '',
    tag: '',
    area: '',
    version: '',
    hyperparameters: '',
    metadata: '',
})

const training = ref(false)

const submitting = computed(() => modelStore.creating || training.value)

const canContinue = computed(() => {
    if (step.value === 1) {
        const trimmedName = form.name.trim()
        const trimmedDataset = form.datasetId.trim()
        return Boolean(trimmedName) && (!trimmedDataset || trimmedDataset.length >= 4)
    }
    return true
})

watch(
    () => props.open,
    (value) => {
        if (value) {
            window.addEventListener('keydown', handleKeydown)
            ensureDatasetsLoaded()
            step.value = 1
        } else {
            window.removeEventListener('keydown', handleKeydown)
            reset()
        }
    },
)

onBeforeUnmount(() => {
    window.removeEventListener('keydown', handleKeydown)
})

function handleKeydown(event) {
    if (event.key === 'Escape') {
        handleClose()
    }
}

function reset() {
    form.name = ''
    form.datasetId = ''
    form.tag = ''
    form.area = ''
    form.version = ''
    form.hyperparameters = ''
    form.metadata = ''
    form.autoTrain = true
    errors.name = ''
    errors.datasetId = ''
    errors.tag = ''
    errors.area = ''
    errors.version = ''
    errors.hyperparameters = ''
    errors.metadata = ''
    training.value = false
    step.value = 1
}

function handleClose() {
    emit('close')
}

function goNext() {
    if (step.value >= steps.length || submitting.value) {
        return
    }

    const { valid } = validate(1)
    if (!valid) {
        return
    }

    step.value += 1
}

function goBack() {
    if (step.value <= 1 || submitting.value) {
        return
    }

    step.value -= 1
}

function handleSubmit() {
    if (step.value < steps.length) {
        goNext()
        return
    }

    void submit()
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
        const params = { per_page: 100, sort: 'name' }
        params.filter = { status: 'ready' }

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

function parseJsonField(value, field) {
    errors[field] = ''
    if (!value) {
        return null
    }

    try {
        const parsed = JSON.parse(value)
        if (parsed && typeof parsed === 'object' && !Array.isArray(parsed)) {
            return parsed
        }
        errors[field] = 'Provide a JSON object.'
    } catch (error) {
        errors[field] = 'Invalid JSON. Please double-check the structure.'
    }

    return null
}

function validate(targetStep = step.value) {
    let valid = true

    let hyperparameters = null
    let metadata = null

    if (targetStep >= 1) {
        errors.name = form.name.trim() ? '' : 'Model name is required.'
        if (errors.name) {
            valid = false
        }

        errors.tag = ''
        errors.area = ''
        errors.version = ''

        if (form.datasetId && form.datasetId.trim().length < 4) {
            errors.datasetId = 'Dataset identifiers should be at least 4 characters long.'
            valid = false
        } else {
            errors.datasetId = ''
        }
    }

    if (targetStep >= 2) {
        hyperparameters = parseJsonField(form.hyperparameters, 'hyperparameters')
        metadata = parseJsonField(form.metadata, 'metadata')

        if (errors.hyperparameters || errors.metadata) {
            valid = false
        }
    }

    return { valid, hyperparameters, metadata }
}

function resolveErrorField(field) {
    if (!field) {
        return ''
    }

    const base = String(field).split('.')[0]
    return base.replace(/_([a-z])/g, (_, character) => character.toUpperCase())
}

async function submit() {
    if (submitting.value) {
        return
    }

    const { valid, hyperparameters, metadata } = validate(steps.length)
    if (!valid) {
        return
    }

    const payload = {
        name: form.name.trim(),
        datasetId: form.datasetId.trim() || null,
        tag: form.tag.trim() || null,
        area: form.area.trim() || null,
        version: form.version.trim() || null,
        hyperparameters: hyperparameters ?? undefined,
        metadata: metadata ?? undefined,
    }

    const { model, errors: validationErrors } = await modelStore.createModel(payload)

    if (!model) {
        if (validationErrors) {
            Object.entries(validationErrors).forEach(([field, messages]) => {
                const resolved = resolveErrorField(field)
                if (resolved in errors) {
                    errors[resolved] = Array.isArray(messages) ? messages.join(' ') : String(messages)
                }
            })
        }
        return
    }

    if (form.autoTrain) {
        training.value = true
        await modelStore.trainModel(model.id, hyperparameters ?? undefined)
        training.value = false
    }

    emit('created', model)
    handleClose()
}
</script>
