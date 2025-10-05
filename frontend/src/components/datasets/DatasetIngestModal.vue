<template>
    <BaseModal
        :open="open"
        :dialog-class="'max-w-2xl'"
        :body-class="'min-h-[40vh]'"
        @close="handleClose"
    >
        <template #header>
            <h2
                id="dataset-ingest-title"
                class="text-lg font-semibold text-slate-900"
            >
                Ingestion Wizard
            </h2>
            <p
                id="dataset-ingest-description"
                class="text-sm text-slate-600"
            >
                Provide details, choose a dataset, align schema, preview and submit for processing.
            </p>
        </template>

        <template #steps>
            <nav
                aria-label="Wizard steps"
                class="border-b border-stone-200 bg-stone-50"
            >
                <ol class="flex text-sm divide-x divide-stone-200">
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
        </template>

        <component :is="activeStep"/>

        <template #footer>
            <button
                type="button"
                class="rounded-md border border-stone-300 px-4 py-2 text-sm font-semibold text-stone-700 shadow-sm
                transition hover:border-stone-400 hover:text-stone-900 focus-visible:outline
                focus-visible:outline-offset-2 focus-visible:outline-blue-500 disabled:cursor-not-allowed disabled:opacity-60"
                @click="goBack"
                :disabled="datasetStore.step === 1 || datasetStore.submitting"
            >
                Back
            </button>
            <div class="flex items-center gap-3">
                <button
                    v-if="datasetStore.step < steps.length"
                    :disabled="
                            !canContinue ||
                            (datasetStore.uploadState !== 'idle' && datasetStore.uploadState !== 'error')
                        "
                    class="inline-flex items-center justify-center rounded-md bg-blue-600 px-4 py-2 text-sm font-semibold text-white shadow-sm transition hover:bg-blue-700 focus-visible:outline focus-visible:outline-offset-2 focus-visible:outline-blue-500 disabled:cursor-not-allowed disabled:bg-stone-400"
                    type="button"
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
                    class="inline-flex items-center justify-center gap-2 rounded-md bg-stone-900 px-4 py-2 text-sm font-semibold text-white shadow-sm transition focus-visible:outline focus-visible:outline-offset-2 focus-visible:outline-blue-500 disabled:cursor-not-allowed disabled:bg-stone-400"
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
                    <span>{{ submissionButtonText }}</span>
                </button>
                <button
                    type="button"
                    class="rounded-md border border-stone-300 px-4 py-2 text-sm font-semibold text-stone-700 shadow-sm transition hover:border-stone-400 hover:text-stone-900 focus-visible:outline  focus-visible:outline-offset-2 focus-visible:outline-blue-500"
                    @click="handleClose"
                    :disabled="datasetStore.submitting"
                >
                    Cancel
                </button>
            </div>
        </template>
    </BaseModal>
</template>

<script setup>
import {computed, watch} from 'vue'
import {storeToRefs} from 'pinia'
import {useDatasetStore} from '../../stores/dataset'
import DetailsStep from './steps/DetailsStep.vue'
import SourceStep from './steps/SourceStep.vue'
import UploadStep from './steps/UploadStep.vue'
import SchemaStep from './steps/SchemaStep.vue'
import PreviewStep from './steps/PreviewStep.vue'
import BaseModal from "../common/BaseModal.vue";

const props = defineProps({
    open: {
        type: Boolean,
        default: false,
    },
})

const emit = defineEmits(['close', 'update:modelValue', 'submitted'])
const datasetStore = useDatasetStore()
const {step, canSubmit} = storeToRefs(datasetStore)

const steps = computed(() => {
    const orderedSteps = [
        {key: 'details', label: 'Details', component: DetailsStep},
        {key: 'source', label: 'Source', component: SourceStep},
    ]

    if (datasetStore.sourceType === 'file') {
        orderedSteps.push(
            {key: 'upload', label: 'Upload', component: UploadStep},
            {key: 'schema', label: 'Schema', component: SchemaStep},
            {key: 'preview', label: 'Preview', component: PreviewStep}
        )
    } else {
        orderedSteps.push({key: 'review', label: 'Review & Submit', component: PreviewStep})
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

const submissionButtonText = computed(() => {
    if (datasetStore.submitting) {
        return 'Submitting…'
    }
    if (datasetStore.uploadState === 'processing') {
        return 'Processing…'
    }
    if (datasetStore.uploadState === 'completed') {
        return 'Completed'
    }
    if (datasetStore.uploadState === 'error') {
        return 'Retry submission'
    }

    return 'Submit datasets'
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
    {immediate: true}
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

function handleClose() {
    datasetStore.reset();
    emit('close');
}

async function submit() {
    if (!canSubmit.value) {
        return
    }

    let result = false
    try {
        result = await datasetStore.submitIngestion({ submittedAt: new Date().toISOString() });

        if (result) {
            emit('submitted', result);
        }
    } finally {
        handleClose()
    }
}
</script>
