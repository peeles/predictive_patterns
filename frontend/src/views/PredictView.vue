<template>
    <div class="flex flex-col min-h-full space-y-6">
        <PageHeader
            :page-tag="'Forecast Workspace'"
            :page-title="'Predictive Mapping'"
            :page-subtitle="'Configure the forecast horizon and geography to build a fresh prediction using the latest ingested data.'"
        >
            <template #actions>
                <button
                    v-if="isAdmin"
                    class="inline-flex items-center justify-center rounded-md bg-blue-600 px-4 py-2 text-sm font-semibold text-white shadow-sm transition hover:bg-blue-700 focus-visible:outline  focus-visible:outline-offset-2 focus-visible:outline-blue-500"
                    type="button"
                    @click="openWizard"
                >
                    Launch predict wizard
                </button>
            </template>
        </PageHeader>

        <BaseTabs
            v-model="activeTab"
            :tabs="tabs"
        >
            <template #panels="{ active }">
                <BaseTabPanel
                    id="map"
                    :active="active"
                >
                    <div
                        class="flex flex-1 flex-col gap-6"
                        aria-live="polite"
                        role="region"
                    >
                        <PredictionStatusBar
                            :status="activePredictionStatus"
                            :progress="activePredictionProgress"
                            :message="activePredictionMessage"
                            :updated-at="activePredictionUpdatedAt"
                        />
                        <div class="relative flex flex-col flex-[1_0_28rem] md:flex-[1_0_34rem]">
                            <Suspense>
                                <template #default>
                                    <MapView
                                        :center="mapCenter"
                                        :points="predictionStore.heatmapPoints"
                                        :radius-km="predictionStore.lastFilters.radiusKm"
                                        :tile-options="heatmapTileOptions"
                                    />
                                </template>
                                <template #fallback>
                                    <div class="flex flex-[1_0_28rem] md:flex-[1_0_34rem] bg-white p-6 shadow-sm shadow-stone-200/70">
                                        <p class="text-sm text-stone-500">Loading map…</p>
                                    </div>
                                </template>
                            </Suspense>
                        </div>
                    </div>
                </BaseTabPanel>

                <BaseTabPanel
                    id="insights"
                    :active="active"
                >
                    <div
                        class="flex flex-1 flex-col"
                        role="region"
                    >
                        <PredictionResult
                            v-if="predictionStore.hasPrediction"
                            :features="predictionStore.featureBreakdown"
                            :radius="predictionStore.lastFilters.radiusKm"
                            :summary="predictionSummary"
                        />
                        <div
                            v-else
                            class="flex flex-1 items-center justify-center p-12 text-center text-sm text-stone-500"
                        >
                            <p>
                                Generate a prediction to unlock detailed insights about contributing factors and forecast confidence.
                            </p>
                        </div>
                    </div>
                </BaseTabPanel>

                <BaseTabPanel
                    id="archive"
                    :active="active"
                >
                    <div
                        class="space-y-6"
                        role="region"
                    >
                        <PredictionHistory />
                    </div>
                </BaseTabPanel>
            </template>
        </BaseTabs>

        <PredictGenerateModal
            v-if="isAdmin"
            :open="wizardOpen"
            @close="wizardOpen = false"
            @generated="wizardOpen = false"
        />
    </div>
</template>

<script setup>
import { computed, defineAsyncComponent, ref } from 'vue'
import { usePredictionStore } from '../stores/prediction'
import PredictionResult from '../components/predict/PredictionResult.vue'
import PredictionHistory from '../components/predict/PredictionHistory.vue'
import { storeToRefs } from 'pinia'
import { useAuthStore } from '../stores/auth.js'
import PredictGenerateModal from '../components/predict/PredictGenerateModal.vue'
import BaseTabs from '../components/common/BaseTabs.vue'
import BaseTabPanel from '../components/common/BaseTabPanel.vue'
import PageHeader from '../components/common/PageHeader.vue'
import PredictionStatusBar from '../components/predict/PredictionStatusBar.vue'

const MapView = defineAsyncComponent(() => import('../components/map/MapView.vue'))

const predictionStore = usePredictionStore()
const authStore = useAuthStore()
const { isAdmin } = storeToRefs(authStore)

const wizardOpen = ref(false)
const tabs = [
    { id: 'map', label: 'Map view' },
    { id: 'insights', label: 'Prediction insights' },
    { id: 'archive', label: 'Prediction archive' },
]
const activeTab = ref(tabs[0].id)

const mapCenter = computed(() => predictionStore.currentPrediction?.filters?.center ?? predictionStore.lastFilters.center)

const heatmapTileOptions = computed(() => {
    const options = {}
    const tsStart = predictionStore.lastFilters.timestamp
    if (tsStart) {
        options.tsStart = tsStart
    }
    const horizon = Number(predictionStore.lastFilters.horizon)
    if (Number.isFinite(horizon) && horizon >= 0) {
        options.horizon = horizon
    }
    return options
})

const predictionSummary = computed(() => ({
    generatedAt: predictionStore.currentPrediction?.generatedAt,
    horizonHours: predictionStore.summary?.horizonHours ?? predictionStore.lastFilters.horizon,
    riskScore: predictionStore.summary?.riskScore ?? 0,
    confidence: predictionStore.summary?.confidence ?? 'Unknown',
}))

const realtimeStatus = computed(() => predictionStore.realtimeStatus ?? null)

const activePredictionStatus = computed(() => {
    return predictionStore.currentPrediction?.status ?? realtimeStatus.value?.status ?? null
})

const activePredictionProgress = computed(() => {
    const currentProgress = predictionStore.currentPrediction?.progress
    if (Number.isFinite(currentProgress)) {
        return currentProgress
    }

    const realtimeProgress = realtimeStatus.value?.progress
    if (typeof realtimeProgress === 'number' && Number.isFinite(realtimeProgress)) {
        return Math.min(100, Math.max(0, Math.round(realtimeProgress * 100)))
    }

    return null
})

const activePredictionMessage = computed(() => {
    return (
        predictionStore.currentPrediction?.progressMessage
        ?? realtimeStatus.value?.message
        ?? null
    )
})

const activePredictionUpdatedAt = computed(() => {
    return (
        realtimeStatus.value?.updatedAt
        ?? predictionStore.currentPrediction?.finishedAt
        ?? predictionStore.currentPrediction?.startedAt
        ?? null
    )
})

function openWizard() {
    wizardOpen.value = true
}
</script>
