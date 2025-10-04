import { defineStore } from 'pinia'
import apiClient from '../services/apiClient'
import { getBroadcastClient, onConnectionStateChange } from '../services/broadcast'
import { notifyError, notifySuccess } from '../utils/notifications'
import { useRequestStore } from './request'

const FALLBACK_STATUS_POLL_INTERVAL = 30000
let connectivityListenersRegistered = false
let broadcastListenersRegistered = false

const fallbackModels = [
    {
        id: 'baseline-01',
        dataset_id: 'baseline-datasets-01',
        name: 'Baseline Gradient Boosting',
        status: 'active',
        metrics: {
            precision: 0.72,
            recall: 0.64,
            f1: 0.68,
        },
        lastTrainedAt: '2024-10-01T12:30:00.000Z',
        evaluations: [],
    },
    {
        id: 'spatial-graph-02',
        dataset_id: 'baseline-datasets-01',
        name: 'Spatial Graph Attention',
        status: 'idle',
        metrics: {
            precision: 0.78,
            recall: 0.7,
            f1: 0.74,
        },
        lastTrainedAt: '2024-08-16T09:00:00.000Z',
        evaluations: [],
    },
]

export const useModelStore = defineStore('model', {
    state: () => ({
        models: [],
        meta: { total: 0, per_page: 15, current_page: 1 },
        links: { first: null, last: null, prev: null, next: null },
        loading: false,
        creating: false,
        actionState: {},
        statusSnapshots: {},
        statusPolling: {},
        statusSubscriptions: {},
        statusLoading: {},
        statusOrigins: {},
        evaluationRefresh: {},
    }),
    getters: {
        activeModel: (state) => state.models.find((model) => model.status === 'active') ?? null,
    },
    actions: {
        async fetchModels(options = {}) {
            const {
                page = 1,
                perPage = 15,
                sort = '-updated_at',
                filters = {},
            } = options

            this.loading = true
            try {
                const params = { page, per_page: perPage }

                if (sort) {
                    params.sort = sort
                }

                if (filters && Object.keys(filters).length) {
                    params.filter = filters
                }

                const { data } = await apiClient.get('/models', { params })
                if (Array.isArray(data?.data)) {
                    const previousRefresh = { ...this.evaluationRefresh }
                    this.models = data.data.map(normaliseModel)
                    this.evaluationRefresh = this.models.reduce((acc, model) => {
                        if (previousRefresh[model.id]) {
                            acc[model.id] = previousRefresh[model.id]
                        }
                        return acc
                    }, {})
                    this.statusOrigins = this.models.reduce((acc, model) => {
                        acc[model.id] = model.status ?? null
                        return acc
                    }, {})
                    this.meta = {
                        total: Number(data?.meta?.total ?? data.data.length ?? 0),
                        per_page: Number(data?.meta?.per_page ?? perPage),
                        current_page: Number(data?.meta?.current_page ?? page),
                    }
                    this.links = {
                        first: data?.links?.first ?? null,
                        last: data?.links?.last ?? null,
                        prev: data?.links?.prev ?? null,
                        next: data?.links?.next ?? null,
                    }
                    this.syncStatusTracking()
                    await this.refreshStatuses()
                } else {
                    this.applyFallback()
                }
            } catch (error) {
                this.applyFallback()
                notifyError(error, 'Unable to load models from the service. Showing cached values.')
            } finally {
                this.loading = false
            }
        },
        applyFallback() {
            this.models = fallbackModels
            this.meta = {
                total: fallbackModels.length,
                per_page: fallbackModels.length,
                current_page: 1,
            }
            this.links = { first: null, last: null, prev: null, next: null }
            this.statusOrigins = fallbackModels.reduce((acc, model) => {
                acc[model.id] = model.status ?? null
                return acc
            }, {})
            this.evaluationRefresh = {}
            this.clearStatusTracking()
        },

        async createModel(payload) {
            this.creating = true

            const body = sanitizeModelPayload(payload)

            try {
                const { data } = await apiClient.post('/models', body)
                const created = extractModel(data)

                if (created) {
                    const existingIndex = this.models.findIndex((model) => model.id === created.id)
                    const remaining = existingIndex === -1 ? this.models : this.models.filter((model) => model.id !== created.id)

                    this.models = [created, ...remaining]
                    this.statusOrigins = {
                        ...this.statusOrigins,
                        [created.id]: created.status ?? null,
                    }
                    const currentTotal = Number(this.meta?.total ?? 0)
                    this.meta = {
                        ...this.meta,
                        total: existingIndex === -1 ? currentTotal + 1 : currentTotal,
                        current_page: 1,
                    }
                    await this.fetchModelStatus(created.id, { silent: true })
                    notifySuccess({ title: 'Model created', message: 'The model has been added to governance.' })
                }

                return { model: created, errors: null }
            } catch (error) {
                notifyError(error, 'Unable to create the model. Review the form and try again.')
                return { model: null, errors: error?.validationErrors ?? null }
            } finally {
                this.creating = false
            }
        },

        async trainModel(modelId, hyperparameters = null) {
            this.actionState = { ...this.actionState, [modelId]: 'training' }
            this.statusSnapshots = {
                ...this.statusSnapshots,
                [modelId]: {
                    state: 'training',
                    progress: 0,
                    updatedAt: new Date().toISOString(),
                    error: false,
                },
            }
            const modelIndex = this.models.findIndex((model) => model.id === modelId)
            if (modelIndex !== -1) {
                const current = this.models[modelIndex]
                const originStatus = this.statusOrigins[modelId] ?? null
                const nextStatus = deriveRealtimeStatus(current.status, 'training', originStatus)
                const nextModels = [...this.models]
                nextModels[modelIndex] = {
                    ...current,
                    status: nextStatus,
                    lastTrainedAt: new Date().toISOString(),
                }
                this.models = nextModels
                this.statusOrigins = updateStatusOrigins(
                    this.statusOrigins,
                    modelId,
                    'training',
                    current.status,
                    nextStatus
                )
            }
            this.ensureRealtimeTracking(modelId)
            const payload = { model_id: modelId }

            if (hyperparameters && Object.keys(hyperparameters).length > 0) {
                payload.hyperparameters = hyperparameters
            }

            const requestStore = useRequestStore()
            const idempotencyKey = requestStore.issueIdempotencyKey(
                `model:train:${modelId}`,
                payload
            )

            try {
                await apiClient.post('/models/train', payload, {
                    metadata: { idempotencyKey },
                })
                notifySuccess({ title: 'Training started', message: 'Model training pipeline initiated.' })
                await this.fetchModelStatus(modelId, { silent: true })
            } catch (error) {
                notifyError(error, 'Training could not be started. Please retry later.')
            } finally {
                this.actionState = { ...this.actionState, [modelId]: 'idle' }
            }
        },

        async evaluateModel(modelId, options = {}) {
            this.actionState = { ...this.actionState, [modelId]: 'evaluating' }
            this.statusSnapshots = {
                ...this.statusSnapshots,
                [modelId]: {
                    state: 'evaluating',
                    progress: 0,
                    updatedAt: new Date().toISOString(),
                    error: false,
                },
            }

            const modelIndex = this.models.findIndex((model) => model.id === modelId)
            if (modelIndex !== -1) {
                const current = this.models[modelIndex]
                const originStatus = this.statusOrigins[modelId] ?? null
                const nextStatus = deriveRealtimeStatus(current.status, 'evaluating', originStatus)
                const nextModels = [...this.models]
                nextModels[modelIndex] = {
                    ...current,
                    status: nextStatus,
                    lastTrainedAt: new Date().toISOString(),
                }
                this.models = nextModels
                this.statusOrigins = updateStatusOrigins(
                    this.statusOrigins,
                    modelId,
                    'evaluating',
                    current.status,
                    nextStatus
                )
            }
            this.ensureRealtimeTracking(modelId)

            const requestStore = useRequestStore()
            const payload = sanitizeEvaluationPayload(options)
            const idempotencyKey = requestStore.issueIdempotencyKey(
                `model:evaluate:${modelId}`,
                payload
            )

            try {
                await apiClient.post(`/models/${modelId}/evaluate`, payload, {
                    metadata: { idempotencyKey },
                })
                notifySuccess({ title: 'Evaluation scheduled', message: 'Evaluation job enqueued successfully.' })
                this.evaluationRefresh = { ...this.evaluationRefresh, [modelId]: 'pending' }
                await this.fetchModelStatus(modelId, { silent: true })
                return { success: true, errors: null }
            } catch (error) {
                notifyError(error, 'Evaluation job failed to start. Please review the form and try again.')
                return { success: false, errors: error?.validationErrors ?? null }
            } finally {
                this.actionState = { ...this.actionState, [modelId]: 'idle' }
            }
        },

        async activateModel(modelId) {
            this.actionState = { ...this.actionState, [modelId]: 'activating' }

            try {
                const { data } = await apiClient.post(`/models/${modelId}/activate`)
                const updated = extractModel(data)

                if (updated) {
                    const affectedIds = this.applyActivationUpdate(updated)
                    notifySuccess({
                        title: 'Model activated',
                        message: 'The model is now serving production traffic.',
                    })
                    await this.refreshStatuses(affectedIds)
                } else {
                    await this.fetchModels()
                }
            } catch (error) {
                notifyError(error, 'Unable to activate the model. Please try again later.')
            } finally {
                this.actionState = { ...this.actionState, [modelId]: 'idle' }
            }
        },

        async deactivateModel(modelId) {
            this.actionState = { ...this.actionState, [modelId]: 'deactivating' }

            try {
                const { data } = await apiClient.post(`/models/${modelId}/deactivate`)
                const updated = extractModel(data)

                if (updated) {
                    this.models = replaceModelEntry(this.models, updated)
                    notifySuccess({
                        title: 'Model deactivated',
                        message: 'The model has been removed from production.',
                    })
                    await this.refreshStatuses([updated.id])
                } else {
                    await this.fetchModels()
                }
            } catch (error) {
                notifyError(error, 'Unable to deactivate the model right now.')
            } finally {
                this.actionState = { ...this.actionState, [modelId]: 'idle' }
            }
        },

        async refreshStatuses(modelIds = null) {
            const ids = Array.isArray(modelIds) && modelIds.length ? modelIds : this.models.map((model) => model.id)
            if (!ids.length) {
                return
            }

            await Promise.allSettled(ids.map((id) => this.fetchModelStatus(id, { silent: true })))
        },

        async fetchModelDetails(modelId, options = {}) {
            const { silent = false } = options

            try {
                const { data } = await apiClient.get(`/models/${modelId}`)
                const updated = extractModel(data)

                if (updated) {
                    const exists = this.models.some((model) => model.id === updated.id)
                    this.models = exists ? replaceModelEntry(this.models, updated) : [updated, ...this.models]
                    this.statusOrigins = {
                        ...this.statusOrigins,
                        [updated.id]: updated.status ?? null,
                    }
                }

                return updated
            } catch (error) {
                if (!silent) {
                    notifyError(error, 'Unable to refresh model details right now.')
                }
                return null
            }
        },

        async fetchModelStatus(modelId, options = {}) {
            const { silent = false } = options

            if (this.statusLoading[modelId]) {
                return null
            }

            this.statusLoading = { ...this.statusLoading, [modelId]: true }

            try {
                const { data } = await apiClient.get(`/models/${modelId}/status`)
                const snapshot = normaliseStatus(data)
                this.statusSnapshots = {
                    ...this.statusSnapshots,
                    [modelId]: snapshot,
                }
                const modelIndex = this.models.findIndex((model) => model.id === modelId)
                if (modelIndex !== -1) {
                    const current = this.models[modelIndex]
                    const originStatus = this.statusOrigins[modelId] ?? null
                    const nextStatus = deriveRealtimeStatus(current.status, snapshot.state, originStatus)
                    if (nextStatus !== current.status || snapshot.updatedAt) {
                        const nextModels = [...this.models]
                        nextModels[modelIndex] = {
                            ...current,
                            status: nextStatus,
                            lastTrainedAt: snapshot.updatedAt ?? current.lastTrainedAt,
                        }
                        this.models = nextModels
                        this.statusOrigins = updateStatusOrigins(
                            this.statusOrigins,
                            modelId,
                            snapshot.state,
                            current.status,
                            nextStatus
                        )
                    }
                }
                if (isActiveState(snapshot.state)) {
                    this.ensureRealtimeTracking(modelId)
                } else {
                    this.unsubscribeRealtimeTracking(modelId)
                    this.stopStatusPolling(modelId)
                    await this.refreshEvaluationsIfNeeded(modelId, snapshot.state)
                }
                return snapshot
            } catch (error) {
                this.unsubscribeRealtimeTracking(modelId)
                this.stopStatusPolling(modelId)
                const previous = this.statusSnapshots[modelId] ?? null
                this.statusSnapshots = {
                    ...this.statusSnapshots,
                    [modelId]: {
                        state: previous?.state ?? 'unknown',
                        progress: previous?.progress ?? null,
                        updatedAt: previous?.updatedAt ?? null,
                        error: true,
                    },
                }
                if (!silent) {
                    notifyError(error, 'Unable to determine the model status at this time.')
                }
                return null
            } finally {
                this.statusLoading = { ...this.statusLoading, [modelId]: false }
            }
        },

        ensureStatusPolling(modelId, options = {}) {
            const { force = false } = options

            const isOffline = typeof navigator !== 'undefined' && navigator.onLine === false
            const subscription = this.statusSubscriptions[modelId] ?? null
            const hasRealtime = Boolean(subscription && subscription.status === 'subscribed')
            if (!force && !isOffline && hasRealtime) {
                return
            }

            if (this.statusPolling[modelId]) {
                return
            }

            const timer = typeof window !== 'undefined' ? window : globalThis
            const interval = timer.setInterval(() => {
                void this.fetchModelStatus(modelId, { silent: true })
            }, FALLBACK_STATUS_POLL_INTERVAL)

            this.statusPolling = { ...this.statusPolling, [modelId]: interval }
        },

        stopStatusPolling(modelId) {
            const interval = this.statusPolling[modelId]
            if (interval) {
                const timer = typeof window !== 'undefined' ? window : globalThis
                timer.clearInterval(interval)
                const next = { ...this.statusPolling }
                delete next[modelId]
                this.statusPolling = next
            }
        },

        ensureRealtimeTracking(modelId) {
            registerConnectivityListeners(this)
            registerBroadcastConnectionListener(this)

            if (typeof navigator !== 'undefined' && navigator.onLine === false) {
                this.ensureStatusPolling(modelId, { force: true })
                return
            }

            if (this.statusSubscriptions[modelId]) {
                const existing = this.statusSubscriptions[modelId]
                if (existing.status !== 'subscribed') {
                    this.ensureStatusPolling(modelId, { force: true })
                }
                return
            }

            const broadcast = getBroadcastClient()
            if (!broadcast) {
                this.ensureStatusPolling(modelId)
                return
            }

            const channelName = `models.${modelId}.status`

            try {
                const subscription = broadcast.subscribe(channelName, {
                    onEvent: (eventName, payload) => {
                        if (eventName === 'ModelStatusUpdated' || eventName === '.ModelStatusUpdated') {
                            this.handleRealtimeStatus(modelId, payload)
                        }
                    },
                    onSubscribed: () => {
                        subscription.status = 'subscribed'
                        this.stopStatusPolling(modelId)
                    },
                    onError: (error) => {
                        console.warn('Model status channel error', error)
                        subscription.status = 'error'
                        this.ensureStatusPolling(modelId, { force: true })
                    },
                })

                this.statusSubscriptions = {
                    ...this.statusSubscriptions,
                    [modelId]: subscription,
                }
            } catch (error) {
                console.warn('Unable to subscribe to model status channel', error)
                this.ensureStatusPolling(modelId, { force: true })
            }
        },

        unsubscribeRealtimeTracking(modelId) {
            const subscription = this.statusSubscriptions[modelId]
            if (!subscription) {
                return
            }

            const broadcast = getBroadcastClient()
            if (broadcast) {
                const channelName = subscription?.channelName ?? `models.${modelId}.status`
                try {
                    broadcast.unsubscribe(channelName)
                } catch (error) {
                    console.warn('Error leaving model status channel', error)
                }
            }

            const next = { ...this.statusSubscriptions }
            delete next[modelId]
            this.statusSubscriptions = next
        },

        handleRealtimeStatus(modelId, payload = {}) {
            const snapshot = normaliseStatus({
                state: payload?.state,
                progress: payload?.progress,
                updated_at: payload?.updated_at,
                message: payload?.message,
            })

            this.statusSnapshots = {
                ...this.statusSnapshots,
                [modelId]: snapshot,
            }

            const modelIndex = this.models.findIndex((model) => model.id === modelId)
            if (modelIndex !== -1) {
                const current = this.models[modelIndex]
                const originStatus = this.statusOrigins[modelId] ?? null
                const nextStatus = deriveRealtimeStatus(current.status, snapshot.state, originStatus)
                const nextModels = [...this.models]
                nextModels[modelIndex] = {
                    ...current,
                    status: nextStatus,
                    lastTrainedAt: snapshot.updatedAt ?? current.lastTrainedAt,
                }
                this.models = nextModels
                this.statusOrigins = updateStatusOrigins(
                    this.statusOrigins,
                    modelId,
                    snapshot.state,
                    current.status,
                    nextStatus
                )
            }

            if (!isActiveState(snapshot.state)) {
                this.unsubscribeRealtimeTracking(modelId)
                this.stopStatusPolling(modelId)
                void this.refreshEvaluationsIfNeeded(modelId, snapshot.state)
            }
        },

        async refreshEvaluationsIfNeeded(modelId, snapshotState) {
            const refreshState = this.evaluationRefresh[modelId] ?? null
            if (!refreshState || refreshState === 'refreshing') {
                return
            }

            const state = typeof snapshotState === 'string' ? snapshotState.toLowerCase() : ''
            if (state === 'evaluating' || state === 'queued') {
                return
            }

            this.evaluationRefresh = { ...this.evaluationRefresh, [modelId]: 'refreshing' }

            const updated = await this.fetchModelDetails(modelId, { silent: true })

            if (updated) {
                const next = { ...this.evaluationRefresh }
                delete next[modelId]
                this.evaluationRefresh = next
                return
            }

            this.evaluationRefresh = { ...this.evaluationRefresh, [modelId]: 'pending' }
        },

        handleOffline() {
            for (const [modelId, snapshot] of Object.entries(this.statusSnapshots)) {
                if (isActiveState(snapshot?.state)) {
                    this.ensureStatusPolling(modelId, { force: true })
                }
            }
        },

        handleOnline() {
            for (const [modelId, snapshot] of Object.entries(this.statusSnapshots)) {
                if (isActiveState(snapshot?.state)) {
                    this.ensureRealtimeTracking(modelId)
                    const subscription = this.statusSubscriptions[modelId] ?? null
                    if (subscription && subscription.status === 'subscribed') {
                        this.stopStatusPolling(modelId)
                    } else {
                        this.ensureStatusPolling(modelId, { force: true })
                    }
                }
            }
        },

        syncStatusTracking() {
            const activeIds = new Set(this.models.map((model) => model.id))

            const pollingCopy = { ...this.statusPolling }
            for (const [modelId, handle] of Object.entries(pollingCopy)) {
                if (!activeIds.has(modelId)) {
                    const timer = typeof window !== 'undefined' ? window : globalThis
                    timer.clearInterval(handle)
                    delete pollingCopy[modelId]
                }
            }
            this.statusPolling = pollingCopy

            for (const modelId of Object.keys(this.statusSubscriptions)) {
                if (!activeIds.has(modelId)) {
                    this.unsubscribeRealtimeTracking(modelId)
                }
            }

            const snapshotsCopy = {}
            for (const modelId of activeIds) {
                if (this.statusSnapshots[modelId]) {
                    snapshotsCopy[modelId] = this.statusSnapshots[modelId]
                }
            }
            this.statusSnapshots = snapshotsCopy

            const loadingCopy = {}
            for (const modelId of activeIds) {
                if (this.statusLoading[modelId]) {
                    loadingCopy[modelId] = this.statusLoading[modelId]
                }
            }
            this.statusLoading = loadingCopy

            const originsCopy = {}
            for (const modelId of activeIds) {
                if (Object.prototype.hasOwnProperty.call(this.statusOrigins, modelId)) {
                    originsCopy[modelId] = this.statusOrigins[modelId]
                }
            }
            this.statusOrigins = originsCopy

            const refreshCopy = {}
            for (const modelId of activeIds) {
                if (Object.prototype.hasOwnProperty.call(this.evaluationRefresh, modelId)) {
                    refreshCopy[modelId] = this.evaluationRefresh[modelId]
                }
            }
            this.evaluationRefresh = refreshCopy
        },

        clearStatusTracking() {
            for (const handle of Object.values(this.statusPolling)) {
                const timer = typeof window !== 'undefined' ? window : globalThis
                timer.clearInterval(handle)
            }
            this.statusPolling = {}
            for (const modelId of Object.keys(this.statusSubscriptions)) {
                this.unsubscribeRealtimeTracking(modelId)
            }
            this.statusSubscriptions = {}
            this.statusLoading = {}
            this.statusSnapshots = {}
        },

        applyActivationUpdate(updatedModel) {
            const tag = updatedModel.tag ?? null
            const area = updatedModel.area ?? null

            const affectedIds = new Set([updatedModel.id])

            this.models = this.models.map((model) => {
                if (model.id === updatedModel.id) {
                    return updatedModel
                }

                const sameTag = (model.tag ?? null) === tag
                const sameArea = (model.area ?? null) === area

                if (sameTag && sameArea && model.status === 'active') {
                    affectedIds.add(model.id)
                    return { ...model, status: 'inactive' }
                }

                return model
            })

            return Array.from(affectedIds)
        },
    },
})

function normaliseModel(model) {
    const metadata = model.metadata ?? null
    return {
        id: model.id,
        datasetId: model.dataset_id ?? null,
        name: model.name,
        status: model.status,
        metrics: model.metrics ?? {},
        tag: model.tag ?? null,
        area: model.area ?? null,
        version: model.version ?? null,
        lastTrainedAt: model.trained_at ?? model.updated_at ?? null,
        metadata,
        evaluations: normaliseEvaluations(metadata),
    }
}

function normaliseEvaluations(metadata) {
    const source = Array.isArray(metadata?.evaluations)
        ? metadata.evaluations
        : Array.isArray(metadata)
        ? metadata
        : []

    return source
        .map((entry, index) => {
            if (!entry || typeof entry !== 'object') {
                return null
            }

            const idCandidate = typeof entry.id === 'string' ? entry.id.trim() : ''
            const datasetId =
                typeof entry.dataset_id === 'string'
                    ? entry.dataset_id
                    : typeof entry.datasetId === 'string'
                    ? entry.datasetId
                    : null
            const rawTimestamp =
                typeof entry.evaluated_at === 'string'
                    ? entry.evaluated_at
                    : typeof entry.evaluatedAt === 'string'
                    ? entry.evaluatedAt
                    : null

            let evaluatedAt = rawTimestamp && rawTimestamp.trim() ? rawTimestamp : null
            let sortValue = null

            if (evaluatedAt) {
                const parsed = new Date(evaluatedAt)
                if (!Number.isNaN(parsed.getTime())) {
                    sortValue = parsed.getTime()
                    evaluatedAt = parsed.toISOString()
                }
            }

            const metricsPayload = entry.metrics
            let metrics = {}
            if (metricsPayload && typeof metricsPayload === 'object' && !Array.isArray(metricsPayload)) {
                metrics = Object.entries(metricsPayload).reduce((acc, [key, value]) => {
                    const trimmedKey = String(key ?? '').trim()
                    if (!trimmedKey) {
                        return acc
                    }

                    if (typeof value === 'number' && Number.isFinite(value)) {
                        acc[trimmedKey] = value
                        return acc
                    }

                    if (typeof value === 'string') {
                        const numeric = Number(value)
                        if (!Number.isNaN(numeric)) {
                            acc[trimmedKey] = numeric
                            return acc
                        }
                    }

                    acc[trimmedKey] = value
                    return acc
                }, {})
            }

            const notes = typeof entry.notes === 'string' ? entry.notes.trim() : ''
            const identifier = idCandidate || `${datasetId ?? 'unknown'}-${evaluatedAt ?? 'pending'}-${index}`

            return {
                id: identifier,
                datasetId,
                evaluatedAt,
                metrics,
                notes,
                sortValue: sortValue ?? Number.NEGATIVE_INFINITY,
            }
        })
        .filter(Boolean)
        .sort((a, b) => (b.sortValue ?? 0) - (a.sortValue ?? 0))
        .map((entry) => {
            const { sortValue, ...rest } = entry
            void sortValue
            return rest
        })
}

function deriveRealtimeStatus(currentStatus, realtimeState, originStatus = null) {
    const nextState = typeof realtimeState === 'string' ? realtimeState.toLowerCase() : ''

    if (!nextState) {
        return currentStatus
    }

    if (nextState === 'failed') {
        return 'failed'
    }

    if (nextState === 'training' || nextState === 'evaluating' || nextState === 'queued') {
        return nextState === 'queued' ? 'training' : nextState
    }

    if (nextState === 'idle') {
        if (originStatus) {
            return originStatus
        }
        if (currentStatus === 'training' || currentStatus === 'evaluating' || currentStatus === 'queued') {
            return 'active'
        }
        return currentStatus
    }

    return nextState
}

function updateStatusOrigins(origins, modelId, snapshotState, previousStatus, nextStatus) {
    const state = typeof snapshotState === 'string' ? snapshotState.toLowerCase() : ''
    const transitional = state === 'training' || state === 'evaluating' || state === 'queued'
    const currentMap = origins ?? {}
    const existing = currentMap[modelId]

    if (transitional) {
        const baseline = existing ?? previousStatus ?? nextStatus ?? null
        if (existing === baseline) {
            return currentMap
        }
        return { ...currentMap, [modelId]: baseline }
    }

    if (state === 'failed') {
        if (existing === 'failed') {
            return currentMap
        }
        return { ...currentMap, [modelId]: 'failed' }
    }

    const resolved = nextStatus ?? existing ?? previousStatus ?? null

    if (state === 'idle') {
        if (existing === resolved) {
            return currentMap
        }
        return { ...currentMap, [modelId]: resolved }
    }

    if (resolved === existing) {
        return currentMap
    }

    return { ...currentMap, [modelId]: resolved }
}

function registerConnectivityListeners(store) {
    if (connectivityListenersRegistered) {
        return
    }

    if (typeof window === 'undefined') {
        return
    }

    connectivityListenersRegistered = true

    window.addEventListener('offline', () => {
        store.handleOffline()
    })

    window.addEventListener('online', () => {
        store.handleOnline()
    })
}

function registerBroadcastConnectionListener(store) {
    if (broadcastListenersRegistered) {
        return
    }

    if (typeof window === 'undefined') {
        return
    }

    const handler = (state) => {
        const current = typeof state?.state === 'string' ? state.state : null
        if (!current) {
            return
        }

        if (current === 'connected') {
            store.handleOnline()
        } else if (current === 'disconnected' || current === 'reconnecting' || current === 'error') {
            store.handleOffline()
        }
    }

    onConnectionStateChange(handler)
    broadcastListenersRegistered = true
}

function normaliseStatus(snapshot = {}) {
    const state = snapshot?.state ?? 'unknown'
    const rawMessage = snapshot?.message ?? snapshot?.error_message ?? snapshot?.errorMessage ?? null
    const message = typeof rawMessage === 'string' ? rawMessage.trim() : ''
    const stateValue = typeof state === 'string' ? state.toLowerCase() : ''
    return {
        state,
        progress: typeof snapshot?.progress === 'number' ? snapshot.progress : null,
        updatedAt: snapshot?.updated_at ?? null,
        message: message ? message : null,
        error: snapshot?.error === true || stateValue === 'error',
    }
}

function isActiveState(state) {
    return state === 'training' || state === 'evaluating' || state === 'queued'
}

function extractModel(response) {
    if (!response) {
        return null
    }

    const payload = typeof response?.data === 'undefined' ? response : response.data
    const candidate = typeof payload?.data === 'undefined' ? payload : payload.data

    if (!candidate) {
        return null
    }

    return normaliseModel(candidate)
}

function sanitizeModelPayload(payload = {}) {
    const body = {}

    if (payload.name) {
        body.name = payload.name
    }

    if (payload.dataset_id || payload.datasetId) {
        body.dataset_id = payload.dataset_id ?? payload.datasetId
    }

    if (payload.tag) {
        body.tag = payload.tag
    }

    if (payload.area) {
        body.area = payload.area
    }

    if (payload.version) {
        body.version = payload.version
    }

    if (payload.hyperparameters && Object.keys(payload.hyperparameters).length > 0) {
        body.hyperparameters = payload.hyperparameters
    }

    if (payload.metadata && Object.keys(payload.metadata).length > 0) {
        body.metadata = payload.metadata
    }

    return body
}

function sanitizeEvaluationPayload(options = {}) {
    const payload = {}

    const datasetId = typeof options.datasetId === 'string' ? options.datasetId.trim() : ''
    if (datasetId) {
        payload.dataset_id = datasetId
    }

    const metricsInput = options.metrics ?? options.metricOverrides ?? null
    if (metricsInput && typeof metricsInput === 'object' && !Array.isArray(metricsInput)) {
        const metrics = {}
        Object.entries(metricsInput).forEach(([key, value]) => {
            if (!key) {
                return
            }

            const trimmedKey = String(key).trim()
            if (!trimmedKey) {
                return
            }

            let numericValue = null

            if (typeof value === 'number') {
                numericValue = value
            } else if (typeof value === 'string') {
                const trimmedValue = value.trim()
                if (!trimmedValue) {
                    return
                }
                numericValue = Number(trimmedValue)
            } else {
                return
            }

            if (typeof numericValue === 'number' && Number.isFinite(numericValue)) {
                metrics[trimmedKey] = numericValue
            }
        })

        if (Object.keys(metrics).length > 0) {
            payload.metrics = metrics
        }
    }

    const notes = typeof options.notes === 'string' ? options.notes.trim() : ''
    if (notes) {
        payload.notes = notes
    }

    return payload
}

function replaceModelEntry(models, updatedModel) {
    return models.map((model) => (model.id === updatedModel.id ? updatedModel : model))
}
