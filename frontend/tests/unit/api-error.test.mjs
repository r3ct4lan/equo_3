import assert from 'node:assert/strict'
import test from 'node:test'
import { toApiClientError } from '../../app/utils/api-error.ts'

test('maps the public validation envelope and field violations', () => {
  const error = toApiClientError({
    response: {
      status: 422,
      _data: {
        error: {
          code: 'VALIDATION_ERROR',
          message: 'Request validation failed.',
          details: {
            violations: [
              { field: 'email', code: 'INVALID_EMAIL', message: 'Email has invalid format.' },
              { field: 'email', code: 'REQUIRED', message: 'Email is required.' }
            ]
          },
          requestId: '01J3M8N8CNQH9Y0G4SKY2GCG4A'
        }
      }
    }
  })

  assert.equal(error.kind, 'api')
  assert.equal(error.status, 422)
  assert.equal(error.code, 'VALIDATION_ERROR')
  assert.equal(error.requestId, '01J3M8N8CNQH9Y0G4SKY2GCG4A')
  assert.deepEqual(error.fieldErrors.email, [
    'Email has invalid format.',
    'Email is required.'
  ])
})

test('does not expose an HTML or proxy error response', () => {
  const error = toApiClientError({
    response: {
      status: 500,
      _data: '<html>internal proxy details</html>'
    }
  })

  assert.equal(error.kind, 'http')
  assert.equal(error.code, 'HTTP_ERROR')
  assert.equal(error.message, 'The service encountered an unexpected error.')
  assert.doesNotMatch(error.message, /proxy details/)
})

test('distinguishes cancellation from request failures', () => {
  const error = toApiClientError(new DOMException('Canceled by caller', 'AbortError'))

  assert.equal(error.kind, 'aborted')
  assert.equal(error.code, 'REQUEST_ABORTED')
  assert.equal(error.isCanceled, true)
})

test('distinguishes timeout from caller cancellation', () => {
  const error = toApiClientError(new DOMException('Timed out', 'TimeoutError'))

  assert.equal(error.kind, 'timeout')
  assert.equal(error.code, 'REQUEST_TIMEOUT')
  assert.equal(error.isCanceled, false)
})

test('maps a connection failure to a safe network error', () => {
  const error = toApiClientError(new TypeError('getaddrinfo ENOTFOUND private-host'))

  assert.equal(error.kind, 'network')
  assert.equal(error.code, 'NETWORK_ERROR')
  assert.doesNotMatch(error.message, /private-host/)
})
