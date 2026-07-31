import type { CurrentUser } from '~/types/api'
import type { CurrentUserState } from '~/types/session'
import type { ApiClientError } from '~/utils/api-error'

export function useCurrentUser() {
  const state = useState<CurrentUserState>('current-user', () => ({
    status: 'unknown',
    user: null,
    error: null
  }))

  function setAuthenticated(user: CurrentUser): void {
    state.value = {
      status: 'authenticated',
      user,
      error: null
    }
  }

  function setAnonymous(): void {
    state.value = {
      status: 'anonymous',
      user: null,
      error: null
    }
  }

  function setError(error: ApiClientError): void {
    state.value = {
      status: 'error',
      user: null,
      error: {
        code: error.code,
        message: error.message,
        ...(error.requestId ? { requestId: error.requestId } : {})
      }
    }
  }

  function reset(): void {
    state.value = {
      status: 'unknown',
      user: null,
      error: null
    }
  }

  return {
    state: readonly(state),
    setAuthenticated,
    setAnonymous,
    setError,
    reset
  }
}
