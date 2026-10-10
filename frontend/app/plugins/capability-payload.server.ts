export default defineNuxtPlugin((nuxtApp) => {
  const path = nuxtApp.ssrContext?.url.split(/[?#]/u)[0]
  const invitation = path?.startsWith('/connect-invitations/') ?? false
  if (path !== '/password-reset' && path !== '/activate' && !invitation) return

  // Nuxt serializes the request URL into payload.path. The client router reads
  // the original query from window.location, so it needs only the pathname here.
  nuxtApp.hook('app:rendered', () => {
    nuxtApp.payload.path = invitation ? '/connect-invitations' : path
  })
})
