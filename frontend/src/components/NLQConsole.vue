<template>
    <section class="rounded-3xl border border-stone-200/80 bg-white/80 p-6 shadow-sm shadow-stone-200/70 backdrop-blur">
        <header class="mb-4 flex items-center justify-between">
            <div>
                <p class="text-xs font-semibold uppercase tracking-wider text-stone-500">Natural language queries</p>
                <h2 class="text-lg font-semibold text-stone-900">Ask the data assistant</h2>
            </div>
            <span v-if="isLoading" class="text-xs text-blue-600">Thinking…</span>
        </header>

        <label class="sr-only" for="nlq-input">Ask a question</label>
        <input
            id="nlq-input"
            v-model="question"
            class="w-full rounded-xl border border-stone-300/80 px-4 py-3 text-sm shadow-sm shadow-stone-200/60 transition focus:border-blue-500 focus:outline-none focus:ring-2 focus:ring-blue-200"
            placeholder="Which areas are highest risk this week?"
            type="text"
            @keyup.enter="ask"
        />

        <div class="mt-4 flex flex-wrap gap-2">
            <button
                class="inline-flex items-center gap-2 rounded-xl bg-blue-600 px-5 py-2 text-sm font-semibold text-white shadow-sm transition hover:bg-blue-700 focus:outline-none focus:ring-2 focus:ring-blue-200 disabled:cursor-not-allowed disabled:bg-stone-300"
                type="button"
                :disabled="isLoading || !question"
                @click="ask"
            >
                Ask
            </button>
            <button
                class="inline-flex items-center gap-2 rounded-xl border border-stone-300/80 px-4 py-2 text-sm font-semibold text-stone-700 shadow-sm transition hover:border-stone-400 hover:text-stone-900 focus:outline-none focus:ring-2 focus:ring-blue-200 disabled:cursor-not-allowed disabled:border-stone-200 disabled:text-stone-400"
                type="button"
                :disabled="!answer"
                @click="clear"
            >
                Clear
            </button>
        </div>

        <p v-if="error" class="mt-3 text-xs text-rose-600">{{ error }}</p>

        <section v-if="hasResult" class="mt-4 space-y-4">
            <article
                v-if="answer"
                class="max-h-48 overflow-y-auto whitespace-pre-wrap rounded-2xl border border-stone-200/80 bg-white px-4 py-3 text-sm leading-relaxed text-stone-800 shadow-inner"
            >{{ answer }}</article>

            <section v-if="metadataEntries.length" class="rounded-2xl border border-stone-200/80 bg-white px-4 py-3 shadow-inner">
                <h3 class="mb-2 text-xs font-semibold uppercase tracking-wide text-stone-500">Query metadata</h3>
                <dl class="space-y-3 text-sm text-stone-700">
                    <div v-for="(entry, index) in metadataEntries" :key="index" class="flex flex-col gap-1">
                        <dt class="text-xs font-semibold uppercase tracking-wide text-stone-500">{{ entry.label }}</dt>
                        <dd v-if="entry.isCode" class="overflow-x-auto rounded-xl bg-stone-100 px-3 py-2 font-mono text-xs text-stone-800">
                            <pre class="whitespace-pre-wrap">{{ entry.value }}</pre>
                        </dd>
                        <dd v-else class="rounded-xl bg-stone-100 px-3 py-2 font-mono text-xs text-stone-800">{{ entry.value }}</dd>
                    </div>
                </dl>
            </section>

            <section class="rounded-2xl border border-stone-200/80 bg-white px-4 py-3 shadow-inner">
                <div class="mb-2 flex items-center justify-between">
                    <h3 class="text-xs font-semibold uppercase tracking-wide text-stone-500">Structured data</h3>
                    <span v-if="tabularData && tabularData.label" class="text-[11px] uppercase tracking-wider text-stone-400">{{ tabularData.label }}</span>
                </div>

                <div v-if="hasStructuredData" class="space-y-3">
                    <div v-if="tabularData" class="overflow-x-auto">
                        <table class="min-w-full divide-y divide-stone-200 text-left text-xs">
                            <thead class="bg-stone-100">
                                <tr>
                                    <th v-for="column in tableColumns" :key="column" scope="col" class="px-3 py-2 font-semibold uppercase tracking-wide text-stone-600">
                                        {{ column }}
                                    </th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-stone-100 bg-white">
                                <tr v-for="(row, rowIndex) in tableRows" :key="rowIndex" class="hover:bg-stone-50">
                                    <td v-for="column in tableColumns" :key="column" class="px-3 py-2 font-mono text-[11px] text-stone-700">
                                        <span v-if="formatValue(row[column]) !== ''">{{ formatValue(row[column]) }}</span>
                                        <span v-else class="text-stone-400">—</span>
                                    </td>
                                </tr>
                            </tbody>
                        </table>
                    </div>

                    <div v-if="formattedData" class="overflow-x-auto rounded-xl bg-stone-100 px-3 py-2 font-mono text-xs text-stone-800">
                        <pre class="whitespace-pre-wrap">{{ formattedData }}</pre>
                    </div>
                </div>

                <p v-else class="text-xs text-stone-500">No structured data was returned for this answer.</p>
            </section>
        </section>
    </section>
</template>

<script setup>
import { computed, ref } from 'vue'
import apiClient from '@/services/apiClient'

const question = ref('Which areas are highest risk this week?')
const answer = ref('')
const error = ref('')
const isLoading = ref(false)
const structuredPayload = ref(null)
const queryMetadata = ref(null)
const lastAskedQuestion = ref('')
const hasResponse = ref(false)

async function ask() {
    if (!question.value || isLoading.value) {
        return
    }

    error.value = ''
    isLoading.value = true
    hasResponse.value = false
    structuredPayload.value = null
    queryMetadata.value = null
    answer.value = ''

    try {
        const { data } = await apiClient.post('/nlq', { question: question.value })
        answer.value = data?.answer ?? 'No answer returned.'
        structuredPayload.value = data?.data ?? null
        queryMetadata.value = data?.query ?? null
        lastAskedQuestion.value = question.value
        hasResponse.value = true
    } catch (err) {
        console.error('NLQ request failed', err)
        if (err?.response?.status === 401) {
            error.value = 'Your session has expired. Please sign in again to ask questions.'
        } else {
            error.value = 'Unable to retrieve an answer right now. Please try again later.'
        }
    } finally {
        isLoading.value = false
    }
}

function clear() {
    answer.value = ''
    error.value = ''
    structuredPayload.value = null
    queryMetadata.value = null
    hasResponse.value = false
}

const normalizedQuestion = computed(() => lastAskedQuestion.value.trim().toLowerCase())

const metadataEntries = computed(() => {
    const entries = []
    const normalized = queryMetadata.value?.normalized ?? normalizedQuestion.value
    if (normalized) {
        entries.push({ label: 'Normalized query', value: normalized, isCode: false })
    }

    if (queryMetadata.value?.type) {
        entries.push({ label: 'Query type', value: String(queryMetadata.value.type), isCode: false })
    }

    if (queryMetadata.value?.sql) {
        entries.push({ label: 'SQL template', value: String(queryMetadata.value.sql), isCode: true })
    }

    if (queryMetadata.value?.parameters && Object.keys(queryMetadata.value.parameters).length > 0) {
        entries.push({ label: 'Parameters', value: JSON.stringify(queryMetadata.value.parameters, null, 2), isCode: true })
    }

    return entries
})

const tabularData = computed(() => {
    if (!structuredPayload.value) {
        return null
    }

    if (Array.isArray(structuredPayload.value)) {
        return { label: 'Rows', rows: structuredPayload.value }
    }

    if (typeof structuredPayload.value === 'object') {
        for (const [key, value] of Object.entries(structuredPayload.value)) {
            if (Array.isArray(value) && value.length > 0) {
                return { label: key, rows: value }
            }
        }
    }

    return null
})

const tableRows = computed(() => {
    if (!tabularData.value) {
        return []
    }

    return tabularData.value.rows.map((row) => {
        if (row && typeof row === 'object' && !Array.isArray(row)) {
            return row
        }

        return { value: row }
    })
})

const tableColumns = computed(() => {
    const columns = new Set()

    for (const row of tableRows.value) {
        Object.keys(row || {}).forEach((key) => columns.add(key))
    }

    if (columns.size === 0 && tabularData.value) {
        return ['value']
    }

    return Array.from(columns)
})

const formattedData = computed(() => {
    if (!structuredPayload.value) {
        return ''
    }

    try {
        return JSON.stringify(structuredPayload.value, null, 2)
    } catch (err) {
        console.warn('Unable to format structured payload', err)
        return ''
    }
})

const hasStructuredData = computed(() => {
    if (!structuredPayload.value) {
        return false
    }

    if (Array.isArray(structuredPayload.value)) {
        return structuredPayload.value.length > 0
    }

    if (typeof structuredPayload.value === 'object') {
        return Object.keys(structuredPayload.value).length > 0
    }

    return false
})

const hasResult = computed(() => hasResponse.value || metadataEntries.value.length > 0 || !!answer.value)

function formatValue(value) {
    if (value === null || value === undefined) {
        return ''
    }

    if (typeof value === 'object') {
        try {
            return JSON.stringify(value)
        } catch (err) {
            return ''
        }
    }

    return String(value)
}
</script>
