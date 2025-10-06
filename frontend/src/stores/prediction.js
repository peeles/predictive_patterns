import { defineStore } from 'pinia'
import apiClient from '../services/apiClient'
import { getBroadcastClient } from '../services/broadcast'
import { notifyError, notifyInfo, notifySuccess } from '../utils/notifications'
import { useModelStore } from './model'
import { useRequestStore } from './request'

const generateId = () => {
    if (typeof crypto !== 'undefined' && crypto.randomUUID) {
        return crypto.randomUUID()
    }
    return `prediction-${Math.random().toString(36).slice(2, 10)}-${Date.now()}`
}

const POLL_INTERVAL_MS = 2000
const POLL_TIMEOUT_MS = 2 * 60 * 1000
const DEFAULT_HISTORY_PAGE_SIZE = 10

const wait = (ms) => new Promise((resolve) => setTimeout(resolve, ms))

const normalisePercent = (value) => {
    if (!Number.isFinite(value)) {
        return null
    }

    const basis = value <= 1 ? value * 100 : value

    return Math.min(100, Math.max(0, Math.round(basis)))
}

const progressRatioToPercent = (value) => {
    if (typeof value !== 'number' || !Number.isFinite(value)) {
        return null
    }

    return normalisePercent(value * 100)
}

const coerceNumber = (value, fallback = null) => {
    if (value === null || typeof value === 'undefined') {
        return fallback
    }
    const parsed = Number(value)
    return Number.isFinite(parsed) ? parsed : fallback
}

const normalizeCenter = (value) => {
    if (!value || typeof value !== 'object') {
        return null
    }

    const directLat = coerceNumber(value.lat ?? value.latitude ?? value[1] ?? value.y)
    const directLng = coerceNumber(value.lng ?? value.lon ?? value.longitude ?? value[0] ?? value.x)

    if (Number.isFinite(directLat) && Number.isFinite(directLng)) {
        const label = typeof value.label === 'string' ? value.label : undefined
        return { lat: directLat, lng: directLng, ...(label ? { label } : {}) }
    }

    if (Array.isArray(value.coordinates) && value.coordinates.length >= 2) {
        const [lng, lat] = value.coordinates
        if (Number.isFinite(lat) && Number.isFinite(lng)) {
            return { lat, lng }
        }
    }

    if (value.center) {
        return normalizeCenter(value.center)
    }

    return null
}

const normalizeFeatures = (payload) => {
    const featureCandidates = [
        payload?.top_features,
        payload?.features,
        payload?.feature_importances,
    ].find((candidate) => Array.isArray(candidate))

    if (!Array.isArray(featureCandidates)) {
        return []
    }

    return featureCandidates
        .map((feature, index) => {
            if (!feature || typeof feature !== 'object') {
                return null
            }

            const name = feature.name ?? feature.feature ?? feature.id ?? `Feature ${index + 1}`
            const contribution = coerceNumber(
                feature.contribution ?? feature.value ?? feature.score ?? feature.importance,
                0
            )

            if (!name) {
                return null
            }

            return {
                name,
                contribution: Number(contribution.toFixed(2)),
            }
        })
        .filter(Boolean)
}

const normalizeHeatmapPoints = (payload) => {
    const sources = [
        payload?.heatmap?.points,
        payload?.heatmap?.cells,
        payload?.heatmap,
        payload?.points,
        payload?.cells,
    ]

    const dataSource = sources.find((candidate) => Array.isArray(candidate) && candidate.length)
    if (!Array.isArray(dataSource)) {
        return []
    }

    return dataSource
        .map((entry, index) => {
            if (!entry || typeof entry !== 'object') {
                return null
            }

            const lat = coerceNumber(
                entry.lat ??
                    entry.latitude ??
                    entry.y ??
                    entry?.center?.lat ??
                    entry?.centroid?.lat ??
                    entry?.coordinates?.[1]
            )
            const lng = coerceNumber(
                entry.lng ??
                    entry.lon ??
                    entry.longitude ??
                    entry.x ??
                    entry?.center?.lng ??
                    entry?.centroid?.lng ??
                    entry?.coordinates?.[0]
            )

            if (!Number.isFinite(lat) || !Number.isFinite(lng)) {
                return null
            }

            const intensity = coerceNumber(
                entry.intensity ?? entry.risk ?? entry.score ?? entry.value ?? entry.count,
                0
            )

            const id = entry.id ?? entry.cell_id ?? entry.area_id ?? `point-${index}`

            return {
                id,
                lat,
                lng,
                intensity: Number(intensity.toFixed(3)),
            }
        })
        .filter(Boolean)
}

const mergeFilters = (parameters = {}, fallback = {}) => {
    const center = normalizeCenter(parameters.center ?? parameters.location ?? parameters.centroid)
    const horizon = coerceNumber(
        parameters.horizon_hours ?? parameters.horizon ?? parameters.horizonHours,
        null
    )
    const radius = coerceNumber(parameters.radius_km ?? parameters.radiusKm ?? parameters.radius, null)
    const timestamp = parameters.observed_at ?? parameters.timestamp ?? parameters.ts_end ?? null

    return {
        horizon: horizon ?? fallback.horizon ?? fallback.horizonHours ?? null,
        timestamp: timestamp ?? fallback.timestamp ?? null,
        center: center ?? (fallback.center ? { ...fallback.center } : null),
        radiusKm: radius ?? fallback.radiusKm ?? null,
        modelId: parameters.model_id ?? fallback.modelId ?? null,
        datasetId: parameters.dataset_id ?? fallback.datasetId ?? null,
    }
}

const buildSummary = (summary = {}, filters = {}, status = 'unknown') => {
    const riskCandidate =
        summary.risk_score ?? summary.mean_score ?? summary.score ?? summary.max_score ?? null
    const riskScore = coerceNumber(riskCandidate, null)

    const horizonCandidate =
        summary.horizon_hours ?? summary.horizon ?? filters.horizon ?? filters.horizonHours ?? null
    const horizon = coerceNumber(horizonCandidate, null)

    let confidence = summary.confidence ?? summary.confidence_label ?? null
    if (!confidence) {
        if (status === 'queued' || status === 'running') {
            confidence = 'Pending'
        } else if (status === 'failed') {
            confidence = 'Unavailable'
        } else {
            confidence = 'Unknown'
        }
    }

    return {
        riskScore: Number((riskScore ?? 0).toFixed(2)),
        confidence,
        horizonHours: horizon ?? filters.horizon ?? filters.horizonHours ?? 0,
    }
}

const normalizePredictionResponse = (prediction = {}, fallbackFilters = {}) => {
    if (!prediction || typeof prediction !== 'object') {
        return null
    }

    const status = typeof prediction.status === 'string' ? prediction.status.toLowerCase() : 'unknown'
    const outputs = Array.isArray(prediction.outputs) ? prediction.outputs : []
    const jsonOutput = outputs.find(
        (output) => typeof output?.format === 'string' && output.format.toLowerCase() === 'json'
    )

    const payload = jsonOutput && typeof jsonOutput.payload === 'object' ? jsonOutput.payload : {}
    const mergedParameters = {
        ...(typeof prediction.parameters === 'object' && prediction.parameters ? prediction.parameters : {}),
        ...(typeof payload.parameters === 'object' && payload.parameters ? payload.parameters : {}),
    }

    const filters = mergeFilters(mergedParameters, fallbackFilters)

    const modelInfo = (() => {
        const candidate = typeof prediction.model === 'object' && prediction.model ? prediction.model : {}
        const id = candidate.id ?? prediction.model_id ?? filters.modelId ?? fallbackFilters?.modelId ?? null

        if (!id) {
            return null
        }

        const name = candidate.name ?? prediction.model_name ?? null
        const version = candidate.version ?? prediction.model_version ?? null
        const normalised = { id }

        if (typeof name === 'string' && name.trim().length) {
            normalised.name = name
        }

        if (typeof version === 'string' && version.trim().length) {
            normalised.version = version
        }

        return normalised
    })()

    const summary = buildSummary(payload.summary ?? {}, filters, status)
    const heatmap = normalizeHeatmapPoints(payload)
    const features = normalizeFeatures(payload)

    const generatedAt =
        payload.generated_at ??
        prediction.finished_at ??
        prediction.started_at ??
        prediction.queued_at ??
        filters.timestamp ??
        new Date().toISOString()

    const progressPercent = (() => {
        const rawProgress = coerceNumber(prediction.progress, null)
        if (Number.isFinite(rawProgress)) {
            return normalisePercent(rawProgress)
        }
        return null
    })()

    const progressMessage = (() => {
        const explicit = prediction.progress_message ?? prediction.status_message ?? null
        if (typeof explicit === 'string' && explicit.trim().length) {
            return explicit
        }

        if (status === 'queued') {
            return 'Prediction queued. Waiting for worker availability.'
        }

        if (status === 'running') {
            return 'Generating forecast using the latest model snapshot.'
        }

        return null
    })()

    return {
        id: prediction.id ?? generateId(),
        status,
        modelId: modelInfo?.id ?? prediction.model_id ?? filters.modelId ?? null,
        datasetId: prediction.dataset_id ?? filters.datasetId ?? null,
        errorMessage: prediction.error_message ?? null,
        queuedAt: prediction.queued_at ?? null,
        startedAt: prediction.started_at ?? null,
        finishedAt: prediction.finished_at ?? null,
        createdAt: prediction.created_at ?? null,
        generatedAt,
        filters,
        summary,
        topFeatures: features,
        heatmap,
        outputs,
        model: modelInfo,
        progress: progressPercent,
        progressMessage,
    }
}

const fallbackPrediction = (filters) => {
    const seededIntensity = Math.sin(Date.now() / 100000) * 0.5 + 0.5
    return {
        id: generateId(),
        status: 'simulated',
        generatedAt: new Date().toISOString(),
        createdAt: new Date().toISOString(),
        filters,
        summary: {
            riskScore: Number((0.6 + seededIntensity * 0.3).toFixed(2)),
            confidence: seededIntensity > 0.6 ? 'High' : seededIntensity > 0.3 ? 'Medium' : 'Low',
            horizonHours: filters.horizon,
        },
        topFeatures: [
            { name: 'Recent incidents', contribution: Number((seededIntensity * 0.5 + 0.25).toFixed(2)) },
            { name: 'Population density', contribution: Number((0.2 + seededIntensity * 0.3).toFixed(2)) },
            { name: 'Lighting quality', contribution: Number((0.1 + seededIntensity * 0.2).toFixed(2)) },
        ],
        heatmap: Array.from({ length: 20 }).map((_, index) => ({
            id: index,
            lat: filters.center.lat + (Math.random() - 0.5) * 0.02,
            lng: filters.center.lng + (Math.random() - 0.5) * 0.02,
            intensity: Number((0.3 + Math.random() * 0.7).toFixed(2)),
        })),
        outputs: [],
        errorMessage: null,
        queuedAt: null,
        startedAt: null,
        finishedAt: new Date().toISOString(),
    }
}

export const usePredictionStore = defineStore('prediction', {
    state: () => ({
        currentPrediction: null,
        loading: false,
        lastFilters: {
            horizon: 6,
            timestamp: new Date().toISOString().slice(0, 16),
            center: { lat: 51.5074, lng: -0.1278 },
            radiusKm: 1.5,
            modelId: null,
            datasetId: null,
        },
        history: [],
        historyMeta: { current_page: 1, per_page: DEFAULT_HISTORY_PAGE_SIZE, total: 0 },
        historyFilters: { status: 'all', modelId: null, from: null, to: null },
        historyLoading: false,
        historyError: null,
        historyHydrated: false,
        pollAbortController: null,
        realtimeStatus: null,
        realtimeSubscription: null,
        realtimePredictionId: null,
        realtimeFilters: null,
        realtimeAwaiter: null,
        realtimeCompletionNotified: false,
    }),
    getters: {
        hasPrediction: (state) => Boolean(state.currentPrediction),
        heatmapPoints: (state) => state.currentPrediction?.heatmap ?? [],
        featureBreakdown: (state) => state.currentPrediction?.topFeatures ?? [],
        summary: (state) => state.currentPrediction?.summary ?? null,
    },
    actions: {
        cancelPolling() {
            if (this.pollAbortController) {
                this.pollAbortController.abort()
                this.pollAbortController = null
            }

            this.abortRealtimeAwaiter('REALTIME_ABORTED')
            this.stopRealtimeTracking()
        },
        settleRealtimeAwaiter(handler) {
            const awaiter = this.realtimeAwaiter
            if (!awaiter || awaiter.settled) {
                return
            }

            awaiter.settled = true

            try {
                handler(awaiter)
            } catch (error) {
                awaiter.reject?.(error)
            } finally {
                this.realtimeAwaiter = null
            }
        },
        abortRealtimeAwaiter(code = 'REALTIME_ABORTED', cause = null) {
            const awaiter = this.realtimeAwaiter
            if (!awaiter || awaiter.settled) {
                return
            }

            const error = new Error('Realtime tracking aborted')
            error.code = code
            if (cause) {
                error.cause = cause
            }

            awaiter.settled = true
            awaiter.reject?.(error)
            this.realtimeAwaiter = null
        },
        stopRealtimeTracking() {
            const subscription = this.realtimeSubscription
            if (subscription) {
                const broadcast = getBroadcastClient()
                if (broadcast) {
                    const channelName = subscription?.channelName ?? `predictions.${this.realtimePredictionId}.status`
                    try {
                        broadcast.unsubscribe(channelName)
                    } catch (error) {
                        console.warn('Error unsubscribing from prediction status channel', error)
                    }
                }
            }

            this.realtimeSubscription = null
            this.realtimePredictionId = null
            this.realtimeFilters = null
            this.realtimeStatus = null
        },
        startRealtimeTracking(predictionId, filters = {}, options = {}) {
            const awaitCompletion = Boolean(options?.awaitCompletion)

            if (!predictionId) {
                return null
            }

            const broadcast = getBroadcastClient()
            if (!broadcast) {
                return null
            }

            this.stopRealtimeTracking()
            this.abortRealtimeAwaiter('REALTIME_REPLACED')

            const channelName = `predictions.${predictionId}.status`
            let subscription

            try {
                subscription = broadcast.subscribe(channelName, {
                    onEvent: (eventName, payload) => {
                        if (eventName === 'PredictionStatusUpdated' || eventName === '.PredictionStatusUpdated') {
                            this.handleRealtimeStatus(predictionId, payload)
                        }
                    },
                    onSubscribed: () => {
                        subscription.status = 'subscribed'
                    },
                    onError: (error) => {
                        console.warn('Prediction status channel error', error)
                        this.handleRealtimeSubscriptionError(error)
                    },
                })
            } catch (error) {
                console.warn('Unable to subscribe to prediction status channel', error)
                return null
            }

            subscription.status = 'pending'
            subscription.channelName = channelName

            this.realtimeSubscription = subscription
            this.realtimePredictionId = predictionId
            this.realtimeFilters = filters ? { ...filters } : null
            this.realtimeStatus = null
            this.realtimeCompletionNotified = false

            if (!awaitCompletion) {
                this.realtimeAwaiter = null
                return subscription
            }

            let resolveFn
            let rejectFn
            const promise = new Promise((resolve, reject) => {
                resolveFn = resolve
                rejectFn = reject
            })

            this.realtimeAwaiter = {
                promise,
                resolve: resolveFn,
                reject: rejectFn,
                settled: false,
            }

            return promise
        },
        handleRealtimeSubscriptionError(error) {
            this.abortRealtimeAwaiter('REALTIME_UNAVAILABLE', error)
            this.stopRealtimeTracking()
        },
        handleRealtimeStatus(predictionId, payload = {}) {
            const targetId = typeof payload?.prediction_id === 'string' ? payload.prediction_id : predictionId
            if (!targetId || targetId !== this.realtimePredictionId) {
                return
            }

            const status = typeof payload?.status === 'string' ? payload.status.toLowerCase() : null
            const progressRatio = typeof payload?.progress === 'number' ? payload.progress : null
            const message = typeof payload?.message === 'string' ? payload.message : null

            this.realtimeStatus = {
                status,
                progress: progressRatio,
                updatedAt: payload?.updated_at ?? new Date().toISOString(),
                message,
            }

            if (this.currentPrediction && this.currentPrediction.id === targetId) {
                const next = { ...this.currentPrediction }

                if (status) {
                    next.status = status
                }

                if (payload?.queued_at) {
                    next.queuedAt = payload.queued_at
                }

                if (payload?.started_at) {
                    next.startedAt = payload.started_at
                }

                if (payload?.finished_at) {
                    next.finishedAt = payload.finished_at
                }

                if (message) {
                    next.progressMessage = message
                } else if (status === 'queued' && !next.progressMessage) {
                    next.progressMessage = 'Prediction queued. Waiting for worker availability.'
                } else if (status === 'running' && !next.progressMessage) {
                    next.progressMessage = 'Generating forecast using the latest model snapshot.'
                }

                if (progressRatio !== null && Number.isFinite(progressRatio)) {
                    next.progress = progressRatioToPercent(progressRatio)
                }

                if (status === 'completed') {
                    next.progress = 100
                    next.progressMessage = 'Prediction complete.'
                }

                if (status === 'failed' && message) {
                    next.errorMessage = message
                }

                this.currentPrediction = next
            }

            if (status === 'completed') {
                void this.fetchPredictionSnapshot(targetId, this.realtimeFilters ?? this.lastFilters)
                    .then((prediction) => {
                        this.realtimeCompletionNotified = true
                        notifySuccess({
                            title: 'Prediction ready',
                            message: 'Latest risk surface generated successfully.',
                        })

                        const resolved = prediction ?? this.currentPrediction
                        this.settleRealtimeAwaiter((awaiter) => awaiter.resolve?.(resolved))
                    })
                    .catch((error) => {
                        this.settleRealtimeAwaiter((awaiter) => awaiter.reject?.(error))
                    })
                    .finally(() => {
                        this.stopRealtimeTracking()
                    })

                return
            }

            if (status === 'failed') {
                const failureError = new Error(message || 'Prediction failed to generate results.')
                failureError.code = 'PREDICTION_FAILED'

                if (this.currentPrediction && this.currentPrediction.id === targetId) {
                    failureError.prediction = this.currentPrediction
                }

                notifyError(failureError, failureError.message)
                this.settleRealtimeAwaiter((awaiter) => awaiter.reject?.(failureError))
                this.stopRealtimeTracking()
            }
        },
        async fetchPredictionSnapshot(predictionId, filters = null) {
            if (!predictionId) {
                return null
            }

            const { data } = await apiClient.get(`/predictions/${predictionId}`)
            const prediction = normalizePredictionResponse(
                data?.prediction ?? data,
                filters ?? this.lastFilters
            )

            if (!prediction) {
                return null
            }

            const next = {
                ...prediction,
                progress:
                    prediction.progress ??
                    (prediction.status === 'completed' ? 100 : prediction.progress),
                progressMessage:
                    prediction.progressMessage ??
                    (prediction.status === 'completed' ? 'Prediction complete.' : prediction.progressMessage),
            }

            this.currentPrediction = next
            this.upsertHistory(next)
            this.updateLastFiltersFromPrediction(next)

            return next
        },
        updateLastFiltersFromPrediction(prediction) {
            if (!prediction || !prediction.filters) {
                return
            }

            const filters = prediction.filters

            this.lastFilters = {
                horizon: Number.isFinite(filters.horizon)
                    ? filters.horizon
                    : this.lastFilters.horizon,
                timestamp: filters.timestamp ?? this.lastFilters.timestamp,
                center: filters.center
                    ? { ...filters.center }
                    : this.lastFilters.center
                    ? { ...this.lastFilters.center }
                    : null,
                radiusKm: Number.isFinite(filters.radiusKm)
                    ? filters.radiusKm
                    : this.lastFilters.radiusKm,
                modelId: filters.modelId ?? this.lastFilters.modelId ?? null,
                datasetId: filters.datasetId ?? this.lastFilters.datasetId ?? null,
            }
        },
        upsertHistory(prediction) {
            if (!prediction || !prediction.id) {
                return
            }
            const existingIndex = this.history.findIndex((entry) => entry.id === prediction.id)
            if (existingIndex === -1) {
                this.history.unshift(prediction)
                const perPage = Number(this.historyMeta?.per_page ?? DEFAULT_HISTORY_PAGE_SIZE)
                if (this.history.length > perPage) {
                    this.history = this.history.slice(0, perPage)
                }

                const currentMeta = this.historyMeta ?? {
                    current_page: 1,
                    per_page: perPage,
                    total: 0,
                }

                const nextTotal = Number(currentMeta.total ?? 0) + 1
                this.historyMeta = {
                    ...currentMeta,
                    per_page: perPage,
                    total: Math.max(nextTotal, this.history.length),
                }
                return
            }

            this.history.splice(existingIndex, 1)
            this.history.unshift(prediction)

            if (this.historyMeta) {
                this.historyMeta = {
                    ...this.historyMeta,
                    total: Math.max(Number(this.historyMeta.total ?? 0), this.history.length),
                }
            }
        },
        async hydrateHistory(force = false) {
            if (this.historyHydrated && !force) {
                return
            }

            try {
                await this.fetchHistory({ page: 1 })
                this.historyHydrated = true
            } catch (error) {
                this.historyHydrated = false
                throw error
            }
        },
        async fetchHistory(options = {}) {
            const page = Math.max(1, Number(options.page ?? this.historyMeta?.current_page ?? 1))
            const perPage = Math.max(1, Number(options.perPage ?? this.historyMeta?.per_page ?? DEFAULT_HISTORY_PAGE_SIZE))
            const mergedFilters = {
                ...this.historyFilters,
                ...(options.filters || {}),
            }

            const params = { page, per_page: perPage }
            const filterParams = {}

            if (mergedFilters.status && mergedFilters.status !== 'all') {
                filterParams.status = mergedFilters.status
            }

            if (mergedFilters.modelId) {
                filterParams.model_id = mergedFilters.modelId
            }

            if (mergedFilters.from) {
                filterParams.from = mergedFilters.from
            }

            if (mergedFilters.to) {
                filterParams.to = mergedFilters.to
            }

            if (Object.keys(filterParams).length) {
                params.filter = filterParams
            }

            this.historyLoading = true
            this.historyError = null

            try {
                const { data } = await apiClient.get('/predictions', { params })

                if (Array.isArray(data?.data)) {
                    this.history = data.data
                        .map((item) => normalizePredictionResponse(item, item?.parameters ?? {}))
                        .filter(Boolean)

                    const meta = {
                        current_page: Number(data?.meta?.current_page ?? page),
                        per_page: Number(data?.meta?.per_page ?? perPage),
                        total: Number(data?.meta?.total ?? data?.data?.length ?? 0),
                    }

                    meta.total = Math.max(meta.total, this.history.length)

                    this.historyMeta = meta
                    this.historyFilters = mergedFilters
                    this.historyHydrated = true
                } else {
                    this.history = []
                    this.historyMeta = { current_page: page, per_page: perPage, total: 0 }
                }

                return this.history
            } catch (error) {
                this.historyError = error
                throw error
            } finally {
                this.historyLoading = false
            }
        },
        async selectHistoryPrediction(predictionId) {
            if (!predictionId) {
                return null
            }

            this.cancelPolling()
            this.loading = true

            try {
                const prediction = await this.fetchPredictionSnapshot(predictionId, this.lastFilters)

                if (prediction && (prediction.status === 'queued' || prediction.status === 'running')) {
                    this.startRealtimeTracking(prediction.id, prediction.filters ?? this.lastFilters)
                }

                return prediction ?? null
            } finally {
                this.loading = false
            }
        },
        async pollPredictionStatus(predictionId, submissionFilters, signal) {
            const startedAt = Date.now()
            let lastPrediction = this.currentPrediction

            while (Date.now() - startedAt < POLL_TIMEOUT_MS) {
                if (signal?.aborted) {
                    const abortError = new Error('Polling aborted')
                    abortError.name = 'AbortError'
                    throw abortError
                }

                const { data } = await apiClient.get(`/predictions/${predictionId}`, {
                    signal,
                })

                const prediction = normalizePredictionResponse(
                    data?.prediction ?? data,
                    submissionFilters
                )

                if (prediction) {
                    this.currentPrediction = prediction
                    this.upsertHistory(prediction)
                    lastPrediction = prediction
                }

                if (prediction && prediction.status === 'completed' && prediction.outputs.length) {
                    return prediction
                }

                if (prediction && prediction.status === 'failed') {
                    const error = new Error(
                        prediction.errorMessage || 'Prediction failed to generate results.'
                    )
                    error.code = 'PREDICTION_FAILED'
                    error.prediction = prediction
                    throw error
                }

                await wait(POLL_INTERVAL_MS)
            }

            const timeoutError = new Error(
                'Prediction is still pending. Results will appear once processing completes.'
            )
            timeoutError.code = 'PREDICTION_TIMEOUT'
            timeoutError.prediction = lastPrediction
            throw timeoutError
        },
        async submitPrediction(filters) {
            this.loading = true
            this.cancelPolling()
            this.realtimeCompletionNotified = false
            const previousFilters = {
                horizon: this.lastFilters.horizon,
                timestamp: this.lastFilters.timestamp,
                center: this.lastFilters.center ? { ...this.lastFilters.center } : null,
                radiusKm: this.lastFilters.radiusKm,
                modelId: this.lastFilters.modelId ?? null,
                datasetId: this.lastFilters.datasetId ?? null,
            }
            const modelStore = useModelStore()
            let activeModel = modelStore.activeModel
            if (!activeModel && !modelStore.loading) {
                await modelStore.fetchModels()
                activeModel = modelStore.activeModel ?? modelStore.models[0] ?? null
            }

            const normalizeNumber = (value, fallback) => {
                const parsed = Number(value)
                return Number.isFinite(parsed) ? parsed : fallback
            }

            const modelId = filters.modelId ?? activeModel?.id ?? null
            const datasetId =
                filters.datasetId ?? activeModel?.dataset_id ?? activeModel?.datasetId ?? null

            const submissionFilters = {
                horizon: normalizeNumber(filters.horizon, previousFilters.horizon),
                timestamp: filters.timestamp ?? previousFilters.timestamp,
                center: filters.center ? { ...filters.center } : previousFilters.center,
                radiusKm: normalizeNumber(filters.radiusKm, previousFilters.radiusKm),
                modelId,
                datasetId,
            }

            this.lastFilters = submissionFilters

            if (!modelId) {
                const prediction = fallbackPrediction(submissionFilters)
                this.currentPrediction = prediction
                this.upsertHistory(prediction)
                notifyError(
                    new Error('Prediction model unavailable.'),
                    'No active prediction model is available. Showing cached simulation instead.'
                )
                return prediction
            }

            const payload = {
                model_id: modelId,
                parameters: {
                    center: submissionFilters.center,
                    horizon_hours: submissionFilters.horizon,
                    observed_at: submissionFilters.timestamp,
                    radius_km: submissionFilters.radiusKm,
                },
                metadata: {
                    request_origin: 'prediction-form',
                },
            }

            if (datasetId) {
                payload.dataset_id = datasetId
            }
            const requestStore = useRequestStore()
            const idempotencyKey = requestStore.issueIdempotencyKey(
                'prediction:submit',
                payload
            )

            try {
                const { data } = await apiClient.post('/predictions', payload, {
                    metadata: { idempotencyKey },
                })
                const initialPrediction = normalizePredictionResponse(
                    data?.prediction ?? data,
                    submissionFilters
                )

                if (!initialPrediction || !initialPrediction.id) {
                    throw new Error('Prediction request did not return a valid identifier.')
                }

                const normalizedInitial = {
                    ...initialPrediction,
                    progress: initialPrediction.progress ?? null,
                    progressMessage:
                        initialPrediction.progressMessage
                        ?? (initialPrediction.status === 'completed'
                            ? 'Prediction complete.'
                            : 'Waiting for the prediction service to finish processing your request.'),
                }

                this.currentPrediction = normalizedInitial
                this.upsertHistory(normalizedInitial)

                const isPending = normalizedInitial.status === 'queued' || normalizedInitial.status === 'running'

                if (isPending) {
                    notifyInfo({
                        title: 'Prediction queued',
                        message: 'Waiting for the prediction service to finish processing your request.',
                    })
                }

                if (normalizedInitial.status === 'completed' && normalizedInitial.outputs.length) {
                    notifySuccess({
                        title: 'Prediction ready',
                        message: 'Latest risk surface generated successfully.',
                    })
                    return normalizedInitial
                }

                let realtimePromise = null

                if (isPending) {
                    realtimePromise = this.startRealtimeTracking(normalizedInitial.id, submissionFilters, {
                        awaitCompletion: true,
                    })
                }

                if (realtimePromise) {
                    try {
                        const realtimeResult = await realtimePromise
                        const resolved = realtimeResult ?? this.currentPrediction

                        if (
                            resolved
                            && resolved.status === 'completed'
                            && Array.isArray(resolved.outputs)
                            && resolved.outputs.length
                            && !this.realtimeCompletionNotified
                        ) {
                            notifySuccess({
                                title: 'Prediction ready',
                                message: 'Latest risk surface generated successfully.',
                            })
                        }

                        return resolved
                    } catch (realtimeError) {
                        if (realtimeError?.name === 'AbortError') {
                            throw realtimeError
                        }

                        if (realtimeError?.code === 'PREDICTION_FAILED') {
                            if (realtimeError?.prediction) {
                                this.currentPrediction = realtimeError.prediction
                                this.upsertHistory(realtimeError.prediction)
                            }
                            throw realtimeError
                        }

                        if (
                            realtimeError?.code !== 'REALTIME_UNAVAILABLE'
                            && realtimeError?.code !== 'REALTIME_ABORTED'
                            && realtimeError?.code !== 'REALTIME_REPLACED'
                        ) {
                            throw realtimeError
                        }
                    }
                }

                const controller = typeof AbortController !== 'undefined' ? new AbortController() : null
                if (controller) {
                    this.pollAbortController = controller
                }

                let finalPrediction = normalizedInitial

                try {
                    finalPrediction = await this.pollPredictionStatus(
                        normalizedInitial.id,
                        submissionFilters,
                        controller?.signal
                    )
                    if (
                        finalPrediction
                        && finalPrediction.status === 'completed'
                        && Array.isArray(finalPrediction.outputs)
                        && finalPrediction.outputs.length
                        && !this.realtimeCompletionNotified
                    ) {
                        notifySuccess({
                            title: 'Prediction ready',
                            message: 'Latest risk surface generated successfully.',
                        })
                    }
                } catch (pollError) {
                    if (pollError?.name === 'AbortError') {
                        throw pollError
                    }

                    if (pollError?.code === 'PREDICTION_FAILED') {
                        this.currentPrediction = pollError.prediction
                        this.upsertHistory(pollError.prediction)
                        notifyError(pollError, pollError.message)
                        throw pollError
                    }

                    if (pollError?.code === 'PREDICTION_TIMEOUT') {
                        this.currentPrediction = pollError.prediction ?? normalizedInitial
                        this.upsertHistory(this.currentPrediction)
                        notifyInfo({
                            title: 'Prediction pending',
                            message:
                                'The prediction is still processing. Results will update automatically when ready.',
                        })
                        return this.currentPrediction
                    }

                    throw pollError
                } finally {
                    if (controller && this.pollAbortController === controller) {
                        this.pollAbortController = null
                    }
                }

                return finalPrediction
            } catch (error) {
                if (error?.response?.status === 422 && error.validationErrors) {
                    this.lastFilters = previousFilters
                    throw error
                }
                if (error?.name === 'AbortError') {
                    throw error
                }
                if (error?.code === 'PREDICTION_FAILED' && error?.prediction) {
                    this.currentPrediction = error.prediction
                    this.upsertHistory(error.prediction)
                    return error.prediction
                }
                const prediction = fallbackPrediction(submissionFilters)
                this.currentPrediction = prediction
                this.upsertHistory(prediction)
                notifyError(error, 'Prediction service is unreachable. Showing cached simulation instead.')
                return prediction
            } finally {
                if (this.pollAbortController) {
                    this.pollAbortController = null
                }
                this.loading = false
            }
        },
        resetPrediction() {
            this.cancelPolling()
            this.currentPrediction = null
            this.realtimeCompletionNotified = false
        },
    },
})
