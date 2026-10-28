import type { DebtSummary, Page } from '~/types/finance'
import { toApiClientError, type ApiClientError } from '~/utils/api-error'

export interface BalanceSummary {
  totalOwedAmount: number
  totalReceivableAmount: number
}

export function useDashboard() {
  const { $auth } = useNuxtApp()
  const { state } = useCurrentUser()
  const data = shallowRef<{ balances: BalanceSummary, expenses: DebtSummary[] } | null>(null)
  const pending = ref(false)
  const error = shallowRef<ApiClientError | null>(null)
  let generation = 0
  let mounted = false

  function clear(): void {
    ++generation
    data.value = null
    pending.value = false
    error.value = null
  }

  async function load(): Promise<void> {
    if (!mounted || pending.value || state.value.status !== 'authenticated') return
    const viewerId = state.value.user?.id
    const requestGeneration = ++generation
    data.value = null
    error.value = null
    pending.value = true
    try {
      const [balances, expenses] = await Promise.all([
        $auth.protectedRequest<BalanceSummary>('/v1/transfers/balance-summary'),
        $auth.protectedRequest<Page<DebtSummary>>('/v1/debts', { query: { limit: 5, status: 'active' } })
      ])
      if (requestGeneration !== generation || state.value.status !== 'authenticated' || state.value.user?.id !== viewerId) return
      data.value = { balances, expenses: expenses.items }
    }
    catch (failure) {
      if (requestGeneration === generation) error.value = toApiClientError(failure)
    }
    finally {
      if (requestGeneration === generation) pending.value = false
    }
  }

  onMounted(() => {
    mounted = true
    void load()
  })
  watch(() => [state.value.status, state.value.user?.id], () => {
    clear()
    void load()
  }, { flush: 'sync' })
  onBeforeUnmount(() => {
    mounted = false
    clear()
  })

  return { data, pending, error, load }
}
