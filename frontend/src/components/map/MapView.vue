<template>
    <header class="flex flex-wrap shrink items-center justify-between gap-3 border-b border-stone-200/80 px-6 py-4">
        <div>
            <h2 class="text-lg font-semibold text-stone-900">Map view</h2>
            <p class="text-sm text-stone-500">Visualise predicted hotspots across the selected radius.</p>
        </div>
        <div class="flex flex-wrap items-center gap-2" role="group" aria-label="Map preferences">
            <button
                class="rounded-xl border border-stone-200/80 px-4 py-2 text-sm font-medium text-stone-700 shadow-sm shadow-stone-200/60 transition hover:border-stone-300 hover:text-stone-900 focus-visible:outline  focus-visible:outline-offset-2 focus-visible:outline-blue-500"
                type="button"
                @click="toggleBase"
            >
                Base: {{ mapStore.baseLayerLabel }}
            </button>
            <label class="inline-flex items-center gap-2 text-sm text-stone-700">
                <input
                    v-model="mapStore.showHeatmap"
                    class="h-4 w-4 rounded border-stone-300 text-blue-600 focus:ring-blue-500"
                    type="checkbox"
                />
                Show heatmap
            </label>
            <label class="inline-flex items-center gap-2 text-sm text-stone-700">
                Opacity
                <input
                    v-model.number="mapStore.heatmapOpacity"
                    class="h-2 w-24 cursor-pointer appearance-none rounded-full bg-stone-200"
                    max="1"
                    min="0.2"
                    step="0.1"
                    type="range"
                    aria-valuemin="0.2"
                    aria-valuemax="1"
                    :aria-valuenow="mapStore.heatmapOpacity"
                />
            </label>
        </div>
    </header>

    <div class="relative flex z-0 flex-col flex-[1_0_28rem] md:flex-[1_0_34rem]">
        <div
            v-if="fallbackReason"
            class="absolute inset-0 flex items-center justify-center bg-stone-50 px-6 text-center text-sm text-stone-600"
        >
            {{ fallbackReason }}
        </div>

        <div
            v-else
            ref="mapContainer"
            aria-label="Heatmap of predicted hotspots"
            class="h-full w-full focus:outline-none"
            role="application"
            tabindex="0"
        ></div>
    </div>
</template>

<script setup>
import { onBeforeUnmount, onMounted, ref, shallowRef, watch, nextTick } from 'vue'
import apiClient from '../../services/apiClient'
import { useMapStore } from '../../stores/map'

const props = defineProps({
    center: { type: Object, required: true },
    points: { type: Array, default: () => [] },
    radiusKm: { type: Number, default: 1.5 },
    tileOptions: { type: Object, default: () => ({}) },
})

const mapStore = useMapStore()
const mapContainer = ref(null)
const mapInstance = shallowRef(null)
const tileLayer = shallowRef(null)
const heatLayer = shallowRef(null)
const radiusCircle = shallowRef(null)
const pointsLayer = shallowRef(null)
const fallbackReason = ref('')

const heatmapOptions = ref(normalizeTileOptions(props.tileOptions ?? {}))
let leafletLib = null

// NEW: observe container size to keep Leaflet sized correctly
let ro = null

const MAX_CONCURRENT_TILE_REQUESTS = 6
const tileRequestQueue = []
let activeTileRequests = 0

function createAbortError() {
    if (typeof DOMException === 'function') return new DOMException('Aborted', 'AbortError')
    const error = new Error('Aborted'); error.name = 'AbortError'; return error
}

function processTileQueue() {
    while (activeTileRequests < MAX_CONCURRENT_TILE_REQUESTS && tileRequestQueue.length > 0) {
        const next = tileRequestQueue.shift()
        next()
    }
}

function scheduleTileRequest(task, signal) {
    if (signal?.aborted) return Promise.reject(createAbortError())
    return new Promise((resolve, reject) => {
        const runTask = () => {
            if (signal) {
                signal.removeEventListener('abort', handleAbort)
                if (signal.aborted) { reject(createAbortError()); processTileQueue(); return }
            }
            activeTileRequests += 1
            task().then(resolve).catch(reject).finally(() => { activeTileRequests -= 1; processTileQueue() })
        }
        const handleAbort = () => {
            const index = tileRequestQueue.indexOf(runTask)
            if (index !== -1) tileRequestQueue.splice(index, 1)
            reject(createAbortError()); processTileQueue()
        }
        if (signal) signal.addEventListener('abort', handleAbort, { once: true })
        tileRequestQueue.push(runTask); processTileQueue()
    })
}

const tileSources = {
    streets: 'https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png',
    satellite: 'https://{s}.basemaps.cartocdn.com/dark_all/{z}/{x}/{y}{r}.png',
}

function normalizeTileOptions(value = {}) {
    if (!value || typeof value !== 'object') return {}
    const normalized = {}
    const tsStart = value.tsStart ?? value.ts_start
    if (tsStart) normalized.tsStart = tsStart
    const tsEnd = value.tsEnd ?? value.ts_end
    if (tsEnd) normalized.tsEnd = tsEnd
    const horizonCandidate = value.horizonHours ?? value.horizon ?? value.horizon_hours
    const parsedHorizon = Number(horizonCandidate)
    if (Number.isFinite(parsedHorizon) && parsedHorizon >= 0) normalized.horizon = Math.round(parsedHorizon)
    return normalized
}

function buildTileParams(options = {}) {
    const params = {}
    if (options.tsStart) params.ts_start = options.tsStart
    if (options.tsEnd) params.ts_end = options.tsEnd
    if (Number.isFinite(options.horizon) && options.horizon > 0) params.horizon = options.horizon
    return params
}

function projectToTile(lng, lat, coords, tileSize) {
    const size = tileSize.x || 256
    const scale = size * Math.pow(2, coords.z)
    const sinLat = Math.min(Math.max(Math.sin((lat * Math.PI) / 180), -0.9999), 0.9999)
    const worldX = ((lng + 180) / 360) * scale
    const worldY = (0.5 - Math.log((1 + sinLat) / (1 - sinLat)) / (4 * Math.PI)) * scale
    const pixelX = worldX - coords.x * size
    const pixelY = worldY - coords.y * size
    return [pixelX, pixelY]
}

function colorForIntensity(intensity) {
    const clamped = Math.max(0, Math.min(1, intensity))
    const start = [37, 99, 235]
    const end = [14, 165, 233]
    const mix = (from, to) => Math.round(from + (to - from) * clamped)
    const alpha = Math.min(0.85, 0.25 + clamped * 0.55)
    return `rgba(${mix(start[0], end[0])}, ${mix(start[1], end[1])}, ${mix(start[2], end[2])}, ${alpha})`
}

function drawHeatmapTile(context, coords, size, data) {
    context.clearRect(0, 0, size.x, size.y)
    const cells = Array.isArray(data?.cells) ? data.cells : []
    if (!cells.length) return

    let maxCount = Number(data?.meta?.max_count ?? 0)
    if (!Number.isFinite(maxCount) || maxCount <= 0) {
        maxCount = cells.reduce((max, cell) => Math.max(max, Number(cell?.count ?? 0)), 0)
    }
    if (!maxCount) return

    context.imageSmoothingEnabled = true
    context.globalCompositeOperation = 'lighter'
    context.lineJoin = 'round'
    context.lineCap = 'round'

    cells.forEach((cell) => {
        const polygon = Array.isArray(cell?.polygon) ? cell.polygon : []
        if (polygon.length < 3) return

        const count = Number(cell.count ?? 0)
        if (!Number.isFinite(count) || count <= 0) return

        const path = polygon
            .map((vertex) => {
                const lng = typeof vertex?.lng === 'number' ? vertex.lng : Number(vertex?.[0])
                const lat = typeof vertex?.lat === 'number' ? vertex.lat : Number(vertex?.[1])
                if (!Number.isFinite(lng) || !Number.isFinite(lat)) return null
                return projectToTile(lng, lat, coords, size)
            })
            .filter(Boolean)

        if (path.length < 3) return

        const intensity = Math.max(0, Math.min(1, count / maxCount))
        context.beginPath()
        path.forEach(([px, py], idx) => (idx === 0 ? context.moveTo(px, py) : context.lineTo(px, py)))
        context.closePath()
        context.fillStyle = colorForIntensity(intensity)
        context.fill()
        context.strokeStyle = `rgba(30, 64, 175, ${0.12 + intensity * 0.18})`
        context.lineWidth = 0.6
        context.stroke()
    })

    context.globalCompositeOperation = 'source-over'
}

function createHeatmapLayer() {
    const controllers = new Map()
    const layer = leafletLib.gridLayer({ tileSize: 256, updateWhenIdle: true, keepBuffer: 2 })

    const handleTileUnload = (event) => {
        const controller = controllers.get(event.tile)
        if (controller) { controller.abort(); controllers.delete(event.tile) }
    }

    layer.on('tileunload', handleTileUnload)

    layer.createTile = function createTile(coords, done) {
        const size = this.getTileSize()
        const canvas = document.createElement('canvas')
        canvas.width = size.x; canvas.height = size.y
        const context = canvas.getContext('2d')
        if (!context) { done(null, canvas); return canvas }

        const controller = new AbortController()
        controllers.set(canvas, controller)

        scheduleTileRequest(
            () => apiClient.get(`/heatmap/${coords.z}/${coords.x}/${coords.y}`, {
                params: buildTileParams(heatmapOptions.value),
                signal: controller.signal,
            }),
            controller.signal
        )
            .then(({ data }) => drawHeatmapTile(context, coords, size, data))
            .catch((error) => {
                if (error?.code !== 'ERR_CANCELED' && error?.name !== 'AbortError') {
                    console.error('Failed to load heatmap tile', error)
                }
                context.clearRect(0, 0, size.x, size.y)
            })
            .finally(() => { controllers.delete(canvas); done(null, canvas) })

        return canvas
    }

    layer.cancelPending = () => { controllers.forEach((c) => c.abort()); controllers.clear() }
    layer.dispose = () => { layer.off('tileunload', handleTileUnload); layer.cancelPending() }
    return layer
}

async function ensureLeaflet() {
    if (leafletLib) return leafletLib
    try {
        const [{ default: L }] = await Promise.all([import('leaflet'), loadLeafletStyles()])
        leafletLib = L
        return L
    } catch (error) {
        console.error('Failed to load Leaflet', error)
        fallbackReason.value = 'Interactive map unavailable. Please ensure you are online and try again.'
        throw error
    }
}

function loadLeafletStyles() {
    return import('leaflet/dist/leaflet.css')
}

async function initMap() {
    try {
        const L = await ensureLeaflet()
        if (!mapContainer.value) return

        mapInstance.value = L.map(mapContainer.value, {
            center: [props.center.lat, props.center.lng],
            zoom: 13,
            preferCanvas: true,
        })

        updateBaseLayer()
        updateHeatmap()
        updatePointOverlay()
        updateRadiusCircle()

        // Ensure Leaflet sizes correctly after initial layout pass
        await nextTick()
        mapInstance.value.invalidateSize()

        // Observe future size changes (flex-basis, sidebar toggle, tab visibility)
        ro = new ResizeObserver(() => mapInstance.value?.invalidateSize())
        ro.observe(mapContainer.value)
    } catch (error) {
        fallbackReason.value = 'Unable to display the map in this browser.'
    }
}

function updateBaseLayer() {
    if (!leafletLib || !mapInstance.value) return
    if (tileLayer.value) {
        mapInstance.value.removeLayer(tileLayer.value)
    }
    tileLayer.value = leafletLib.tileLayer(tileSources[mapStore.selectedBaseLayer], {
        attribution: '&copy; OpenStreetMap contributors',
    })
    tileLayer.value.addTo(mapInstance.value)

    // Nudge Leaflet after the layer swap to avoid occasional mis-measures
    queueMicrotask(() => mapInstance.value?.invalidateSize())
}

function hasPointData() {
    return Array.isArray(props.points) && props.points.length > 0
}

function updateHeatmap() {
    if (!leafletLib || !mapInstance.value) return

    if (!heatLayer.value) {
        heatLayer.value = createHeatmapLayer()
    }

    const shouldDisplayTiles = mapStore.showHeatmap && !hasPointData()
    if (!shouldDisplayTiles) {
        heatLayer.value.cancelPending?.()
        if (mapInstance.value.hasLayer(heatLayer.value)) {
            mapInstance.value.removeLayer(heatLayer.value)
        }
        return
    }

    heatLayer.value.setOpacity?.(mapStore.heatmapOpacity)

    if (!mapInstance.value.hasLayer(heatLayer.value)) {
        heatLayer.value.addTo(mapInstance.value)
    } else {
        heatLayer.value.redraw()
    }
}

function createPolygon(points, options) {
    const latLngs = points
        .map((vertex) => {
            if (!vertex) return null
            const lat = Number(vertex.lat ?? vertex.latitude ?? vertex[1])
            const lng = Number(vertex.lng ?? vertex.lon ?? vertex.longitude ?? vertex[0])
            if (!Number.isFinite(lat) || !Number.isFinite(lng)) return null
            return [lat, lng]
        })
        .filter(Boolean)

    if (latLngs.length < 3) return null
    return leafletLib.polygon(latLngs, options)
}

function intensityToStyle(intensity, maxIntensity) {
    const safeMax = maxIntensity > 0 ? maxIntensity : 1
    const normalized = Math.max(0, Math.min(1, intensity / safeMax))
    const fill = colorForIntensity(normalized)
    const outlineAlpha = 0.25 + normalized * 0.35
    const fillOpacity = Math.min(0.85, 0.35 + normalized * 0.45)

    return {
        fillColor: fill,
        fillOpacity,
        color: `rgba(30, 64, 175, ${outlineAlpha})`,
        weight: 1,
        bubblingMouseEvents: false,
    }
}

function updatePointOverlay() {
    if (!leafletLib || !mapInstance.value) return

    if (!pointsLayer.value) {
        pointsLayer.value = leafletLib.layerGroup()
    }

    const layer = pointsLayer.value

    if (!mapStore.showHeatmap || !hasPointData()) {
        layer.clearLayers?.()
        if (mapInstance.value.hasLayer(layer)) {
            mapInstance.value.removeLayer(layer)
        }
        return
    }

    layer.clearLayers()

    const intensities = props.points
        .map((point) => Number(point?.intensity ?? 0))
        .filter((value) => Number.isFinite(value) && value >= 0)

    const maxIntensity = intensities.length ? Math.max(...intensities) : 1

    props.points.forEach((point) => {
        if (!point || typeof point !== 'object') return

        const intensity = Number(point.intensity ?? 0)
        const style = intensityToStyle(intensity, maxIntensity)

        if (Array.isArray(point.polygon) && point.polygon.length >= 3) {
            const polygon = createPolygon(point.polygon, style)
            if (polygon) layer.addLayer(polygon)
            return
        }

        const lat = Number(point.lat ?? point.latitude)
        const lng = Number(point.lng ?? point.longitude)
        if (!Number.isFinite(lat) || !Number.isFinite(lng)) return

        const normalized = maxIntensity > 0 ? Math.max(0, Math.min(1, intensity / maxIntensity)) : 0
        const radius = 60 + normalized * 140

        const circle = leafletLib.circle([lat, lng], { ...style, radius })
        layer.addLayer(circle)
    })

    if (!mapInstance.value.hasLayer(layer)) {
        layer.addTo(mapInstance.value)
    }
}

function updateRadiusCircle() {
    if (!leafletLib || !mapInstance.value) return
    if (radiusCircle.value) {
        mapInstance.value.removeLayer(radiusCircle.value)
    }
    radiusCircle.value = leafletLib.circle([props.center.lat, props.center.lng], {
        radius: props.radiusKm * 1000,
        color: '#1d4ed8',
        fill: false,
        weight: 1.5,
        dashArray: '4 4',
    })
    radiusCircle.value.addTo(mapInstance.value)
}

function toggleBase() {
    mapStore.toggleBaseLayer()
}

watch(() => ({ ...props.center }), (center) => {
    if (mapInstance.value) {
        mapInstance.value.setView([center.lat, center.lng], mapInstance.value.getZoom())
        updateRadiusCircle()
    }
})

watch(() => mapStore.selectedBaseLayer, () => updateBaseLayer())

watch(() => mapStore.showHeatmap, () => {
    updateHeatmap()
    updatePointOverlay()
})

watch(() => mapStore.heatmapOpacity, (opacity) => {
    if (heatLayer.value) heatLayer.value.setOpacity?.(opacity)
})

watch(() => props.radiusKm, () => updateRadiusCircle())

watch(
    () => props.tileOptions,
    (next) => {
        heatmapOptions.value = normalizeTileOptions(next ?? {})
        if (heatLayer.value && mapInstance.value?.hasLayer(heatLayer.value)) {
            heatLayer.value.redraw()
        }
    },
    { deep: true }
)

watch(
    () => props.points,
    () => {
        updateHeatmap()
        updatePointOverlay()
    },
    { deep: true, immediate: true }
)

onMounted(() => {
    if (typeof window === 'undefined') return

    // If IO isn’t available, just init
    if (!('IntersectionObserver' in window)) {
        initMap()
        return
    }

    const observer = new IntersectionObserver(
        (entries) => {
            entries.forEach((entry) => {
                if (entry.isIntersecting) {
                    initMap()
                    observer.disconnect()
                }
            })
        },
        { root: null, threshold: 0.1 }
    )

    if (mapContainer.value) observer.observe(mapContainer.value)
})

onBeforeUnmount(() => {
    ro?.disconnect?.()
    if (mapInstance.value) mapInstance.value.remove()
    heatLayer.value?.dispose?.()
    pointsLayer.value?.clearLayers?.()
})
</script>
