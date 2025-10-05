<template>
    <div class="space-y-6">
        <PageHeader
            :page-tag="'Governance Workspace'"
            :page-title="'Observation Datasets'"
            :page-subtitle="'Upload new observational datasets and monitor the automated data ingestion pipeline.'"
        >
            <template #actions>
                <button
                    class="inline-flex items-center justify-center rounded-md bg-blue-600 px-4 py-2 text-sm font-semibold
                text-white shadow-sm transition hover:bg-blue-700 cursor-pointer disabled:cursor-not-allowed disabled:bg-stone-400"
                    type="button"
                    @click="openWizard"
                >
                    Launch ingest wizard
                </button>
            </template>
        </PageHeader>

        <section aria-labelledby="uploaded-datasets-heading" class="rounded-xl border border-stone-200 bg-white shadow-sm">
            <header class="flex flex-wrap items-center justify-between gap-4 border-b border-stone-200 px-6 py-4">
                <div>
                    <h2 id="uploaded-datasets-heading" class="text-lg font-semibold text-stone-900">Recent dataset uploads</h2>
                    <p class="text-sm text-stone-600">Review datasets submitted through the ingest wizard and monitor their processing status.</p>
                </div>
                <div class="flex flex-wrap items-center gap-3 text-sm text-stone-600">
                    <label class="flex items-center gap-2">
                        <span class="hidden sm:inline">Status</span>
                        <select
                            v-model="datasetStatusFilter"
                            class="rounded-md border border-stone-300 bg-white px-3 py-1.5 text-sm text-stone-700 shadow-sm transition focus-visible:outline  focus-visible:outline-offset-2 focus-visible:outline-blue-500"
                        >
                            <option v-for="option in datasetStatusOptions" :key="option.value" :value="option.value">
                                {{ option.label }}
                            </option>
                        </select>
                    </label>
                    <span class="hidden sm:inline">Last refreshed:</span>
                    <span class="font-medium text-stone-900">{{ datasetsLastRefreshedLabel }}</span>
                    <button
                        class="inline-flex items-center rounded-md border border-stone-300 px-3 py-1.5 font-medium text-stone-700 shadow-sm transition hover:bg-stone-50 focus-visible:outline  focus-visible:outline-offset-2 focus-visible:outline-blue-500"
                        type="button"
                        :disabled="datasetsLoading"
                        @click="refreshDatasets"
                    >
                        Refresh
                    </button>
                </div>
            </header>

            <div v-if="datasetsErrorMessage" class="border-b border-rose-200 bg-rose-50 px-6 py-3 text-sm text-rose-700">
                {{ datasetsErrorMessage }}
            </div>

            <div class="overflow-x-auto">
                <table class="min-w-full divide-y divide-stone-200 text-left text-sm">
                    <thead class="bg-stone-50 text-xs font-semibold uppercase tracking-wide text-stone-500">
                    <tr>
                        <th
                            v-for="column in datasetColumns"
                            :key="column.key"
                            :class="['px-6 py-3', column.sortable ? 'cursor-pointer select-none' : '']"
                            scope="col"
                            @click="column.sortable ? toggleDatasetSort(column.key) : undefined"
                        >
                            <div class="flex items-center gap-1">
                                <span>{{ column.label }}</span>
                                <span v-if="column.sortable && datasetsSortKey === column.key" aria-hidden="true">
                                    {{ datasetsSortDirection === 'asc' ? '▲' : '▼' }}
                                </span>
                            </div>
                        </th>
                    </tr>
                    </thead>
                    <tbody class="divide-y divide-stone-200">
                    <tr v-if="datasetsLoading">
                        <td class="px-6 py-6 text-center text-sm text-stone-500" :colspan="datasetColumns.length">
                            Loading dataset uploads…
                        </td>
                    </tr>
                    <tr v-else-if="!datasets.length">
                        <td class="px-6 py-6 text-center text-sm text-stone-500" :colspan="datasetColumns.length">
                            No datasets have been uploaded yet.
                        </td>
                    </tr>
                    <tr v-for="dataset in datasets" v-else :key="dataset.id" class="odd:bg-white even:bg-stone-50">
                        <td class="px-6 py-3 text-stone-700">
                            <div class="flex flex-col gap-1">
                                <RouterLink
                                    :to="{ name: 'admin-datasets-detail', params: { id: dataset.id } }"
                                    class="text-blue-600 hover:text-blue-700"
                                >
                                    {{ dataset.name }}
                                </RouterLink>
                                <div class="flex flex-wrap items-center gap-2 text-xs text-stone-500">
                                    <span class="font-mono break-all">{{ dataset.id }}</span>
                                    <button
                                        class="inline-flex items-center gap-1 rounded-full border border-stone-300 px-2 py-0.5 text-[11px] font-medium text-stone-600 transition hover:bg-stone-100 focus-visible:outline  focus-visible:outline-offset-2 focus-visible:outline-blue-500"
                                        type="button"
                                        @click="copyDatasetId(dataset)"
                                    >
                                        <span>{{ copiedDatasetId === dataset.id ? 'Copied' : 'Copy ID' }}</span>
                                    </button>
                                </div>
                            </div>
                        </td>
                        <td class="px-6 py-3 text-stone-700">{{ formatDatasetSource(dataset.source_type) }}</td>
                        <td class="px-6 py-3">
                            <div class="flex flex-col gap-2">
                                <span :class="datasetStatusClasses(dataset.status)">{{ datasetStatusLabel(dataset.status) }}</span>
                                <div
                                    v-if="dataset.status === 'processing' && datasetHasRealtimeProgress(dataset)"
                                    class="space-y-1 text-xs text-stone-500"
                                >
                                    <div class="flex items-center justify-between">
                                        <span>{{ datasetProgressLabel(dataset) }}</span>
                                        <span class="font-semibold text-stone-600">{{ datasetProgressPercent(dataset) }}%</span>
                                    </div>
                                    <div class="h-1.5 overflow-hidden rounded-full bg-stone-200">
                                        <div
                                            class="h-full rounded-full bg-blue-500 transition-all duration-200"
                                            :style="{ width: `${Math.max(datasetProgressPercent(dataset), 5)}%` }"
                                        ></div>
                                    </div>
                                </div>
                                <div
                                    v-else-if="dataset.status === 'processing' || dataset.status === 'pending'"
                                    class="flex items-center gap-2 text-xs text-stone-500"
                                >
                                    <svg aria-hidden="true" class="h-4 w-4 animate-spin"  viewBox="0 0 24 24">
                                        <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
                                        <path class="opacity-75" d="M4 12a8 8 0 018-8v4a4 4 0 00-4 4H4z" fill="currentColor"></path>
                                    </svg>
                                    <span>{{ dataset.status === 'pending' ? 'Waiting for ingestion to start…' : 'Processing datasets…' }}</span>
                                </div>
                                <div v-else-if="dataset.status === 'failed' && dataset.ingest_message" class="text-xs text-rose-600">
                                    {{ dataset.ingest_message }}
                                </div>
                            </div>
                        </td>
                        <td class="px-6 py-3 text-stone-700">{{ formatNumber(dataset.features_count) }}</td>
                        <td class="px-6 py-3 text-stone-700">{{ formatDateTime(dataset.created_at) }}</td>
                        <td class="px-6 py-3 text-stone-700">{{ formatDateTime(dataset.ingested_at) }}</td>
                    </tr>
                    </tbody>
                </table>
            </div>

            <PaginationControls
                :meta="datasetsMeta"
                :count="datasets.length"
                :loading="datasetsLoading"
                label="datasets"
                @previous="previousDatasetsPage"
                @next="nextDatasetsPage"
            >
                <template #summary="{ from, to, total }">
                    <span v-if="total">Showing {{ from }}-{{ to }} of {{ total.toLocaleString() }} datasets</span>
                    <span v-else>No datasets available</span>
                </template>
            </PaginationControls>
        </section>

        <section aria-labelledby="ingest-history-heading" class="rounded-xl border border-stone-200 bg-white shadow-sm">
            <header class="flex flex-wrap items-center justify-between gap-4 border-b border-stone-200 px-6 py-4">
                <div>
                    <h2 id="ingest-history-heading" class="text-lg font-semibold text-stone-900">Crime ingestion runs</h2>
                    <p class="text-sm text-stone-600">Monitor the most recent automated ingests and troubleshoot failures.</p>
                </div>
                <div class="flex flex-wrap items-center gap-3 text-sm text-stone-600">
                    <label class="flex items-center gap-2">
                        <span class="hidden sm:inline">Status</span>
                        <select
                            v-model="statusFilter"
                            class="rounded-md border border-stone-300 bg-white px-3 py-1.5 text-sm text-stone-700 shadow-sm transition focus-visible:outline  focus-visible:outline-offset-2 focus-visible:outline-blue-500"
                        >
                            <option v-for="option in statusOptions" :key="option.value" :value="option.value">
                                {{ option.label }}
                            </option>
                        </select>
                    </label>
                    <span class="hidden sm:inline">Last refreshed:</span>
                    <span class="font-medium text-stone-900">{{ lastRefreshedLabel }}</span>
                    <button
                        class="inline-flex items-center rounded-md border border-stone-300 px-3 py-1.5 font-medium text-stone-700 shadow-sm transition hover:bg-stone-50 focus-visible:outline  focus-visible:outline-offset-2 focus-visible:outline-blue-500"
                        type="button"
                        :disabled="loading"
                        @click="refresh"
                    >
                        Refresh
                    </button>
                </div>
            </header>

            <div v-if="errorMessage" class="border-b border-rose-200 bg-rose-50 px-6 py-3 text-sm text-rose-700">
                {{ errorMessage }}
            </div>

            <div class="overflow-x-auto">
                <table class="min-w-full divide-y divide-stone-200 text-left text-sm">
                    <thead class="bg-stone-50 text-xs font-semibold uppercase tracking-wide text-stone-500">
                    <tr>
                        <th
                            v-for="column in columns"
                            :key="column.key"
                            :class="['px-6 py-3', column.sortable ? 'cursor-pointer select-none' : '']"
                            scope="col"
                            @click="column.sortable ? toggleSort(column.key) : undefined"
                        >
                            <div class="flex items-center gap-1">
                                <span>{{ column.label }}</span>
                                <span v-if="column.sortable && sortKey === column.key" aria-hidden="true">
                                        {{ sortDirection === 'asc' ? '▲' : '▼' }}
                                    </span>
                            </div>
                        </th>
                        <th class="px-6 py-3 text-right" scope="col">Details</th>
                    </tr>
                    </thead>
                    <tbody class="divide-y divide-stone-200">
                    <tr v-if="loading">
                        <td class="px-6 py-6 text-center text-sm text-stone-500" :colspan="columns.length + 1">
                            Loading ingestion runs…
                        </td>
                    </tr>
                    <tr v-else-if="!runs.length">
                        <td class="px-6 py-6 text-center text-sm text-stone-500" :colspan="columns.length + 1">
                            No ingestion runs have been recorded yet.
                        </td>
                    </tr>
                    <tr v-for="run in runs" v-else :key="run.id" class="odd:bg-white even:bg-stone-50">
                        <td class="px-6 py-3 text-stone-700">{{ formatMonth(run.month) }}</td>
                        <td class="px-6 py-3">
                            <span :class="statusClasses(run.status)">{{ statusLabel(run.status) }}</span>
                        </td>
                        <td class="px-6 py-3 text-stone-700">{{ formatNumber(run.records_expected) }}</td>
                        <td class="px-6 py-3 text-stone-700">{{ formatNumber(run.records_inserted) }}</td>
                        <td class="px-6 py-3 text-stone-700">{{ formatNumber(run.records_detected) }}</td>
                        <td class="px-6 py-3 text-stone-700">{{ formatNumber(run.records_existing) }}</td>
                        <td class="px-6 py-3 text-stone-700">{{ formatDateTime(run.started_at) }}</td>
                        <td class="px-6 py-3 text-stone-700">{{ formatDateTime(run.finished_at) }}</td>
                        <td class="px-6 py-3 text-right">
                            <button
                                class="inline-flex items-center rounded-md border border-stone-300 px-3 py-1.5 text-xs font-medium text-stone-700 shadow-sm transition hover:bg-stone-50 focus-visible:outline  focus-visible:outline-offset-2 focus-visible:outline-blue-500"
                                type="button"
                                @click="openRunDetails(run)"
                            >
                                View
                            </button>
                        </td>
                    </tr>
                    </tbody>
                </table>
            </div>

            <PaginationControls
                :meta="meta"
                :count="runs.length"
                :loading="loading"
                label="runs"
                @previous="previousPage"
                @next="nextPage"
            >
                <template #summary="{ from, to, total }">
                    <span v-if="total">Showing {{ from }}-{{ to }} of {{ total.toLocaleString() }} runs</span>
                    <span v-else>No runs available</span>
                </template>
            </PaginationControls>
        </section>

        <DatasetIngestModal
            @submitted="handleDatasetSubmitted"
            :open="wizardOpen"
            @close="wizardOpen = false"
        />

        <div
            v-if="selectedRun"
            class="fixed inset-0 z-50 flex items-center justify-center bg-stone-900/60 px-4 py-8"
            role="dialog"
            aria-modal="true"
        >
            <div class="max-h-full w-full max-w-2xl overflow-y-auto rounded-xl bg-white shadow-xl">
                <header class="flex items-start justify-between gap-4 border-b border-stone-200 px-6 py-4">
                    <div>
                        <h2 class="text-lg font-semibold text-stone-900">Ingestion run details</h2>
                        <p class="text-sm text-stone-600">Month {{ selectedRun.month }} • Run #{{ selectedRun.id }}</p>
                    </div>
                    <button
                        class="inline-flex items-center rounded-md border border-stone-300 px-2 py-1 text-sm font-medium text-stone-700 shadow-sm transition hover:bg-stone-50 focus-visible:outline  focus-visible:outline-offset-2 focus-visible:outline-blue-500"
                        type="button"
                        @click="closeRunDetails"
                    >
                        Close
                    </button>
                </header>
                <div class="space-y-4 px-6 py-4 text-sm text-stone-700">
                    <dl class="grid grid-cols-1 gap-4 sm:grid-cols-2">
                        <div>
                            <dt class="text-xs font-semibold uppercase tracking-wide text-stone-500">Status</dt>
                            <dd class="mt-1">{{ statusLabel(selectedRun.status) }}</dd>
                        </div>
                        <div>
                            <dt class="text-xs font-semibold uppercase tracking-wide text-stone-500">Dry run</dt>
                            <dd class="mt-1">{{ selectedRun.dry_run ? 'Yes' : 'No' }}</dd>
                        </div>
                        <div>
                            <dt class="text-xs font-semibold uppercase tracking-wide text-stone-500">Records expected</dt>
                            <dd class="mt-1">{{ formatNumber(selectedRun.records_expected) }}</dd>
                        </div>
                        <div>
                            <dt class="text-xs font-semibold uppercase tracking-wide text-stone-500">Records inserted</dt>
                            <dd class="mt-1">{{ formatNumber(selectedRun.records_inserted) }}</dd>
                        </div>
                        <div>
                            <dt class="text-xs font-semibold uppercase tracking-wide text-stone-500">Records detected</dt>
                            <dd class="mt-1">{{ formatNumber(selectedRun.records_detected) }}</dd>
                        </div>
                        <div>
                            <dt class="text-xs font-semibold uppercase tracking-wide text-stone-500">Existing records</dt>
                            <dd class="mt-1">{{ formatNumber(selectedRun.records_existing) }}</dd>
                        </div>
                        <div>
                            <dt class="text-xs font-semibold uppercase tracking-wide text-stone-500">Started at</dt>
                            <dd class="mt-1">{{ formatDateTime(selectedRun.started_at) }}</dd>
                        </div>
                        <div>
                            <dt class="text-xs font-semibold uppercase tracking-wide text-stone-500">Finished at</dt>
                            <dd class="mt-1">{{ formatDateTime(selectedRun.finished_at) }}</dd>
                        </div>
                        <div v-if="selectedRun.archive_url" class="sm:col-span-2">
                            <dt class="text-xs font-semibold uppercase tracking-wide text-stone-500">Archive URL</dt>
                            <dd class="mt-1">
                                <a :href="selectedRun.archive_url" class="text-blue-600 underline hover:text-blue-700" target="_blank" rel="noopener">{{ selectedRun.archive_url }}</a>
                            </dd>
                        </div>
                        <div v-if="selectedRun.archive_checksum" class="sm:col-span-2">
                            <dt class="text-xs font-semibold uppercase tracking-wide text-stone-500">Archive checksum</dt>
                            <dd class="mt-1 font-mono text-xs text-stone-600">{{ selectedRun.archive_checksum }}</dd>
                        </div>
                    </dl>
                    <div v-if="selectedRun.error_message" class="rounded-lg border border-rose-200 bg-rose-50 p-4 text-sm text-rose-700">
                        <h3 class="font-semibold">Error message</h3>
                        <p class="mt-1 whitespace-pre-wrap">{{ selectedRun.error_message }}</p>
                    </div>
                    <div v-else class="rounded-lg border border-emerald-200 bg-emerald-50 p-4 text-sm text-emerald-700">
                        <h3 class="font-semibold">No errors reported</h3>
                        <p class="mt-1">This run completed without reporting any errors.</p>
                    </div>
                </div>
            </div>
        </div>
    </div>
</template>

<script setup>
import { computed, onBeforeUnmount, onMounted, ref, watch } from 'vue'
import PaginationControls from '../../components/common/pagination/PaginationControls.vue'
import DatasetIngestModal from '../../components/datasets/DatasetIngestModal.vue'
import apiClient from '../../services/apiClient'
import { notifyError } from '../../utils/notifications'
import { getBroadcastClient } from '../../services/broadcast'
import PageHeader from "../../components/common/PageHeader.vue";

const wizardOpen = ref(false)
const datasets = ref([])
const datasetsLoading = ref(false)
const datasetsErrorMessage = ref('')
const datasetsMeta = ref({ total: 0, per_page: 10, current_page: 1 })
const datasetsSortKey = ref('started_at')
const datasetsSortDirection = ref('desc')
const datasetStatusFilter = ref('all')
const datasetsLastRefreshedAt = ref(null)
const copiedDatasetId = ref('')
const runs = ref([])
const loading = ref(false)
const errorMessage = ref('')
const meta = ref({ total: 0, per_page: 25, current_page: 1 })
const links = ref({ first: null, last: null, prev: null, next: null })
const datasetPerPage = 10
const perPage = 25
const datasetsColumnsSortDefaults = ['created_at', 'ingested_at']
const sortKey = ref('started_at')
const sortDirection = ref('desc')
const statusFilter = ref('all')
const lastRefreshedAt = ref(null)
const selectedRun = ref(null)
let pollTimer = null
let copyResetTimer = null
const datasetSubscriptions = new Map()

const datasetColumns = [
    { key: 'name', label: 'Name', sortable: true },
    { key: 'source_type', label: 'Source', sortable: true },
    { key: 'status', label: 'Status', sortable: true },
    { key: 'features_count', label: 'Records', sortable: true },
    { key: 'created_at', label: 'Uploaded', sortable: true },
    { key: 'ingested_at', label: 'Processed', sortable: true },
]

const datasetStatusOptions = [
    { value: 'all', label: 'All statuses' },
    { value: 'ready', label: 'Ready' },
    { value: 'processing', label: 'Processing' },
    { value: 'pending', label: 'Pending' },
    { value: 'failed', label: 'Failed' },
]

const columns = [
    { key: 'month', label: 'Month', sortable: true },
    { key: 'status', label: 'Status', sortable: true },
    { key: 'records_expected', label: 'Records expected', sortable: true },
    { key: 'records_inserted', label: 'Records inserted', sortable: true },
    { key: 'records_detected', label: 'Detected', sortable: true },
    { key: 'records_existing', label: 'Existing', sortable: true },
    { key: 'started_at', label: 'Started', sortable: true },
    { key: 'finished_at', label: 'Finished', sortable: true },
]

const statusOptions = [
    { value: 'all', label: 'All statuses' },
    { value: 'completed', label: 'Completed' },
    { value: 'running', label: 'Running' },
    { value: 'failed', label: 'Failed' },
    { value: 'pending', label: 'Pending' },
]

const datasetsLastRefreshedLabel = computed(() => formatDateTime(datasetsLastRefreshedAt.value))
const lastRefreshedLabel = computed(() => formatDateTime(lastRefreshedAt.value))

onMounted(() => {
    fetchDatasets()
    fetchRuns()
    pollTimer = window.setInterval(() => {
        fetchDatasets(datasetsMeta.value.current_page, { silent: true })
        fetchRuns(meta.value.current_page, { silent: true })
    }, 30000)
})

onBeforeUnmount(() => {
    if (pollTimer) {
        window.clearInterval(pollTimer)
        pollTimer = null
    }
    if (copyResetTimer) {
        window.clearTimeout(copyResetTimer)
        copyResetTimer = null
    }
    cleanupDatasetSubscriptions()
})

watch(datasetStatusFilter, () => {
    fetchDatasets(1)
})

watch(statusFilter, () => {
    fetchRuns(1)
})

function buildDatasetSortParam() {
    return datasetsSortDirection.value === 'desc' ? `-${datasetsSortKey.value}` : datasetsSortKey.value
}

function currentDatasetFilters() {
    const filters = {}
    if (datasetStatusFilter.value !== 'all') {
        filters.status = datasetStatusFilter.value
    }
    return filters
}

function cleanupDatasetSubscriptions() {
    const broadcast = getBroadcastClient()
    for (const [datasetId, subscription] of datasetSubscriptions.entries()) {
        if (broadcast) {
            try {
                const channelName = subscription?.channelName ?? `datasets.${datasetId}.status`
                broadcast.unsubscribe(channelName)
            } catch (error) {
                console.warn('Error unsubscribing from datasets status channel', error)
            }
        }
        datasetSubscriptions.delete(datasetId)
    }
}

function syncDatasetSubscriptions() {
    const broadcast = getBroadcastClient()
    if (!broadcast) {
        return
    }

    const activeIds = new Set(
        datasets.value
            .filter((dataset) => dataset.status === 'processing' || dataset.status === 'pending')
            .map((dataset) => dataset.id)
    )

    for (const [datasetId, subscription] of datasetSubscriptions.entries()) {
        if (!activeIds.has(datasetId)) {
            try {
                const channelName = subscription?.channelName ?? `datasets.${datasetId}.status`
                broadcast.unsubscribe(channelName)
            } catch (error) {
                console.warn('Error unsubscribing from datasets status channel', error)
            }
            datasetSubscriptions.delete(datasetId)
        }
    }

    for (const dataset of datasets.value) {
        if (!activeIds.has(dataset.id) || datasetSubscriptions.has(dataset.id)) {
            continue
        }

        try {
            const subscription = broadcast.subscribe(`datasets.${dataset.id}.status`, {
                onEvent: (eventName, payload) => {
                    if (eventName === 'DatasetStatusUpdated' || eventName === '.DatasetStatusUpdated') {
                        handleDatasetRealtime(dataset.id, payload)
                    }
                },
                onError: (error) => {
                    console.warn('Dataset status channel error', error)
                },
            })
            datasetSubscriptions.set(dataset.id, subscription)
        } catch (error) {
            console.warn('Unable to subscribe to datasets status updates', error)
        }
    }
}

function handleDatasetRealtime(datasetId, payload = {}) {
    const index = datasets.value.findIndex((dataset) => dataset.id === datasetId)
    if (index === -1) {
        return
    }

    const current = datasets.value[index]
    const status = typeof payload?.status === 'string' ? payload.status : current.status
    const ingestedAt = payload?.ingested_at ?? current.ingested_at
    const progressValue = typeof payload?.progress === 'number' ? payload.progress : null
    const message = typeof payload?.message === 'string' ? payload.message : null

    const progressPercent = progressValue !== null && Number.isFinite(progressValue)
        ? Math.min(100, Math.max(0, Math.round(progressValue * 100)))
        : null

    const updated = {
        ...current,
        status,
        ingested_at: ingestedAt,
        ingest_progress: status === 'processing' ? progressPercent : null,
        ingest_message: status === 'failed' ? message : null,
    }

    datasets.value.splice(index, 1, updated)

    if (status === 'ready' || status === 'failed') {
        const broadcast = getBroadcastClient()
        const subscription = datasetSubscriptions.get(datasetId)
        if (subscription && broadcast) {
            try {
                const channelName = subscription?.channelName ?? `datasets.${datasetId}.status`
                broadcast.unsubscribe(channelName)
            } catch (error) {
                console.warn('Error unsubscribing from datasets status channel', error)
            }
        }
        datasetSubscriptions.delete(datasetId)
    }
}

async function fetchDatasets(page = 1, options = {}) {
    const silent = options.silent ?? false
    if (!silent) {
        datasetsLoading.value = true
    }
    datasetsErrorMessage.value = ''

    try {
        const previousProgress = new Map(
            datasets.value.map((dataset) => [
                dataset.id,
                {
                    progress: typeof dataset.ingest_progress === 'number' ? dataset.ingest_progress : null,
                    message: dataset.ingest_message ?? null,
                },
            ])
        )

        const params = { page, per_page: datasetPerPage, sort: buildDatasetSortParam() }
        const filters = currentDatasetFilters()
        if (Object.keys(filters).length) {
            params.filter = filters
        }

        const { data } = await apiClient.get('/datasets', { params })

        const payload = Array.isArray(data?.data) ? data.data : []
        datasets.value = payload.map((dataset) => {
            const previous = previousProgress.get(dataset.id) ?? { progress: null, message: null }
            const progress = typeof previous.progress === 'number' ? previous.progress : null
            return {
                ...dataset,
                ingest_progress: dataset.status === 'processing' ? progress : null,
                ingest_message: dataset.status === 'failed' ? previous.message : null,
            }
        })
        datasetsMeta.value = {
            total: Number(data?.meta?.total ?? datasets.value.length ?? 0),
            per_page: Number(data?.meta?.per_page ?? datasetPerPage),
            current_page: Number(data?.meta?.current_page ?? page),
        }
        datasetsLastRefreshedAt.value = new Date()
        syncDatasetSubscriptions()
    } catch (error) {
        notifyError(error, 'Unable to load datasets uploads.')
        datasetsErrorMessage.value = error?.response?.data?.message || error.message || 'Unable to load datasets uploads.'
    } finally {
        datasetsLoading.value = false
    }
}

function toggleDatasetSort(key) {
    if (datasetsSortKey.value === key) {
        datasetsSortDirection.value = datasetsSortDirection.value === 'asc' ? 'desc' : 'asc'
    } else {
        datasetsSortKey.value = key
        datasetsSortDirection.value = datasetsColumnsSortDefaults.includes(key) ? 'desc' : 'asc'
    }
    fetchDatasets(1)
}

function formatDatasetSource(source) {
    if (!source) return '—'
    return source === 'file' ? 'File upload' : source === 'url' ? 'Remote URL' : source
}

function datasetStatusLabel(status) {
    switch (status) {
        case 'ready':
            return 'Ready'
        case 'processing':
            return 'Processing'
        case 'failed':
            return 'Failed'
        case 'pending':
            return 'Pending'
        default:
            return status ? status.charAt(0).toUpperCase() + status.slice(1) : 'Unknown'
    }
}

function datasetStatusClasses(status) {
    const base = 'inline-flex items-center rounded-full px-2 py-1 text-xs font-semibold'
    switch (status) {
        case 'ready':
            return `${base} bg-emerald-100 text-emerald-700`
        case 'processing':
            return `${base} bg-amber-100 text-amber-700`
        case 'failed':
            return `${base} bg-rose-100 text-rose-700`
        default:
            return `${base} bg-stone-100 text-stone-700`
    }
}

function datasetHasRealtimeProgress(dataset) {
    return typeof dataset?.ingest_progress === 'number'
        && dataset.ingest_progress >= 0
        && dataset.ingest_progress <= 100;
}

function datasetProgressPercent(dataset) {
    if (!datasetHasRealtimeProgress(dataset)) {
        return 0;
    }

    return Math.min(100, Math.max(0, Math.round(dataset.ingest_progress)))
}

function datasetProgressLabel(dataset) {
    if (dataset?.status === 'pending') {
        return 'Queued for download'
    }
    return 'Processing datasets'
}

function refreshDatasets() {
    fetchDatasets(datasetsMeta.value.current_page ?? 1)
}

function nextDatasetsPage() {
    const totalPages = Math.max(1, Math.ceil((datasetsMeta.value.total ?? 0) / (datasetsMeta.value.per_page || datasetPerPage)))
    if (datasetsMeta.value.current_page < totalPages && !datasetsLoading.value) {
        fetchDatasets(datasetsMeta.value.current_page + 1)
    }
}

function previousDatasetsPage() {
    if (datasetsMeta.value.current_page > 1 && !datasetsLoading.value) {
        fetchDatasets(datasetsMeta.value.current_page - 1)
    }
}

async function copyDatasetId(dataset) {
    if (!dataset?.id) return
    try {
        await navigator.clipboard.writeText(dataset.id)
        copiedDatasetId.value = dataset.id
        if (copyResetTimer) {
            window.clearTimeout(copyResetTimer)
        }
        copyResetTimer = window.setTimeout(() => {
            copiedDatasetId.value = ''
            copyResetTimer = null
        }, 2500)
    } catch (error) {
        notifyError(error, 'Unable to copy datasets identifier.')
    }
}

function buildSortParam() {
    return sortDirection.value === 'desc' ? `-${sortKey.value}` : sortKey.value
}

function currentFilters() {
    const filters = {}
    if (statusFilter.value !== 'all') {
        filters.status = statusFilter.value
    }
    return filters
}

async function fetchRuns(page = 1, options = {}) {
    const silent = options.silent ?? false
    if (!silent) {
        loading.value = true
    }
    errorMessage.value = ''

    try {
        const params = { page, per_page: perPage, sort: buildSortParam() }
        const filters = currentFilters()
        if (Object.keys(filters).length) {
            params.filter = filters
        }

        const { data } = await apiClient.get('/datasets/runs', { params })

        runs.value = Array.isArray(data?.data) ? data.data : []
        meta.value = {
            total: Number(data?.meta?.total ?? runs.value.length ?? 0),
            per_page: Number(data?.meta?.per_page ?? perPage),
            current_page: Number(data?.meta?.current_page ?? page),
        }
        links.value = {
            first: data?.links?.first ?? null,
            last: data?.links?.last ?? null,
            prev: data?.links?.prev ?? null,
            next: data?.links?.next ?? null,
        }
        lastRefreshedAt.value = new Date()
    } catch (error) {
        notifyError(error, 'Unable to load ingestion runs.')
        errorMessage.value = error?.response?.data?.message || error.message || 'Unable to load ingestion runs.'
    } finally {
        loading.value = false
    }
}

function toggleSort(key) {
    if (sortKey.value === key) {
        sortDirection.value = sortDirection.value === 'asc' ? 'desc' : 'asc'
    } else {
        sortKey.value = key
        sortDirection.value = key === 'month' || key.endsWith('_at') ? 'desc' : 'asc'
    }
    fetchRuns(1)
}

function formatMonth(month) {
    if (!month) return '—'
    const date = new Date(`${month}-01T00:00:00`)
    if (Number.isNaN(date.getTime())) return month
    return new Intl.DateTimeFormat('en-US', { month: 'long', year: 'numeric' }).format(date)
}

function formatNumber(value) {
    if (value === null || value === undefined) return '—'
    const numeric = Number(value)
    if (Number.isNaN(numeric)) return String(value)
    return numeric.toLocaleString()
}

function formatDateTime(value) {
    if (!value) return '—'
    const date = value instanceof Date ? value : new Date(value)
    if (Number.isNaN(date.getTime())) return '—'
    return new Intl.DateTimeFormat('en-GB', {
        dateStyle: 'medium',
        timeStyle: 'short',
    }).format(date)
}

function statusLabel(status) {
    switch (status) {
        case 'completed':
            return 'Completed'
        case 'failed':
            return 'Failed'
        case 'running':
            return 'Running'
        default:
            return 'Pending'
    }
}

function statusClasses(status) {
    const base = 'inline-flex items-center rounded-full px-2 py-1 text-xs font-semibold'
    switch (status) {
        case 'completed':
            return `${base} bg-emerald-100 text-emerald-700`
        case 'failed':
            return `${base} bg-rose-100 text-rose-700`
        case 'running':
            return `${base} bg-amber-100 text-amber-700`
        default:
            return `${base} bg-stone-100 text-stone-700`
    }
}

function handleDatasetSubmitted() {
    fetchDatasets(1)
}

function refresh() {
    fetchRuns(meta.value.current_page ?? 1)
}

function nextPage() {
    const totalPages = Math.max(1, Math.ceil((meta.value.total ?? 0) / (meta.value.per_page || perPage)))
    if (meta.value.current_page < totalPages && !loading.value) {
        fetchRuns(meta.value.current_page + 1)
    }
}

function previousPage() {
    if (meta.value.current_page > 1 && !loading.value) {
        fetchRuns(meta.value.current_page - 1)
    }
}

function openRunDetails(run) {
    selectedRun.value = run
}

function closeRunDetails() {
    selectedRun.value = null
}

function openWizard() {
    wizardOpen.value = true
}
</script>
