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

export interface ApiRequestOptions extends Omit<FetchOptions<'json'>, 'baseURL' | 'credentials'> {
  accessToken?: string | null
}

export type ApiClient = <T>(path: string, options?: ApiRequestOptions) => Promise<T>
