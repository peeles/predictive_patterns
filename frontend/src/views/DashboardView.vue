<template>
    <div class="space-y-6">
        <PageHeader
            :page-tag="'Overview'"
            :page-title="'Dashboard'"
            :page-subtitle="'Keep tabs on the latest forecasting inputs and interrogate the data assistant.'"
        />

        <div class="grid gap-6 xl:grid-cols-[minmax(0,2fr)_minmax(0,1fr)]">
            <NLQConsole />
            <RecentConfigurationCard />
        </div>
    </div>
</template>

<script setup>
import NLQConsole from '../components/NLQConsole.vue'
import RecentConfigurationCard from '../components/predict/RecentConfigurationCard.vue'
import PageHeader from "../components/common/PageHeader.vue";
</script>
