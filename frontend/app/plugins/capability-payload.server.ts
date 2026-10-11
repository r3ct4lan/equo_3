export default defineNuxtPlugin((nuxtApp) => {
  const path = nuxtApp.ssrContext?.url.split(/[?#]/u)[0]
  const invitation = path?.startsWith('/connect-invitations/') ?? false
  if (path !== '/password-reset' && path !== '/activate' && !invitation) return

  // Nuxt serializes the request URL into payload.path. The client router reads
  // the original URL from window.location. Dynamic invitation paths omit the
  // rendered path so the router uses the actual client URL during hydration.
  nuxtApp.hook('app:rendered', () => {
    nuxtApp.payload.path = invitation ? '' : path
  })
})
