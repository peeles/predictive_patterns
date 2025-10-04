const STORAGE_PREFIX = 'predictive-patterns'

const allowList = {
    prediction: ['lastFilters'],
    map: ['selectedBaseLayer', 'heatmapOpacity', 'showHeatmap'],
}

function getStorage() {
    if (typeof window === 'undefined') {
        return null
    }
    try {
        return window.localStorage
    } catch (error) {
        console.warn('Storage unavailable', error)
        return null
    }
}

export function persistPlugin({ store }) {
    const storage = getStorage()
    const keys = allowList[store.$id]

    if (!storage || !keys) {
        return
    }

    const storageKey = `${STORAGE_PREFIX}:${store.$id}`
    try {
        const savedState = JSON.parse(storage.getItem(storageKey) || '{}')
        if (savedState && typeof savedState === 'object') {
            store.$patch(
                Object.fromEntries(
                    keys
                        .filter((key) => key in savedState)
                        .map((key) => [key, savedState[key]])
                )
            )
        }
    } catch (error) {
        console.warn('Failed to restore state for', store.$id, error)
    }

    store.$subscribe(
        (_, state) => {
            const partial = {}
            for (const key of keys) {
                partial[key] = state[key]
            }
            try {
                storage.setItem(storageKey, JSON.stringify(partial))
            } catch (error) {
                console.warn('Failed to persist state for', store.$id, error)
            }
        },
        { detached: true }
    )
}
