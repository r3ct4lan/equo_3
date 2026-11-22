import { test } from 'node:test'
import assert from 'node:assert/strict'
import { createAuthSession } from '../../app/utils/auth-session.ts'
import { readTelegramInitData } from '../../app/utils/telegram-launch.ts'
import { ApiClientError } from '../../app/utils/api-error.ts'

const telegramUser = { id: 'telegram-account', name: 'Telegram User', email: null, isActive: true, locale: 'en' }
const browserUser = { ...telegramUser, id: 'browser-account', email: 'browser@example.test' }
const launch = 'auth_date=1234567890&user=%7B%22id%22%3A4294967297%7D&hash=fixture'

function setup(telegramInitData, invoke, overrides = {}) {
  const requests = []
  const state = { value: { status: 'unknown' } }
  const auth = createAuthSession({
    telegramInitData,
    api: async (path, options) => {
      requests.push([path, options])
      return await invoke(path)
    },
    document: { cookie: '__Host-equo_csrf=browser-csrf' },
    navigator: { locks: { request: async (_name, action) => action() } },
    currentUser: {
      state,
      setAuthenticated: (user) => { state.value = { status: 'authenticated', user } },
      setAnonymous: () => { state.value = { status: 'anonymous' } },
      setError: (error) => { state.value = { status: 'error', error } }
    },
    ...overrides
  })
  return { auth, requests, state }
}

test('reads the signed payload as one opaque field and detects ambiguous launches', () => {
  assert.equal(readTelegramInitData('#section'), null)
  assert.equal(readTelegramInitData('#' + new URLSearchParams({ tgWebAppData: launch })), launch)
  assert.equal(readTelegramInitData('#tgWebAppData=one&tgWebAppData=two'), '')
})

test('Mini App authenticates with Telegram before making requests with a stored browser session', async () => {
  const { auth, requests, state } = setup(launch, (path) => {
    if (path === '/v1/auth/telegram/mini-app') {
      return { accessToken: 'telegram-access', expiresIn: 900, user: telegramUser }
    }
    return browserUser
  })

  await Promise.all([auth.bootstrap(), auth.bootstrap()])

  assert.equal(state.value.user.id, telegramUser.id)
  assert.deepEqual(requests, [['/v1/auth/telegram/mini-app', { method: 'POST', body: { initData: launch } }]])
})

test('an invalid Mini App launch remains in error state and can be retried explicitly', async () => {
  let attempt = 0
  const { auth, requests, state } = setup(launch, (path) => {
    if (path !== '/v1/auth/telegram/mini-app') throw new Error('unexpected browser authentication')
    if (attempt++ === 0) {
      throw new ApiClientError({ kind: 'api', status: 401, code: 'TELEGRAM_AUTHENTICATION_FAILED', message: 'Invalid launch.' })
    }
    return { accessToken: 'telegram-access', expiresIn: 900, user: telegramUser }
  })

  await auth.bootstrap()

  assert.equal(state.value.status, 'error')
  assert.equal(state.value.error.code, 'TELEGRAM_AUTHENTICATION_FAILED')
  await assert.rejects(auth.protectedRequest('/v1/me'))
  assert.equal(requests.length, 1)

  await auth.retryBootstrap()

  assert.equal(state.value.user.id, telegramUser.id)
  assert.equal(requests.length, 2)
})

test('opening the website still restores the existing browser session', async () => {
  const { auth, requests, state } = setup(undefined, path => path === '/v1/me'
    ? browserUser
    : { accessToken: 'browser-access', expiresIn: 900 })

  await auth.bootstrap()

  assert.equal(state.value.user.id, browserUser.id)
  assert.deepEqual(requests.map(([path]) => path), ['/v1/auth/refresh', '/v1/me'])
})

test('does not retry a Mini App request with a refresh belonging to a different account', async () => {
  const { auth, requests, state } = setup(launch, (path) => {
    if (path === '/v1/auth/telegram/mini-app') {
      return { accessToken: 'telegram-access', expiresIn: 900, user: telegramUser }
    }
    if (path === '/v1/auth/refresh') return { accessToken: 'browser-access', expiresIn: 900 }
    if (path === '/v1/me') return browserUser
    throw new ApiClientError({ kind: 'api', status: 401, code: 'AUTHENTICATION_REQUIRED', message: 'Session expired.' })
  })

  await auth.bootstrap()
  await assert.rejects(auth.protectedRequest('/v1/connects'))

  assert.equal(state.value.status, 'error')
  assert.equal(requests.filter(([path]) => path === '/v1/connects').length, 1)
})

test('all-device logout suppresses retries and reloads of the same launch, then a new launch can sign in', async () => {
  const stored = new Map()
  const sessionStorage = {
    setItem: (key, value) => stored.set(key, value),
    getItem: key => stored.get(key) ?? null,
    removeItem: key => stored.delete(key)
  }
  const invoke = path => path.endsWith('/mini-app') ? { accessToken: 'fixture', user: telegramUser } : undefined
  const current = setup(launch, invoke, { sessionStorage })
  await current.auth.bootstrap()
  await current.auth.logoutAll()
  await current.auth.retryBootstrap()
  assert.equal(current.state.value.status, 'anonymous')
  assert.deepEqual(current.requests.map(([path]) => path), ['/v1/auth/telegram/mini-app', '/v1/auth/logout-all'])

  const same = setup(launch, invoke, { sessionStorage })
  await same.auth.bootstrap()
  assert.equal(same.state.value.status, 'anonymous')
  assert.equal(same.requests.length, 0)

  const fresh = setup(launch.replace('1234567890', '1234567891'), invoke, { sessionStorage })
  await fresh.auth.bootstrap()
  assert.equal(fresh.state.value.user.id, telegramUser.id)
})

test('a successful explicit Mini App reactivation continues the verified invitation', async () => {
  const continuations = []
  const inactive = setup(launch, (path) => {
    if (path.endsWith('/mini-app/reactivate')) return { accessToken: 'fixture', user: telegramUser, startParam: 'invite_example' }
    throw new ApiClientError({ kind: 'api', status: 403, code: 'ACCOUNT_INACTIVE', message: 'Inactive.' })
  }, { continueTelegramLaunch: async (value) => { continuations.push(value) } })
  await inactive.auth.bootstrap()
  assert.equal(inactive.state.value.error.code, 'ACCOUNT_INACTIVE')
  assert.equal(continuations.length, 0)

  await inactive.auth.reactivateTelegram()
  assert.equal(inactive.state.value.user.id, telegramUser.id)
  assert.deepEqual(continuations, ['invite_example'])
})

test('rejecting an incorrect current password leaves the Mini App session signed in', async () => {
  const { auth, state, requests } = setup(launch, (path) => {
    if (path.endsWith('/mini-app')) return { accessToken: 'fixture', user: telegramUser }
    throw new ApiClientError({ kind: 'api', status: 401, code: 'INVALID_CREDENTIALS', message: 'Invalid credentials.' })
  })
  await auth.bootstrap()
  await assert.rejects(auth.protectedRequest('/v1/account/credentials/telegram/start', { method: 'POST' }), { code: 'INVALID_CREDENTIALS' })
  assert.equal(state.value.status, 'authenticated')
  assert.deepEqual(requests.map(([path]) => path), ['/v1/auth/telegram/mini-app', '/v1/account/credentials/telegram/start'])
})
