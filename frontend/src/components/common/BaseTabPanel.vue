<template>
    <section
        v-show="id === active"
        role="tabpanel"
        :id="`panel-${id}`"
        :aria-labelledby="`${labelledByPrefix}-${id}`"
        class="flex flex-1 flex-col w-full h-full"
    >
        <slot />
    </section>
</template>

<script setup>
const props = defineProps({
    id: {
        type: [String, Number],
        required: true,
    },
    active: {
        type: [String, Number],
        required: true,
    },
    labelledByPrefix: {
        type: String,
        default: 'tab',
    },
})
</script>
