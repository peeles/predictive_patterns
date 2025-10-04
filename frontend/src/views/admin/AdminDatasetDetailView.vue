<template>
    <div class="space-y-6">
        <header class="flex flex-wrap items-center justify-between gap-4">
            <div>
                <h1 class="text-2xl font-semibold text-stone-900">{{ dataset?.name ?? 'Dataset details' }}</h1>
                <p class="mt-1 text-sm text-stone-600">
                    Review ingestion metadata, source files, and preview rows for this dataset.
                </p>
            </div>
            <div class="flex flex-wrap items-center gap-3">
                <button
                    class="inline-flex items-center rounded-md border border-stone-300 px-3 py-1.5 text-sm font-medium text-stone-700 shadow-sm transition hover:bg-stone-50 focus-visible:outline  focus-visible:outline-offset-2 focus-visible:outline-blue-500 disabled:cursor-not-allowed disabled:opacity-70"
                    type="button"
                    :disabled="loading"
                    @click="fetchDataset"
                >
                    Refresh
                </button>
                <RouterLink
                    class="inline-flex items-center rounded-md bg-stone-900 px-4 py-2 text-sm font-semibold text-white shadow-sm transition hover:bg-stone-800 focus-visible:outline  focus-visible:outline-offset-2 focus-visible:outline-blue-500"
                    :to="{ name: 'admin-datasets' }"
                >
                    Back to datasets
                </RouterLink>
            </div>
        </header>

        <section class="rounded-xl border border-stone-200 bg-white shadow-sm">
            <div v-if="errorMessage" class="border-b border-rose-200 bg-rose-50 px-6 py-3 text-sm text-rose-700">
                {{ errorMessage }}
            </div>
            <div v-if="loading" class="px-6 py-8 text-center text-sm text-stone-500">Loading dataset details…</div>
            <div v-else-if="!dataset" class="px-6 py-8 text-center text-sm text-stone-500">Dataset not found.</div>
            <div v-else class="space-y-6 px-6 py-6 text-sm text-stone-700">
                <dl class="grid gap-4 sm:grid-cols-2">
                    <div>
                        <dt class="text-xs font-semibold uppercase tracking-wide text-stone-500">Identifier</dt>
                        <dd class="mt-1">
                            <div class="flex flex-wrap items-center gap-2">
                                <span class="break-all font-mono text-xs text-stone-600">{{ dataset.id }}</span>
                                <button
                                    class="inline-flex items-center gap-1 rounded-full border border-stone-300 px-2 py-0.5 text-[11px] font-medium text-stone-600 transition hover:bg-stone-100 focus-visible:outline  focus-visible:outline-offset-2 focus-visible:outline-blue-500"
                                    type="button"
                                    @click="copyIdentifier"
                                >
                                    <span>{{ copiedId === dataset.id ? 'Copied' : 'Copy ID' }}</span>
                                </button>
                            </div>
                        </dd>
                    </div>
                    <div>
                        <dt class="text-xs font-semibold uppercase tracking-wide text-stone-500">Status</dt>
                        <dd class="mt-1">
                            <span :class="datasetStatusClasses(dataset.status)">{{ datasetStatusLabel(dataset.status) }}</span>
                        </dd>
                    </div>
                    <div>
                        <dt class="text-xs font-semibold uppercase tracking-wide text-stone-500">Source</dt>
                        <dd class="mt-1">{{ formatDatasetSource(dataset.source_type) }}</dd>
                    </div>
                    <div v-if="dataset.source_uri">
                        <dt class="text-xs font-semibold uppercase tracking-wide text-stone-500">Source URI</dt>
                        <dd class="mt-1 break-all text-blue-600">
                            <a :href="dataset.source_uri" class="hover:underline" target="_blank" rel="noopener">{{ dataset.source_uri }}</a>
                        </dd>
                    </div>
                    <div>
                        <dt class="text-xs font-semibold uppercase tracking-wide text-stone-500">Records</dt>
                        <dd class="mt-1">{{ formatNumber(dataset.features_count) }}</dd>
                    </div>
                    <div>
                        <dt class="text-xs font-semibold uppercase tracking-wide text-stone-500">Uploaded</dt>
                        <dd class="mt-1">{{ formatDateTime(dataset.created_at) }}</dd>
                    </div>
                    <div>
                        <dt class="text-xs font-semibold uppercase tracking-wide text-stone-500">Processed</dt>
                        <dd class="mt-1">{{ formatDateTime(dataset.ingested_at) }}</dd>
                    </div>
                    <div v-if="rowCount !== null">
                        <dt class="text-xs font-semibold uppercase tracking-wide text-stone-500">Previewed rows</dt>
                        <dd class="mt-1">{{ formatNumber(rowCount) }}</dd>
                    </div>
                </dl>

                <div v-if="sourceFiles.length" class="rounded-lg border border-stone-200 bg-stone-50 p-4">
                    <h3 class="text-sm font-semibold text-stone-900">Source files</h3>
                    <ul class="mt-3 space-y-1 text-xs text-stone-600">
                        <li v-for="file in sourceFiles" :key="file" class="rounded-md bg-white px-3 py-2 shadow-sm">{{ file }}</li>
                    </ul>
                </div>

                <div v-if="dataset.description" class="rounded-lg border border-stone-200 bg-stone-50 p-4">
                    <h3 class="text-sm font-semibold text-stone-900">Description</h3>
                    <p class="mt-2 whitespace-pre-wrap text-sm text-stone-600">{{ dataset.description }}</p>
                </div>
            </div>
        </section>

        <section class="rounded-xl border border-stone-200 bg-white shadow-sm">
            <header class="flex flex-wrap items-center justify-between gap-3 border-b border-stone-200 px-6 py-4">
                <div>
                    <h2 class="text-lg font-semibold text-stone-900">Preview rows</h2>
                    <p class="text-sm text-stone-600">
                        Explore a sample of the ingested dataset without leaving this page.
                    </p>
                </div>
                <div class="flex flex-col items-end gap-2 text-sm text-stone-600 sm:flex-row sm:items-center">
                    <label class="flex items-center gap-2">
                        <span class="text-xs font-medium uppercase tracking-wide text-stone-500">Rows per page</span>
                        <select
                            v-model.number="pageSize"
                            class="rounded-md border border-stone-300 px-2 py-1 text-sm text-stone-700 shadow-sm transition hover:border-stone-400 focus:border-blue-500 focus:outline-none focus:ring-2 focus:ring-blue-500/40"
                        >
                            <option v-for="size in pageSizeOptions" :key="size" :value="size">{{ size }}</option>
                        </select>
                    </label>
                    <span>
                        {{ formatNumber(filteredPreviewRows.length) }} of
                        {{ rowCount === null ? '—' : formatNumber(rowCount) }} rows
                    </span>
                </div>
            </header>
            <div class="px-6 py-4">
                <DataTable
                    v-if="!loading"
                    :columns="previewTableColumns"
                    :rows="paginatedPreviewRows"
                    :empty-message="'No preview rows available for this datasets.'"
                />
                <DataTable v-else :columns="[]" :rows="[]" loading>
                    <template #loading>
                        Loading preview…
                    </template>
                </DataTable>
            </div>
            <PaginationControls
                v-if="!loading && filteredPreviewRows.length > 0 && totalPreviewPages > 1"
                :meta="paginationMeta"
                :count="paginatedPreviewRows.length"
                :loading="loading"
                label="preview rows"
                @previous="goToPreviousPage"
                @next="goToNextPage"
            />
        </section>
    </div>
</template>

<script setup>
import { computed, onBeforeUnmount, onMounted, ref, watch } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import apiClient from '../../services/apiClient'
import { notifyError } from '../../utils/notifications'
import DataTable from '../../components/common/DataTable.vue'
import PaginationControls from '../../components/pagination/PaginationControls.vue'

const route = useRoute()
const router = useRouter()

const dataset = ref(null)
const loading = ref(true)
const errorMessage = ref('')
const copiedId = ref('')
let copyTimer = null

const datasetId = computed(() => route.params.id)
const excludedPreviewKeys = ['uuid', 'crimeid']

const previewHeaders = computed(() => dataset.value?.metadata?.headers ?? [])
const previewRows = computed(() => dataset.value?.metadata?.preview_rows ?? [])
const filteredPreviewHeaders = computed(() => {
    if (!Array.isArray(previewHeaders.value)) return []

    return previewHeaders.value.filter((header) => {
        const normalised = String(header ?? '').toLowerCase().replace(/[^a-z0-9]/g, '')
        return normalised.length === 0 || !excludedPreviewKeys.includes(normalised)
    })
})
const previewTableColumns = computed(() =>
    filteredPreviewHeaders.value.map((header, index) => ({
        key: header && header.length ? header : `column-${index + 1}`,
        label: header && header.length ? header : `Column ${index + 1}`,
    }))
)
const filteredPreviewRows = computed(() => {
    if (!Array.isArray(previewRows.value) || previewRows.value.length === 0) {
        return []
    }

    return previewRows.value.map((row) => {
        if (!row || typeof row !== 'object') {
            return {}
        }

        return filteredPreviewHeaders.value.reduce((accumulator, header, index) => {
            const key = header && header.length ? header : `column-${index + 1}`
            accumulator[key] = row[header]
            return accumulator
        }, {})
    })
})
const pageSizeOptions = Object.freeze([5, 10, 25, 50])
const pageSize = ref(pageSizeOptions[0])
const currentPage = ref(1)
const totalPreviewPages = computed(() => {
    if (!filteredPreviewRows.value.length) return 1
    return Math.max(1, Math.ceil(filteredPreviewRows.value.length / pageSize.value))
})
const paginatedPreviewRows = computed(() => {
    if (!filteredPreviewRows.value.length) return []
    const start = (currentPage.value - 1) * pageSize.value
    return filteredPreviewRows.value.slice(start, start + pageSize.value)
})
const paginationMeta = computed(() => ({
    total: filteredPreviewRows.value.length,
    per_page: pageSize.value,
    current_page: currentPage.value,
}))
const sourceFiles = computed(() => {
    const files = dataset.value?.metadata?.source_files
    return Array.isArray(files) ? files : []
})
const rowCount = computed(() => {
    if (!dataset.value) return null
    const metadataCount = dataset.value.metadata?.row_count
    if (typeof metadataCount === 'number') {
        return metadataCount
    }
    return dataset.value.features_count ?? 0
})

async function fetchDataset() {
    if (!datasetId.value) {
        return
    }
    loading.value = true
    errorMessage.value = ''
    try {
        const { data } = await apiClient.get(`/datasets/${datasetId.value}`)
        dataset.value = data
    } catch (error) {
        notifyError(error, 'Unable to load datasets details.')
        errorMessage.value = error?.response?.data?.message || error.message || 'Unable to load datasets details.'
        dataset.value = null
    } finally {
        loading.value = false
    }
}

onMounted(() => {
    if (!datasetId.value) {
        router.replace({ name: 'admin-datasets' })
        return
    }
    fetchDataset()
})

watch(
    () => route.params.id,
    (value, previous) => {
        if (value && value !== previous) {
            fetchDataset()
        }
    }
)

watch(previewRows, () => {
    currentPage.value = 1
})

watch([filteredPreviewRows, pageSize], () => {
    const totalPages = Math.max(1, Math.ceil(filteredPreviewRows.value.length / (pageSize.value || 1)))
    if (currentPage.value > totalPages) {
        currentPage.value = totalPages
    }
    if (currentPage.value < 1) {
        currentPage.value = 1
    }
})

onBeforeUnmount(() => {
    if (copyTimer) {
        window.clearTimeout(copyTimer)
        copyTimer = null
    }
})

async function copyIdentifier() {
    if (!dataset.value?.id) return
    try {
        await navigator.clipboard.writeText(dataset.value.id)
        copiedId.value = dataset.value.id
        if (copyTimer) {
            window.clearTimeout(copyTimer)
        }
        copyTimer = window.setTimeout(() => {
            copiedId.value = ''
            copyTimer = null
        }, 2500)
    } catch (error) {
        notifyError(error, 'Unable to copy datasets identifier.')
    }
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

function formatDatasetSource(source) {
    if (!source) return '—'
    return source === 'file' ? 'File upload' : source === 'url' ? 'Remote URL' : source
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

function goToPreviousPage() {
    if (currentPage.value > 1) {
        currentPage.value -= 1
    }
}

function goToNextPage() {
    if (currentPage.value < totalPreviewPages.value) {
        currentPage.value += 1
    }
}
</script>
