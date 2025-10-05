import { nextTick } from 'vue';

const STORAGE_PREFIX = 'predictive-patterns'

const allowList = {
    prediction: ['lastFilters'],
    map: ['selectedBaseLayer', 'heatmapOpacity', 'showHeatmap'],
    auth: [
        'token',
        'user',
        // Special handling for hasRefreshSession to support both options API and setup stores
        {
            key: 'hasRefreshSession',
            read: (store) => store.canRefresh,
            write: (store, value) => {
                if (typeof store.setHasRefreshSession === 'function') {
                    store.setHasRefreshSession(value)
                } else if ('hasRefreshSession' in store) {
                    store.hasRefreshSession = value
                } else {
                    store.$patch({ hasRefreshSession: value })
                }
            },
            actions: ['setHasRefreshSession', 'restoreSession'],
        },
    ],
}

function normalizeEntries(entries) {
    return entries.map((entry) =>
        typeof entry === 'string' ? { key: entry } : entry
    )
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

    const storageKey = `${STORAGE_PREFIX}:${store.$id}`;
    const entries = normalizeEntries(keys)
    const actionNames = new Set()

    for (const entry of entries) {
        if (Array.isArray(entry.actions)) {
            for (const actionName of entry.actions) {
                actionNames.add(actionName)
            }
        }
    }

    const readEntryValue = (entry) =>
        typeof entry.read === 'function'
            ? entry.read(store, store.$state)
            : store[entry.key]

    const persistSnapshot = () => {
        const partial = {}
        for (const entry of entries) {
            partial[entry.key] = readEntryValue(entry)
        }
        try {
            storage.setItem(storageKey, JSON.stringify(partial))
        } catch (error) {
            console.warn('Failed to persist state for', store.$id, error)
        }
    }

    if (actionNames.size > 0) {
        store.$onAction(
            ({ name, after }) => {
                if (!actionNames.has(name)) {
                    return
                }
                after(async () => {
                    await nextTick()
                    persistSnapshot()
                })
            },
            true
        )
    }

    try {
        const savedState = JSON.parse(storage.getItem(storageKey) || '{}')
        if (savedState && typeof savedState === 'object') {
            const patchPayload = {}
            for (const entry of entries) {
                if (!(entry.key in savedState)) {
                    continue
                }
                if (typeof entry.write === 'function') {
                    entry.write(store, savedState[entry.key])
                } else {
                    patchPayload[entry.key] = savedState[entry.key]
                }
            }
            if (Object.keys(patchPayload).length) {
                store.$patch(patchPayload)
            }
        }
    } catch (error) {
        console.warn('Failed to restore state for', store.$id, error)
    }

    store.$subscribe(
        () => {
            persistSnapshot()},
        { detached: true }
    )
}

