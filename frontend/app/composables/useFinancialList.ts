import type {
  Page
} from '~/types/finance'
import {
  toApiClientError, type ApiClientError
} from '~/utils/api-error'

export function useFinancialList<T>(path: () => string, query: () => Record<string, string> = () => ({})) {
  const {
    $auth
  } = useNuxtApp()
  const {
    state
  } = useCurrentUser()
  const items = shallowRef<T[]>([])
  const pending = ref(false)
  const loaded = ref(false)
  const cursor = ref<string | null>(null)
  const error = ref<ApiClientError | null>(null)
  let generation = 0
  async function load(more = false): Promise<void> {
    if (state.value.status !== 'authenticated' || pending.value) return
    const requestGeneration = ++generation
    const viewer = state.value.user?.id
    const requestPath = path()
    pending.value = true
    error.value = null
    try {
      const result = await $auth.protectedRequest<Page<T>>(requestPath, {
        query: { ...query(), ...(more && cursor.value ? { cursor: cursor.value } : {}) }
      })
      if (requestGeneration !== generation || state.value.user?.id !== viewer || state.value.status !== 'authenticated') return
      items.value = more ? [...items.value, ...result.items] : result.items
      cursor.value = result.pagination.nextCursor
      loaded.value = true
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
    items.value = []
    cursor.value = null
    loaded.value = false
    pending.value = false
    error.value = null
  }
  onMounted(() => {
    void load()
  })
  watch(() => [state.value.status, state.value.user?.id, path(), JSON.stringify(query())], () => {
    clear()
    void load()
  })
  onBeforeUnmount(clear)
  return {
    items, pending, loaded, cursor, error, load
  }
}
