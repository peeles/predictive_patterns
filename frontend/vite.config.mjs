import { defineConfig } from 'vite'
import vue from '@vitejs/plugin-vue'
import { dirname, resolve } from 'node:path'
import { fileURLToPath } from 'node:url'

const __filename = fileURLToPath(import.meta.url)
const __dirname = dirname(__filename)

export default defineConfig(async () => {
  const plugins = [vue()]

  if (!process.env.VITEST) {
    const { default: tailwindcss } = await import('@tailwindcss/vite')
    plugins.push(tailwindcss())
  }

  const target = process.env.VITE_PROXY_TARGET || 'http://localhost:8000'

  return {
    plugins,
    resolve: {
      alias: {
        '@': resolve(__dirname, 'src'),
      },
    },
    server: {
      host: '0.0.0.0',
      port: 3000,
      proxy: {
        '/api': { target, changeOrigin: true },
        '/broadcasting': { target, changeOrigin: true },
        '/sanctum': { target, changeOrigin: true },
      },
      watch: {
        usePolling: true,
      },
    },
    test: {
      environment: 'jsdom',
      globals: true,
      setupFiles: ['./tests/setup.js'],
      restoreMocks: true,
      coverage: {
        provider: 'v8',
        reports: ['text', 'lcov'],
      },
    },
  }
})
