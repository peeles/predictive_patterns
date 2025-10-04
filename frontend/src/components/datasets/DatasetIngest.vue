<template>
    <Transition name="fade">
        <div v-if="modelValue" class="fixed inset-0 z-40 flex items-center justify-center bg-stone-900/60 p-4" @click.self="close">
            <FocusTrap>
                <div
                    aria-describedby="dataset-ingest-description"
                    aria-labelledby="dataset-ingest-title"
                    aria-modal="true"
                    class="relative max-h-[90vh] w-full max-w-3xl overflow-hidden rounded-2xl bg-white shadow-xl"
                    @keydown.esc.prevent="close"
                    role="dialog"
                >
                    <header class="flex items-center justify-between border-b border-stone-200 px-6 py-4">
                        <div>
                            <h2 id="dataset-ingest-title" class="text-lg font-semibold text-slate-900">Dataset ingest wizard</h2>
                            <p id="dataset-ingest-description" class="text-sm text-slate-600">
                                Provide dataset details, choose a source, align schema headers, preview parsed rows, and submit for processing.
                            </p>
                        </div>
                        <button
                            class="rounded-full p-2 text-stone-500 transition hover:bg-stone-100 hover:text-stone-700 focus-visible:outline  focus-visible:outline-offset-2 focus-visible:outline-blue-500"
                            type="button"
                            @click="close"
                        >
                            <span class="sr-only">Close wizard</span>
                            <svg aria-hidden="true" class="h-5 w-5"  stroke="currentColor" viewBox="0 0 24 24">
                                <path d="M6 18L18 6M6 6l12 12" stroke-linecap="round" stroke-linejoin="round" stroke-width="2" />
                            </svg>
                        </button>
                    </header>

                    <nav aria-label="Wizard steps" class="border-b border-stone-200 bg-stone-50">
                        <ol class="flex divide-x divide-stone-200 text-sm">
                            <li
                                v-for="stepLabel in steps"
                                :key="stepLabel.key"
                                :aria-current="datasetStore.step === stepLabel.id ? 'step' : undefined"
                                class="flex-1 px-4 py-3"
                            >
                                <span
                                    :class="[
                                        'font-medium',
                                        datasetStore.step === stepLabel.id ? 'text-blue-600' : 'text-slate-500'
                                    ]"
                                >
                                    {{ stepLabel.label }}
                                </span>
                            </li>
                        </ol>
                    </nav>

                    <section class="max-h-[60vh] overflow-y-auto px-6 py-6 text-sm text-stone-700">
                        <component :is="activeStep" />
                    </section>

                    <footer class="flex items-center justify-between gap-3 border-t border-stone-200 px-6 py-4">
                        <button
                            class="rounded-md border border-stone-300 px-4 py-2 text-sm font-semibold text-stone-700 shadow-sm transition hover:border-stone-400 hover:text-stone-900 focus-visible:outline  focus-visible:outline-offset-2 focus-visible:outline-blue-500"
                            type="button"
                            @click="goBack"
                            :disabled="datasetStore.step === 1"
                        >
                            Back
                        </button>
                        <div class="flex items-center gap-3">
                            <button
                                v-if="datasetStore.step < steps.length"
                                class="inline-flex items-center justify-center rounded-md bg-blue-600 px-4 py-2 text-sm font-semibold text-white shadow-sm transition hover:bg-blue-700 focus-visible:outline  focus-visible:outline-offset-2 focus-visible:outline-blue-500 disabled:cursor-not-allowed disabled:bg-stone-400"
                                type="button"
                                :disabled="
                                    !canContinue ||
                                    (datasetStore.uploadState !== 'idle' && datasetStore.uploadState !== 'error')
                                "
                                @click="goNext"
                            >
                                Continue
                            </button>
                            <button
                                v-else
                                :disabled="
                                    datasetStore.submitting ||
                                    !canSubmit ||
                                    (datasetStore.uploadState !== 'idle' && datasetStore.uploadState !== 'error')
                                "
                                class="inline-flex items-center justify-center gap-2 rounded-md bg-stone-900 px-4 py-2 text-sm font-semibold text-white shadow-sm transition focus-visible:outline  focus-visible:outline-offset-2 focus-visible:outline-blue-500 disabled:cursor-not-allowed disabled:bg-stone-400"
                                type="button"
                                @click="submit"
                            >
                                <svg
                                    v-if="datasetStore.submitting"
                                    aria-hidden="true"
                                    class="h-4 w-4 animate-spin"

                                    viewBox="0 0 24 24"
                                >
                                    <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
                                    <path class="opacity-75" d="M4 12a8 8 0 018-8v4a4 4 0 00-4 4H4z" fill="currentColor"></path>
                                </svg>
                                <span>
                                    {{
                                        datasetStore.submitting
                                            ? 'Submitting…'
                                            : datasetStore.uploadState === 'processing'
                                                ? 'Processing…'
                                                : datasetStore.uploadState === 'completed'
                                                    ? 'Completed'
                                                    : datasetStore.uploadState === 'error'
                                                        ? 'Retry submission'
                                                        : 'Submit datasets'
                                    }}
                                </span>
                            </button>
                        </div>
                    </footer>
                </div>
            </FocusTrap>
        </div>
    </Transition>
</template>

<script setup>
import { computed, watch } from 'vue'
import { storeToRefs } from 'pinia'
import FocusTrap from '../accessibility/FocusTrap.vue'
import { useDatasetStore } from '../../stores/dataset'
import DetailsStep from './steps/DetailsStep.vue'
import SourceStep from './steps/SourceStep.vue'
import UploadStep from './steps/UploadStep.vue'
import SchemaStep from './steps/SchemaStep.vue'
import PreviewStep from './steps/PreviewStep.vue'

const props = defineProps({
    modelValue: {
        type: Boolean,
        default: false,
    },
})

const emit = defineEmits(['update:modelValue', 'submitted'])

const datasetStore = useDatasetStore()
const { step, canSubmit } = storeToRefs(datasetStore)

const steps = computed(() => {
    const orderedSteps = [
        { key: 'details', label: 'Details', component: DetailsStep },
        { key: 'source', label: 'Source', component: SourceStep },
    ]

    if (datasetStore.sourceType === 'file') {
        orderedSteps.push(
            { key: 'upload', label: 'Upload', component: UploadStep },
            { key: 'schema', label: 'Schema mapping', component: SchemaStep },
            { key: 'preview', label: 'Preview & submit', component: PreviewStep }
        )
    } else {
        orderedSteps.push({ key: 'review', label: 'Review & submit', component: PreviewStep })
    }

    return orderedSteps.map((stepConfig, index) => ({
        ...stepConfig,
        id: index + 1,
    }))
})

const currentStepIndex = computed(() => {
    if (steps.value.length === 0) {
        return -1
    }

    const index = steps.value.findIndex((stepConfig) => stepConfig.id === step.value)
    if (index !== -1) {
        return index
    }

    return Math.min(Math.max(step.value - 1, 0), steps.value.length - 1)
})

const activeStep = computed(() => {
    if (currentStepIndex.value === -1) {
        return null
    }
    return steps.value[currentStepIndex.value]?.component ?? null
})

const canContinue = computed(() => {
    if (currentStepIndex.value === -1) {
        return false
    }

    const currentStep = steps.value[currentStepIndex.value]
    if (!currentStep) {
        return false
    }

    switch (currentStep.key) {
        case 'details':
            return datasetStore.detailsValid
        case 'source':
            return datasetStore.sourceStepValid
        case 'upload':
            return datasetStore.hasValidFile
        case 'schema':
            return datasetStore.hasRequiredSchemaFields
        default:
            return true
    }
})

watch(
    steps,
    (newSteps) => {
        if (newSteps.length === 0) {
            datasetStore.setStep(1)
            return
        }

        const maxStepId = newSteps[newSteps.length - 1].id
        if (step.value > maxStepId) {
            datasetStore.setStep(maxStepId)
            return
        }

        if (step.value < newSteps[0].id) {
            datasetStore.setStep(newSteps[0].id)
        }
    },
    { immediate: true }
)

function goNext() {
    const nextStep = steps.value.find((stepConfig) => stepConfig.id === datasetStore.step + 1)
    if (nextStep) {
        datasetStore.setStep(nextStep.id)
    }
}

function goBack() {
    const previousStep = steps.value
        .slice()
        .reverse()
        .find((stepConfig) => stepConfig.id === datasetStore.step - 1)

    if (previousStep) {
        datasetStore.setStep(previousStep.id)
    }
}

function close() {
    emit('update:modelValue', false)
    datasetStore.reset()
}

async function submit() {
    if (!canSubmit.value) {
        return
    }
    const result = await datasetStore.submitIngestion({ submittedAt: new Date().toISOString() })
    if (result) {
        emit('submitted', result)
        if (datasetStore.uploadState !== 'error') {
            close()
        }
    }
}
</script>

<style scoped>
.fade-enter-active,
.fade-leave-active {
    transition: opacity 150ms ease;
}

.fade-enter-from,
.fade-leave-to {
    opacity: 0;
}
</style>
