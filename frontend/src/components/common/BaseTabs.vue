<template>
    <component :is="card ? BaseCard : 'div'" class="relative flex flex-1 w-full flex-col overflow-hidden">
        <div class="flex flex-row bg-stone-200/80 backdrop-blur supports-[backdrop-filter]:bg-stone-3--/60 shrink">
            <div class="shadow-[inset_0_-1px_0_rgba(0,0,0,0.06)]">
                <div
                    ref="tablistRef"
                    role="tablist"
                    aria-label="Sections"
                    class="flex gap-1 overflow-x-auto no-scrollbar"
                >
                    <button
                        v-for="(tab, i) in tabs"
                        :key="tab.id"
                        type="button"
                        role="tab"
                        :id="`tab-${tab.id}`"
                        :aria-controls="`panel-${tab.id}`"
                        :aria-selected="tab.id === active ? 'true' : 'false'"
                        :tabindex="tab.id === active ? 0 : -1"
                        @click="set(tab.id)"
                        @keydown="onKeys($event, i)"
                        class="group relative shrink-0 max-w-[min(44vw,320px)] truncate
                           rounded-t-md px-8 py-5 text-sm font-medium
                           text-stone-600 hover:text-stone-800 hover:bg-white/50
                           focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-blue-500/60
                           aria-selected:bg-white aria-selected:text-stone-900
                           aria-selected:shadow-sm aria-selected:-mb-px uppercase
                        "
                    >
                        <span class="inline-flex items-center gap-2">
                            <component v-if="tab.icon" :is="tab.icon" class="h-4 w-4" aria-hidden="true" />
                            <span class="truncate">{{ tab.label }}</span>
                            <span v-if="tab.badge !== undefined" class="rounded bg-black/5 px-1.5 py-0.5 text-xs text-stone-600">
                            {{ tab.badge }}
                            </span>
                        </span>
                    </button>
                </div>
            </div>
        </div>
        <div class="flex w-full grow flex-col bg-white overflow-hidden">
            <slot name="panels" :active="active" />
        </div>
    </component>
</template>

<script setup>
import { nextTick, onMounted, ref, watch } from 'vue'
import BaseCard from './BaseCard.vue'

const props = defineProps({
    tabs: { type: Array, required: true },          // [{ id, label, icon?, badge? }]
    modelValue: { type: [String, Number], default: null },
    card: { type: Boolean, default: false }
})
const emit = defineEmits(['update:modelValue', 'change'])

const active = ref(props.modelValue ?? props.tabs[0]?.id ?? null)
const tablistRef = ref(null)

watch(() => props.modelValue, v => {
    if (v != null && v !== active.value) {
        active.value = v
    }
})

watch(() => props.tabs, tabs => {
    if (!tabs?.length) {
        active.value = null;
        return
    }

    if (!tabs.some(t => t.id === active.value)) {
        set(tabs[0].id)
    }
}, { deep: true })

function set(id) {
    if (id === active.value) return
    active.value = id
    emit('update:modelValue', id)
    emit('change', id)
    nextTick(() => {
        const el = tablistRef.value?.querySelector('[role="tab"][aria-selected="true"]')
        el?.focus({ preventScroll: true })
        el?.scrollIntoView({ inline: 'nearest', block: 'nearest', behavior: 'smooth' })
    })
}

function onKeys(e, i) {
    const n = props.tabs.length; if (!n) return
    const go = j => set(props.tabs[(j + n) % n].id)
    switch (e.key) {
        case 'ArrowRight': case 'Right': e.preventDefault(); go(i + 1); break
        case 'ArrowLeft':  case 'Left':  e.preventDefault(); go(i - 1); break
        case 'Home':                     e.preventDefault(); go(0); break
        case 'End':                      e.preventDefault(); go(n - 1); break
        case 'Enter': case ' ':          e.preventDefault(); break
    }
}

onMounted(() => {
    if (!active.value && props.tabs[0]) set(props.tabs[0].id)
})
</script>

<style scoped>
.no-scrollbar::-webkit-scrollbar { display: none; }
.no-scrollbar { -ms-overflow-style: none; scrollbar-width: none; }
</style>
