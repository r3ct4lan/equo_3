import type { CurrentUser, ApiRequestOptions } from './api'

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

export interface LoginValues extends Record<string, unknown> {
  email: string
  password: string
}

export interface LoginOptions {
  redirectTo?: string
}

export interface AuthSession {
  bootstrap: () => Promise<void>
  retryBootstrap: () => Promise<void>
  login: (values: LoginValues, options?: LoginOptions) => Promise<void>
  protectedRequest: <T>(path: string, options?: ApiRequestOptions) => Promise<T>
}
