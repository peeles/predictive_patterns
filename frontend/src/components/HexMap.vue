<template>
    <section class="relative h-full flex-1">
        <div ref="mapEl" class="h-full w-full"></div>

        <div class="pointer-events-none absolute inset-x-0 top-2 flex justify-center">
            <transition name="fade">
                <div
                    v-if="fetchError"
                    class="pointer-events-auto rounded bg-rose-50 px-4 py-2 text-sm font-medium text-rose-700 shadow-lg ring-1 ring-rose-300/60"
                >
                    {{ fetchError }}
                </div>
            </transition>
        </div>

        <aside class="pointer-events-auto absolute bottom-4 left-4 z-[1000] rounded bg-white/95 p-4 text-xs shadow-xl ring-1 ring-stone-900/10">
            <fieldset class="flex flex-col gap-2">
                <label class="font-semibold">H3 Resolution: {{ resolvedResolution }}</label>
                <label class="flex items-center gap-2 text-[11px]">
                    <input v-model="autoResolution" type="checkbox" class="h-3 w-3" />
                    <span>Sync resolution with map zoom</span>
                </label>
                <input
                    v-model.number="resolution"
                    class="w-full"
                    type="range"
                    :min="MIN_RESOLUTION"
                    :max="MAX_RESOLUTION"
                    step="1"
                    :disabled="autoResolution"
                />
            </fieldset>
        </aside>
    </section>
</template>

<script setup>
import { computed, onBeforeUnmount, onMounted, ref, watch } from 'vue'
import L from 'leaflet'
import * as h3 from 'h3-js'
import apiClient from '@/services/apiClient'

const props = defineProps({
    windowStart: { type: String, required: true },
    windowEnd: { type: String, required: true },
    center: { type: Array, default: () => [53.4084, -2.9916] },
    crimeType: { type: String, default: 'all' },
    district: { type: String, default: '' },
    customLayer: { type: String, default: '' },
})

const DEBUG = import.meta.env.DEV
const MAX_VIEWPORT_AREAS = 1200
const MIN_RESOLUTION = 5
const MAX_RESOLUTION = 11
const DEBOUNCE_MS = 250
const BUCKET_COUNT = 6
const BUCKET_COLOURS = ['#2ECC71', '#7FD67F', '#C9E68D', '#F6D04D', '#F39C12', '#E74C3C']

const mapEl = ref(null)
const mapRef = ref(null)
const layerGroup = ref(null)
const legendControl = ref(null)
const pendingController = ref(null)
const debounceTimer = ref(null)
const resolution = ref(8)
const autoResolution = ref(true)
const fetchError = ref('')
const canvasRenderer = L.canvas({ padding: 0.1 })
const quantDomain = ref([0, 1])
const quantThresholds = ref([])

const resolvedResolution = computed(() => {
    const value = Number(resolution.value)
    if (Number.isNaN(value)) {
        return 8
    }

    return Math.min(MAX_RESOLUTION, Math.max(MIN_RESOLUTION, value))
})

watch(() => props.center, (value) => {
    if (mapRef.value && Array.isArray(value) && value.length === 2) {
        mapRef.value.setView(value, mapRef.value.getZoom() ?? 12)
    }
})

watch(
    () => [props.windowStart, props.windowEnd, props.crimeType, props.district, props.customLayer],
    () => scheduleRender()
)

watch(resolvedResolution, () => scheduleRender())

watch(
    autoResolution,
    (value) => {
        if (!value) {
            return
        }

        const changed = syncResolutionWithZoom()
        if (!changed) {
            scheduleRender()
        }
    },
    { flush: 'post' }
)

function scheduleRender() {
    clearTimeout(debounceTimer.value)
    debounceTimer.value = setTimeout(renderPredictions, DEBOUNCE_MS)
}

function cancelPending() {
    if (pendingController.value) {
        pendingController.value.abort()
        pendingController.value = null
    }
}

function polygonFromH3(cellId) {
    try {
        return h3.cellToBoundary(cellId)
    } catch (error) {
        DEBUG && console.error('Failed to resolve H3 boundary', error)
        return null
    }
}

function estimateViewportCellCount(map, bounds, resolutionValue) {
    try {
        const southWest = bounds.getSouthWest()
        const southEast = bounds.getSouthEast()
        const northWest = bounds.getNorthWest()

        const widthMeters = map.distance(southWest, southEast)
        const heightMeters = map.distance(southWest, northWest)

        if (!Number.isFinite(widthMeters) || !Number.isFinite(heightMeters)) {
            return 0
        }

        const areaKm2 = (widthMeters * heightMeters) / 1_000_000
        const cellAreaKm2 = h3.getHexagonAreaAvg(resolutionValue, 'km2')

        if (!Number.isFinite(areaKm2) || !Number.isFinite(cellAreaKm2) || cellAreaKm2 <= 0) {
            return 0
        }

        return areaKm2 / cellAreaKm2
    } catch (error) {
        DEBUG && console.warn('[H3] Failed to estimate viewport cell count', error)
        return 0
    }
}

function computeViewportCells(map, resolutionValue) {
    const bounds = map.getBounds()
    if (!bounds || !bounds.isValid()) {
        return { areas: [], reason: 'invalid-bounds' }
    }

    const ringLatLng = [
        [bounds.getSouth(), bounds.getWest()],
        [bounds.getSouth(), bounds.getEast()],
        [bounds.getNorth(), bounds.getEast()],
        [bounds.getNorth(), bounds.getWest()],
        [bounds.getSouth(), bounds.getWest()],
    ]
    const ringGeo = ringLatLng.map(([lat, lng]) => [lng, lat])
    const estimatedCells = estimateViewportCellCount(map, bounds, resolutionValue)

    if (estimatedCells > MAX_VIEWPORT_AREAS) {
        DEBUG &&
        console.warn(
            '[H3] Viewport too large for resolution',
            { estimatedCells, limit: MAX_VIEWPORT_AREAS, resolution: resolutionValue }
        )
        return { areas: [], reason: 'viewport-too-large' }
    }

    const attempts = [
        () => h3.polygonToCells(ringLatLng, resolutionValue),
        () => h3.polygonToCells([ringLatLng], resolutionValue),
        () => h3.polygonToCells(ringGeo, resolutionValue, { isGeoJson: true }),
        () => h3.polygonToCells([ringGeo], resolutionValue, { isGeoJson: true }),
    ]

    for (const attempt of attempts) {
        try {
            const result = attempt()
            if (Array.isArray(result) && result.length > 0) {
                DEBUG && console.debug('[H3] polygonToCells returned', result.length)
                return { areas: result, reason: null }
            }
        } catch (error) {
            DEBUG && console.warn('[H3] polygonToCells failure', error)
        }
    }

    DEBUG && console.warn('[H3] No cells produced for viewport.')
    return { areas: [], reason: 'h3-failure' }
}

function recommendResolutionForViewport(map) {
    const bounds = map.getBounds()
    if (!bounds || !bounds.isValid()) {
        return null
    }

    for (let candidate = MAX_RESOLUTION; candidate >= MIN_RESOLUTION; candidate -= 1) {
        const estimated = estimateViewportCellCount(map, bounds, candidate)
        if (estimated <= MAX_VIEWPORT_AREAS) {
            return candidate
        }
    }

    return MIN_RESOLUTION
}

function syncResolutionWithZoom() {
    const map = mapRef.value
    if (!map || !autoResolution.value) {
        return false
    }

    const recommended = recommendResolutionForViewport(map)
    if (recommended == null) {
        return false
    }

    if (resolution.value !== recommended) {
        resolution.value = recommended
        return true
    }

    return false
}

function updateQuantization(values) {
    const finiteValues = values.filter((value) => Number.isFinite(value))
    if (finiteValues.length === 0) {
        quantDomain.value = [0, 1]
        quantThresholds.value = computeThresholds(0, 1, BUCKET_COUNT)
        return
    }

    let min = Math.min(...finiteValues)
    let max = Math.max(...finiteValues)

    if (min === max) {
        const epsilon = Math.max(1e-6, Math.abs(min) * 0.01)
        min -= epsilon
        max += epsilon
    }

    quantDomain.value = [min, max]
    quantThresholds.value = computeThresholds(min, max, BUCKET_COUNT)
}

function computeThresholds(min, max, buckets) {
    const thresholds = []
    const step = (max - min) / buckets
    for (let i = 1; i < buckets; i += 1) {
        thresholds.push(min + step * i)
    }
    return thresholds
}

function colourForValue(value) {
    if (!Number.isFinite(value)) {
        return BUCKET_COLOURS[0]
    }

    const [min, max] = quantDomain.value
    if (value <= min) {
        return BUCKET_COLOURS[0]
    }
    if (value >= max) {
        return BUCKET_COLOURS[BUCKET_COLOURS.length - 1]
    }

    const idx = quantThresholds.value.findIndex((threshold) => value <= threshold)
    return idx === -1 ? BUCKET_COLOURS[BUCKET_COLOURS.length - 1] : BUCKET_COLOURS[idx]
}

function formatNumber(value) {
    return Number.isFinite(value) ? value.toFixed(3) : 'n/a'
}

function removeLegend() {
    if (legendControl.value) {
        legendControl.value.remove()
        legendControl.value = null
    }
}

function addLegend(map) {
    removeLegend()

    const Legend = L.Control.extend({
        options: { position: 'bottomright' },
        onAdd() {
            const div = L.DomUtil.create('div', 'leaflet-control legend')
            Object.assign(div.style, {
                background: 'rgba(255,255,255,0.95)',
                padding: '8px 10px',
                borderRadius: '10px',
                boxShadow: '0 1px 4px rgba(0,0,0,.15)',
                font: '12px/1.3 system-ui, -apple-system, Segoe UI, Roboto, sans-serif',
                maxWidth: '240px',
            })

            const heading = document.createElement('div')
            heading.textContent = 'Risk buckets'
            heading.style.fontWeight = '700'
            heading.style.marginBottom = '6px'
            div.appendChild(heading)

            const [min, max] = quantDomain.value
            const edges = [min, ...quantThresholds.value, max]

            for (let i = 0; i < edges.length - 1; i += 1) {
                const row = document.createElement('div')
                row.style.display = 'flex'
                row.style.alignItems = 'center'
                row.style.gap = '8px'
                row.style.marginTop = i === 0 ? '0' : '4px'

                const swatch = document.createElement('span')
                Object.assign(swatch.style, {
                    display: 'inline-block',
                    width: '18px',
                    height: '12px',
                    border: '1px solid rgba(0,0,0,.15)',
                    background: BUCKET_COLOURS[i],
                })

                const label = document.createElement('span')
                label.textContent = `${formatNumber(edges[i])} – ${formatNumber(edges[i + 1])}`

                row.appendChild(swatch)
                row.appendChild(label)
                div.appendChild(row)
            }

            return div
        },
    })

    legendControl.value = new Legend()
    legendControl.value.addTo(map)
}

async function fetchPredictions(map) {
    const resolutionValue = resolvedResolution.value
    fetchError.value = '';
    let { areas, reason } = computeViewportCells(map, resolutionValue)

    if (reason === 'viewport-too-large') {
        fetchError.value = 'Zoom in to load risk predictions for the current view.'
    } else if (reason === 'h3-failure') {
        fetchError.value = 'Unable to resolve map bounds to H3 cells.'
    }

    if (areas.length > MAX_VIEWPORT_AREAS) {
        areas = areas.slice(0, MAX_VIEWPORT_AREAS)
    }

    if (areas.length === 0) {
        return { predictions: [], areas }
    }

    cancelPending()
    pendingController.value = new AbortController()

    try {
        const response = await apiClient.post(
                '/predict',
                {
                    areas,
                    window_start: props.windowStart,
                    window_end: props.windowEnd,
                    crime_type: props.crimeType,
                    district: props.district,
                    layer: props.customLayer,
                },
            {
                signal: pendingController.value.signal,
            }
        )

        const predictions = Array.isArray(response?.data?.predictions) ? response.data.predictions : []

        return { predictions, areas }
    } catch (error) {
        if (error?.name === 'AbortError' || error?.name === 'CanceledError' || error?.code === 'ERR_CANCELED') {
            return { predictions: [], areas }
        }

        console.error('Error fetching predictions', error)
        fetchError.value = 'Unable to load risk predictions for the current view.'
        return { predictions: [], areas }
    } finally {
        pendingController.value = null
    }
}

function renderWireframe(areas, targetGroup = null) {
    for (const id of areas) {
        const ring = polygonFromH3(id)
        if (!ring) {
            continue
        }

        L.polygon(ring, {
            pane: 'hexPane',
            weight: 1,
            color: '#888',
            fillOpacity: 0,
            renderer: canvasRenderer,
        }).addTo(targetGroup)
    }

    removeLegend()
}

async function renderPredictions() {
    const map = mapRef.value
    if (!map) {
        return
    }

    const { predictions, areas } = await fetchPredictions(map)
    const previousLayerGroup = layerGroup.value
    const nextLayerGroup = L.layerGroup().addTo(map)
    layerGroup.value = nextLayerGroup

    if (!predictions.length) {
        DEBUG && console.warn('[Render] No predictions returned; drawing viewport grid wireframe.')
        renderWireframe(areas, nextLayerGroup)

        if (previousLayerGroup) {
            previousLayerGroup.remove()
        }

        return
    }

    const risks = predictions.map((prediction) => Number(prediction.risk))
    updateQuantization(risks)
    removeLegend()
    addLegend(map)

    for (const prediction of predictions) {
        const ring = polygonFromH3(prediction.area_id)
        if (!ring) {
            continue
        }

        const risk = Number(prediction.risk)
        const layer = L.polygon(ring, {
            pane: 'hexPane',
            weight: 1,
            color: '#333',
            fillColor: colourForValue(risk),
            fillOpacity: 0.55,
            renderer: canvasRenderer,
        })

        const riskText = formatNumber(risk)
        layer.bindPopup(`Cell: ${prediction.area_id}<br/>Risk: ${riskText}<br/>Crime: ${props.crimeType}`)
        layer.bindTooltip(`Risk: ${riskText}`, { sticky: true })
        nextLayerGroup.addLayer(layer)
    }

    if (previousLayerGroup) {
        previousLayerGroup.remove()
    }
}

onMounted(async () => {
    const map = L.map(mapEl.value, { preferCanvas: true }).setView(props.center, 12)
    mapRef.value = map

    const light = L.tileLayer('https://{s}.basemaps.cartocdn.com/light_all/{z}/{x}/{y}{r}.png', { maxZoom: 18 })
    const dark = L.tileLayer('https://{s}.basemaps.cartocdn.com/dark_all/{z}/{x}/{y}{r}.png', { maxZoom: 18 })
    light.addTo(map)
    L.control.layers({ Light: light, Dark: dark }).addTo(map)

    map.createPane('hexPane')
    map.getPane('hexPane').style.zIndex = 500

    layerGroup.value = L.layerGroup().addTo(map)

    map.on('moveend', scheduleRender)
    map.on('zoomend', () => {
        const changed = syncResolutionWithZoom()
        if (!changed) {
            scheduleRender()
        }
    })

    updateQuantization([])

    const changed = syncResolutionWithZoom()
    if (changed) {
        clearTimeout(debounceTimer.value)
        debounceTimer.value = null
    }

    await renderPredictions()
})

onBeforeUnmount(() => {
    cancelPending()
    clearTimeout(debounceTimer.value)
    removeLegend()

    const map = mapRef.value
    if (map) {
        map.off('moveend', scheduleRender)
        map.off('zoomend')
        map.remove()
    }
})
</script>

<style scoped>
.fade-enter-active,
.fade-leave-active {
    transition: opacity 0.2s ease;
}

.fade-enter-from,
.fade-leave-to {
    opacity: 0;
}
</style>
