import { test } from 'node:test'
import assert from 'node:assert/strict'
import { createAuthSession } from '../../app/utils/auth-session.ts'
import { readTelegramInitData } from '../../app/utils/telegram-launch.ts'
import { ApiClientError } from '../../app/utils/api-error.ts'

const telegramUser = { id: 'telegram-account', name: 'Telegram User', email: null, isActive: true, locale: 'en' }
const browserUser = { ...telegramUser, id: 'browser-account', email: 'browser@example.test' }
const launch = 'auth_date=1234567890&user=%7B%22id%22%3A4294967297%7D&hash=fixture'

function setup(telegramInitData, invoke) {
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
    }
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
