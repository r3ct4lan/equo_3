import { $fetch } from 'ofetch'
import type { ApiClient } from '~/types/api'
import { toApiClientError } from '~/utils/api-error'

export default defineNuxtPlugin(() => {
  const config = useRuntimeConfig()
  const { setAnonymous } = useCurrentUser()
  const baseURL = import.meta.server
    ? config.apiInternalBase || config.public.apiBase
    : config.public.apiBase

  const api: ApiClient = async (path, options = {}) => {
    const { accessToken, ...fetchOptions } = options
    const headers = new Headers(fetchOptions.headers)

    headers.set('Accept', 'application/json')

    if (fetchOptions.body !== undefined && !headers.has('Content-Type')) {
      headers.set('Content-Type', 'application/json')
    }

    if (accessToken) {
      headers.set('Authorization', `Bearer ${accessToken}`)
    }

    try {
      return await $fetch(path, {
        ...fetchOptions,
        baseURL,
        credentials: 'include',
        headers,
        retry: fetchOptions.retry ?? false
      })
    } catch (error: unknown) {
      const normalized = toApiClientError(error)

      if (normalized.status === 401) {
        setAnonymous()
      }

      throw normalized
    }
  }

  return {
    provide: {
      api
    }
  }
})
