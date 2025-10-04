<template>
    <section class="space-y-4" aria-labelledby="schema-heading">
        <header>
            <h3 id="schema-heading" class="text-base font-semibold text-stone-900">Map schema</h3>
            <p class="mt-1 text-sm text-stone-600">
                Align your columns to the platform schema. All required fields must be mapped before continuing.
            </p>
        </header>
        <div class="grid gap-4 md:grid-cols-2">
            <div v-for="field in requiredFields" :key="field.id" class="flex flex-col gap-2">
                <label :for="`mapping-${field.id}`" class="text-sm font-medium text-stone-800">
                    {{ field.label }}
                    <span class="ml-1 text-xs text-rose-600" aria-hidden="true">*</span>
                </label>
                <select
                    :id="`mapping-${field.id}`"
                    v-model="localMapping[field.id]"
                    class="rounded-md border border-stone-300 px-3 py-2 text-sm shadow-sm focus:border-blue-500 focus:outline-none focus:ring-2 focus:ring-blue-200"
                >
                    <option value="">Select column</option>
                    <option v-for="option in columnOptions" :key="option" :value="option">{{ option }}</option>
                </select>
            </div>
            <div class="md:col-span-2 flex flex-col gap-2">
                <label for="optional-risk" class="text-sm font-medium text-stone-800">Optional risk score column</label>
                <select
                    id="optional-risk"
                    v-model="localMapping.risk"
                    class="rounded-md border border-stone-300 px-3 py-2 text-sm shadow-sm focus:border-blue-500 focus:outline-none focus:ring-2 focus:ring-blue-200"
                >
                    <option value="">Auto-calculate</option>
                    <option v-for="option in columnOptions" :key="`risk-${option}`" :value="option">{{ option }}</option>
                </select>
            </div>
            <div class="md:col-span-2 flex flex-col gap-2">
                <label for="optional-label" class="text-sm font-medium text-stone-800">Label column</label>
                <select
                    id="optional-label"
                    v-model="localMapping.label"
                    class="rounded-md border border-stone-300 px-3 py-2 text-sm shadow-sm focus:border-blue-500 focus:outline-none focus:ring-2 focus:ring-blue-200"
                >
                    <option value="">No label</option>
                    <option v-for="option in columnOptions" :key="`label-${option}`" :value="option">{{ option }}</option>
                </select>
            </div>
        </div>
    </section>
</template>

<script setup>
import { computed, reactive, watch } from 'vue'
import { useDatasetStore } from '../../../stores/dataset'

const datasetStore = useDatasetStore()

const requiredFields = [
    { id: 'timestamp', label: 'Event timestamp' },
    { id: 'latitude', label: 'Latitude' },
    { id: 'longitude', label: 'Longitude' },
    { id: 'category', label: 'Incident category' },
]

const columnOptions = computed(() => {
    if (!datasetStore.previewRows.length) return []
    return Object.keys(datasetStore.previewRows[0] ?? {})
})

const localMapping = reactive({
    timestamp: datasetStore.schemaMapping.timestamp || '',
    latitude: datasetStore.schemaMapping.latitude || '',
    longitude: datasetStore.schemaMapping.longitude || '',
    category: datasetStore.schemaMapping.category || '',
    risk: datasetStore.schemaMapping.risk || '',
    label: datasetStore.schemaMapping.label || '',
})

watch(
    () => ({ ...localMapping }),
    (value) => {
        datasetStore.setSchemaMapping(value)
    },
    { deep: true }
)
</script>
