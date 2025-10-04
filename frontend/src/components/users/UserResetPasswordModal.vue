<template>
    <BaseModal :open="open" dialog-class="max-w-lg" @close="handleClose">
        <template #header>
            <h2 class="text-lg font-semibold text-stone-900">Reset password</h2>
            <p class="mt-1 text-sm text-stone-600">
                Generate a new temporary password for the user. They'll be prompted to choose their own after signing in.
            </p>
        </template>

        <form id="reset-user-password" class="space-y-5" @submit.prevent="handleSubmit">
            <div class="rounded-xl border border-stone-200 bg-stone-50/70 p-4 text-sm">
                <p class="font-semibold text-stone-900">{{ user?.name || 'Unknown user' }}</p>
                <p class="text-xs text-stone-500">{{ user?.email }}</p>
            </div>

            <p class="text-sm text-stone-600">
                This action immediately invalidates the existing password. Share the generated password securely with the
                user.
            </p>

            <div v-if="temporaryPassword" class="rounded-xl border border-blue-200 bg-blue-50/80 px-4 py-3 text-sm text-blue-700">
                <p class="text-xs font-semibold uppercase tracking-wide">Temporary password</p>
                <code class="mt-2 block text-base font-semibold">{{ temporaryPassword }}</code>
                <p class="mt-2 text-xs text-blue-600">Make sure to copy this password before closing the dialog.</p>
            </div>

            <p v-if="errors.password" class="text-sm text-rose-600">{{ errors.password }}</p>
        </form>

        <template #footer>
            <button
                type="button"
                class="rounded-md border border-stone-300 px-4 py-2 text-sm font-semibold text-stone-700 shadow-sm transition hover:border-stone-400 hover:text-stone-900 focus-visible:outline  focus-visible:outline-offset-2 focus-visible:outline-blue-500"
                @click="handleClose"
            >
                Close
            </button>
            <button
                type="submit"
                form="reset-user-password"
                class="inline-flex items-center justify-center rounded-md bg-blue-600 px-4 py-2 text-sm font-semibold text-white shadow-sm transition hover:bg-blue-700 focus-visible:outline  focus-visible:outline-offset-2 focus-visible:outline-blue-500 disabled:cursor-not-allowed disabled:opacity-60"
                :disabled="isResetting"
            >
                {{ isResetting ? 'Resetting…' : 'Reset password' }}
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
})

const emit = defineEmits(['close', 'reset'])

const userStore = useUserStore()
const { actionState } = storeToRefs(userStore)

const temporaryPassword = ref('')
const errors = reactive({})

const isResetting = computed(() => {
    if (!props.user?.id) {
        return false
    }
    return actionState.value?.[props.user.id] === 'resetting-password'
})

watch(
    () => props.open,
    (isOpen) => {
        if (isOpen) {
            resetState()
        }
    }
)

watch(
    () => props.user,
    () => {
        if (props.open) {
            resetState()
        }
    }
)

function resetState() {
    temporaryPassword.value = ''
    clearErrors()
}

function clearErrors() {
    Object.keys(errors).forEach((key) => {
        delete errors[key]
    })
}

function handleClose() {
    emit('close')
}

async function handleSubmit() {
    clearErrors()
    if (!props.user?.id) {
        errors.password = 'A user must be selected.'
        return
    }

    const { password, errors: validationErrors } = await userStore.resetUserPassword(props.user.id)
    if (validationErrors) {
        Object.assign(errors, validationErrors)
        return
    }

    if (password) {
        temporaryPassword.value = password
    }

    emit('reset', password)
}
</script>
