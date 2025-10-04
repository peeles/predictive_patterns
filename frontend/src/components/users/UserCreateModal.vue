<template>
    <BaseModal :open="open" dialog-class="max-w-xl" @close="handleClose">
        <template #header>
            <h2 class="text-lg font-semibold text-stone-900">Invite a new user</h2>
            <p class="mt-1 text-sm text-stone-600">
                Provide the user's details and assign an initial role. They'll receive an email to finish setup.
            </p>
        </template>

        <form id="create-user-form" class="space-y-5" @submit.prevent="handleSubmit">
            <div v-if="errors.general" class="rounded-md border border-rose-200 bg-rose-50 px-3 py-2 text-sm text-rose-700">
                {{ errors.general }}
            </div>
            <div>
                <label for="user-name" class="block text-sm font-medium text-stone-700">Full name</label>
                <input
                    id="user-name"
                    v-model="form.name"
                    type="text"
                    name="name"
                    class="mt-1 block w-full rounded-md border border-stone-300 px-3 py-2 text-sm text-stone-900 shadow-sm focus:border-blue-500 focus:outline-none focus:ring-2 focus:ring-blue-500"
                    autocomplete="off"
                />
                <p v-if="errors.name" class="mt-1 text-sm text-rose-600">{{ errors.name }}</p>
            </div>

            <div>
                <label for="user-email" class="block text-sm font-medium text-stone-700">Email</label>
                <input
                    id="user-email"
                    v-model="form.email"
                    type="email"
                    name="email"
                    class="mt-1 block w-full rounded-md border border-stone-300 px-3 py-2 text-sm text-stone-900 shadow-sm focus:border-blue-500 focus:outline-none focus:ring-2 focus:ring-blue-500"
                    autocomplete="off"
                />
                <p v-if="errors.email" class="mt-1 text-sm text-rose-600">{{ errors.email }}</p>
            </div>

            <div>
                <label for="user-role" class="block text-sm font-medium text-stone-700">Role</label>
                <select
                    id="user-role"
                    v-model="form.role"
                    name="role"
                    class="mt-1 block w-full rounded-md border border-stone-300 px-3 py-2 text-sm text-stone-900 shadow-sm focus:border-blue-500 focus:outline-none focus:ring-2 focus:ring-blue-500"
                >
                    <option v-for="role in roleOptions" :key="role" :value="role">{{ formatRole(role) }}</option>
                </select>
                <p v-if="errors.role" class="mt-1 text-sm text-rose-600">{{ errors.role }}</p>
            </div>

            <div>
                <label for="user-password" class="block text-sm font-medium text-stone-700">Temporary password (optional)</label>
                <input
                    id="user-password"
                    v-model="form.password"
                    type="text"
                    name="password"
                    class="mt-1 block w-full rounded-md border border-stone-300 px-3 py-2 text-sm text-stone-900 shadow-sm focus:border-blue-500 focus:outline-none focus:ring-2 focus:ring-blue-500"
                    autocomplete="off"
                    placeholder="Leave blank to send an invite email"
                />
                <p v-if="errors.password" class="mt-1 text-sm text-rose-600">{{ errors.password }}</p>
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
                form="create-user-form"
                class="inline-flex items-center justify-center rounded-md bg-blue-600 px-4 py-2 text-sm font-semibold text-white shadow-sm transition hover:bg-blue-700 focus-visible:outline  focus-visible:outline-offset-2 focus-visible:outline-blue-500 disabled:cursor-not-allowed disabled:opacity-60"
                :disabled="saving"
            >
                {{ saving ? 'Creating…' : 'Create user' }}
            </button>
        </template>
    </BaseModal>
</template>

<script setup>
import { computed, reactive, watch } from 'vue'
import { storeToRefs } from 'pinia'
import BaseModal from '../common/BaseModal.vue'
import { useUserStore } from '../../stores/user'

const props = defineProps({
    open: {
        type: Boolean,
        default: false,
    },
    roles: {
        type: Array,
        default: () => [],
    },
})

const emit = defineEmits(['close', 'created'])

const userStore = useUserStore()
const { roles: storeRoles, saving } = storeToRefs(userStore)

const form = reactive({
    name: '',
    email: '',
    role: '',
    password: '',
})

const errors = reactive({})

const roleOptions = computed(() => {
    const provided = Array.isArray(props.roles) ? props.roles : []
    if (provided.length) {
        return provided
    }
    return storeRoles.value ?? []
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
    roleOptions,
    (roles) => {
        if (!roles.includes(form.role)) {
            form.role = roles[0] ?? ''
        }
    },
    { immediate: true }
)

function resetForm() {
    form.name = ''
    form.email = ''
    form.password = ''
    form.role = roleOptions.value[0] ?? ''
    clearErrors()
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
    const { user, errors: validationErrors } = await userStore.createUser({ ...form })
    if (validationErrors) {
        Object.assign(errors, validationErrors)
        return
    }

    emit('created', user)
    emit('close')
}
</script>
