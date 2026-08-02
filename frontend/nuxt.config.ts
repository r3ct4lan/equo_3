export default defineNuxtConfig({
  devtools: { enabled: true },
  css: ['~/assets/css/main.css'],
  runtimeConfig: {
    apiInternalBase: '',
    public: {
      apiBase: '/api'
    }
  },
  compatibilityDate: '2026-07-26',
  vite: {
    server: {
      watch: {
        usePolling: process.env.CHOKIDAR_USEPOLLING === 'true',
        interval: 500
      }
    }
  },
  typescript: {
    strict: true,
    typeCheck: true
  }
})
