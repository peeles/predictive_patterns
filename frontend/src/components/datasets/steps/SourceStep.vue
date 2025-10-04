<template>
    <section class="space-y-4" aria-labelledby="source-heading">
        <header>
            <h3 id="source-heading" class="text-base font-semibold text-slate-900">Select data source</h3>
            <p class="mt-1 text-sm text-slate-600">
                Choose whether to upload local files or import an archive from a remote URL.
            </p>
        </header>
        <fieldset class="space-y-3">
            <legend class="sr-only">Dataset source type</legend>
            <div
                v-for="option in sourceOptions"
                :key="option.value"
                :class="[
                    'flex flex-col gap-2 rounded-xl border px-4 py-3 shadow-sm transition',
                    sourceType === option.value
                        ? 'border-blue-500 ring-2 ring-blue-200'
                        : 'border-slate-200 hover:border-slate-300',
                ]"
            >
                <label class="flex cursor-pointer items-start gap-3">
                    <input
                        class="mt-1 h-4 w-4 border-slate-300 text-blue-600 focus:ring-blue-500"
                        name="dataset-source"
                        type="radio"
                        :value="option.value"
                        v-model="sourceType"
                    />
                    <span>
                        <span class="block text-sm font-semibold text-slate-900">{{ option.label }}</span>
                        <span class="mt-1 block text-sm text-slate-600">{{ option.description }}</span>
                    </span>
                </label>
                <div v-if="option.value === 'url' && sourceType === 'url'" class="pl-7">
                    <label for="dataset-source-url" class="text-xs font-medium text-slate-700">Dataset URL</label>
                    <input
                        id="dataset-source-url"
                        v-model="sourceUri"
                        class="mt-1 w-full rounded-md border border-slate-300 px-3 py-2 text-sm shadow-sm focus:border-blue-500 focus:outline-none focus:ring-2 focus:ring-blue-200"
                        placeholder="https://example.com/archive.csv"
                        type="url"
                    />
                    <p v-if="!datasetStore.sourceUriProvided" class="mt-1 text-xs text-slate-500">
                        Provide a direct link to a CSV, JSON, or zipped archive. The file must be publicly accessible.
                    </p>
                    <p v-else-if="!datasetStore.sourceUriValid" class="mt-1 text-xs text-rose-600">
                        Enter a valid HTTP or HTTPS URL to continue.
                    </p>
                    <p v-else class="mt-1 text-xs text-emerald-600">URL looks valid.</p>
                </div>
            </div>
        </fieldset>
    </section>
</template>

<script setup>
import { computed } from 'vue'
import { useDatasetStore } from '../../../stores/dataset'

const datasetStore = useDatasetStore()

const sourceType = computed({
    get: () => datasetStore.sourceType,
    set: (value) => datasetStore.setSourceType(value),
})

const sourceUri = computed({
    get: () => datasetStore.sourceUri,
    set: (value) => datasetStore.setSourceUri(value),
})

const sourceOptions = [
    {
        value: 'file',
        label: 'Upload files',
        description: 'Upload one or more local files to validate the schema before ingestion.',
    },
    {
        value: 'url',
        label: 'Import from URL',
        description: 'Provide a URL to download and ingest a remote archive automatically.',
    },
]
</script>
