import type { FetchOptions } from 'ofetch'

export type Uuid = string
export type UtcDateTime = string

export interface ApiViolation {
  field: string
  code: string
  message: string
}

export interface ApiErrorDetails {
  violations?: ApiViolation[]
  [key: string]: unknown
}

export interface ApiErrorPayload {
  code: string
  message: string
  details?: ApiErrorDetails
  requestId?: string
}

export interface ApiErrorEnvelope {
  error: ApiErrorPayload
}

export interface CurrentUser {
  id: Uuid
  name: string
  email: string
  isActive: boolean
  createdAt: UtcDateTime
}

export interface HealthResponse {
  status: string
  service: string
}

export interface RegisterRequest {
  name: string
  email: string
  password: string
}

export interface RegisteredUser {
  id: Uuid
  name: string
  email: string
  isActive: false
  createdAt: UtcDateTime
}

export interface RegisterResponse {
  user: RegisteredUser
  activationRequired: true
}

export interface ActivateRequest {
  token: string
}

export interface ActivationRequestRequest {
  email: string
  password: string
}

export interface ActivationRequestResponse {
  status: 'activation_email_scheduled'
}

export interface LoginRequest {
  email: string
  password: string
}

export interface LoginResponse {
  accessToken: string
  expiresIn: 900
  user: {
    id: Uuid
    name: string
    email: string
    isActive: true
  }
}

export interface RefreshResponse {
  accessToken: string
  expiresIn: 900
}

export interface ApiRequestOptions extends Omit<FetchOptions<'json'>, 'baseURL' | 'credentials'> {
  accessToken?: string | null
}

export type ApiClient = <T>(path: string, options?: ApiRequestOptions) => Promise<T>
