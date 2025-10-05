<template>
    <nav
        aria-label="Pagination"
        class="flex flex-wrap items-center justify-between gap-4 border-t border-stone-200 px-6 py-4 text-sm text-stone-600"
    >
        <div>
            <slot
                name="summary"
                :from="from"
                :to="to"
                :total="total"
                :label="label"
            >
                <span v-if="total">Showing {{ from }}-{{ to }} of {{ total.toLocaleString() }} {{ label }}</span>
                <span v-else>No {{ label }} available</span>
            </slot>
        </div>
        <div class="flex items-center gap-2">
            <button
                class="inline-flex items-center rounded-md border border-stone-300 px-3 py-1.5 font-medium text-stone-700 shadow-sm transition hover:bg-stone-50 focus-visible:outline  focus-visible:outline-offset-2 focus-visible:outline-blue-500 disabled:cursor-not-allowed disabled:opacity-60"
                type="button"
                :disabled="isFirstPage || loading"
                @click="$emit('previous')"
            >
                Previous
            </button>
            <span class="font-medium text-stone-900">Page {{ currentPage }} of {{ totalPages }}</span>
            <button
                class="inline-flex items-center rounded-md border border-stone-300 px-3 py-1.5 font-medium text-stone-700 shadow-sm transition hover:bg-stone-50 focus-visible:outline  focus-visible:outline-offset-2 focus-visible:outline-blue-500 disabled:cursor-not-allowed disabled:opacity-60"
                type="button"
                :disabled="isLastPage || loading"
                @click="$emit('next')"
            >
                Next
            </button>
        </div>
    </nav>
</template>

<script setup>
import { computed } from 'vue'

const props = defineProps({
    meta: {
        type: Object,
        required: true,
    },
    count: {
        type: Number,
        default: 0,
    },
    loading: {
        type: Boolean,
        default: false,
    },
    label: {
        type: String,
        default: 'items',
    },
})

defineEmits(['previous', 'next'])

const total = computed(() => Number(props.meta?.total ?? 0))
const perPage = computed(() => Number(props.meta?.per_page ?? 0))
const currentPage = computed(() => {
    const value = Number(props.meta?.current_page ?? 1)
    return Number.isNaN(value) || value < 1 ? 1 : value
})

const totalPages = computed(() => {
    if (!perPage.value) return 1
    const pages = Math.ceil(total.value / perPage.value)
    return Number.isNaN(pages) || pages < 1 ? 1 : pages
})

const from = computed(() => {
    if (!props.count || !perPage.value) return 0
    return (currentPage.value - 1) * perPage.value + 1
})

const to = computed(() => {
    if (!props.count || !perPage.value) return 0
    return from.value + props.count - 1
})

const isFirstPage = computed(() => currentPage.value <= 1)
const isLastPage = computed(() => currentPage.value >= totalPages.value)
</script>

