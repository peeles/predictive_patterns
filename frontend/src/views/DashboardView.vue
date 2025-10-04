<template>
    <div class="space-y-6">
        <header class="space-y-2">
            <p class="text-xs font-semibold uppercase tracking-wider text-stone-500">Operations overview</p>
            <h1 class="text-2xl font-semibold text-stone-900">Dashboard</h1>
            <p class="text-sm text-stone-600">Keep tabs on the latest forecasting inputs and interrogate the data assistant.</p>
        </header>
        <div class="grid gap-6 xl:grid-cols-[minmax(0,2fr)_minmax(0,1fr)]">
            <NLQConsole />
            <RecentConfigurationCard />
        </div>
    </div>
</template>

<script setup>
import NLQConsole from '../components/NLQConsole.vue'
import RecentConfigurationCard from '../components/predict/RecentConfigurationCard.vue'
</script>
