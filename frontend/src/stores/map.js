import { defineStore } from 'pinia'

export const useMapStore = defineStore('map', {
    state: () => ({
        selectedBaseLayer: 'streets',
        heatmapOpacity: 0.75,
        showHeatmap: true,
    }),
    getters: {
        baseLayerLabel: (state) => (state.selectedBaseLayer === 'streets' ? 'Streets' : 'Satellite'),
    },
    actions: {
        toggleBaseLayer() {
            this.selectedBaseLayer = this.selectedBaseLayer === 'streets' ? 'satellite' : 'streets'
        },
        setBaseLayer(layer) {
            this.selectedBaseLayer = layer
        },
        setHeatmapOpacity(value) {
            this.heatmapOpacity = value
        },
        toggleHeatmap() {
            this.showHeatmap = !this.showHeatmap
        },
    },
})
