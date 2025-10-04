<template>
    <BaseModal
        :open="open"
        @close="handleClose"
    >
        <template #header>
            <p class="text-xs font-semibold uppercase tracking-wider text-stone-500">Forecast workspace</p>
            <h2 id="predict-form-heading" class="text-2xl font-semibold text-stone-900">Generate a prediction</h2>
        </template>

        <PredictForm
            :disabled="predictionStore.loading"
            :initial-filters="predictionStore.lastFilters"
            :errors="formErrors"
            @submit="handleSubmit"
        />
    </BaseModal>
</template>

<script setup>
import PredictForm from "./PredictForm.vue";
import {usePredictionStore} from "../../stores/prediction.js";
import {ref} from "vue";
import BaseModal from "../common/BaseModal.vue";

const props = defineProps({
    open: {
        type: Boolean,
        default: false,
    },
})

const emit = defineEmits(['close', 'generated'])

const predictionStore = usePredictionStore()

const formErrors = ref({})

async function handleSubmit(payload) {
    formErrors.value = {}
    try {
        await predictionStore.submitPrediction(payload)
        emit('generated')
    } catch (error) {
        if (error.validationErrors) {
            formErrors.value = error.validationErrors
        }
    }
}

function handleClose() {
    emit('close')
}
</script>
