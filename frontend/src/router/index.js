import { createRouter, createWebHistory } from 'vue-router'
import { useAuthStore } from '../stores/auth'
import { notifyError } from '../utils/notifications'

const router = createRouter({
    history: createWebHistory(),
    routes: [
        {
            path: '/',
            name: 'login',
            component: () => import('../views/AuthView.vue'),
        },
        {
            path: '/dashboard',
            name: 'dashboard',
            component: () => import('../views/DashboardView.vue'),
            meta: { requiresAuth: true },
        },
        {
            path: '/predict',
            name: 'predict',
            component: () => import('../views/PredictView.vue'),
            meta: { requiresAuth: true },
        },
        {
            path: '/admin/models',
            name: 'admin-models',
            component: () => import('../views/admin/AdminModelsView.vue'),
            meta: { requiresAuth: true, requiresAdmin: true },
        },
        {
            path: '/admin/datasets',
            name: 'admin-datasets',
            component: () => import('../views/admin/AdminDatasetsView.vue'),
            meta: { requiresAuth: true, requiresAdmin: true },
        },
        {
            path: '/admin/datasets/ingest',
            name: 'admin-datasets-ingest-legacy',
            redirect: { name: 'admin-datasets' },
            meta: { requiresAuth: true, requiresAdmin: true },
        },
        {
            path: '/admin/datasets/:id',
            name: 'admin-datasets-detail',
            component: () => import('../views/admin/AdminDatasetDetailView.vue'),
            meta: { requiresAuth: true, requiresAdmin: true },
        },
        {
            path: '/admin/users',
            name: 'admin-users',
            component: () => import('../views/admin/AdminUsersView.vue'),
            meta: { requiresAuth: true, requiresAdmin: true },
        },
        {
            path: '/:pathMatch(.*)*',
            redirect: '/',
        },
    ],
    scrollBehavior() {
        return { top: 0 }
    },
})

export async function authNavigationGuard(to) {
    const auth = useAuthStore()

    if (!auth.hasAttemptedSessionRestore) {
        await auth.restoreSession()
    }

    if (to.meta.requiresAuth) {
        if (!auth.isAuthenticated && auth.canRefresh) {
            await auth.restoreSession({ force: true })
        }

        if (!auth.isAuthenticated) {
            return { name: 'login', query: { redirect: to.fullPath } }
        }
    }

    if (to.meta.requiresAdmin && !auth.isAdmin) {
        notifyError('Admin privileges are required to access that area.')
        return { name: 'dashboard' }
    }

    if (to.path.startsWith('/admin') && !auth.isAdmin) {
        notifyError('Admin privileges are required to access that area.')
        return { name: 'dashboard' }
    }

    return true
}

router.beforeEach(authNavigationGuard)

export default router
