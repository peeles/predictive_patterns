<template>
    <section class="space-y-4" aria-labelledby="details-heading">
        <header>
            <h3 id="details-heading" class="text-base font-semibold text-slate-900">Dataset details</h3>
            <p class="mt-1 text-sm text-slate-600">
                Provide a descriptive name and optional summary to help your team identify this dataset later.
            </p>
        </header>
        <div class="space-y-3">
            <div class="flex flex-col gap-2">
                <label for="dataset-name" class="text-sm font-medium text-slate-800">
                    Dataset name
                    <span class="ml-1 text-xs text-rose-600" aria-hidden="true">*</span>
                </label>
                <input
                    id="dataset-name"
                    v-model="name"
                    class="rounded-md border border-slate-300 px-3 py-2 text-sm shadow-sm focus:border-blue-500 focus:outline-none focus:ring-2 focus:ring-blue-200"
                    maxlength="255"
                    placeholder="e.g. March 2025 assaults"
                    type="text"
                />
                <p v-if="nameError" class="text-xs text-rose-600">{{ nameError }}</p>
            </div>
            <div class="flex flex-col gap-2">
                <label for="dataset-description" class="text-sm font-medium text-slate-800">Description</label>
                <textarea
                    id="dataset-description"
                    v-model="description"
                    class="min-h-[96px] rounded-md border border-slate-300 px-3 py-2 text-sm shadow-sm focus:border-blue-500 focus:outline-none focus:ring-2 focus:ring-blue-200"
                    maxlength="1000"
                    placeholder="Outline the source, timeframe, or unique characteristics of this dataset."
                ></textarea>
                <p class="text-xs text-slate-500">Optional. Up to 1,000 characters.</p>
            </div>
        </div>
    </section>
</template>

<script setup>
import { computed } from 'vue'
import { useDatasetStore } from '../../../stores/dataset'

const datasetStore = useDatasetStore()

const name = computed({
    get: () => datasetStore.name,
    set: (value) => datasetStore.setName(value),
})

const description = computed({
    get: () => datasetStore.description,
    set: (value) => datasetStore.setDescription(value),
})

const nameError = computed(() => {
    if (datasetStore.name.trim().length > 0) {
        return ''
    }
    return 'Dataset name is required.'
})
</script>
