<template>
    <section class="space-y-4" aria-labelledby="upload-heading">
        <header>
            <h3
                id="upload-heading"
                class="text-sm font-semibold uppercase tracking-wide text-stone-600"
            >
                Upload dataset
            </h3>
            <p class="mt-1 text-sm text-stone-600">Supported formats: CSV or JSON up to {{ MAX_FILE_SIZE_MB }}MB.</p>
        </header>
        <label
            class="flex cursor-pointer flex-col items-center justify-center rounded-xl border-2 border-dashed border-stone-300 bg-stone-50 px-6 py-12 text-center transition hover:border-stone-400 focus-within:border-blue-500 focus-within:outline focus-within:outline-2 focus-within:outline-offset-2 focus-within:outline-blue-500"
        >
            <input class="sr-only" type="file" multiple accept=".csv,.json" @change="onFileChange" />
            <svg aria-hidden="true" class="h-10 w-10 text-stone-400"  stroke="currentColor" viewBox="0 0 24 24">
                <path d="M12 16V4m0 0l-3.5 3.5M12 4l3.5 3.5" stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" />
                <path d="M6 16v2a2 2 0 002 2h8a2 2 0 002-2v-2" stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" />
            </svg>
            <span class="mt-3 text-sm font-medium text-stone-700">Select files</span>
            <span class="mt-1 text-xs text-stone-500">Drop one or more CSV files, or browse from your computer.</span>
        </label>
        <div v-if="datasetStore.uploadFiles.length" class="space-y-1 text-sm text-stone-600">
            <p class="font-medium text-stone-700">
                Selected {{ datasetStore.uploadFiles.length === 1 ? 'file' : 'files' }}:
            </p>
            <ul class="space-y-1">
                <li
                    v-for="file in datasetStore.uploadFiles"
                    :key="file.name + file.size"
                    class="flex items-center justify-between rounded-lg border border-stone-200 bg-white px-3 py-2 text-xs shadow-sm"
                >
                    <span class="font-medium text-stone-700">{{ file.name }}</span>
                    <span class="text-stone-500">{{ formatFileSize(file.size) }}</span>
                </li>
            </ul>
        </div>
        <ul v-if="datasetStore.validationErrors.length" class="space-y-2" role="list">
            <li
                v-for="error in datasetStore.validationErrors"
                :key="error"
                class="rounded-md border border-rose-200 bg-rose-50 px-4 py-2 text-sm text-rose-700"
            >
                {{ error }}
            </li>
        </ul>
    </section>
</template>

<script setup>
import { useDatasetStore, MAX_FILE_SIZE_MB } from '../../../stores/dataset'

const datasetStore = useDatasetStore()

async function onFileChange(event) {
    const files = Array.from(event.target.files || [])
    if (!datasetStore.validateFiles(files)) {
        event.target.value = ''
        return
    }
    await datasetStore.parsePreview(datasetStore.primaryUploadFile)
    event.target.value = ''
}

function formatFileSize(bytes) {
    if (!Number.isFinite(bytes)) return ''
    const units = ['bytes', 'KB', 'MB', 'GB']
    let value = bytes
    let unitIndex = 0
    while (value >= 1024 && unitIndex < units.length - 1) {
        value /= 1024
        unitIndex += 1
    }
    return `${value.toFixed(unitIndex === 0 ? 0 : 1)} ${units[unitIndex]}`
}
</script>
