import tailwindcss from '@tailwindcss/vite'

export default defineNuxtConfig({

  modules: ['shadcn-nuxt'],
  devtools: { enabled: true }, app: { head: { script: [{ src: '/theme-init.js' }] } },
  css: ['~/assets/css/tailwind.css', '~/assets/css/vars.css', '~/assets/css/main.css', '~/assets/css/multiselect.css'],
  vue: { optionsApi: true },
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
    plugins: [tailwindcss()],
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
  },
  shadcn: {
    prefix: 'Shadcn',
    componentDir: '~/components/ui'
  }
})
