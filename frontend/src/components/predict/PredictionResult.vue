<template>
    <section
        aria-labelledby="prediction-summary-heading"
        class="flex h-full flex-col overflow-hidden bg-white"
    >
        <header class="px-6 py-6">
            <div class="flex flex-wrap items-center justify-between gap-4">
                <div class="space-y-1">
                    <p class="text-xs font-semibold uppercase tracking-[0.2em] text-shadow-stone-700/70">Latest forecast</p>
                    <h2
                        id="prediction-summary-heading"
                        class="text-2xl font-semibold text-shadow-stone-600"
                    >
                        Prediction insights
                    </h2>
                    <p class="text-sm text-stone-700/80">
                        Generated on <time :datetime="summary.generatedAt">{{ formattedGeneratedAt }}</time> for a
                        {{ horizonLabel }} horizon.
                    </p>
                </div>
                <div class="flex items-center gap-5">
                    <div
                        class="relative flex h-28 w-28 items-center justify-center rounded-full border border-stone-500/20 shadow-inner bg-stone-500/60"
                        :style="riskDialStyle"
                        role="img"
                        :aria-label="`Risk score ${riskPercentLabel}`"
                    >
                        <span class="text-3xl font-semibold text-stone-700">{{ riskPercentLabel }}</span>
                    </div>
                    <div class="space-y-2 text-right">
                        <p class="text-sm font-semibold uppercase tracking-wide text-stone-700/70">Risk score</p>
                        <p class="text-3xl font-semibold leading-none text-stone-700">{{ formattedRiskScore }}</p>
                        <p class="text-xs text-stone-700/80">
                            Confidence
                            <span
                                class="ml-2 inline-flex items-center rounded-full bg-white/15 px-2 py-1 text-xs font-semibold uppercase tracking-wide text-stone-700 shadow-sm"
                            >
                                {{ confidenceLabel }}
                            </span>
                        </p>
                    </div>
                </div>
            </div>
        </header>
        <div class="grid flex-1 gap-6 px-6 lg:grid-cols-[minmax(0,0.95fr)_minmax(0,1.05fr)]">
            <section
                aria-labelledby="forecast-snapshot-heading"
                class="space-y-6 self-start rounded-2xl border border-stone-200/80 bg-stone-50/70 p-6 shadow-inner"
            >
                <header class="space-y-2">
                    <p class="text-xs font-semibold uppercase tracking-wide text-stone-500">Forecast snapshot</p>
                    <h3 id="forecast-snapshot-heading" class="text-lg font-semibold text-stone-900">Operational overview</h3>
                </header>
                <dl class="space-y-4 text-sm text-stone-600">
                    <div class="flex items-center justify-between gap-4">
                        <dt class="text-stone-500">Radius of focus</dt>
                        <dd class="text-base font-semibold text-stone-900">{{ radiusLabel }}</dd>
                    </div>
                    <div class="flex items-center justify-between gap-4">
                        <dt class="text-stone-500">Horizon</dt>
                        <dd class="text-base font-semibold text-stone-900">{{ horizonLabel }}</dd>
                    </div>
                    <div class="flex items-center justify-between gap-4">
                        <dt class="text-stone-500">Generated</dt>
                        <dd class="text-base font-semibold text-stone-900">{{ formattedGeneratedAt }}</dd>
                    </div>
                </dl>
            </section>
            <section
                aria-labelledby="top-features-heading"
                class="flex flex-col gap-4 rounded-2xl border border-stone-200/80 bg-white/85 p-6 shadow-sm shadow-stone-200/60"
            >
                <header class="space-y-1">
                    <p class="text-xs font-semibold uppercase tracking-wide text-stone-500">Explainability</p>
                    <h3 id="top-features-heading" class="text-lg font-semibold text-stone-900">Top contributing features</h3>
                    <p class="text-sm text-stone-500">Relative impact is scaled against the leading signal.</p>
                </header>
                <ul v-if="normalizedFeatures.length" class="flex flex-1 flex-col gap-3" role="list">
                    <li
                        v-for="feature in normalizedFeatures"
                        :key="feature.name"
                        class="rounded-xl border border-stone-200/70 bg-white/80 p-4 shadow-sm shadow-stone-200/50"
                    >
                        <div class="flex items-baseline justify-between gap-3">
                            <p class="text-sm font-medium text-stone-900">{{ feature.name }}</p>
                            <p class="text-sm font-semibold text-stone-600">{{ feature.displayValue }}</p>
                        </div>
                        <div class="mt-3 h-2 rounded-full bg-stone-200/80" aria-hidden="true">
                            <div
                                class="h-2 rounded-full bg-gradient-to-r from-blue-500 via-indigo-500 to-sky-500 transition-all duration-500"
                                :style="{ width: feature.barWidth }"
                            />
                        </div>
                    </li>
                    <li
                        v-if="!hasFeatureIntensity"
                        class="rounded-xl border border-dashed border-stone-300 bg-stone-50/80 p-4 text-sm text-stone-500"
                    >
                        Contributions were provided without intensity values. Displayed order reflects received ranking.
                    </li>
                </ul>
                <p
                    v-else
                    class="flex flex-1 items-center justify-center rounded-xl border border-dashed border-stone-300 bg-stone-50/80 p-6 text-sm text-stone-500"
                >
                    No feature contributions available for this prediction.
                </p>
            </section>
        </div>
    </section>
</template>

<script setup>
import {computed} from 'vue'

const props = defineProps({
    summary: {
        type: Object,
        required: true,
    },
    features: {
        type: Array,
        default: () => [],
    },
    radius: {
        type: Number,
        default: 1.5,
    },
})

const formattedGeneratedAt = computed(() => {
    if (!props.summary.generatedAt) return 'Unknown time'
    return new Intl.DateTimeFormat('en-GB', {
        dateStyle: 'medium',
        timeStyle: 'short',
    }).format(new Date(props.summary.generatedAt))
})

const parsedRiskScore = computed(() => {
    const value = Number.parseFloat(props.summary?.riskScore ?? 0)
    return Number.isFinite(value) ? value : 0
})

const formattedRiskScore = computed(() =>
    new Intl.NumberFormat('en-GB', { maximumFractionDigits: 2, minimumFractionDigits: 2 }).format(parsedRiskScore.value)
)

const riskPercent = computed(() => {
    return Math.min(Math.max(parsedRiskScore.value, 0), 1)
})

const riskPercentLabel = computed(() => `${Math.round(riskPercent.value * 100)}%`)

const riskDialStyle = computed(() => {
    const degrees = Math.round(riskPercent.value * 360)
    return {
        backgroundImage: `conic-gradient(rgba(255,255,255,0.92) ${degrees}deg, rgba(255,255,255,0.18) ${degrees}deg)`,
    }
})

const confidenceLabel = computed(() => {
    const confidence = props.summary?.confidence
    if (typeof confidence === 'string' && confidence.trim().length) {
        return confidence
    }
    return 'Unknown'
})

const radiusLabel = computed(() => {
    const numeric = Number.parseFloat(props.radius)
    if (Number.isFinite(numeric)) {
        return `${numeric.toFixed(1)} km`
    }
    return 'N/A'
})

const horizonLabel = computed(() => {
    const numeric = Number.parseFloat(props.summary?.horizonHours ?? '')
    if (Number.isFinite(numeric)) {
        return `${numeric} hour${numeric === 1 ? '' : 's'}`
    }
    return 'Unknown duration'
})

const normalizedFeatures = computed(() => {
    const features = Array.isArray(props.features) ? props.features : []
    if (!features.length) {
        return []
    }

    const sanitized = features.map((feature) => {
        const name = typeof feature?.name === 'string' && feature.name.trim().length ? feature.name : 'Unnamed feature'
        const rawContribution = feature?.contribution
        const numeric = typeof rawContribution === 'number' ? rawContribution : Number.parseFloat(rawContribution)
        const value = Number.isFinite(numeric) ? numeric : null
        const displayValue = value === null
            ? (rawContribution ?? '—')
            : new Intl.NumberFormat('en-GB', { maximumFractionDigits: 2, minimumFractionDigits: 2 }).format(value)

        return { name, value, displayValue }
    })

    const max = sanitized.reduce((acc, feature) => {
        if (feature.value === null) {
            return acc
        }
        return Math.max(acc, Math.abs(feature.value))
    }, 0)

    return sanitized.map((feature) => {
        if (feature.value === null || max === 0) {
            return {
                ...feature,
                percentage: 0,
                barWidth: '0%',
            }
        }

        const percentage = Math.round((Math.abs(feature.value) / max) * 100)
        const width = `${Math.min(100, Math.max(percentage, 6))}%`

        return {
            ...feature,
            percentage,
            barWidth: width,
        }
    })
})

const hasFeatureIntensity = computed(() =>
    normalizedFeatures.value.some((feature) => feature.percentage > 0)
)
</script>
