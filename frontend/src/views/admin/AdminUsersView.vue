<template>
    <div class="space-y-6">
        <PageHeader
            :page-tag="'Administration'"
            :page-title="'User Management'"
            :page-subtitle="'Manage users, roles, and permissions within your workspace.'"
        >
            <template #actions>
                <button
                    class="inline-flex items-center justify-center rounded-md bg-blue-600 px-4 py-2 text-sm font-semibold text-white shadow-sm transition hover:bg-blue-700 focus-visible:outline  focus-visible:outline-offset-2 focus-visible:outline-blue-500"
                    type="button"
                    @click="openCreateModal"
                >
                    Invite user
                </button>
            </template>
        </PageHeader>

        <UserCreateModal
            :open="createModalOpen"
            :roles="roles"
            @close="createModalOpen = false"
            @created="handleUserCreated"
        />

        <UserRoleModal
            :open="roleModalOpen"
            :user="selectedUser"
            :roles="roles"
            @close="closeRoleModal"
            @updated="handleRoleUpdated"
        />

        <UserResetPasswordModal
            :open="passwordModalOpen"
            :user="selectedUser"
            @close="closePasswordModal"
            @reset="handlePasswordReset"
        />

        <div
            v-if="error"
            class="rounded-md border border-rose-200 bg-rose-50 px-4 py-3 text-sm text-rose-700"
        >
            {{ error }}
        </div>

        <UserTable
            :users="users"
            :loading="loading"
            :action-state="actionState"
            @edit-role="openRoleModal"
            @reset-password="openPasswordModal"
            @delete-user="handleDeleteUser"
        />
    </div>
</template>

<script setup>
import { onMounted, ref } from 'vue'
import { storeToRefs } from 'pinia'
import UserCreateModal from '../../components/users/UserCreateModal.vue'
import UserRoleModal from '../../components/users/UserRoleModal.vue'
import UserResetPasswordModal from '../../components/users/UserResetPasswordModal.vue'
import UserTable from '../../components/users/UserTable.vue'
import { useUserStore } from '../../stores/user'
import PageHeader from '../../components/common/PageHeader.vue'

const userStore = useUserStore()
const { users, loading, roles, actionState, error } = storeToRefs(userStore)

const createModalOpen = ref(false)
const roleModalOpen = ref(false)
const passwordModalOpen = ref(false)
const selectedUser = ref(null)

onMounted(() => {
    userStore.fetchUsers()
})

function openCreateModal() {
    createModalOpen.value = true
}

async function handleUserCreated() {
    createModalOpen.value = false
    await userStore.fetchUsers()
}

function openRoleModal(user) {
    selectedUser.value = user
    roleModalOpen.value = true
}

function closeRoleModal() {
    roleModalOpen.value = false
    selectedUser.value = null
}

async function handleRoleUpdated() {
    roleModalOpen.value = false
    await userStore.fetchUsers()
    selectedUser.value = null
}

function openPasswordModal(user) {
    selectedUser.value = user
    passwordModalOpen.value = true
}

function closePasswordModal() {
    passwordModalOpen.value = false
    selectedUser.value = null
}

function handlePasswordReset() {
    // keep dialog open if a password is generated so operators can copy it
}

async function handleDeleteUser(user) {
    if (!user?.id) {
        return
    }

    const confirmed = window.confirm(
        `Remove ${user.name || user.email || 'this user'} from the workspace? This action cannot be easily undone.`
    )
    if (!confirmed) {
        return
    }

    const { success } = await userStore.deleteUser(user.id)
    if (success) {
        await userStore.fetchUsers()
        if (selectedUser.value?.id === user.id) {
            selectedUser.value = null
        }
    }
}
</script>
