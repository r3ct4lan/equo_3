import type { CurrentUser } from './api'

export type SessionStatus = 'unknown' | 'authenticated' | 'anonymous' | 'error'

export interface SessionError {
  code: string
  message: string
  requestId?: string
}

export interface CurrentUserState {
  status: SessionStatus
  user: CurrentUser | null
  error: SessionError | null
}
