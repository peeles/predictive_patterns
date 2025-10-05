<template>
    <section class="space-y-4" aria-labelledby="preview-heading">
        <header>
            <h3
                id="preview-heading"
                class="text-sm font-semibold uppercase tracking-wide text-stone-600"
            >
                Preview
            </h3>
            <p class="mt-1 text-sm text-stone-600">
                Confirm the parsed rows and schema alignment before submitting the dataset for ingestion.
            </p>
        </header>

        <article class="rounded-lg border border-slate-200 bg-slate-50 p-4 text-sm text-slate-700">
            <h4 class="text-sm font-semibold text-slate-900">Submission summary</h4>
            <dl class="mt-3 grid grid-cols-1 gap-3 sm:grid-cols-2">
                <div class="flex flex-col gap-1 rounded-md bg-white px-3 py-2 shadow-sm">
                    <dt class="text-xs font-semibold uppercase tracking-wide text-slate-500">Name</dt>
                    <dd class="text-sm text-slate-800">{{ datasetStore.name }}</dd>
                </div>
                <div class="flex flex-col gap-1 rounded-md bg-white px-3 py-2 shadow-sm">
                    <dt class="text-xs font-semibold uppercase tracking-wide text-slate-500">Source</dt>
                    <dd class="text-sm text-slate-800">{{ sourceLabel }}</dd>
                </div>
                <div v-if="datasetStore.description" class="sm:col-span-2 flex flex-col gap-1 rounded-md bg-white px-3 py-2 shadow-sm">
                    <dt class="text-xs font-semibold uppercase tracking-wide text-slate-500">Description</dt>
                    <dd class="text-sm text-slate-700">{{ datasetStore.description }}</dd>
                </div>
                <div v-if="datasetStore.sourceType === 'url'" class="sm:col-span-2 flex flex-col gap-1 rounded-md bg-white px-3 py-2 shadow-sm">
                    <dt class="text-xs font-semibold uppercase tracking-wide text-slate-500">Import URL</dt>
                    <dd class="text-sm text-slate-700 break-words">{{ datasetStore.sourceUri }}</dd>
                </div>
            </dl>
        </article>

        <article v-if="datasetStore.sourceType === 'file'" class="rounded-lg border border-slate-200 bg-slate-50 p-4 text-sm text-slate-700">
            <h4 class="text-sm font-semibold text-slate-900">Schema summary</h4>
            <dl class="mt-3 grid grid-cols-1 gap-2 sm:grid-cols-2">
                <template v-for="(value, key) in datasetStore.schemaMapping" :key="key">
                    <div class="flex justify-between gap-4 rounded-md bg-white px-3 py-2 shadow-sm">
                        <dt class="font-medium capitalize">{{ key }}</dt>
                        <dd class="text-stone-600">{{ value || 'Auto' }}</dd>
                    </div>
                </template>
            </dl>
        </article>

        <article class="rounded-lg border border-slate-200 bg-slate-50 p-4 text-sm text-slate-700">
            <header class="flex items-start justify-between gap-3">
                <div>
                    <h4 class="text-sm font-semibold text-slate-900">{{ progressTitle }}</h4>
                    <p class="mt-1 text-xs" :class="progressMessageClass">
                        {{ progressMessage }}
                    </p>
                </div>
                <span v-if="showProgressValue" class="text-xs font-semibold text-stone-600">{{ progressValue }}</span>
            </header>

            <div v-if="hasStarted && showProgressBar" class="mt-4 h-2 overflow-hidden rounded-full bg-stone-200">
                <div
                    class="h-full rounded-full bg-blue-600 transition-all duration-200"
                    :class="datasetStore.uploadState === 'completed' ? 'bg-emerald-600' : ''"
                    :style="{ width: `${progressWidth}%` }"
                ></div>
            </div>

            <div
                v-else-if="hasStarted && datasetStore.uploadState === 'error'"
                class="mt-4 rounded-md border border-rose-200 bg-rose-50 px-3 py-2 text-xs text-rose-700"
            >
                {{ datasetStore.uploadError || 'Dataset ingestion failed.' }}
            </div>

            <div v-else-if="hasStarted" class="mt-4 flex items-center gap-2 text-xs text-stone-600">
                <svg aria-hidden="true" class="h-4 w-4 animate-spin text-stone-500"  viewBox="0 0 24 24">
                    <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
                    <path class="opacity-75" d="M4 12a8 8 0 018-8v4a4 4 0 00-4 4H4z" fill="currentColor"></path>
                </svg>
                <span>Awaiting ingestion updates…</span>
            </div>
        </article>
    </section>
</template>

<script setup>
import { computed } from 'vue'
import { useDatasetStore } from '../../../stores/dataset'

const datasetStore = useDatasetStore()

const hasStarted = computed(() => datasetStore.uploadState !== 'idle')

const showProgressBar = computed(() => {
    if (!hasStarted.value) {
        return false
    }
    if (datasetStore.uploadState === 'uploading' || datasetStore.uploadState === 'completed') {
        return true
    }
    const progress = datasetStore.realtimeStatus?.progress
    return datasetStore.uploadState === 'processing' && typeof progress === 'number'
})

const progressWidth = computed(() => {
    if (!hasStarted.value) {
        return 0
    }
    if (datasetStore.uploadState === 'completed') {
        return 100
    }
    if (datasetStore.uploadState === 'uploading') {
        return Math.min(100, Math.max(0, Math.round(datasetStore.uploadProgress)))
    }
    const progress = datasetStore.realtimeStatus?.progress
    if (typeof progress === 'number' && Number.isFinite(progress)) {
        return Math.min(100, Math.max(0, Math.round(progress * 100)))
    }
    return Math.min(100, Math.max(0, Math.round(datasetStore.uploadProgress)))
})

const showProgressValue = computed(() => {
    if (!hasStarted.value) {
        return false
    }
    if (datasetStore.uploadState === 'uploading' || datasetStore.uploadState === 'completed') {
        return true
    }
    return typeof datasetStore.realtimeStatus?.progress === 'number'
})

const progressValue = computed(() => `${progressWidth.value}%`)

const progressMessage = computed(() => {
    switch (datasetStore.uploadState) {
        case 'uploading':
            return `Uploading dataset… ${progressWidth.value}%`
        case 'processing':
            if (datasetStore.realtimeStatus?.status === 'failed') {
                return datasetStore.uploadError || 'Dataset ingestion failed.'
            }
            if (datasetStore.realtimeStatus?.status === 'ready') {
                return 'Dataset ingestion completed successfully.'
            }
            if (datasetStore.realtimeStatus?.status === 'pending') {
                return 'Dataset queued. Waiting for the remote download to start…'
            }
            return 'Validating and ingesting the datasets. This may take a couple of minutes.'
        case 'completed':
            return 'Dataset ingestion completed successfully.'
        case 'error':
            return datasetStore.uploadError || 'Dataset ingestion failed. Please try again.'
        default:
            return 'Submit the datasets to start ingestion and track progress here.'
    }
})

const progressTitle = computed(() => {
    switch (datasetStore.uploadState) {
        case 'completed':
            return 'Dataset ready'
        case 'error':
            return 'Ingestion failed'
        case 'processing':
            return datasetStore.realtimeStatus?.status === 'pending'
                ? 'Dataset queued'
                : 'Ingestion in progress'
        case 'uploading':
            return 'Uploading datasets'
        default:
            return 'Ingestion progress'
    }
})

const progressMessageClass = computed(() =>
    datasetStore.uploadState === 'error' ? 'text-rose-600' : 'text-stone-600'
)

const sourceLabel = computed(() => {
    if (datasetStore.sourceType === 'url') {
        return 'Import from URL'
    }
    if (datasetStore.uploadFiles.length > 1) {
        return `Upload (${datasetStore.uploadFiles.length} files)`
    }
    return datasetStore.uploadFiles.length === 1 ? datasetStore.uploadFiles[0].name : 'Upload files'
})
</script>
