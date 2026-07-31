import type { ApiErrorDetails, ApiErrorEnvelope, ApiViolation } from '../types/api'

export type ApiClientErrorKind = 'api' | 'http' | 'network' | 'timeout' | 'aborted' | 'unknown'
export type FieldErrorMap = Record<string, string[]>

const HTTP_MESSAGES: Record<number, string> = {
  400: 'The request could not be understood.',
  401: 'Authentication is required.',
  403: 'You do not have permission to perform this action.',
  404: 'The requested resource was not found.',
  405: 'This action is not supported.',
  409: 'The request conflicts with the current state.',
  410: 'The requested link is no longer available.',
  415: 'The request format is not supported.',
  422: 'Please correct the highlighted fields.',
  428: 'The resource must be refreshed before changing it.',
  429: 'Too many requests. Please try again later.',
  500: 'The service encountered an unexpected error.',
  502: 'The service is temporarily unavailable.',
  503: 'The service is temporarily unavailable.',
  504: 'The service did not respond in time.'
}

export class ApiClientError extends Error {
  readonly kind: ApiClientErrorKind
  readonly status: number | null
  readonly code: string
  readonly requestId: string | null
  readonly details: ApiErrorDetails | null
  readonly violations: readonly ApiViolation[]
  readonly fieldErrors: FieldErrorMap

  constructor(options: {
    kind: ApiClientErrorKind
    status?: number | null
    code: string
    message: string
    requestId?: string | null
    details?: ApiErrorDetails | null
    violations?: readonly ApiViolation[]
  }) {
    super(options.message)
    this.name = 'ApiClientError'
    this.kind = options.kind
    this.status = options.status ?? null
    this.code = options.code
    this.requestId = options.requestId ?? null
    this.details = options.details ?? null
    this.violations = options.violations ?? []
    this.fieldErrors = groupViolations(this.violations)
  }

  get isCanceled(): boolean {
    return this.kind === 'aborted'
  }
}

export function toApiClientError(error: unknown): ApiClientError {
  if (error instanceof ApiClientError) {
    return error
  }

  if (hasErrorName(error, 'TimeoutError') || hasCauseName(error, 'TimeoutError')) {
    return new ApiClientError({
      kind: 'timeout',
      code: 'REQUEST_TIMEOUT',
      message: 'The request timed out. Please try again.'
    })
  }

  if (hasErrorName(error, 'AbortError') || hasCauseName(error, 'AbortError')) {
    return new ApiClientError({
      kind: 'aborted',
      code: 'REQUEST_ABORTED',
      message: 'The request was canceled.'
    })
  }

  const record = asRecord(error)
  const response = asRecord(record?.response)
  const status = readStatus(record, response)
  const envelope = readEnvelope(record?.data ?? response?._data)
  const headerRequestId = readRequestIdHeader(response?.headers)

  if (envelope) {
    const violations = readViolations(envelope.error.details)

    return new ApiClientError({
      kind: 'api',
      status,
      code: envelope.error.code,
      message: envelope.error.message,
      requestId: envelope.error.requestId ?? headerRequestId,
      details: envelope.error.details ?? null,
      violations
    })
  }

  if (status !== null) {
    return new ApiClientError({
      kind: 'http',
      status,
      code: 'HTTP_ERROR',
      message: HTTP_MESSAGES[status] ?? 'The service could not complete the request.',
      requestId: headerRequestId
    })
  }

  if (record) {
    return new ApiClientError({
      kind: 'network',
      code: 'NETWORK_ERROR',
      message: 'Unable to reach the service. Check your connection and try again.'
    })
  }

  return new ApiClientError({
    kind: 'unknown',
    code: 'UNEXPECTED_CLIENT_ERROR',
    message: 'The request could not be completed.'
  })
}

function groupViolations(violations: readonly ApiViolation[]): FieldErrorMap {
  return violations.reduce<FieldErrorMap>((grouped, violation) => {
    (grouped[violation.field] ??= []).push(violation.message)

    return grouped
  }, {})
}

function readEnvelope(value: unknown): ApiErrorEnvelope | null {
  const envelope = asRecord(value)
  const payload = asRecord(envelope?.error)

  if (!payload || typeof payload.code !== 'string' || typeof payload.message !== 'string') {
    return null
  }

  const details = asRecord(payload.details)

  return {
    error: {
      code: payload.code,
      message: payload.message,
      ...(details ? { details: details as ApiErrorDetails } : {}),
      ...(typeof payload.requestId === 'string' ? { requestId: payload.requestId } : {})
    }
  }
}

function readViolations(details: ApiErrorDetails | undefined): ApiViolation[] {
  if (!Array.isArray(details?.violations)) {
    return []
  }

  return details.violations.filter((violation): violation is ApiViolation => {
    const value = asRecord(violation)

    return value !== null
      && typeof value.field === 'string'
      && typeof value.code === 'string'
      && typeof value.message === 'string'
  })
}

function readStatus(error: Record<string, unknown> | null, response: Record<string, unknown> | null): number | null {
  const candidate = response?.status ?? error?.status ?? error?.statusCode

  return typeof candidate === 'number' ? candidate : null
}

function readRequestIdHeader(headers: unknown): string | null {
  if (headers && typeof (headers as Headers).get === 'function') {
    const value = (headers as Headers).get('x-request-id')
    return value || null
  }

  return null
}

function hasErrorName(error: unknown, expected: string): boolean {
  return asRecord(error)?.name === expected
}

function hasCauseName(error: unknown, expected: string): boolean {
  return asRecord(asRecord(error)?.cause)?.name === expected
}

function asRecord(value: unknown): Record<string, unknown> | null {
  return typeof value === 'object' && value !== null
    ? value as Record<string, unknown>
    : null
}
