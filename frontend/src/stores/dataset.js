import {defineStore} from 'pinia'
import apiClient from '../services/apiClient'
import {subscribeToChannel, unsubscribeFromChannel} from '../services/realtime'
import {notifyError, notifySuccess} from '../utils/notifications'

export const MAX_FILE_SIZE_BYTES = 200 * 1024 * 1024 // 200MB
export const MAX_FILE_SIZE_MB = Math.round(MAX_FILE_SIZE_BYTES / (1024 * 1024))
const ACCEPTED_TYPES = [
    'text/csv',
    'application/vnd.ms-excel',
    'application/json',
    'text/plain',
    'application/csv',
    'text/comma-separated-values',
]

const CSV_MIME_TYPES = [
    'text/csv',
    'application/vnd.ms-excel',
    'application/csv',
    'text/comma-separated-values',
    'text/plain',
]

export const useDatasetStore = defineStore('dataset', {
    state: () => ({
        name: '',
        description: '',
        sourceType: 'file',
        sourceUri: '',
        uploadFiles: [],
        validationErrors: [],
        schemaMapping: {},
        previewRows: [],
        submitting: false,
        uploadState: 'idle',
        uploadProgress: 0,
        uploadDatasetId: null,
        uploadError: '',
        realtimeStatus: null,
        realtimeSubscription: null,
        step: 1,
        form: {
            name: '',
            sourceType: 'file',
            sourceUri: '',
        },
        nameManuallyEdited: false,
    }),
    getters: {
        detailsValid: (state) => state.name.trim().length > 0,
        hasValidFile: (state) => state.uploadFiles.length > 0 && state.validationErrors.length === 0,
        primaryUploadFile: (state) => (state.uploadFiles.length ? state.uploadFiles[0] : null),
        mappedFields: (state) =>
            Object.values(state.schemaMapping).reduce((count, value) => {
                if (typeof value === 'string' && value.trim() !== '') {
                    return count + 1
                }
                return count
            }, 0),
        hasRequiredSchemaFields: (state) => {
            const required = ['timestamp', 'latitude', 'longitude', 'category']

            return required.every((field) => {
                const value = state.schemaMapping[field]
                return typeof value === 'string' && value.trim() !== ''
            })
        },
        sourceUriProvided: (state) => state.sourceUri.trim().length > 0,
        sourceUriValid: (state) => {
            if (state.sourceType !== 'url') {
                return true
            }
            const trimmed = state.sourceUri.trim()
            if (!trimmed) {
                return false
            }
            try {
                const parsed = new URL(trimmed)
                return parsed.protocol === 'http:' || parsed.protocol === 'https:'
            } catch {
                return false
            }
        },
        canSubmit() {
            if (!this.detailsValid) {
                return false
            }

            if (this.sourceType === 'url') {
                return this.sourceUriValid
            }

            if (this.sourceType === 'file') {
                return this.hasValidFile && this.hasRequiredSchemaFields
            }

            return false
        },
        sourceStepValid() {
            if (this.sourceType === 'url') {
                return this.sourceUriValid
            }
            return this.sourceType === 'file'
        },
    },
    actions: {
        reset() {
            this.stopRealtimeTracking()
            this.name = ''
            this.description = ''
            this.sourceType = 'file'
            this.sourceUri = ''
            this.uploadFiles = []
            this.validationErrors = []
            this.schemaMapping = {}
            this.previewRows = []
            this.step = 1
            this.uploadState = 'idle'
            this.uploadProgress = 0
            this.uploadDatasetId = null
            this.uploadError = ''
            this.realtimeStatus = null
            this.form = {
                name: '',
                sourceType: 'file',
                sourceUri: '',
            }
            this.nameManuallyEdited = false
        },
        setDatasetName(name) {
            this.form.name = (name ?? '').slice(0, 255)
            this.nameManuallyEdited = true
        },
        setName(value) {
            this.name = value
        },
        setDescription(value) {
            this.description = value
        },
        setSourceType(type) {
            if (!['file', 'url'].includes(type)) {
                return
            }
            this.sourceType = type
            if (type === 'file') {
                this.sourceUri = ''
            } else {
                this.uploadFiles = []
                this.validationErrors = []
                this.schemaMapping = {}
                this.previewRows = []
            }
        },
        setSourceUri(value) {
            this.sourceUri = value
        },
        validateFiles(files) {
            this.previewRows = []
            this.validationErrors = []
            const selected = Array.isArray(files) ? files.filter(Boolean) : []

            if (!selected.length) {
                this.uploadFiles = []
                this.validationErrors.push('Please select a datasets file to continue.')
                return false
            }
            const allowMultiple = selected.length > 1

            for (const file of selected) {
                if (allowMultiple) {
                    if (!CSV_MIME_TYPES.includes(file.type)) {
                        this.validationErrors.push('Multiple file uploads currently support CSV files only.')
                        break
                    }
                } else if (!ACCEPTED_TYPES.includes(file.type)) {
                    this.validationErrors.push('Unsupported file type. Upload CSV or JSON files.')
                    break
                }

                if (file.size > MAX_FILE_SIZE_BYTES) {
                    this.validationErrors.push(`File exceeds the ${MAX_FILE_SIZE_MB}MB upload limit.`)
                    break
                }
            }

            if (this.validationErrors.length === 0) {
                this.uploadFiles = selected
                if (selected.length) {
                    this.form.sourceType = 'file'
                    this.inferDatasetName(selected[0])
                }
                return true
            }
            this.uploadFiles = []
            return false
        },
        async parsePreview(file) {
            this.previewRows = []
            if (!file) {
                return
            }
            const text = await file.text()
            if (file.type === 'application/json') {
                const parsed = JSON.parse(text)
                this.previewRows = Array.isArray(parsed) ? parsed.slice(0, 5) : []
            } else {
                const [headerLine, ...rows] = text.split(/\r?\n/)
                const headers = headerLine.split(',')
                this.previewRows = rows
                    .filter(Boolean)
                    .slice(0, 5)
                    .map((row) => {
                        const values = row.split(',')
                        return headers.reduce((acc, header, index) => {
                            acc[header] = values[index]
                            return acc
                        }, {})
                    })
            }
        },
        setSchemaMapping(mapping) {
            if (!mapping || typeof mapping !== 'object') {
                this.schemaMapping = {}
                return
            }

            const normalised = {}

            Object.entries(mapping).forEach(([key, value]) => {
                if (typeof value !== 'string') {
                    return
                }

                const trimmed = value.trim()

                if (trimmed === '') {
                    return
                }

                normalised[key] = trimmed
            })

            this.schemaMapping = normalised
        },
        setStep(step) {
            if (!Number.isFinite(step)) {
                return
            }

            this.step = Math.max(1, Math.trunc(step))
        },
        inferDatasetName(file) {
            if (!file || this.nameManuallyEdited) {
                return
            }

            const originalName = typeof file.name === 'string' ? file.name : ''
            const lastDotIndex = originalName.lastIndexOf('.')
            let inferredName = ''

            if (lastDotIndex > 0) {
                inferredName = originalName.slice(0, lastDotIndex)
            }

            if (!inferredName) {
                inferredName = originalName
            }

            this.form.name = (inferredName || '').slice(0, 255)
        },
        async submitIngestion(payload) {
            if (this.submitting) {
                return false
            }

            this.submitting = true
            this.uploadError = ''
            this.uploadDatasetId = null
            this.realtimeStatus = null

            this.stopRealtimeTracking()

            const hasFileUploads = this.sourceType === 'file'
            this.uploadState = hasFileUploads ? 'uploading' : 'processing'
            this.uploadProgress = 0

            try {
                const formData = new FormData()
                formData.append('name', this.name.trim())
                if (this.description.trim()) {
                    formData.append('description', this.description.trim())
                }
                formData.append('source_type', this.sourceType)

                if (this.sourceType === 'file') {
                    if (this.uploadFiles.length === 1) {
                        formData.append('file', this.uploadFiles[0])
                    } else {
                        this.uploadFiles.forEach((file) => {
                            formData.append('files[]', file)
                        })
                    }
                }

                if (this.sourceType === 'url') {
                    formData.append('source_uri', this.sourceUri.trim())
                }

                formData.append('schema', JSON.stringify(this.schemaMapping))
                if (payload && Object.keys(payload).length > 0) {
                    formData.append('metadata', JSON.stringify(payload))
                }
                const { data } = await apiClient.post('/datasets/ingest', formData, {
                    onUploadProgress: (event) => {
                        if (!hasFileUploads) {
                            return
                        }
                        const { loaded, total } = event
                        if (typeof loaded === 'number' && typeof total === 'number' && total > 0) {
                            this.uploadProgress = Math.min(100, Math.round((loaded / total) * 100))
                        }
                    },
                })

                if (hasFileUploads) {
                    this.uploadProgress = 100
                }

                const normalizedData = data && typeof data === 'object' ? data : null
                const hasId = normalizedData?.id !== undefined && normalizedData?.id !== null
                const status = typeof normalizedData?.status === 'string' ? normalizedData.status : null

                if (!normalizedData || (!hasId && !status)) {
                    this.uploadDatasetId = null
                    this.uploadProgress = 100
                    this.uploadState = 'completed'
                    this.realtimeStatus = {
                        status: 'ready',
                        progress: 1,
                        updatedAt: new Date().toISOString(),
                    }
                    notifySuccess({ title: 'Dataset queued', message: 'Ingestion pipeline started successfully.' })
                    return normalizedData ?? { status: 'ready' }
                }

                this.uploadDatasetId = hasId ? normalizedData.id : null

                if (status === 'ready') {
                    this.uploadState = 'completed'
                    this.realtimeStatus = {
                        status: 'ready',
                        progress: 1,
                        updatedAt: normalizedData?.ingested_at ?? new Date().toISOString(),
                    }
                } else {
                    this.uploadState = 'processing'
                    if (this.uploadDatasetId) {
                        this.startRealtimeTracking(this.uploadDatasetId)
                    }
                }

                notifySuccess({ title: 'Dataset queued', message: 'Ingestion pipeline started successfully.' })
                return normalizedData
            } catch (error) {
                this.uploadState = 'error'
                this.uploadError = error?.response?.data?.message || error.message || 'Dataset ingestion failed to start.'
                notifyError(error, 'Dataset ingestion failed to start.')
                return false
            } finally {
                this.submitting = false;
            }
        },
        startRealtimeTracking(datasetId) {
            if (!datasetId) {
                return
            }

            this.stopRealtimeTracking()

            try {
                const channelName = `datasets.${datasetId}.status`
                this.realtimeSubscription = subscribeToChannel(channelName, {
                    events: ['DatasetStatusUpdated'],
                    onEvent: (eventName, payload) => {
                        if (eventName === 'DatasetStatusUpdated') {
                            this.handleRealtimeStatus(payload)
                        }
                    },
                    onError: (error) => {
                        console.warn('Dataset status channel error', error)
                        if (this.uploadState === 'processing') {
                            this.uploadState = 'processing'
                        }
                    },
                })
            } catch (error) {
                console.warn('Unable to subscribe to datasets status channel', error)
            }
        },
        stopRealtimeTracking() {
            const subscription = this.realtimeSubscription
            if (!subscription) {
                return
            }

            unsubscribeFromChannel(subscription)

            this.realtimeSubscription = null
        },
        handleRealtimeStatus(payload = {}) {
            const status = typeof payload?.status === 'string' ? payload.status : null
            const progress = typeof payload?.progress === 'number' ? payload.progress : null
            const message = typeof payload?.message === 'string' ? payload.message : null

            this.realtimeStatus = {
                status,
                progress,
                updatedAt: payload?.updated_at ?? new Date().toISOString(),
                message,
            }

            if (progress !== null && Number.isFinite(progress)) {
                this.uploadProgress = Math.min(100, Math.max(0, Math.round(progress * 100)))
            }

            if (status === 'ready') {
                this.uploadState = 'completed'
                this.stopRealtimeTracking()
            } else if (status === 'failed') {
                this.uploadState = 'error'
                this.uploadError = message || 'Dataset ingestion failed.'
                this.stopRealtimeTracking()
            } else if (status && status !== 'pending') {
                this.uploadState = 'processing'
            }
        },
    },
})
