<template>
    <div class="space-y-6">
        <header class="flex flex-wrap items-center justify-between">
            <div class="space-y-2">
                <p class="text-xs font-semibold uppercase tracking-wider text-stone-500">Governance Workspace</p>
                <h1 class="text-2xl font-semibold text-stone-900">Learning Models</h1>
                <p class="mt-1 max-w-2xl text-sm text-stone-600">
                    Administer forecasting models, trigger retraining, and schedule evaluations.
                </p>
            </div>
            <button
                v-if="isAdmin"
                class="inline-flex items-center justify-center rounded-md bg-blue-600 px-4 py-2 text-sm font-semibold text-white shadow-sm transition hover:bg-blue-700 focus-visible:outline  focus-visible:outline-offset-2 focus-visible:outline-blue-500"
                type="button"
                @click="openWizard"
            >
                Launch model wizard
            </button>
        </header>

        <CreateModelModal
            v-if="isAdmin"
            :open="wizardOpen"
            @close="wizardOpen = false"
            @created="handleCreated"
        />

        <ModelsTable
            @request-create="wizardOpen = true"
            @select-model="handleModelSelection"
        />

        <ModelEvaluationsPanel
            :models="models"
            :selected-id="selectedModelId"
            :loading="modelStore.loading"
            :refresh-states="evaluationRefresh"
            @select="handleModelSelection"
        />
    </div>
</template>

<script setup>
import { ref, watch } from 'vue'
import { storeToRefs } from 'pinia'
import CreateModelModal from '../../components/models/CreateModelModal.vue'
import ModelsTable from '../../components/models/ModelsTable.vue'
import ModelEvaluationsPanel from '../../components/models/ModelEvaluationsPanel.vue'
import { useAuthStore } from '../../stores/auth'
import { useModelStore } from '../../stores/model'

const authStore = useAuthStore()
const modelStore = useModelStore()
const { isAdmin } = storeToRefs(authStore)
const { models, evaluationRefresh } = storeToRefs(modelStore)

const wizardOpen = ref(false)
const selectedModelId = ref('')

watch(
    models,
    (list) => {
        if (!Array.isArray(list) || !list.length) {
            selectedModelId.value = ''
            return
        }

        const exists = list.some((model) => model.id === selectedModelId.value)
        if (!exists) {
            selectedModelId.value = list[0].id
        }
    },
    { immediate: true }
)

function handleCreated() {
    wizardOpen.value = false
    modelStore.fetchModels({ page: 1 })
}

function openWizard() {
    wizardOpen.value = true
}

function handleModelSelection(modelId) {
    selectedModelId.value = modelId || ''
}
</script>
