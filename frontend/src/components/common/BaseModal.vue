<template>
    <Teleport
        to="body"
        v-if="open"
    >
        <div
            class="fixed inset-0 z-50 flex items-center justify-center bg-stone-900/60 px-4 py-8"
            role="dialog"
            aria-modal="true"
        >
            <div
                :class="[
                    'w-full overflow-hidden rounded-2xl bg-white shadow-xl',
                    dialogClass,
                ]"
            >
                <header
                    class="flex items-start justify-between gap-4 border-b border-stone-200 px-6 py-6"
                >
                    <div>
                        <slot name="header" />
                    </div>
                    <button
                        type="button"
                        class="rounded-full p-2 text-stone-500 transition hover:bg-stone-100 hover:text-stone-700 focus-visible:outline  focus-visible:outline-offset-2 focus-visible:outline-blue-500"
                        @click="close"
                    >
                        <span class="sr-only">Close</span>
                        <svg aria-hidden="true" class="h-5 w-5"  stroke="currentColor" viewBox="0 0 24 24">
                            <path d="M6 18L18 6M6 6l12 12" stroke-linecap="round" stroke-linejoin="round" stroke-width="2" />
                        </svg>
                    </button>
                </header>

                <nav
                    v-if="hasSteps"
                    aria-label="Wizard steps"
                    class="border-b border-stone-200 bg-stone-50"
                >
                    <slot name="steps" />
                </nav>

                <section
                    :class="[
                        'overflow-y-auto px-6 py-6 text-sm text-stone-700',
                        bodyClass,
                    ]"
                >
                    <slot />
                </section>

                <footer
                    v-if="hasFooter"
                    class="flex items-center justify-between gap-3 border-t border-stone-200 px-6 py-4"
                >
                    <slot name="footer" />
                </footer>
            </div>
        </div>
    </Teleport>
</template>

<script setup>
import { computed, useSlots } from 'vue'

const props = defineProps({
    open: {
        type: Boolean,
        default: false,
    },
    dialogClass: {
        type: String,
        default: 'max-w-3xl',
    },
    bodyClass: {
        type: String,
        default: 'max-h-[60vh]',
    },
})

const emit = defineEmits(['close'])

const slots = useSlots()

const hasSteps = computed(() => Boolean(slots.steps))
const hasFooter = computed(() => Boolean(slots.footer))

function close() {
    emit('close')
}
</script>
