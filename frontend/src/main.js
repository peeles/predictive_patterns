import { createApp } from 'vue'
import { createPinia } from 'pinia'
import { persistPlugin } from './stores/plugins/persist'
import App from './App.vue'
import router from './router'
import './assets/main.css'
import { ensureCsrfCookie } from './services/csrf'

ensureCsrfCookie().catch((error) => {
    console.warn('Unable to establish CSRF protection during bootstrap.', error)
})

const app = createApp(App)
const pinia = createPinia()
pinia.use(persistPlugin)

app.use(pinia).use(router).mount('#app')
