<template>
    <header
        class="flex flex-wrap items-center justify-between"
        aria-label="Page Header"
        role="banner"
        aria-describedby="pageTitle"
    >
        <div class="space-y-1">
            <p class="text-xs font-semibold uppercase tracking-wider text-stone-500">
                {{ pageTag }}
            </p>
            <h1
                id="pageTitle"
                class="text-2xl font-semibold text-stone-900"
            >
                {{ pageTitle }}
            </h1>
            <p class="max-w-2xl text-sm text-stone-600">
                {{ pageSubtitle }}
            </p>
        </div>
        <div class="flex flex-wrap items-center gap-3">
            <slot name="actions" />
        </div>
    </header>
</template>

<script setup>
defineProps({
    pageTitle: {
        type: String,
        default: null
    },
    pageSubtitle: {
        type: String,
        default: null
    },
    pageTag: {
        type: String,
        default: null
    }
})
</script>
