import assert from 'node:assert/strict'
import test from 'node:test'
import {
  AUTH_REFRESH_LOCK,
  createAuthSession,
  LOGIN_ENDPOINT,
  LOGOUT_ENDPOINT,
  LOGOUT_ALL_ENDPOINT,
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
  assert.equal(calls.filter(call => call[0] === 'message').length, 0)
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

function logoutHarness(apiOverride, options = {}) {
  const calls = []
  const messages = []
  const user = currentUserState()
  const storage = storageStub()
  const document = { cookie: '__Host-equo_csrf=csrf-latest' }
  let listener
  const auth = createAuthSession({
    currentUser: user,
    document,
    sessionStorage: storage,
    navigator: { locks: locks(calls) },
    navigateTo: path => calls.push(['navigate', path]),
    createBroadcastChannel: () => ({
      postMessage: message => messages.push(message),
      addEventListener(_type, callback) { listener = callback },
      close() {}
    }),
    api: async (path, request = {}) => {
      calls.push(['api', path, request])
      if (path === LOGIN_ENDPOINT) {
        return { accessToken: 'access.login', user: { id: 'u1', name: 'User', email: 'u@example.test', isActive: true } }
      }
      return await apiOverride(path, request)
    },
    ...options
  })
  return { auth, calls, messages, user, storage, document, receive: message => listener({ data: message }) }
}

test('current logout shares refresh lock, reads csrf after lock, sends no Bearer and only publishes confirmed 204', async () => {
  const harness = logoutHarness(async (path, options) => {
    assert.equal(path, LOGOUT_ENDPOINT)
    assert.equal(options.accessToken, undefined)
    assert.deepEqual(options.headers, { 'X-CSRF-Token': 'csrf-latest' })
    options.onResponse({ response: { status: 204 } })
  })
  await harness.auth.login({ email: 'u@example.test', password: 'password' })
  await harness.auth.logout()
  assert.equal(harness.user.state.value.status, 'anonymous')
  assert.deepEqual(harness.messages, [{ type: SESSION_ENDED_EVENT }])
  assert.ok(harness.calls.some(call => call[0] === 'lock' && call[1] === AUTH_REFRESH_LOCK))
  assert.deepEqual(harness.calls.at(-1), ['navigate', '/login?signedOut=1'])
  assert.equal(harness.storage.getItem('equo:signed-out'), 'true')
  const requestCount = harness.calls.length
  await harness.auth.bootstrap()
  await harness.auth.retryBootstrap()
  await assert.rejects(harness.auth.protectedRequest('/v1/me'), ApiClientError)
  assert.equal(harness.calls.length, requestCount)
})

test('failed current logout clears local state, suppresses restoration and requires explicit retry without success event', async () => {
  for (const error of [apiError(403, 'FORBIDDEN'), apiError(500, 'INTERNAL_SERVER_ERROR'), new Error('offline')]) {
    let fail = true
    const harness = logoutHarness(async () => {
      if (fail) {
        throw error
      }
    })
    await harness.auth.login({ email: 'u@example.test', password: 'password' })
    await assert.rejects(harness.auth.logout(), { code: 'LOGOUT_UNCONFIRMED' })
    assert.equal(harness.user.state.value.status, 'anonymous')
    assert.deepEqual(harness.messages, [])
    assert.deepEqual(harness.calls.at(-1), ['navigate', '/logout?scope=current'])
    await harness.auth.bootstrap()
    assert.equal(harness.calls.filter(call => call[1] === REFRESH_ENDPOINT).length, 0)
    fail = false
    await harness.auth.logout()
    assert.deepEqual(harness.messages, [{ type: SESSION_ENDED_EVENT }])
  }
})

test('unexpected successful HTTP status does not claim confirmed server logout', async () => {
  const harness = logoutHarness(async (_path, options) => options.onResponse({ response: { status: 200 } }))
  await assert.rejects(harness.auth.logout(), { code: 'LOGOUT_UNCONFIRMED' })
  assert.deepEqual(harness.messages, [])
})

test('logout-all uses one Bearer refresh and one retry, including an explicit retry after local token clearing', async () => {
  for (const failsTwice of [false, true]) {
    let attempts = 0
    const harness = logoutHarness(async (path, options) => {
      if (path === REFRESH_ENDPOINT) {
        return { accessToken: 'access.refreshed', expiresIn: 900 }
      }
      assert.equal(path, LOGOUT_ALL_ENDPOINT)
      assert.equal(options.accessToken, attempts === 0 ? 'access.login' : 'access.refreshed')
      if (++attempts === 1 || failsTwice) {
        throw apiError(401, 'AUTHENTICATION_REQUIRED')
      }
    })
    await harness.auth.login({ email: 'u@example.test', password: 'password' })
    if (failsTwice) {
      await assert.rejects(harness.auth.logoutAll(), { code: 'LOGOUT_UNCONFIRMED' })
      assert.deepEqual(harness.messages, [])
    }
    else {
      await harness.auth.logoutAll()
      assert.deepEqual(harness.messages, [{ type: SESSION_ENDED_EVENT }])
    }
    assert.equal(attempts, 2)
    assert.equal(harness.calls.filter(call => call[1] === REFRESH_ENDPOINT).length, 1)
    assert.equal(harness.user.state.value.status, 'anonymous')
  }
})

test('failed logout-all refresh never retries revoke or broadcasts success', async () => {
  const harness = logoutHarness(async (path) => {
    throw apiError(401, path === REFRESH_ENDPOINT ? 'INVALID_REFRESH_TOKEN' : 'AUTHENTICATION_REQUIRED')
  })
  await harness.auth.login({ email: 'u@example.test', password: 'password' })
  await assert.rejects(harness.auth.logoutAll(), { code: 'LOGOUT_UNCONFIRMED' })
  assert.equal(harness.calls.filter(call => call[1] === LOGOUT_ALL_ENDPOINT).length, 1)
  assert.equal(harness.calls.filter(call => call[1] === REFRESH_ENDPOINT).length, 1)
  assert.deepEqual(harness.messages, [])
})

test('late refresh response cannot resurrect auth after logout or session-ended in another tab', async () => {
  for (const viaMessage of [false, true]) {
    let release
    let refreshStarted
    const started = new Promise((resolve) => {
      refreshStarted = resolve
    })
    const harness = logoutHarness(async (path) => {
      if (path === REFRESH_ENDPOINT) {
        refreshStarted()
        return await new Promise((resolve) => {
          release = resolve
        })
      }
      if (path === LOGOUT_ENDPOINT) {
        return
      }
      throw new Error('Late bootstrap must not call me')
    })
    const bootstrap = harness.auth.bootstrap()
    await started
    if (viaMessage) {
      harness.receive({ type: SESSION_ENDED_EVENT })
    }
    else {
      await harness.auth.logout()
    }
    release({ accessToken: 'access.late', expiresIn: 900 })
    await bootstrap
    assert.equal(harness.user.state.value.status, 'anonymous')
    await assert.rejects(harness.auth.protectedRequest('/v1/me'), ApiClientError)
    assert.equal(harness.calls.filter(call => call[1] === ME_ENDPOINT).length, 0)
    assert.equal(harness.messages.length, viaMessage ? 0 : 1)
  }
})

test('late protected response is discarded and cannot trigger refresh after logout', async () => {
  let release
  const harness = logoutHarness(async (path) => {
    if (path === ME_ENDPOINT) {
      return await new Promise((resolve) => {
        release = resolve
      })
    }
  })
  await harness.auth.login({ email: 'u@example.test', password: 'password' })
  const me = harness.auth.protectedRequest(ME_ENDPOINT)
  await harness.auth.logout()
  release({ id: 'u1', name: 'Late User' })
  await assert.rejects(me, ApiClientError)
  assert.equal(harness.calls.filter(call => call[1] === REFRESH_ENDPOINT).length, 0)
})

test('reload suppression stores only a boolean marker and explicit login resumes normal auth', async () => {
  const storage = storageStub()
  storage.setItem('equo:signed-out', 'true')
  const harness = logoutHarness(async () => {
    throw new Error('No automatic request after reload')
  }, { sessionStorage: storage })
  await harness.auth.bootstrap()
  await harness.auth.retryBootstrap()
  assert.equal(harness.calls.length, 0)
  await harness.auth.login({ email: 'u@example.test', password: 'password' })
  assert.equal(storage.length, 0)
  assert.equal(harness.user.state.value.status, 'authenticated')
})

test('repeated logout calls share the pending action', async () => {
  let release
  const harness = logoutHarness(async () => await new Promise((resolve) => {
    release = resolve
  }))
  const first = harness.auth.logout()
  const second = harness.auth.logoutAll()
  assert.equal(first, second)
  release()
  await first
  assert.equal(harness.calls.filter(call => call[1] === LOGOUT_ENDPOINT).length, 1)
  assert.deepEqual(harness.messages, [{ type: SESSION_ENDED_EVENT }])
})

test('current logout reads csrf inside the lock after another tab rotates cookies', async () => {
  const document = { cookie: '__Host-equo_csrf=old' }
  const harness = logoutHarness(async (_path, options) => {
    assert.deepEqual(options.headers, { 'X-CSRF-Token': 'after-lock' })
  }, {
    document,
    navigator: { locks: {
      request: async (_name, callback) => {
        document.cookie = '__Host-equo_csrf=after-lock'
        return await callback()
      }
    } }
  })
  await harness.auth.logout()
})

test('logout-all never refreshes or broadcasts on transport, 403 or 500 failures', async () => {
  for (const error of [new Error('offline'), apiError(403, 'FORBIDDEN'), apiError(500, 'INTERNAL_SERVER_ERROR')]) {
    const harness = logoutHarness(async () => {
      throw error
    })
    await harness.auth.login({ email: 'u@example.test', password: 'password' })
    await assert.rejects(harness.auth.logoutAll(), { code: 'LOGOUT_UNCONFIRMED' })
    assert.equal(harness.calls.filter(call => call[1] === LOGOUT_ALL_ENDPOINT).length, 1)
    assert.equal(harness.calls.filter(call => call[1] === REFRESH_ENDPOINT).length, 0)
    assert.deepEqual(harness.messages, [])
  }
})

test('explicit retry of logout-all obtains one temporary Bearer without restoring local auth', async () => {
  let first = true
  const harness = logoutHarness(async (path, options) => {
    if (path === REFRESH_ENDPOINT) {
      assert.equal(harness.user.state.value.status, 'anonymous')
      return { accessToken: 'access.retry', expiresIn: 900 }
    }
    assert.equal(path, LOGOUT_ALL_ENDPOINT)
    if (first) {
      first = false
      throw apiError(500, 'INTERNAL_SERVER_ERROR')
    }
    assert.equal(options.accessToken, 'access.retry')
    assert.equal(harness.user.state.value.status, 'anonymous')
  })
  await harness.auth.login({ email: 'u@example.test', password: 'password' })
  await assert.rejects(harness.auth.logoutAll(), { code: 'LOGOUT_UNCONFIRMED' })
  await harness.auth.logoutAll()
  assert.equal(harness.calls.filter(call => call[1] === REFRESH_ENDPOINT).length, 1)
  assert.deepEqual(harness.messages, [{ type: SESSION_ENDED_EVENT }])
})
