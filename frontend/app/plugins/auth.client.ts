import { createAuthSession, SIGNED_OUT_KEY } from '~/utils/auth-session'
import { forgetInvitation, rememberInvitation } from '~/utils/connect-invitations'
import { isTelegramInvitation, readTelegramInitData, readTelegramInvitation } from '~/utils/telegram-launch'

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
    const signedInThroughTelegram = document.cookie.split(';').some(cookie => cookie.trim() === '__Host-equo_telegram_signed_in=1')
    if (signedInThroughTelegram) {
      document.cookie = '__Host-equo_telegram_signed_in=; Path=/; Secure; SameSite=Lax; Max-Age=0'
      try {
        storage?.removeItem(SIGNED_OUT_KEY)
      }
      catch { /* A successful provider return can still use the session cookies. */ }
    }
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
      continueTelegramLaunch: async (startParam) => {
        if (!isTelegramInvitation(startParam)) return
        forgetInvitation()
        const token = readTelegramInvitation(startParam)
        if (token) rememberInvitation(token)
        await navigateTo('/connect-invitations/mini-app')
      },
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
