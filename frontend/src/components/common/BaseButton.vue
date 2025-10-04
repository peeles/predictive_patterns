<template>
    <button
        :type="props.type"
        :title="props.title"
        class="w-full align-middle select-none font-roboto font-medium text-center uppercase transition-all disabled:opacity-50 disabled:pointer-events-none text-sm py-3 px-6 rounded shadow-md shadow-stone-900/10"
    >
        {{ props.title }}
    </button>
</template>

<script setup>
const props = defineProps({
    type: {
        type: String,
        default: 'button'
    },
    title: {
        type: String,
        default: null
    }
});
</script>
