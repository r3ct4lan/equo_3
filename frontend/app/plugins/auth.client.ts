import { createAuthSession } from '~/utils/auth-session'

export default defineNuxtPlugin({
  name: 'auth',
  dependsOn: ['api'],
  setup(nuxtApp) {
    const currentUser = useCurrentUser()
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
