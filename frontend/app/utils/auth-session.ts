import type { CurrentUser, LoginRequest, LoginResponse, RefreshResponse, ApiClient, ApiRequestOptions } from '../types/api'
import type { AuthSession, LoginOptions, LoginValues } from '../types/session'
import { ApiClientError, type FieldErrorMap } from './api-error.ts'

export const LOGIN_ENDPOINT = '/v1/auth/login'
export const REFRESH_ENDPOINT = '/v1/auth/refresh'
export const ME_ENDPOINT = '/v1/me'
export const AUTH_REFRESH_LOCK = 'equo:auth-refresh'
export const SESSION_ENDED_EVENT = 'session-ended'
export const AUTH_CHANNEL = 'equo:auth'

const EMAIL_PATTERN = /^[^\s@]+@[^\s@]+\.[^\s@]+$/u
const CSRF_COOKIE_NAME = '__Host-equo_csrf'

export interface LoginErrorPresentation {
  title: string
  message: string
  retryable: boolean
}

export interface AuthSessionDependencies {
  api: ApiClient
  currentUser: {
    state: Readonly<{ value: { status: string } }>
    setAuthenticated: (user: CurrentUser) => void
    setAnonymous: () => void
    setError: (error: ApiClientError) => void
  }
  navigateTo?: (path: string) => Promise<unknown> | unknown
  document?: Pick<Document, 'cookie'>
  navigator?: Pick<Navigator, 'locks'>
  createBroadcastChannel?: (name: string) => Pick<BroadcastChannel, 'postMessage' | 'close' | 'addEventListener'>
}

export function validateLogin(values: Readonly<LoginValues>): FieldErrorMap {
  const errors: FieldErrorMap = {}

  if (values.email.trim() === '') {
    errors.email = ['Enter your email address.']
  }
  else if (!EMAIL_PATTERN.test(values.email.trim())) {
    errors.email = ['Enter an email address in a valid format.']
  }

  if (values.password === '') {
    errors.password = ['Enter your password.']
  }

  return errors
}

export function loginErrorPresentation(error: {
  code: string
  status: number | null
  retryAfterSeconds?: number | null
  fieldErrors: FieldErrorMap
}): LoginErrorPresentation {
  switch (error.code) {
    case 'INVALID_CREDENTIALS':
      return {
        title: 'Email or password is incorrect',
        message: 'Check your details and try again.',
        retryable: false
      }
    case 'ACCOUNT_INACTIVE':
      return {
        title: 'Account is not active',
        message: 'Activate your account before signing in.',
        retryable: false
      }
    case 'RATE_LIMIT_EXCEEDED':
      return {
        title: 'Too many sign-in attempts',
        message: error.retryAfterSeconds !== null && error.retryAfterSeconds !== undefined
          ? `Wait ${error.retryAfterSeconds} seconds before trying again.`
          : 'Wait a little before trying again.',
        retryable: false
      }
    case 'VALIDATION_ERROR':
      return {
        title: 'Check the form',
        message: Object.keys(error.fieldErrors).length > 0
          ? 'Correct the highlighted fields and submit again.'
          : 'Check the entered values and submit again.',
        retryable: false
      }
    default:
      if (error.status !== null && error.status < 500) {
        return {
          title: 'Sign-in is unavailable',
          message: 'This sign-in request could not be completed.',
          retryable: false
        }
      }

      return {
        title: 'We could not sign you in',
        message: 'Try again when the service is reachable.',
        retryable: true
      }
  }
}

export function readCsrfCookie(source: Pick<Document, 'cookie'> | undefined = globalThis.document): string | null {
  const cookieHeader = source?.cookie

  if (!cookieHeader) {
    return null
  }

  for (const part of cookieHeader.split(';')) {
    const [rawName, ...rawValue] = part.trim().split('=')
    if (rawName !== CSRF_COOKIE_NAME || rawValue.length === 0) {
      continue
    }

    try {
      const value = decodeURIComponent(rawValue.join('='))
      return value === '' ? null : value
    }
    catch {
      return null
    }
  }

  return null
}

export function safeRedirectPath(raw: unknown, fallback = '/me'): string {
  if (typeof raw !== 'string' || raw === '') {
    return fallback
  }

  let decoded: string
  try {
    decoded = decodeURIComponent(raw)
  }
  catch {
    return fallback
  }

  if (!decoded.startsWith('/') || decoded.startsWith('//') || decoded.includes('\\')) {
    return fallback
  }

  try {
    const url = new URL(decoded, 'https://equo.local')
    if (url.origin !== 'https://equo.local') {
      return fallback
    }

    return `${url.pathname}${url.search}${url.hash}`
  }
  catch {
    return fallback
  }
}

export function createAuthSession(dependencies: AuthSessionDependencies): AuthSession {
  let accessToken: string | null = null
  let refreshPromise: Promise<void> | null = null
  let bootstrapPromise: Promise<void> | null = null
  const channel = dependencies.createBroadcastChannel?.(AUTH_CHANNEL)

  channel?.addEventListener('message', (event: MessageEvent<unknown>) => {
    if (event.data === SESSION_ENDED_EVENT || (isRecord(event.data) && event.data.type === SESSION_ENDED_EVENT)) {
      clearToken()
      dependencies.currentUser.setAnonymous()
    }
  })

  async function login(values: LoginValues, options: LoginOptions = {}): Promise<void> {
    const response = await dependencies.api<LoginResponse>(LOGIN_ENDPOINT, {
      method: 'POST',
      body: {
        email: values.email,
        password: values.password
      } satisfies LoginRequest
    })

    accessToken = response.accessToken
    dependencies.currentUser.setAuthenticated({
      id: response.user.id,
      name: response.user.name,
      email: response.user.email,
      isActive: response.user.isActive,
      createdAt: ''
    })
    bootstrapPromise = Promise.resolve()
    await dependencies.navigateTo?.(safeRedirectPath(options.redirectTo))
  }

  async function bootstrap(): Promise<void> {
    if (bootstrapPromise) {
      return bootstrapPromise
    }

    bootstrapPromise = runBootstrap()
    return bootstrapPromise
  }

  async function retryBootstrap(): Promise<void> {
    bootstrapPromise = null
    return bootstrap()
  }

  async function runBootstrap(): Promise<void> {
    try {
      await refresh()
      const user = await authenticatedMe()
      dependencies.currentUser.setAuthenticated(user)
    }
    catch (error: unknown) {
      const normalized = error instanceof ApiClientError
        ? error
        : new ApiClientError({
            kind: 'unknown',
            code: 'UNEXPECTED_CLIENT_ERROR',
            message: 'The session could not be checked.'
          })

      clearToken()

      if (normalized.status === 401 || normalized.code === 'ACCOUNT_INACTIVE') {
        dependencies.currentUser.setAnonymous()
        return
      }

      dependencies.currentUser.setError(normalized)
    }
  }

  async function protectedRequest<T>(path: string, options: ApiRequestOptions = {}): Promise<T> {
    if (!accessToken) {
      await bootstrap()
    }

    if (!accessToken) {
      throw new ApiClientError({
        kind: 'api',
        status: 401,
        code: 'AUTHENTICATION_REQUIRED',
        message: 'Authentication is required.'
      })
    }

    try {
      return await dependencies.api<T>(path, { ...options, accessToken })
    }
    catch (error: unknown) {
      const normalized = error instanceof ApiClientError ? error : null
      if (normalized?.status !== 401) {
        throw error
      }
    }

    await refresh()

    if (!accessToken) {
      endSession()
      throw new ApiClientError({
        kind: 'api',
        status: 401,
        code: 'AUTHENTICATION_REQUIRED',
        message: 'Authentication is required.'
      })
    }

    try {
      return await dependencies.api<T>(path, { ...options, accessToken })
    }
    catch (error: unknown) {
      const normalized = error instanceof ApiClientError ? error : null
      if (normalized?.status === 401) {
        endSession()
      }
      throw error
    }
  }

  async function authenticatedMe(): Promise<CurrentUser> {
    if (!accessToken) {
      throw new ApiClientError({
        kind: 'api',
        status: 401,
        code: 'AUTHENTICATION_REQUIRED',
        message: 'Authentication is required.'
      })
    }

    return await dependencies.api<CurrentUser>(ME_ENDPOINT, {
      method: 'GET',
      accessToken
    })
  }

  async function refresh(): Promise<void> {
    if (refreshPromise) {
      return refreshPromise
    }

    refreshPromise = coordinatedRefresh().finally(() => {
      refreshPromise = null
    })

    return refreshPromise
  }

  async function coordinatedRefresh(): Promise<void> {
    const locks = dependencies.navigator?.locks

    if (!locks) {
      throw new ApiClientError({
        kind: 'unknown',
        code: 'WEB_LOCKS_UNAVAILABLE',
        message: 'Session refresh cannot be coordinated in this browser.'
      })
    }

    await locks.request(AUTH_REFRESH_LOCK, async () => {
      const csrfToken = readCsrfCookie(dependencies.document)
      const response = await dependencies.api<RefreshResponse>(REFRESH_ENDPOINT, {
        method: 'POST',
        ...(csrfToken
          ? {
              headers: {
                'X-CSRF-Token': csrfToken
              }
            }
          : {})
      })
      accessToken = response.accessToken
    })
  }

  function endSession(): void {
    clearToken()
    dependencies.currentUser.setAnonymous()
    channel?.postMessage({ type: SESSION_ENDED_EVENT })
  }

  function clearToken(): void {
    accessToken = null
  }

  return {
    bootstrap,
    retryBootstrap,
    login,
    protectedRequest
  }
}

function isRecord(value: unknown): value is Record<string, unknown> {
  return typeof value === 'object' && value !== null
}
