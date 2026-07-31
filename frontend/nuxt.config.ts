export default defineNuxtConfig({
  compatibilityDate: '2026-07-26',
  devtools: { enabled: true },
  css: ['~/assets/css/main.css'],
  runtimeConfig: {
    apiInternalBase: '',
    public: {
      apiBase: '/api'
    }
  },
  typescript: {
    strict: true,
    typeCheck: true
  },
  vite: {
    server: {
      watch: {
        usePolling: process.env.CHOKIDAR_USEPOLLING === 'true',
        interval: 500
      }
    }
  }
})
