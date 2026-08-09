import assert from 'node:assert/strict'
import test from 'node:test'
import {
  AUTH_REFRESH_LOCK,
  createAuthSession,
  LOGIN_ENDPOINT,
  ME_ENDPOINT,
  readCsrfCookie,
  REFRESH_ENDPOINT,
  safeRedirectPath,
  SESSION_ENDED_EVENT,
  validateLogin
} from '../../app/utils/auth-session.ts'
import { ApiClientError } from '../../app/utils/api-error.ts'

function storageStub() {
  const values = new Map()

  return {
    get length() {
      return values.size
    },
    getItem: key => values.get(key) ?? null,
    setItem: (key, value) => values.set(key, value),
    removeItem: key => values.delete(key),
    clear: () => values.clear()
  }
}

globalThis.localStorage ??= storageStub()
globalThis.sessionStorage ??= storageStub()

function currentUserState() {
  const transitions = []
  const state = { value: { status: 'unknown' } }

  return {
    state,
    transitions,
    setAuthenticated(user) {
      state.value = { status: 'authenticated', user }
      transitions.push(['authenticated', user])
    },
    setAnonymous() {
      state.value = { status: 'anonymous' }
      transitions.push(['anonymous'])
    },
    setError(error) {
      state.value = { status: 'error', error: { code: error.code, message: error.message } }
      transitions.push(['error', error.code])
    }
  }
}

function apiError(status, code) {
  return new ApiClientError({
    kind: 'api',
    status,
    code,
    message: code
  })
}

function locks(calls) {
  return {
    async request(name, callback) {
      calls.push(['lock', name])
      return await callback()
    }
  }
}

test('validates login without applying registration password policy', () => {
  assert.deepEqual(validateLogin({ email: '', password: '' }), {
    email: ['Enter your email address.'],
    password: ['Enter your password.']
  })
  assert.deepEqual(validateLogin({ email: 'not-an-email', password: 'x' }), {
    email: ['Enter an email address in a valid format.']
  })
})

test('reads only the csrf cookie and rejects malformed encoded values', () => {
  assert.equal(readCsrfCookie({ cookie: 'equo_refresh=secret; __Host-equo_csrf=csrf.public' }), 'csrf.public')
  assert.equal(readCsrfCookie({ cookie: 'equo_refresh=secret' }), null)
  assert.equal(readCsrfCookie({ cookie: '__Host-equo_csrf=%E0%A4%A' }), null)
})

test('accepts only safe local redirect paths', () => {
  assert.equal(safeRedirectPath('/me'), '/me')
  assert.equal(safeRedirectPath('/me?tab=profile'), '/me?tab=profile')
  assert.equal(safeRedirectPath('//evil.test'), '/me')
  assert.equal(safeRedirectPath('https://evil.test/me'), '/me')
  assert.equal(safeRedirectPath('%2F%2Fevil.test'), '/me')
  assert.equal(safeRedirectPath('/\\evil'), '/me')
})

test('login stores token privately and never exposes it through current user or messages', async () => {
  const calls = []
  const user = currentUserState()
  const messages = []
  const auth = createAuthSession({
    currentUser: user,
    navigator: { locks: locks(calls) },
    document: { cookie: '__Host-equo_csrf=csrf' },
    createBroadcastChannel: () => ({
      postMessage: message => messages.push(message),
      addEventListener() {},
      close() {}
    }),
    navigateTo: path => calls.push(['navigate', path]),
    api: async (path, options = {}) => {
      calls.push(['api', path, options])
      assert.equal(localStorage.getItem('accessToken'), null)
      assert.equal(sessionStorage.getItem('accessToken'), null)

      return {
        accessToken: 'access.secret',
        expiresIn: 900,
        user: {
          id: 'u1',
          name: 'Login User',
          email: 'login@example.test',
          isActive: true
        }
      }
    }
  })

  assert.equal('accessToken' in auth, false)
  await auth.login({ email: 'login@example.test', password: 'password' }, { redirectTo: '/me' })

  assert.equal(calls[0][1], LOGIN_ENDPOINT)
  assert.equal(user.state.value.status, 'authenticated')
  assert.doesNotMatch(JSON.stringify(user.state.value), /access\.secret/u)
  assert.deepEqual(messages, [])
  assert.equal(localStorage.length, 0)
  assert.equal(sessionStorage.length, 0)
})

test('bootstrap refreshes under Web Lock then loads me once for concurrent callers', async () => {
  const calls = []
  const user = currentUserState()
  const auth = createAuthSession({
    currentUser: user,
    navigator: { locks: locks(calls) },
    document: { cookie: '__Host-equo_csrf=csrf-after-lock' },
    api: async (path, options = {}) => {
      calls.push(['api', path, options])
      if (path === REFRESH_ENDPOINT) {
        assert.equal(options.headers['X-CSRF-Token'], 'csrf-after-lock')
        return { accessToken: 'access.refreshed', expiresIn: 900 }
      }
      if (path === ME_ENDPOINT) {
        assert.equal(options.accessToken, 'access.refreshed')
        return { id: 'u1', name: 'Fresh User', email: 'fresh@example.test', isActive: true, createdAt: '2026-08-08T12:00:00Z' }
      }
      throw new Error('Unexpected path')
    }
  })

  await Promise.all([auth.bootstrap(), auth.bootstrap()])

  assert.deepEqual(calls.map(call => call[0] === 'lock' ? call : ['api', call[1]]), [
    ['lock', AUTH_REFRESH_LOCK],
    ['api', REFRESH_ENDPOINT],
    ['api', ME_ENDPOINT]
  ])
  assert.equal(user.state.value.status, 'authenticated')
})

test('bootstrap maps refresh auth, inactive and technical failures to distinct states', async () => {
  for (const [error, expected] of [
    [apiError(401, 'AUTHENTICATION_REQUIRED'), 'anonymous'],
    [apiError(403, 'ACCOUNT_INACTIVE'), 'anonymous'],
    [apiError(403, 'FORBIDDEN'), 'error'],
    [apiError(500, 'INTERNAL_SERVER_ERROR'), 'error']
  ]) {
    const user = currentUserState()
    const auth = createAuthSession({
      currentUser: user,
      navigator: { locks: locks([]) },
      document: { cookie: '__Host-equo_csrf=csrf' },
      api: async () => { throw error }
    })

    await auth.bootstrap()
    assert.equal(user.state.value.status, expected)
    await auth.retryBootstrap()
  }
})

test('missing Web Locks API fails safely without refresh request', async () => {
  const user = currentUserState()
  let requests = 0
  const auth = createAuthSession({
    currentUser: user,
    document: { cookie: '__Host-equo_csrf=csrf' },
    api: async () => {
      ++requests
      return {}
    }
  })

  await auth.bootstrap()

  assert.equal(requests, 0)
  assert.equal(user.state.value.status, 'error')
})

test('protected request uses one refresh and one retry budget', async () => {
  const calls = []
  const user = currentUserState()
  const auth = createAuthSession({
    currentUser: user,
    navigator: { locks: locks(calls) },
    document: { cookie: '__Host-equo_csrf=csrf' },
    api: async (path, options = {}) => {
      calls.push(['api', path, options])
      if (path === REFRESH_ENDPOINT) {
        return { accessToken: 'access.refreshed', expiresIn: 900 }
      }
      if (path === ME_ENDPOINT) {
        return { id: 'u1', name: 'Fresh User', email: 'fresh@example.test', isActive: true, createdAt: '2026-08-08T12:00:00Z' }
      }
      if (path === '/v1/protected') {
        throw apiError(401, 'AUTHENTICATION_REQUIRED')
      }
      throw new Error('Unexpected path')
    },
    createBroadcastChannel: () => ({
      postMessage: message => calls.push(['message', message]),
      addEventListener() {},
      close() {}
    })
  })

  await assert.rejects(() => auth.protectedRequest('/v1/protected'), ApiClientError)

  assert.equal(calls.filter(call => call[1] === REFRESH_ENDPOINT).length, 2)
  assert.equal(calls.filter(call => call[1] === '/v1/protected').length, 2)
  assert.equal(user.state.value.status, 'anonymous')
  assert.deepEqual(calls.at(-1), ['message', { type: SESSION_ENDED_EVENT }])
  assert.doesNotMatch(JSON.stringify(calls.filter(call => call[0] === 'message')), /access\.refreshed|csrf/u)
})

test('protected request does not refresh after non-401 failures', async () => {
  const calls = []
  const user = currentUserState()
  const auth = createAuthSession({
    currentUser: user,
    navigator: { locks: locks(calls) },
    document: { cookie: '__Host-equo_csrf=csrf' },
    api: async (path) => {
      calls.push(['api', path])
      if (path === REFRESH_ENDPOINT) {
        return { accessToken: 'access.refreshed', expiresIn: 900 }
      }
      if (path === ME_ENDPOINT) {
        return { id: 'u1', name: 'Fresh User', email: 'fresh@example.test', isActive: true, createdAt: '2026-08-08T12:00:00Z' }
      }
      throw apiError(403, 'FORBIDDEN')
    }
  })

  await assert.rejects(() => auth.protectedRequest('/v1/protected'), ApiClientError)

  assert.equal(calls.filter(call => call[1] === REFRESH_ENDPOINT).length, 1)
})

test('session-ended message clears local tab without propagating secrets', () => {
  const user = currentUserState()
  let listener
  createAuthSession({
    currentUser: user,
    api: async () => ({}),
    createBroadcastChannel: () => ({
      postMessage() {},
      addEventListener(_type, callback) {
        listener = callback
      },
      close() {}
    })
  })

  listener({ data: { type: SESSION_ENDED_EVENT } })
  assert.equal(user.state.value.status, 'anonymous')
})
