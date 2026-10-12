import {
  toApiClientError, type ApiClientError
} from '~/utils/api-error'

export function useFinancialDetail<T>(path: () => string) {
  const {
    $auth
  } = useNuxtApp()
  const {
    state
  } = useCurrentUser()
  const item = shallowRef<T | null>(null)
  const etag = ref<string | null>(null)
  const pending = ref(false)
  const error = ref<ApiClientError | null>(null)
  let generation = 0
  async function load(): Promise<void> {
    if (state.value.status !== 'authenticated' || pending.value) return
    const requestGeneration = ++generation
    const viewer = state.value.user?.id
    pending.value = true
    error.value = null
    item.value = null
    etag.value = null
    let responseEtag: string | null = null
    try {
      const result = await $auth.protectedRequest<T>(path(), {
        onResponse({ response }) {
          if (response.ok) responseEtag = response.headers.get('ETag')
        }
      })
      if (requestGeneration === generation && state.value.user?.id === viewer && state.value.status === 'authenticated') {
        item.value = result
        etag.value = responseEtag
      }
    }
    catch (failure) {
      if (requestGeneration === generation) error.value = toApiClientError(failure)
    }
    finally {
      if (requestGeneration === generation) pending.value = false
    }
  }
  function clear(): void {
    ++generation
    item.value = null
    etag.value = null
    pending.value = false
    error.value = null
  }
  onMounted(() => {
    void load()
  })
  watch(() => [state.value.status, state.value.user?.id, path()], () => {
    clear()
    void load()
  })
  onBeforeUnmount(clear)
  return {
    item, etag, pending, error, load
  }
}
