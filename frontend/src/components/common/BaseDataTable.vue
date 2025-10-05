<template>
    <div class="w-full">
        <div v-if="loading" class="px-6 py-6 text-center text-sm text-stone-500">
            <slot name="loading">Loading…</slot>
        </div>
        <template v-else>
            <div v-if="hasRows" class="hidden sm:block">
                <div class="overflow-x-auto">
                    <table class="min-w-full divide-y divide-stone-200 text-left text-sm">
                        <thead class="bg-stone-50 text-xs font-semibold uppercase tracking-wide text-stone-500">
                            <tr>
                                <th
                                    v-for="column in normalisedColumns"
                                    :key="column.slotKey"
                                    class="px-4 py-3"
                                    scope="col"
                                >
                                    {{ column.label }}
                                </th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-stone-200">
                            <tr
                                v-for="(row, rowIndex) in rows"
                                :key="rowKey(row, rowIndex)"
                                class="odd:bg-white even:bg-stone-50"
                            >
                                <td
                                    v-for="column in normalisedColumns"
                                    :key="`${rowIndex}-${column.slotKey}`"
                                    class="px-4 py-2 text-stone-700"
                                >
                                    <slot
                                        :name="`cell-${column.slotKey}`"
                                        :value="row[column.accessor]"
                                        :column="column"
                                        :row="row"
                                    >
                                        {{ formatValue(row[column.accessor]) }}
                                    </slot>
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </div>
            <div v-else class="rounded-lg border border-dashed border-stone-300 bg-stone-50 px-6 py-8 text-center text-sm text-stone-600">
                <slot name="empty">{{ emptyMessage }}</slot>
            </div>
            <div v-if="hasRows" class="space-y-4 sm:hidden">
                <article
                    v-for="(row, rowIndex) in rows"
                    :key="rowKey(row, rowIndex)"
                    class="rounded-lg border border-stone-200 bg-white p-4 shadow-sm"
                >
                    <dl class="space-y-3">
                        <div
                            v-for="column in normalisedColumns"
                            :key="`${rowIndex}-${column.slotKey}`"
                            class="grid grid-cols-1 gap-1"
                        >
                            <dt class="text-xs font-semibold uppercase tracking-wide text-stone-500">
                                {{ column.label }}
                            </dt>
                            <dd class="text-sm text-stone-700">
                                <slot
                                    :name="`cell-${column.slotKey}`"
                                    :value="row[column.accessor]"
                                    :column="column"
                                    :row="row"
                                >
                                    {{ formatValue(row[column.accessor]) }}
                                </slot>
                            </dd>
                        </div>
                    </dl>
                </article>
            </div>
        </template>
    </div>
</template>

<script setup>
import { computed } from 'vue'

const props = defineProps({
    columns: {
        type: Array,
        default: () => [],
    },
    rows: {
        type: Array,
        default: () => [],
    },
    emptyMessage: {
        type: String,
        default: 'No records to display.',
    },
    loading: {
        type: Boolean,
        default: false,
    },
    rowKeyField: {
        type: String,
        default: '',
    },
})

const normalisedColumns = computed(() =>
    props.columns.map((column, index) => {
        const fallbackKey = `column-${index}`

        if (typeof column === 'string') {
            return buildColumn(column || fallbackKey, column || `Column ${index + 1}`)
        }

        if (column && typeof column === 'object') {
            const accessor = column.key ?? column.accessor ?? fallbackKey
            const label = column.label ?? accessor ?? `Column ${index + 1}`
            const slotKey = column.slotKey ?? column.slot ?? accessor
            return buildColumn(accessor || fallbackKey, label, slotKey)
        }

        return buildColumn(fallbackKey, `Column ${index + 1}`)
    })
)

const hasRows = computed(() => Array.isArray(props.rows) && props.rows.length > 0)

function buildColumn(accessor, label, slotKey = accessor) {
    const normalisedSlotKey = String(slotKey ?? accessor)
        .trim()
        .toLowerCase()
        .replace(/[^a-z0-9]+/g, '-')
        .replace(/^-+|-+$/g, '')

    const resolvedSlotKey = normalisedSlotKey.length ? normalisedSlotKey : String(accessor)

    return {
        accessor,
        label,
        key: accessor,
        slotKey: resolvedSlotKey,
    }
}

function rowKey(row, index) {
    if (props.rowKeyField && row && typeof row === 'object' && row[props.rowKeyField] !== undefined) {
        return row[props.rowKeyField]
    }
    return index
}

function formatValue(value) {
    if (value === null || value === undefined || value === '') {
        return '—'
    }

    if (typeof value === 'number') {
        return Number.isFinite(value) ? value.toLocaleString() : String(value)
    }

    if (typeof value === 'boolean') {
        return value ? 'True' : 'False'
    }

    if (typeof value === 'object') {
        try {
            return JSON.stringify(value)
        } catch (error) {
            return String(value)
        }
    }

    return String(value)
}
</script>
