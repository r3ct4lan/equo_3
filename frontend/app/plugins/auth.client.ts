import { createAuthSession } from '~/utils/auth-session'

export default defineNuxtPlugin({
  name: 'auth',
  dependsOn: ['api'],
  setup(nuxtApp) {
    const currentUser = useCurrentUser()
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
