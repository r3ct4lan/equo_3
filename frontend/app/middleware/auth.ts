import { safeRedirectPath } from '~/utils/auth-session'

export default defineNuxtRouteMiddleware(async (to) => {
  if (import.meta.server) {
    return
  }

  const { $auth } = useNuxtApp()
  const currentUser = useCurrentUser()

  await $auth.bootstrap()

  if (currentUser.state.value.status === 'authenticated') {
    return
  }

  if (currentUser.state.value.status === 'anonymous') {
    return navigateTo({
      path: '/login',
      query: {
        redirect: safeRedirectPath(to.fullPath)
      }
    })
  }
})
