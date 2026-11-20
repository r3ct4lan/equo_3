import { createAuthSession } from '~/utils/auth-session'
import { readTelegramInitData } from '~/utils/telegram-launch'

export default defineNuxtPlugin({
  name: 'auth',
  dependsOn: ['api'],
  setup(nuxtApp) {
    const currentUser = useCurrentUser()
    const telegramInitData = readTelegramInitData(window.location.hash)
    useState('telegram-mini-app', () => false).value = telegramInitData !== null
    let storage: Storage | undefined
    try {
      storage = window.sessionStorage
    }
    catch { /* Session coordination also works when browser storage is unavailable. */ }
    const auth = createAuthSession({
      api: nuxtApp.$api,
      currentUser: {
        state: currentUser.state,
        setAuthenticated: currentUser.setAuthenticated,
        setAnonymous: currentUser.setAnonymous,
        setError: currentUser.setError
      },
      navigateTo,
      document,
      navigator,
      sessionStorage: storage,
      telegramInitData: telegramInitData ?? undefined,
      signedOut: new URL(window.location.href).searchParams.get('signedOut') === '1'
        || window.location.pathname === '/logout',
      createBroadcastChannel: typeof BroadcastChannel === 'undefined'
        ? undefined
        : name => new BroadcastChannel(name)
    })

    onNuxtReady(() => {
      void auth.bootstrap()
    })

    return {
      provide: {
        auth
      }
    }
  }
})
