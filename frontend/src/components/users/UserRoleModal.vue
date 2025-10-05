<template>
    <BaseModal
        :open="open"
        :dialog-class="'max-w-lg'"
        @close="handleClose"
    >
        <template #header>
            <h2 class="text-lg font-semibold text-stone-900">Update role</h2>
            <p class="mt-1 text-sm text-stone-600">
                Adjust the user's access level. Changes take effect immediately.
            </p>
        </template>

        <form id="update-user-role" class="space-y-5" @submit.prevent="handleSubmit">
            <div v-if="errors.general" class="rounded-md border border-rose-200 bg-rose-50 px-3 py-2 text-sm text-rose-700">
                {{ errors.general }}
            </div>
            <div class="rounded-xl border border-stone-200 bg-stone-50/70 p-4 text-sm">
                <p class="font-semibold text-stone-900">{{ user?.name || 'Unknown user' }}</p>
                <p class="text-xs text-stone-500">{{ user?.email }}</p>
            </div>

            <div>
                <label for="edit-user-role" class="block text-sm font-medium text-stone-700">Role</label>
                <select
                    id="edit-user-role"
                    v-model="selectedRole"
                    name="role"
                    class="mt-1 block w-full rounded-md border border-stone-300 px-3 py-2 text-sm text-stone-900 shadow-sm focus:border-blue-500 focus:outline-none focus:ring-2 focus:ring-blue-500"
                >
                    <option v-for="role in roleOptions" :key="role" :value="role">{{ formatRole(role) }}</option>
                </select>
                <p v-if="errors.role" class="mt-1 text-sm text-rose-600">{{ errors.role }}</p>
            </div>
        </form>

        <template #footer>
            <button
                type="button"
                class="rounded-md border border-stone-300 px-4 py-2 text-sm font-semibold text-stone-700 shadow-sm transition hover:border-stone-400 hover:text-stone-900 focus-visible:outline  focus-visible:outline-offset-2 focus-visible:outline-blue-500"
                @click="handleClose"
            >
                Cancel
            </button>
            <button
                type="submit"
                form="update-user-role"
                class="inline-flex items-center justify-center rounded-md bg-blue-600 px-4 py-2 text-sm font-semibold text-white shadow-sm transition hover:bg-blue-700 focus-visible:outline  focus-visible:outline-offset-2 focus-visible:outline-blue-500 disabled:cursor-not-allowed disabled:opacity-60"
                :disabled="!selectedRole || isUpdating"
            >
                {{ isUpdating ? 'Saving…' : 'Save changes' }}
            </button>
        </template>
    </BaseModal>
</template>

<script setup>
import { computed, reactive, ref, watch } from 'vue'
import { storeToRefs } from 'pinia'
import BaseModal from '../common/BaseModal.vue'
import { useUserStore } from '../../stores/user'

const props = defineProps({
    open: {
        type: Boolean,
        default: false,
    },
    user: {
        type: Object,
        default: null,
    },
    roles: {
        type: Array,
        default: () => [],
    },
})

const emit = defineEmits(['close', 'updated'])

const userStore = useUserStore()
const { roles: storeRoles, actionState } = storeToRefs(userStore)

const selectedRole = ref('')
const errors = reactive({})

const roleOptions = computed(() => {
    const provided = Array.isArray(props.roles) ? props.roles : []
    if (provided.length) {
        return provided
    }
    return storeRoles.value ?? []
})

const isUpdating = computed(() => {
    if (!props.user?.id) {
        return false
    }
    return actionState.value?.[props.user.id] === 'updating-role'
})

watch(
    () => props.open,
    (isOpen) => {
        if (isOpen) {
            resetForm()
        }
    }
)

watch(
    () => props.user,
    () => {
        if (props.open) {
            resetForm()
        }
    }
)

watch(
    roleOptions,
    (roles) => {
        if (!roles.includes(selectedRole.value)) {
            selectedRole.value = roles.includes(props.user?.role) ? props.user?.role : roles[0] ?? ''
        }
    },
    { immediate: true }
)

function resetForm() {
    clearErrors()
    const currentRole = props.user?.role ?? ''
    if (currentRole) {
        selectedRole.value = currentRole
    } else {
        selectedRole.value = roleOptions.value[0] ?? ''
    }
}

function clearErrors() {
    Object.keys(errors).forEach((key) => {
        delete errors[key]
    })
}

function formatRole(role) {
    if (!role) {
        return 'Select role'
    }
    return String(role)
        .toLowerCase()
        .replace(/_/g, ' ')
        .replace(/\b\w/g, (char) => char.toUpperCase())
}

function handleClose() {
    emit('close')
}

async function handleSubmit() {
    clearErrors()
    if (!props.user?.id) {
        errors.role = 'A user must be selected.'
        return
    }

    const { user, errors: validationErrors } = await userStore.updateUserRole(props.user.id, selectedRole.value)
    if (validationErrors) {
        Object.assign(errors, validationErrors)
        return
    }

    emit('updated', user)
    emit('close')
}
</script>
