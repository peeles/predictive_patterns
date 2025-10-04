<template>
    <div ref="trapRoot" @keydown.tab="onKeydown">
        <slot />
    </div>
</template>

<script setup>
import { onMounted, onUnmounted, ref, watch } from 'vue'

const props = defineProps({
    active: {
        type: Boolean,
        default: true,
    },
})

const trapRoot = ref(null)
let previousFocus = null

function focusFirstElement() {
    if (!props.active || !trapRoot.value || typeof document === 'undefined') return
    const focusableSelectors = [
        'a[href]',
        'button:not([disabled])',
        'textarea:not([disabled])',
        'input:not([disabled])',
        'select:not([disabled])',
        '[tabindex]:not([tabindex="-1"])',
    ]
    const focusable = trapRoot.value.querySelectorAll(focusableSelectors.join(','))
    if (focusable.length > 0) {
        focusable[0].focus()
    }
}

function onKeydown(event) {
    if (!props.active || event.key !== 'Tab' || typeof document === 'undefined') return
    const focusable = trapRoot.value?.querySelectorAll(
        'a[href], button:not([disabled]), textarea:not([disabled]), input:not([disabled]), select:not([disabled]), [tabindex]:not([tabindex="-1"])'
    )
    if (!focusable || focusable.length === 0) return

    const first = focusable[0]
    const last = focusable[focusable.length - 1]

    if (event.shiftKey && document.activeElement === first) {
        event.preventDefault()
        last.focus()
    } else if (!event.shiftKey && document.activeElement === last) {
        event.preventDefault()
        first.focus()
    }
}

watch(
    () => props.active,
    (isActive) => {
        if (isActive && typeof document !== 'undefined') {
            previousFocus = document.activeElement
            focusFirstElement()
        }
    }
)

onMounted(() => {
    if (props.active && typeof document !== 'undefined') {
        previousFocus = document.activeElement
        focusFirstElement()
    }
})

onUnmounted(() => {
    if (previousFocus && typeof previousFocus.focus === 'function') {
        previousFocus.focus()
    }
})
</script>
