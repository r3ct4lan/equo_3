export default defineNuxtConfig({
  devtools: { enabled: true },
  css: ['~/assets/css/vars.css', '~/assets/css/main.css'],
  runtimeConfig: {
    apiInternalBase: '',
    public: {
      apiBase: '/api'
    }
  },
  routeRules: {
    '/connect-invitations/**': { headers: { 'referrer-policy': 'no-referrer', 'cache-control': 'no-store' } }
  },
  compatibilityDate: '2026-07-26',
  vite: {
    server: {
      allowedHosts: ['nginx'],
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
