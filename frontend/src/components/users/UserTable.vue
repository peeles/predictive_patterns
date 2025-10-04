<template>
    <section class="overflow-hidden rounded-2xl border border-stone-200/80 bg-white shadow-sm shadow-stone-200/70">
        <header class="flex items-center justify-between gap-4 border-b border-stone-200 bg-stone-50/60 px-5 py-4">
            <div>
                <h2 class="text-sm font-semibold uppercase tracking-wide text-stone-500">User directory</h2>
                <p class="mt-1 text-sm text-stone-600">Review team members with access to the workspace.</p>
            </div>
        </header>
        <div class="overflow-x-auto">
            <table class="min-w-full divide-y divide-stone-200 text-sm">
                <thead class="bg-stone-50/80 text-left text-xs font-semibold uppercase tracking-wide text-stone-500">
                    <tr>
                        <th scope="col" class="px-5 py-3">Name</th>
                        <th scope="col" class="px-5 py-3">Role</th>
                        <th scope="col" class="px-5 py-3">Last active</th>
                        <th scope="col" class="px-5 py-3 text-right">Actions</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-stone-100/80 text-stone-700">
                    <tr v-if="loading">
                        <td colspan="4" class="px-5 py-10">
                            <div class="space-y-4">
                                <div class="h-3 w-2/3 animate-pulse rounded-full bg-stone-200"></div>
                                <div class="h-3 w-1/2 animate-pulse rounded-full bg-stone-200"></div>
                                <div class="h-3 w-1/3 animate-pulse rounded-full bg-stone-200"></div>
                            </div>
                        </td>
                    </tr>
                    <tr v-else-if="!users.length">
                        <td colspan="4" class="px-5 py-12 text-center text-sm text-stone-500">
                            No users found. Invite a colleague to get started.
                        </td>
                    </tr>
                    <tr v-for="user in users" v-else :key="user.id">
                        <td class="px-5 py-4">
                            <div class="space-y-1">
                                <p class="font-medium text-stone-900">{{ user.name || 'Unknown user' }}</p>
                                <p class="text-xs text-stone-500">{{ user.email }}</p>
                            </div>
                        </td>
                        <td class="px-5 py-4">
                            <span class="inline-flex items-center rounded-full bg-stone-100 px-3 py-1 text-xs font-semibold uppercase tracking-wide text-stone-600">
                                {{ roleLabel(user.role) }}
                            </span>
                        </td>
                        <td class="px-5 py-4 text-stone-500">
                            {{ formatDate(user.lastSeenAt || user.updatedAt || user.createdAt) }}
                        </td>
                        <td class="px-5 py-4">
                            <div class="flex items-center justify-end gap-2">
                                <button
                                    type="button"
                                    class="rounded-md border border-stone-300 px-3 py-1.5 text-xs font-semibold text-stone-700 shadow-sm transition hover:border-stone-400 hover:text-stone-900 focus-visible:outline  focus-visible:outline-offset-2 focus-visible:outline-blue-500 disabled:cursor-not-allowed disabled:opacity-60"
                                    :disabled="isBusy(user.id)"
                                    @click="emitEditRole(user)"
                                >
                                    {{ isUpdatingRole(user.id) ? 'Saving…' : 'Edit role' }}
                                </button>
                                <button
                                    type="button"
                                    class="rounded-md border border-stone-300 px-3 py-1.5 text-xs font-semibold text-stone-700 shadow-sm transition hover:border-stone-400 hover:text-stone-900 focus-visible:outline  focus-visible:outline-offset-2 focus-visible:outline-blue-500 disabled:cursor-not-allowed disabled:opacity-60"
                                    :disabled="isBusy(user.id)"
                                    @click="emitResetPassword(user)"
                                >
                                    {{ isResettingPassword(user.id) ? 'Resetting…' : 'Reset password' }}
                                </button>
                                <button
                                    type="button"
                                    class="rounded-md border border-rose-200 px-3 py-1.5 text-xs font-semibold text-rose-600 shadow-sm transition hover:border-rose-300 hover:text-rose-700 focus-visible:outline  focus-visible:outline-offset-2 focus-visible:outline-rose-500 disabled:cursor-not-allowed disabled:opacity-60"
                                    :disabled="isBusy(user.id)"
                                    @click="emitDelete(user)"
                                >
                                    {{ isDeleting(user.id) ? 'Removing…' : 'Remove' }}
                                </button>
                            </div>
                        </td>
                    </tr>
                </tbody>
            </table>
        </div>
    </section>
</template>

<script setup>
import { computed } from 'vue'

const props = defineProps({
    users: {
        type: Array,
        default: () => [],
    },
    loading: {
        type: Boolean,
        default: false,
    },
    actionState: {
        type: Object,
        default: () => ({}),
    },
})

const emit = defineEmits(['edit-role', 'reset-password', 'delete-user'])

const busyMap = computed(() => props.actionState || {})

function roleLabel(role) {
    if (!role) {
        return 'Unassigned'
    }
    return String(role).replace(/_/g, ' ').replace(/\b\w/g, (char) => char.toUpperCase())
}

function formatDate(value) {
    if (!value) {
        return '—'
    }
    const date = new Date(value)
    if (Number.isNaN(date.getTime())) {
        return '—'
    }
    return date.toLocaleString()
}

function isBusy(userId) {
    return Boolean(busyMap.value?.[userId])
}

function isUpdatingRole(userId) {
    return busyMap.value?.[userId] === 'updating-role'
}

function isResettingPassword(userId) {
    return busyMap.value?.[userId] === 'resetting-password'
}

function isDeleting(userId) {
    return busyMap.value?.[userId] === 'deleting'
}

function emitEditRole(user) {
    emit('edit-role', user)
}

function emitResetPassword(user) {
    emit('reset-password', user)
}

function emitDelete(user) {
    emit('delete-user', user)
}
</script>
