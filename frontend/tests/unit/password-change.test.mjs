import assert from 'node:assert/strict'
import test from 'node:test'
import { createAuthSession, ACCOUNT_DEACTIVATE_ENDPOINT, AUTH_REFRESH_LOCK, PASSWORD_CHANGE_ENDPOINT, LOGIN_ENDPOINT, REFRESH_ENDPOINT, SESSION_ENDED_EVENT } from '../../app/utils/auth-session.ts'
import { ApiClientError } from '../../app/utils/api-error.ts'
import { validatePasswordChange, passwordChangeErrorPresentation } from '../../app/utils/password-change.ts'

const failure = (code, status = 401) => new ApiClientError({ kind: 'api', code, status, message: 'Safe error' })

async function fixture(changeApi, coordinated = true) {
  const calls = []
  const events = []
  const stored = new Map()
  let tail = Promise.resolve()
  const auth = createAuthSession({
    api: async (path, options) => {
      calls.push({ path, options })
      if (path === LOGIN_ENDPOINT) return { accessToken: 'initial-access', user: { id: 'user', name: 'Name', email: 'user@example.test', isActive: true } }
      return await changeApi(path, options)
    },
    currentUser: { state: { value: { status: 'authenticated' } }, setAuthenticated() {}, setError() {}, setAnonymous: () => events.push('anonymous') },
    navigateTo: path => events.push(path),
    document: { cookie: '__Host-equo_csrf=synthetic-csrf' },
    sessionStorage: { getItem: key => stored.get(key) ?? null, setItem: (key, value) => stored.set(key, value), removeItem: key => stored.delete(key) },
    createBroadcastChannel: () => ({ addEventListener() {}, close() {}, postMessage: message => events.push(message) }),
    ...(coordinated
      ? { navigator: { locks: { request: (name, callback) => {
          events.push(name)
          const current = tail.then(callback)
          tail = current.catch(() => undefined)
          return current
        } } } }
      : {})
  })
  await auth.login({ email: 'user@example.test', password: 'old' })
  calls.length = 0
  events.length = 0
  return { auth, calls, events, stored }
}

test('password change validates only required current and shared Unicode new policy', () => {
  assert.ok(validatePasswordChange({ currentPassword: '', newPassword: 'New password1!' }).currentPassword)
  for (const password of ['a'.repeat(7), '😀'.repeat(7), ' '.repeat(8), '😀'.repeat(129), '12345678', '1234567!', 'Password!', 'Password1 ']) {
    assert.ok(validatePasswordChange({ currentPassword: ' old ', newPassword: password }).newPassword)
  }
  for (const password of ['abcde1!x', `${'😀'.repeat(6)}я1`, `${'é'.repeat(126)}1!`, '  a1😀d  ', 'пароль١!']) {
    assert.deepEqual(validatePasswordChange({ currentPassword: ' old ', newPassword: password }), {})
  }
  assert.match(passwordChangeErrorPresentation({ code: 'INVALID_CREDENTIALS', status: 401, fieldErrors: {} }).title, /Current password/)
  assert.match(validatePasswordChange({ currentPassword: 'Same password1!', newPassword: 'Same password1!' }).newPassword[0], /different/)
  assert.match(passwordChangeErrorPresentation({ code: 'PASSWORD_UNCHANGED', status: 422, fieldErrors: {} }).message, /differ/)
  assert.equal(passwordChangeErrorPresentation({ code: 'NETWORK', status: null, fieldErrors: {} }).retryable, true)
})

test('change uses common lock, exact 204, single flight and token-free terminal cleanup', async () => {
  let complete
  const { auth, calls, events, stored } = await fixture(async (path, options) => {
    assert.equal(path, PASSWORD_CHANGE_ENDPOINT)
    assert.equal(options.accessToken, 'initial-access')
    assert.deepEqual(options.body, { currentPassword: ' old ', newPassword: '  New password1 😀  ' })
    await new Promise((resolve) => {
      complete = resolve
    })
    options.onResponse({ response: { status: 204 } })
  })
  const pending = auth.changePassword(' old ', '  New password1 😀  ')
  assert.equal(auth.changePassword('ignored', 'ignored'), pending)
  await Promise.resolve()
  assert.deepEqual(events, [AUTH_REFRESH_LOCK])
  await assert.rejects(auth.login({ email: 'user@example.test', password: 'old' }), error => error.code === 'AUTHENTICATION_REQUIRED')
  complete()
  await pending
  assert.equal(calls.length, 1)
  assert.deepEqual(events, [AUTH_REFRESH_LOCK, 'anonymous', { type: SESSION_ENDED_EVENT }, '/login?signedOut=1&passwordChanged=1'])
  assert.deepEqual([...stored], [['equo:signed-out', 'true']])
  await assert.rejects(auth.protectedRequest('/v1/me'), error => error.code === 'AUTHENTICATION_REQUIRED')
})

test('credential denial never refreshes, retries, clears auth or broadcasts', async () => {
  const { auth, calls, events } = await fixture(async () => {
    throw failure('INVALID_CREDENTIALS')
  })
  await assert.rejects(auth.changePassword('wrong', 'New password1!'), error => error.code === 'INVALID_CREDENTIALS')
  assert.deepEqual(calls.map(call => call.path), [PASSWORD_CHANGE_ENDPOINT])
  assert.deepEqual(events, [AUTH_REFRESH_LOCK])
})

test('Bearer denial refreshes once inside the lock and retries with latest bearer', async () => {
  let attempts = 0
  const { auth, calls, events } = await fixture(async (path, options) => {
    if (path === REFRESH_ENDPOINT) {
      assert.deepEqual(options.headers, { 'X-CSRF-Token': 'synthetic-csrf' })
      return { accessToken: 'replacement-access' }
    }
    if (attempts++ === 0) throw failure('AUTHENTICATION_REQUIRED')
    assert.equal(options.accessToken, 'replacement-access')
    options.onResponse({ response: { status: 204 } })
  })
  await auth.changePassword('old', 'New password1!')
  assert.deepEqual(calls.map(call => call.path), [PASSWORD_CHANGE_ENDPOINT, REFRESH_ENDPOINT, PASSWORD_CHANGE_ENDPOINT])
  assert.equal(events.filter(event => event === AUTH_REFRESH_LOCK).length, 1)
})

test('repeated authentication denial ends local auth without announcing successful change', async () => {
  const { auth, calls, events } = await fixture(async (path) => {
    if (path === REFRESH_ENDPOINT) return { accessToken: 'replacement-access' }
    throw failure('AUTHENTICATION_REQUIRED')
  })
  await assert.rejects(auth.changePassword('old', 'New password1!'))
  assert.equal(calls.length, 3)
  assert.deepEqual(events, [AUTH_REFRESH_LOCK, 'anonymous', '/login?signedOut=1'])
})

test('credential denial after refresh still preserves the authenticated session', async () => {
  let attempts = 0
  const { auth, events } = await fixture(async (path) => {
    if (path === REFRESH_ENDPOINT) return { accessToken: 'replacement-access' }
    throw failure(attempts++ === 0 ? 'AUTHENTICATION_REQUIRED' : 'INVALID_CREDENTIALS')
  })
  await assert.rejects(auth.changePassword('wrong', 'New password1!'), error => error.code === 'INVALID_CREDENTIALS')
  assert.deepEqual(events, [AUTH_REFRESH_LOCK])
})

test('technical failure and unexpected 2xx preserve auth and require explicit user retry', async () => {
  for (const status of [200, 202, 500]) {
    const { auth, calls, events, stored } = await fixture(async (_path, options) => {
      options.onResponse({ response: { status } })
      if (status === 500) throw failure('INTERNAL_SERVER_ERROR', status)
    })
    await assert.rejects(auth.changePassword('old', 'New password1!'))
    assert.equal(calls.length, 1)
    assert.deepEqual(events, [AUTH_REFRESH_LOCK])
    assert.equal(stored.size, 0)
  }
})

test('missing Web Locks cannot send the security command', async () => {
  const { auth, calls, events } = await fixture(async () => undefined, false)
  await assert.rejects(auth.changePassword('old', 'New password1!'), error => error.code === 'WEB_LOCKS_UNAVAILABLE')
  assert.deepEqual(calls, [])
  assert.deepEqual(events, [])
})

test('account deactivation uses the common lock, exact 204 and ends every local session', async () => {
  let complete
  const { auth, calls, events, stored } = await fixture(async (path, options) => {
    assert.equal(path, ACCOUNT_DEACTIVATE_ENDPOINT)
    assert.equal(options.accessToken, 'initial-access')
    assert.deepEqual(options.body, { currentPassword: ' current ' })
    await new Promise((resolve) => {
      complete = resolve
    })
    options.onResponse({ response: { status: 204 } })
  })
  const pending = auth.deactivateAccount(' current ')
  assert.equal(auth.deactivateAccount('ignored'), pending)
  await Promise.resolve()
  assert.deepEqual(events, [AUTH_REFRESH_LOCK])
  complete()
  await pending
  assert.equal(calls.length, 1)
  assert.deepEqual(events, [AUTH_REFRESH_LOCK, 'anonymous', { type: SESSION_ENDED_EVENT }, '/activate?deactivated=1'])
  assert.deepEqual([...stored], [['equo:signed-out', 'true']])
})

test('account deactivation credential denial preserves the active session', async () => {
  const { auth, calls, events, stored } = await fixture(async () => {
    throw failure('INVALID_CREDENTIALS')
  })
  await assert.rejects(auth.deactivateAccount('wrong'), error => error.code === 'INVALID_CREDENTIALS')
  assert.deepEqual(calls.map(call => call.path), [ACCOUNT_DEACTIVATE_ENDPOINT])
  assert.deepEqual(events, [AUTH_REFRESH_LOCK])
  assert.equal(stored.size, 0)
})

test('account deactivation refreshes an expired bearer once before applying', async () => {
  let attempts = 0
  const { auth, calls, events } = await fixture(async (path, options) => {
    if (path === REFRESH_ENDPOINT) return { accessToken: 'replacement-access' }
    if (attempts++ === 0) throw failure('AUTHENTICATION_REQUIRED')
    assert.equal(path, ACCOUNT_DEACTIVATE_ENDPOINT)
    assert.equal(options.accessToken, 'replacement-access')
    options.onResponse({ response: { status: 204 } })
  })
  await auth.deactivateAccount('current')
  assert.deepEqual(calls.map(call => call.path), [ACCOUNT_DEACTIVATE_ENDPOINT, REFRESH_ENDPOINT, ACCOUNT_DEACTIVATE_ENDPOINT])
  assert.deepEqual(events, [AUTH_REFRESH_LOCK, 'anonymous', { type: SESSION_ENDED_EVENT }, '/activate?deactivated=1'])
})
