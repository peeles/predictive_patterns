<template>
    <div class="space-y-6">
        <PageHeader
            :page-tag="'Governance Workspace'"
            :page-title="'Learning Models'"
            :page-subtitle="'Administer forecasting models, trigger retraining, and schedule evaluations.'"
        >
            <template #actions>
                <button
                    v-if="isAdmin"
                    class="inline-flex items-center justify-center rounded-md bg-blue-600 px-4 py-2 text-sm font-semibold text-white shadow-sm transition hover:bg-blue-700 focus-visible:outline  focus-visible:outline-offset-2 focus-visible:outline-blue-500"
                    type="button"
                    @click="openWizard"
                >
                    Launch model wizard
                </button>
            </template>
        </PageHeader>

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
import {ref, watch} from 'vue'
import {storeToRefs} from 'pinia'
import CreateModelModal from '../../components/models/CreateModelModal.vue'
import ModelsTable from '../../components/models/ModelsTable.vue'
import ModelEvaluationsPanel from '../../components/models/ModelEvaluationsPanel.vue'
import {useAuthStore} from '../../stores/auth'
import {useModelStore} from '../../stores/model'
import PageHeader from "../../components/common/PageHeader.vue";

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
