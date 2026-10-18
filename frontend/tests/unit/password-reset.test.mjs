import assert from 'node:assert/strict'
import test from 'node:test'
import { createAuthSession, AUTH_REFRESH_LOCK, PASSWORD_RESET_ENDPOINT, SESSION_ENDED_EVENT } from '../../app/utils/auth-session.ts'
import { validateNewPassword, validateRecoveryEmail, passwordResetErrorPresentation, isTerminalRecoveryError } from '../../app/utils/password-reset.ts'
import { ApiClientError } from '../../app/utils/api-error.ts'

test('recovery uses shared unicode password policy and email validation', () => {
  assert.deepEqual(validateRecoveryEmail({ email: ' User@Example.test ' }), {})
  assert.ok(validateRecoveryEmail({ email: 'invalid' }).email)
  for (const password of ['', 'a'.repeat(7), '😀'.repeat(7), ' '.repeat(8), '😀'.repeat(129)]) {
    assert.ok(validateNewPassword({ newPassword: password }).newPassword)
  }
  for (const password of ['a'.repeat(8), '😀'.repeat(8), 'é'.repeat(128), '  ab😀d  ']) {
    assert.deepEqual(validateNewPassword({ newPassword: password }), {})
  }
})

test('lifecycle errors are terminal, quota can be retried later, technical errors are retryable', () => {
  for (const code of ['INVALID_TOKEN', 'TOKEN_EXPIRED', 'TOKEN_USED', 'TOKEN_INVALIDATED']) {
    assert.equal(isTerminalRecoveryError(code), true)
    assert.equal(passwordResetErrorPresentation({ code, status: 410, fieldErrors: {} }).retryable, false)
  }
  assert.equal(isTerminalRecoveryError('RATE_LIMIT_EXCEEDED'), false)
  assert.match(passwordResetErrorPresentation({ code: 'RATE_LIMIT_EXCEEDED', status: 429, retryAfterSeconds: 20, fieldErrors: {} }).message, /20/)
  assert.equal(passwordResetErrorPresentation({ code: 'NETWORK', status: null, fieldErrors: {} }).retryable, true)
})

function fixture(api, coordinated = true) {
  const events = []
  const values = new Map()
  const currentUser = {
    state: { value: { status: 'authenticated' } }, setAuthenticated() {}, setError() {},
    setAnonymous() {
      events.push('anonymous')
    }
  }
  const auth = createAuthSession({
    api, currentUser,
    navigateTo: path => events.push(path),
    sessionStorage: { getItem: key => values.get(key) ?? null, setItem: (key, value) => values.set(key, value), removeItem: key => values.delete(key) },
    createBroadcastChannel: () => ({ addEventListener() {}, close() {}, postMessage: message => events.push(message) }),
    ...(coordinated
      ? { navigator: { locks: {
          async request(name, callback) {
            events.push(name)
            return await callback()
          }
        } } }
      : {})
  })
  return { auth, events, values }
}

test('reset is coordinated, credential free locally, single flight, and ends auth only after exact 204', async () => {
  let complete
  let count = 0
  const { auth, events, values } = fixture(async (path, options) => {
    count++
    assert.equal(path, PASSWORD_RESET_ENDPOINT)
    assert.equal(options.accessToken, undefined)
    assert.deepEqual(options.body, { token: 'synthetic-capability', newPassword: 'New password!' })
    await new Promise((resolve) => {
      complete = resolve
    })
    options.onResponse({ response: { status: 204 } })
  })
  const pending = auth.resetPassword('synthetic-capability', 'New password!')
  assert.equal(auth.resetPassword('synthetic-capability', 'New password!'), pending)
  assert.deepEqual(events, [AUTH_REFRESH_LOCK])
  complete()
  await pending
  assert.equal(count, 1)
  assert.deepEqual(events, [AUTH_REFRESH_LOCK, 'anonymous', { type: SESSION_ENDED_EVENT }, '/login?signedOut=1&passwordReset=1'])
  assert.deepEqual([...values], [['equo:signed-out', 'true']])
  await assert.rejects(auth.protectedRequest('/v1/me'), error => error.code === 'AUTHENTICATION_REQUIRED')
})

test('reset failure or unexpected success cannot clear auth or publish a successful reset', async () => {
  for (const status of [200, 202, 500]) {
    const { auth, events, values } = fixture(async (_path, options) => {
      options.onResponse({ response: { status } })
      if (status >= 500) throw new ApiClientError({ kind: 'api', status, code: 'INTERNAL_SERVER_ERROR', message: 'Unavailable' })
    })
    await assert.rejects(auth.resetPassword('synthetic', 'New password!'))
    assert.deepEqual(events, [AUTH_REFRESH_LOCK])
    assert.equal(values.size, 0)
  }
})

test('public recovery is usable without web locks or a prior session', async () => {
  const { auth, events } = fixture(async (_path, options) => options.onResponse({ response: { status: 204 } }), false)
  await auth.resetPassword('synthetic', 'New password!')
  assert.deepEqual(events, ['anonymous', { type: SESSION_ENDED_EVENT }, '/login?signedOut=1&passwordReset=1'])
})
