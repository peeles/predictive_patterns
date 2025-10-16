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
      strictPort: true,
      allowedHosts: ['.predictive-patterns.test'],
      // Watch options for Docker
      watch: {
        usePolling: true,
        interval: 100
      },

      // HMR configuration for working through Nginx proxy
      hmr: {
        clientPort: 80,
        host: 'predictive-patterns.test',
        protocol: 'ws'
      },

      // CORS configuration
      cors: {
        origin: [
          'http://predictive-patterns.test',
          'http://localhost',
          'http://127.0.0.1'
        ],
        credentials: true
      },

      // Proxy API requests to backend
      proxy: {
        '/api': {
          target: process.env.VITE_PROXY_TARGET || 'http://backend:8000',
          changeOrigin: true,
          secure: false,
          ws: true
        },
        '/broadcasting': {
          target: process.env.VITE_PROXY_TARGET || 'http://backend:8000',
          changeOrigin: true,
          secure: false,
          ws: true
        },
        '/storage': {
          target: process.env.VITE_PROXY_TARGET || 'http://backend:8000',
          changeOrigin: true,
          secure: false
        }
      }
    },

    build: {
      outDir: 'dist',
      sourcemap: true,
      rollupOptions: {
        output: {
          manualChunks: {
            'vue-vendor': ['vue', 'vue-router', 'pinia']
          }
        }
      }
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
