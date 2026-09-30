import type { APIRequestContext, APIResponse, Browser, BrowserContext, Cookie, Page, Route } from '@playwright/test'
import { randomUUID } from 'node:crypto'
import { expect, test } from './fixtures'

interface MailpitSummary {
  ID: string
  To: Array<{ Address: string }>
}

interface MailpitMessage {
  HTML: string
  testMessageId: string
}

interface ActivationAcceptanceState {
  exists: boolean
  active: boolean
  tokenCount: number
  unfinishedTokenCount: number
  invalidatedTokenCount: number
  usedTokenCount: number
  outboxCount: number
}

const mailpitUrl = process.env.E2E_MAILPIT_URL ?? 'http://mailpit:8025'
const baseUrl = process.env.E2E_BASE_URL ?? 'https://nginx'
const testPassword = 'A2345678901!'
const wrongPassword = 'A2345678901?'
let sharedActiveEmail: string | null = null
let forwardedForCounter = 1

function uniqueEmail(prefix: string): string {
  return `${prefix}-${Date.now()}-${Math.random().toString(16).slice(2)}@example.test`
}

function nextForwardedFor(): string {
  forwardedForCounter = forwardedForCounter >= 220 ? 1 : forwardedForCounter + 1

  return `198.51.100.${forwardedForCounter}`
}

async function routeNextLoginFromUniqueIp(page: Page): Promise<void> {
  await page.route('**/api/v1/auth/login', async (route) => {
    await route.continue({
      headers: {
        ...route.request().headers(),
        'x-forwarded-for': nextForwardedFor()
      }
    })
  }, { times: 1 })
}

async function waitForNuxtHydration(page: Page): Promise<void> {
  await page.waitForFunction(() => {
    const root = document.querySelector('#__nuxt') as HTMLElement & {
      __vue_app__?: {
        config?: {
          globalProperties?: {
            $nuxt?: { isHydrating?: boolean }
          }
        }
      }
    } | null

    return root?.__vue_app__?.config?.globalProperties?.$nuxt?.isHydrating === false
  })
}

async function registration(page: Page, email: string): Promise<void> {
  await page.goto('/register')
  await waitForNuxtHydration(page)
  await page.getByLabel('Name').fill('E2E User')
  await page.getByLabel('Email').fill(email)
  await page.getByLabel('Password').fill(testPassword)
}

async function waitForMail(
  request: APIRequestContext,
  recipient: string,
  excludedMessageIds: ReadonlySet<string> = new Set()
): Promise<MailpitMessage> {
  let messageId: string | null = null

  await expect.poll(async () => {
    const response = await request.get(`${mailpitUrl}/api/v1/messages`)
    if (!response.ok()) {
      return false
    }

    const body = await response.json() as { messages: MailpitSummary[] }
    messageId = body.messages.find(message => !excludedMessageIds.has(message.ID)
      && message.To.some(to => to.Address === recipient))?.ID ?? null

    return messageId !== null
  }, {
    message: `activation email for ${recipient} was not handed to Mailpit`,
    timeout: 30_000
  }).toBe(true)

  const response = await request.get(`${mailpitUrl}/api/v1/message/${messageId}`)
  expect(response.ok()).toBe(true)

  const message = await response.json() as Omit<MailpitMessage, 'testMessageId'>

  return {
    ...message,
    testMessageId: messageId ?? ''
  }
}

function activationUrlFrom(message: MailpitMessage): string {
  const match = message.HTML.match(/href=["']([^"']*\/activate\?token=[^"']+)["']/u)

  if (!match?.[1]) {
    throw new Error('Activation email does not contain the expected link.')
  }

  const url = new URL(match[1].replaceAll('&amp;', '&'))
  expect(url.pathname).toBe('/activate')
  expect(url.searchParams.get('token')).toMatch(/^v\d+\.[A-Za-z0-9_-]{20,}$/u)

  return url.toString()
}

async function registerAndActivate(page: Page, request: APIRequestContext, email = uniqueEmail('auth')): Promise<string> {
  await registration(page, email)
  await page.getByRole('button', { name: 'Create account' }).click()
  await expect(page.getByRole('status')).toContainText('Check your email')

  const message = await waitForMail(request, email)
  const activationUrl = activationUrlFrom(message)
  const activationResponsePromise = page.waitForResponse(response =>
    new URL(response.url()).pathname === '/api/v1/auth/activate'
  )
  await followSecretUrl(page, activationUrl)
  const activationResponse = await activationResponsePromise

  expect(activationResponse.status()).toBe(204)
  await expect(page.getByRole('status')).toContainText('Your account is active')

  return email
}

async function registerInactiveUser(request: APIRequestContext, email = uniqueEmail('inactive')): Promise<string> {
  const response = await request.post(`${baseUrl}/api/v1/auth/register`, {
    headers: {
      'Content-Type': 'application/json',
      'Idempotency-Key': randomUUID(),
      'X-Forwarded-For': `198.51.100.${Math.floor(Math.random() * 200) + 1}`
    },
    data: {
      name: 'Inactive E2E User',
      email,
      password: testPassword
    }
  })

  expect(response.status()).toBe(201)

  return email
}

async function activationAcceptanceState(
  request: APIRequestContext,
  email: string
): Promise<ActivationAcceptanceState> {
  const response = await request.post(`${baseUrl}/api/v1/_test/acceptance/activation-resend-state`, {
    data: { email }
  })

  expect(response.ok(), 'e2e-only acceptance probe must be available').toBe(true)

  return await response.json() as ActivationAcceptanceState
}

async function submitActivationRequest(
  page: Page,
  email: string,
  password: string
): Promise<APIResponse> {
  await page.getByLabel('Email').fill(email)
  await page.getByLabel('Current password').fill(password)

  const responsePromise = page.waitForResponse(response =>
    new URL(response.url()).pathname === '/api/v1/auth/activation-requests'
  )
  await page.getByRole('button', { name: 'Request activation link' }).click()

  return await responsePromise
}

async function ensureActiveUser(page: Page, request: APIRequestContext): Promise<string> {
  if (sharedActiveEmail) {
    return sharedActiveEmail
  }

  sharedActiveEmail = await registerAndActivate(page, request, uniqueEmail('shared-auth'))

  return sharedActiveEmail
}

async function login(page: Page, email: string, redirect = '/me', expectProfile = true): Promise<string> {
  await page.goto(`/login?redirect=${encodeURIComponent(redirect)}`)
  await waitForNuxtHydration(page)
  await page.getByLabel('Email').fill(email)
  await page.getByLabel('Password').fill(testPassword)
  await routeNextLoginFromUniqueIp(page)

  const responsePromise = page.waitForResponse(response =>
    new URL(response.url()).pathname === '/api/v1/auth/login'
  )
  await page.getByRole('button', { name: 'Sign in' }).click()
  const response = await responsePromise

  expect(response.status()).toBe(200)
  const body = await response.json() as { accessToken: string }
  await expect(page).toHaveURL(new RegExp(`${redirect.replace('/', '\\/')}$`, 'u'))
  if (expectProfile) {
    await expect(page.getByText(email)).toBeVisible()
  }

  return body.accessToken
}

async function followSecretUrl(page: Page, url: string): Promise<void> {
  await page.evaluate((target) => {
    window.location.assign(target)
  }, url)
}

async function expectNoSessionOrStoredCapability(page: Page): Promise<void> {
  expect(await page.context().cookies()).toEqual([])
  const storage = await page.evaluate(() => ({
    local: Object.values(localStorage),
    session: Object.values(sessionStorage)
  }))
  expect(JSON.stringify(storage)).not.toMatch(/v\d+\.[A-Za-z0-9_-]{20,}/u)
}

async function expectNoPersistentSecrets(page: Page, secrets: string[]): Promise<void> {
  const result = await page.evaluate(async () => {
    const idbDatabases = 'indexedDB' in window && 'databases' in indexedDB
      ? await indexedDB.databases()
      : []
    const nuxtPayload = Array.from(document.scripts)
      .map(script => script.textContent ?? '')
      .join('\n')

    return {
      localStorage: JSON.stringify(localStorage),
      sessionStorage: JSON.stringify(sessionStorage),
      idb: JSON.stringify(idbDatabases),
      url: window.location.href,
      body: document.body.textContent ?? '',
      nuxtPayload,
      readableCookie: document.cookie
    }
  })

  for (const secret of secrets.filter(Boolean)) {
    expect(result.localStorage.includes(secret), 'localStorage must not contain auth secrets').toBe(false)
    expect(result.sessionStorage.includes(secret), 'sessionStorage must not contain auth secrets').toBe(false)
    expect(result.idb.includes(secret), 'IndexedDB metadata must not contain auth secrets').toBe(false)
    expect(result.url.includes(secret), 'URL must not contain auth secrets').toBe(false)
    expect(result.body.includes(secret), 'rendered text must not contain auth secrets').toBe(false)
    expect(result.nuxtPayload.includes(secret), 'Nuxt payload must not contain auth secrets').toBe(false)
  }

  expect(result.readableCookie.includes('equo_refresh='), 'refresh cookie must stay HttpOnly').toBe(false)
}

function requiredCookie(cookies: Cookie[], name: string): Cookie {
  const cookie = cookies.find(item => item.name === name)
  expect(Boolean(cookie), `${name} cookie must exist`).toBe(true)

  return cookie as Cookie
}

async function sessionCookies(context: BrowserContext): Promise<{ refresh: Cookie, csrf: Cookie }> {
  const cookies = await context.cookies(`${baseUrl}/api/v1/auth/refresh`)

  return {
    refresh: requiredCookie(cookies, 'equo_refresh'),
    csrf: requiredCookie(cookies, '__Host-equo_csrf')
  }
}

function expectSessionCookieAttributes(refresh: Cookie, csrf: Cookie): void {
  expect(refresh.httpOnly, 'refresh cookie must be HttpOnly').toBe(true)
  expect(refresh.secure, 'refresh cookie must be Secure').toBe(true)
  expect(refresh.sameSite, 'refresh cookie must be SameSite=Lax').toBe('Lax')
  expect(refresh.path).toBe('/api/v1/auth')
  expect(refresh.domain).toBe('nginx')
  expect(refresh.expires, 'refresh cookie must be persistent').toBeGreaterThan(Date.now() / 1000 + 29 * 24 * 60 * 60)

  expect(csrf.httpOnly, 'CSRF cookie must be readable for double submit').toBe(false)
  expect(csrf.secure, 'CSRF cookie must be Secure').toBe(true)
  expect(csrf.sameSite, 'CSRF cookie must be SameSite=Lax').toBe('Lax')
  expect(csrf.path).toBe('/')
  expect(csrf.domain).toBe('nginx')
}

async function expectStorageHasNoAuthMaterial(page: Page): Promise<void> {
  const storage = await page.evaluate(() => JSON.stringify({
    local: localStorage,
    session: sessionStorage
  }))
  expect(storage, 'persistent storage must not contain JWT').not.toMatch(/\beyJ[A-Za-z0-9_-]+\.[A-Za-z0-9_-]+\.[A-Za-z0-9_-]+\b/u)
  expect(storage, 'persistent storage must not contain refresh token').not.toMatch(/\brt\.[A-Za-z0-9_-]{20,}\b/u)
  expect(storage, 'persistent storage must not contain Authorization header').not.toMatch(/Authorization|Bearer/u)
}

async function newSecureContext(browser: Browser): Promise<BrowserContext> {
  return await browser.newContext({
    baseURL: baseUrl,
    ignoreHTTPSErrors: true
  })
}

async function safeRouteAbort(route: Route): Promise<void> {
  await route.abort('connectionfailed')
}

test('E2E-01 completes register, activate, login, reload and authenticated profile', async ({ page, request }) => {
  const email = uniqueEmail('happy')
  const requests: string[] = []
  page.on('request', (request) => {
    const path = new URL(request.url()).pathname
    if (path.startsWith('/api/')) {
      requests.push(`${request.method()} ${path}`)
    }
  })

  await registration(page, email)

  const registrationResponsePromise = page.waitForResponse(response =>
    new URL(response.url()).pathname === '/api/v1/auth/register'
  )
  await page.getByRole('button', { name: 'Create account' }).click()
  const registrationResponse = await registrationResponsePromise

  expect(registrationResponse.status()).toBe(201)
  expect(registrationResponse.headers()['content-type']).toContain('application/json')
  await expect(page.getByRole('status')).toContainText('Check your email')
  await expect(page.getByRole('status')).toContainText(email)
  await expectNoSessionOrStoredCapability(page)

  await page.reload()
  await expect(page.getByRole('button', { name: 'Create account' })).toBeVisible()

  const message = await waitForMail(request, email)
  const activationUrl = activationUrlFrom(message)
  expect(new URL(activationUrl).origin).toBe(baseUrl)
  const activationResponsePromise = page.waitForResponse(response =>
    new URL(response.url()).pathname === '/api/v1/auth/activate'
  )
  await followSecretUrl(page, activationUrl)
  const activationResponse = await activationResponsePromise

  expect(activationResponse.status()).toBe(204)
  await expect(page).toHaveURL(/\/activate$/u)
  await expect(page.getByRole('status')).toContainText('Your account is active')
  await expectNoSessionOrStoredCapability(page)
  sharedActiveEmail = email
  await page.goto('/me')
  await expect(page).toHaveURL(`${baseUrl}/login?redirect=/me`)

  const accessToken = await login(page, email)
  const cookiesAfterLogin = await sessionCookies(page.context())
  expectSessionCookieAttributes(cookiesAfterLogin.refresh, cookiesAfterLogin.csrf)
  await expectNoPersistentSecrets(page, [
    accessToken,
    cookiesAfterLogin.refresh.value,
    cookiesAfterLogin.csrf.value,
    testPassword
  ])
  await expectStorageHasNoAuthMaterial(page)
  await expect(page).toHaveURL(/\/me$/u)
  await expect(page.getByText('E2E User')).toBeVisible()
  await expect(page.getByText('Sign in')).toHaveCount(0)

  const requestCountBeforeReload = requests.length
  await page.reload()
  await expect(page).toHaveURL(/\/me$/u)
  await expect(page.getByText(email)).toBeVisible()
  await expect(page.getByText('Sign in')).toHaveCount(0)

  const reloadRequests = requests.slice(requestCountBeforeReload)
  expect(reloadRequests.includes('POST /api/v1/auth/refresh'), 'reload must bootstrap through refresh').toBe(true)
  expect(reloadRequests.includes('GET /api/v1/me'), 'reload must read current user after refresh').toBe(true)

  const cookiesAfterReload = await sessionCookies(page.context())
  expectSessionCookieAttributes(cookiesAfterReload.refresh, cookiesAfterReload.csrf)
  expect(cookiesAfterReload.refresh.value === cookiesAfterLogin.refresh.value, 'refresh cookie must rotate on reload').toBe(false)
  expect(cookiesAfterReload.csrf.value === cookiesAfterLogin.csrf.value, 'CSRF cookie must rotate on reload').toBe(false)
  expect(Math.abs(cookiesAfterReload.refresh.expires - cookiesAfterLogin.refresh.expires), 'refresh expiry must not be extended').toBeLessThanOrEqual(2)
  await expectNoPersistentSecrets(page, [
    accessToken,
    cookiesAfterLogin.refresh.value,
    cookiesAfterLogin.csrf.value,
    cookiesAfterReload.refresh.value,
    cookiesAfterReload.csrf.value,
    testPassword
  ])
})

test('E2E-02 shows field validation, permits correction and prevents double submit', async ({ page }) => {
  const email = uniqueEmail('validation')
  await page.goto('/register')
  await waitForNuxtHydration(page)

  await page.getByRole('button', { name: 'Create account' }).click()
  await expect(page.getByText('Enter your name.')).toBeVisible()
  await expect(page.getByText('Enter your email address.')).toBeVisible()
  await expect(page.getByText('Enter a password.')).toBeVisible()

  await page.getByLabel('Name').fill('Validation User')
  await page.getByLabel('Email').fill('not-an-email')
  await page.getByLabel('Password').fill('short')
  await page.getByRole('button', { name: 'Create account' }).click()
  await expect(page.getByText('Enter an email address in a valid format.')).toBeVisible()
  await expect(page.getByText('Use at least 12 characters.')).toBeVisible()

  await page.getByLabel('Email').fill(email)
  await page.getByLabel('Password').fill('A2345678901!')
  let requests = 0
  page.on('request', (request) => {
    if (new URL(request.url()).pathname === '/api/v1/auth/register') {
      ++requests
    }
  })
  const responsePromise = page.waitForResponse(response =>
    new URL(response.url()).pathname === '/api/v1/auth/register'
  )
  await page.getByRole('button', { name: 'Create account' }).evaluate((button: HTMLButtonElement) => {
    button.click()
    button.click()
  })
  const response = await responsePromise

  expect(response.status()).toBe(201)
  expect(requests).toBe(1)
  await expect(page.getByRole('status')).toContainText('Check your email')
})

test('E2E-03 rejects an unknown activation capability and stores no secret', async ({ page }) => {
  const invalidCapability = `v1.${'a'.repeat(43)}`
  await page.goto('/activate')
  const responsePromise = page.waitForResponse(response =>
    new URL(response.url()).pathname === '/api/v1/auth/activate'
  )
  await page.evaluate((token) => {
    history.replaceState(null, '', `/activate?token=${encodeURIComponent(token)}`)
    location.reload()
  }, invalidCapability)
  const response = await responsePromise

  expect(response.status()).toBe(400)
  await expect(page).toHaveURL(/\/activate$/u)
  await expect(page.getByText('Activation link is invalid')).toBeVisible()
  await expect(page.getByRole('button', { name: /resend/iu })).toHaveCount(0)
  expect(await page.locator('body').evaluate(element => (element as HTMLElement).innerText).then(text => text.includes(invalidCapability)), 'invalid activation token must not be rendered').toBe(false)
  await expectNoSessionOrStoredCapability(page)
})

test('E2E-04 safely retries one registration attempt after a network failure', async ({ page }) => {
  const email = uniqueEmail('retry')
  let calls = 0
  let firstKey: string | undefined
  let secondKey: string | undefined

  await page.route('**/api/v1/auth/register', async (route) => {
    ++calls
    const key = route.request().headers()['idempotency-key']

    if (1 === calls) {
      firstKey = key
      await route.abort('connectionfailed')
      return
    }

    secondKey = key
    await route.continue()
  })
  await registration(page, email)
  await page.getByRole('button', { name: 'Create account' }).click()

  await expect(page.getByText('We could not confirm your registration')).toBeVisible()
  const responsePromise = page.waitForResponse(response =>
    new URL(response.url()).pathname === '/api/v1/auth/register'
  )
  await page.getByRole('button', { name: 'Try the same attempt again' }).click()
  const response = await responsePromise

  expect(response.status()).toBe(201)
  expect(calls).toBe(2)
  expect(firstKey).toBeTruthy()
  expect(secondKey).toBe(firstKey)
  await expect(page.getByRole('status')).toContainText('Check your email')
})

test('E2E-05 redirects anonymous protected navigation and accepts only safe login redirects', async ({ browser, page, request }) => {
  const email = await ensureActiveUser(page, request)

  const anonymous = await newSecureContext(browser)
  const anonymousPage = await anonymous.newPage()
  const anonymousRefreshStatuses: number[] = []
  anonymousPage.on('response', (response) => {
    if (new URL(response.url()).pathname === '/api/v1/auth/refresh') {
      anonymousRefreshStatuses.push(response.status())
    }
  })
  await anonymousPage.goto(`${baseUrl}/me`)
  await expect(anonymousPage).toHaveURL(`${baseUrl}/login?redirect=/me`)
  expect(anonymousRefreshStatuses.includes(401), 'anonymous bootstrap must receive refresh 401').toBe(true)
  await anonymous.close()

  for (const redirect of [
    '//example.test',
    'https://example.test',
    encodeURIComponent('//example.test'),
    '/\\example'
  ]) {
    const context = await newSecureContext(browser)
    const loginPage = await context.newPage()
    await loginPage.goto(`${baseUrl}/login?redirect=${encodeURIComponent(redirect)}`)
    await waitForNuxtHydration(loginPage)
    await loginPage.getByLabel('Email').fill(email)
    await loginPage.getByLabel('Password').fill(testPassword)
    await routeNextLoginFromUniqueIp(loginPage)
    await loginPage.getByRole('button', { name: 'Sign in' }).click()
    await expect(loginPage).toHaveURL(`${baseUrl}/me`)
    await context.close()
  }
})

test('E2E-06 shows safe login errors and prevents duplicate login submit', async ({ browser, page, request }) => {
  const activeEmail = await ensureActiveUser(page, request)
  const inactiveEmail = await registerInactiveUser(request)

  await page.goto('/login')
  await waitForNuxtHydration(page)
  await page.getByLabel('Email').fill(activeEmail)
  await page.getByLabel('Password').fill(wrongPassword)
  await routeNextLoginFromUniqueIp(page)
  const wrongResponsePromise = page.waitForResponse(response =>
    new URL(response.url()).pathname === '/api/v1/auth/login'
  )
  await page.getByRole('button', { name: 'Sign in' }).click()
  const wrongResponse = await wrongResponsePromise
  expect(wrongResponse.status()).toBe(401)
  await expect(page.getByText('Email or password is incorrect')).toBeVisible()

  await page.getByLabel('Email').fill(uniqueEmail('unknown'))
  await page.getByLabel('Password').fill(wrongPassword)
  await routeNextLoginFromUniqueIp(page)
  const unknownResponsePromise = page.waitForResponse(response =>
    new URL(response.url()).pathname === '/api/v1/auth/login'
  )
  await page.getByRole('button', { name: 'Sign in' }).click()
  const unknownResponse = await unknownResponsePromise
  expect(unknownResponse.status()).toBe(401)
  await expect(page.getByText('Email or password is incorrect')).toBeVisible()

  await page.getByLabel('Email').fill(inactiveEmail)
  await page.getByLabel('Password').fill(testPassword)
  await routeNextLoginFromUniqueIp(page)
  const inactiveResponsePromise = page.waitForResponse(response =>
    new URL(response.url()).pathname === '/api/v1/auth/login'
  )
  await page.getByRole('button', { name: 'Sign in' }).click()
  const inactiveResponse = await inactiveResponsePromise
  expect(inactiveResponse.status()).toBe(403)
  await expect(page.getByText('Account is not active')).toBeVisible()

  let loginRequests = 0
  await page.route('**/api/v1/auth/login', async (route) => {
    ++loginRequests
    await route.continue({
      headers: {
        ...route.request().headers(),
        'x-forwarded-for': nextForwardedFor()
      }
    })
  })
  await page.getByLabel('Email').fill(activeEmail)
  await page.getByLabel('Password').fill(testPassword)
  await page.getByRole('button', { name: 'Sign in' }).evaluate((button: HTMLButtonElement) => {
    button.click()
    button.click()
  })
  await expect(page).toHaveURL(/\/me$/u)
  expect(loginRequests).toBe(1)
  expect(await page.locator('body').textContent().then(text => text?.includes(testPassword) ?? false), 'password must not be rendered').toBe(false)
  await expectStorageHasNoAuthMaterial(page)

  const technicalContext = await newSecureContext(browser)
  const technical = await technicalContext.newPage()
  await technical.route('**/api/v1/auth/login', safeRouteAbort)
  await technical.goto('/login')
  await waitForNuxtHydration(technical)
  await technical.getByLabel('Email').fill(activeEmail)
  await technical.getByLabel('Password').fill(testPassword)
  await technical.getByRole('button', { name: 'Sign in' }).click()
  await expect(technical.getByText('We could not sign you in')).toBeVisible()
  await expect(technical.getByRole('button', { name: 'Try again' })).toBeVisible()
  await technicalContext.close()
})

test('E2E-07 coordinates refresh across two tabs without persistent secrets', async ({ context, page, request }) => {
  const email = await ensureActiveUser(page, request)
  await context.addInitScript(() => {
    const originalLocks = navigator.locks
    const originalRequest = originalLocks.request.bind(originalLocks)
    const record = (event: string, name: string) => {
      const recorder = (window as typeof window & { recordLockEvent?: (value: string) => void }).recordLockEvent
      if (recorder) {
        recorder(`${event}:${name}`)
      }
    }

    Object.defineProperty(navigator, 'locks', {
      configurable: true,
      value: {
        ...originalLocks,
        request: async (name: string, callbackOrOptions: LockGrantedCallback | LockOptions, maybeCallback?: LockGrantedCallback) => {
          const callback = typeof callbackOrOptions === 'function' ? callbackOrOptions : maybeCallback
          const options = typeof callbackOrOptions === 'function' ? undefined : callbackOrOptions
          if (!callback) {
            throw new TypeError('Web Locks callback is required.')
          }

          record('waiting', name)
          const wrappedCallback = async (lock: Lock | null) => {
            record('entered', name)
            try {
              return await callback(lock)
            }
            finally {
              record('left', name)
            }
          }

          return options === undefined
            ? await originalRequest(name, wrappedCallback)
            : await originalRequest(name, options, wrappedCallback)
        }
      }
    })
  })

  await login(page, email)
  const second = await context.newPage()
  const events: string[] = []
  for (const trackedPage of [page, second]) {
    await trackedPage.exposeFunction('recordLockEvent', (event: string) => {
      events.push(event)
    })
  }

  const refreshStatuses: number[] = []
  context.on('response', (response) => {
    if (new URL(response.url()).pathname === '/api/v1/auth/refresh') {
      refreshStatuses.push(response.status())
    }
  })

  await second.goto('/me')
  await expect(second.getByText(email)).toBeVisible()
  await Promise.all([
    page.reload(),
    second.reload()
  ])
  await expect(page.getByText(email)).toBeVisible()
  await expect(second.getByText(email)).toBeVisible()

  expect(refreshStatuses.every(status => status === 200), 'tab refreshes must not hit replay failure').toBe(true)
  expect(events.filter(event => event === 'entered:equo:auth-refresh').length, 'tabs must enter the shared Web Lock').toBeGreaterThanOrEqual(2)
  await expectStorageHasNoAuthMaterial(page)
  await expectStorageHasNoAuthMaterial(second)
})

test('E2E-08 limits protected request refresh and retry budget', async ({ browser, page, request }) => {
  const email = await ensureActiveUser(page, request)
  let meCalls = 0
  let refreshSuccesses = 0
  await page.route('**/api/v1/me', async (route) => {
    ++meCalls
    if (meCalls === 1) {
      await route.fulfill({
        status: 401,
        contentType: 'application/json',
        body: JSON.stringify({ error: { code: 'AUTHENTICATION_REQUIRED', message: 'Authentication is required.' } })
      })
      return
    }
    await route.continue()
  })
  page.on('response', (response) => {
    if (new URL(response.url()).pathname === '/api/v1/auth/refresh' && response.status() === 200) {
      ++refreshSuccesses
    }
  })
  await login(page, email)
  await expect(page.getByText(email)).toBeVisible()
  expect(meCalls).toBe(2)
  expect(refreshSuccesses).toBe(1)

  const forbiddenContext = await newSecureContext(browser)
  const forbidden = await forbiddenContext.newPage()
  let forbiddenRefreshSuccesses = 0
  forbidden.on('response', (response) => {
    if (new URL(response.url()).pathname === '/api/v1/auth/refresh' && response.status() === 200) {
      ++forbiddenRefreshSuccesses
    }
  })
  await forbidden.route('**/api/v1/me', async (route) => {
    await route.fulfill({
      status: 403,
      contentType: 'application/json',
      body: JSON.stringify({ error: { code: 'FORBIDDEN', message: 'Forbidden.' } })
    })
  })
  await login(forbidden, email, '/me', false)
  await expect(forbidden.getByText('Forbidden.')).toBeVisible()
  expect(forbiddenRefreshSuccesses).toBe(0)
  await forbiddenContext.close()

  const networkContext = await newSecureContext(browser)
  const network = await networkContext.newPage()
  let networkRefreshSuccesses = 0
  network.on('response', (response) => {
    if (new URL(response.url()).pathname === '/api/v1/auth/refresh' && response.status() === 200) {
      ++networkRefreshSuccesses
    }
  })
  await network.route('**/api/v1/me', safeRouteAbort)
  await login(network, email, '/me', false)
  await expect(network.getByText(/Unable to reach the service|Network|unreachable/iu)).toBeVisible()
  expect(networkRefreshSuccesses).toBe(0)
  await networkContext.close()

  const repeatedContext = await newSecureContext(browser)
  const repeated = await repeatedContext.newPage()
  let repeatedRefreshSuccesses = 0
  repeated.on('response', (response) => {
    if (new URL(response.url()).pathname === '/api/v1/auth/refresh' && response.status() === 200) {
      ++repeatedRefreshSuccesses
    }
  })
  await repeated.route('**/api/v1/me', async (route) => {
    await route.fulfill({
      status: 401,
      contentType: 'application/json',
      body: JSON.stringify({ error: { code: 'AUTHENTICATION_REQUIRED', message: 'Authentication is required.' } })
    })
  })
  await login(repeated, email, '/me', false)
  await expect(repeated.getByRole('link', { name: 'Login' })).toBeVisible()
  await expect(repeated.getByText(email)).toHaveCount(0)
  expect(repeatedRefreshSuccesses).toBe(1)
  await repeatedContext.close()
})

test('E2E-09 rejects missing and invalid Bearer tokens even with refresh cookies', async ({ page, request }) => {
  const email = await ensureActiveUser(page, request)
  await login(page, email)

  const results = await page.evaluate(async () => {
    const missing = await fetch('/api/v1/me', { credentials: 'include' })
    const invalid = await fetch('/api/v1/me', {
      credentials: 'include',
      headers: {
        Authorization: 'Bearer invalid.access.token'
      }
    })

    return {
      missingStatus: missing.status,
      missingCode: (await missing.json()).error.code,
      invalidStatus: invalid.status,
      invalidCode: (await invalid.json()).error.code
    }
  })

  expect(results).toEqual({
    missingStatus: 401,
    missingCode: 'AUTHENTICATION_REQUIRED',
    invalidStatus: 401,
    invalidCode: 'AUTHENTICATION_REQUIRED'
  })
})

test('E2E-10 replaces an activation link and completes activation, login and reload', async ({ page, request }) => {
  const email = uniqueEmail('activation-resend')
  const consoleMessages: string[] = []
  page.on('console', message => consoleMessages.push(message.text()))

  await registration(page, email)
  const registrationResponsePromise = page.waitForResponse(response =>
    new URL(response.url()).pathname === '/api/v1/auth/register'
  )
  await page.getByRole('button', { name: 'Create account' }).click()
  expect((await registrationResponsePromise).status()).toBe(201)
  await expect(page.getByRole('status')).toContainText('Check your email')

  const firstEmail = await waitForMail(request, email)
  const firstActivationUrl = activationUrlFrom(firstEmail)
  const firstToken = new URL(firstActivationUrl).searchParams.get('token') ?? ''
  expect(firstToken).not.toBe('')
  expect(await activationAcceptanceState(request, email)).toEqual({
    exists: true,
    active: false,
    tokenCount: 1,
    unfinishedTokenCount: 1,
    invalidatedTokenCount: 0,
    usedTokenCount: 0,
    outboxCount: 1
  })

  const resendResponse = await submitActivationRequest(page, email, testPassword)
  expect(resendResponse.status()).toBe(202)
  expect(await resendResponse.json()).toEqual({ status: 'activation_email_scheduled' })
  const resendStatus = page.getByRole('status').filter({ hasText: 'Request received' })
  await expect(resendStatus).toContainText('If the details can be used for activation')
  await expect(resendStatus).not.toContainText(/account exists|password is correct|email was sent/iu)
  await expectNoSessionOrStoredCapability(page)

  const afterReplacement = await activationAcceptanceState(request, email)
  expect(afterReplacement).toEqual({
    exists: true,
    active: false,
    tokenCount: 2,
    unfinishedTokenCount: 1,
    invalidatedTokenCount: 1,
    usedTokenCount: 0,
    outboxCount: 2
  })

  const secondEmail = await waitForMail(request, email, new Set([firstEmail.testMessageId]))
  const secondActivationUrl = activationUrlFrom(secondEmail)
  const secondToken = new URL(secondActivationUrl).searchParams.get('token') ?? ''
  expect(secondToken).not.toBe('')
  expect(secondToken === firstToken, 'replacement activation token must be new').toBe(false)

  const oldActivationResponsePromise = page.waitForResponse(response =>
    new URL(response.url()).pathname === '/api/v1/auth/activate'
  )
  await followSecretUrl(page, firstActivationUrl)
  const oldActivationResponse = await oldActivationResponsePromise
  expect(oldActivationResponse.status()).toBe(410)
  expect((await oldActivationResponse.json() as { error: { code: string } }).error.code).toBe('TOKEN_INVALIDATED')
  await expect(page).toHaveURL(/\/activate$/u)
  await expect(page.getByText('Activation link was replaced')).toBeVisible()
  expect(await activationAcceptanceState(request, email)).toEqual(afterReplacement)

  await page.goto('/login')
  await waitForNuxtHydration(page)
  await page.getByLabel('Email').fill(email)
  await page.getByLabel('Password').fill(testPassword)
  await routeNextLoginFromUniqueIp(page)
  const inactiveLoginResponsePromise = page.waitForResponse(response =>
    new URL(response.url()).pathname === '/api/v1/auth/login'
  )
  await page.getByRole('button', { name: 'Sign in' }).click()
  const inactiveLoginResponse = await inactiveLoginResponsePromise
  expect(inactiveLoginResponse.status()).toBe(403)
  await expect(page.getByText('Account is not active')).toBeVisible()
  expect(await activationAcceptanceState(request, email)).toEqual(afterReplacement)

  const newActivationResponsePromise = page.waitForResponse(response =>
    new URL(response.url()).pathname === '/api/v1/auth/activate'
  )
  await followSecretUrl(page, secondActivationUrl)
  const newActivationResponse = await newActivationResponsePromise
  expect(newActivationResponse.status()).toBe(204)
  await expect(page).toHaveURL(/\/activate$/u)
  await expect(page.getByRole('status')).toContainText('Your account is active')

  expect(await activationAcceptanceState(request, email)).toEqual({
    exists: true,
    active: true,
    tokenCount: 2,
    unfinishedTokenCount: 0,
    invalidatedTokenCount: 1,
    usedTokenCount: 1,
    outboxCount: 2
  })

  const accessToken = await login(page, email)
  const cookies = await sessionCookies(page.context())
  await expectNoPersistentSecrets(page, [
    firstToken,
    secondToken,
    accessToken,
    cookies.refresh.value,
    cookies.csrf.value,
    testPassword
  ])
  await expect(page).toHaveURL(/\/me$/u)
  await expect(page.getByText(email)).toBeVisible()

  await page.reload()
  await expect(page).toHaveURL(/\/me$/u)
  await expect(page.getByText(email)).toBeVisible()

  for (const secret of [firstToken, secondToken, testPassword]) {
    expect(consoleMessages.some(message => message.includes(secret)), 'browser console must not contain secrets').toBe(false)
  }
})

test('E2E-11 renders the same neutral result for unknown and active accounts without writes', async ({ page, request }) => {
  const unknownEmail = uniqueEmail('unknown-activation-resend')
  const unknownBefore = await activationAcceptanceState(request, unknownEmail)
  await page.goto('/activate')
  await waitForNuxtHydration(page)

  const unknownResponse = await submitActivationRequest(page, unknownEmail, wrongPassword)
  expect(unknownResponse.status()).toBe(202)
  expect(await unknownResponse.json()).toEqual({ status: 'activation_email_scheduled' })
  const unknownStatus = await page.getByRole('status').innerText()
  expect(unknownStatus).toContain('If the details can be used for activation')
  expect(await activationAcceptanceState(request, unknownEmail)).toEqual(unknownBefore)
  await expectNoSessionOrStoredCapability(page)

  const activeEmail = await registerAndActivate(page, request, uniqueEmail('active-activation-resend'))
  const activeBefore = await activationAcceptanceState(request, activeEmail)
  expect(activeBefore.active).toBe(true)
  await page.goto('/activate')
  await waitForNuxtHydration(page)

  const activeResponse = await submitActivationRequest(page, activeEmail, testPassword)
  expect(activeResponse.status()).toBe(202)
  expect(await activeResponse.json()).toEqual({ status: 'activation_email_scheduled' })
  const activeStatus = await page.getByRole('status').innerText()
  expect(activeStatus).toBe(unknownStatus)
  expect(await activationAcceptanceState(request, activeEmail)).toEqual(activeBefore)
  await expectNoSessionOrStoredCapability(page)
})

test('E2E-12 presents activation request rate limiting without retry or persistence writes', async ({ page, request }) => {
  const email = await registerInactiveUser(request, uniqueEmail('limited-activation-resend'))
  const initialState = await activationAcceptanceState(request, email)
  expect(initialState).toEqual({
    exists: true,
    active: false,
    tokenCount: 1,
    unfinishedTokenCount: 1,
    invalidatedTokenCount: 0,
    usedTokenCount: 0,
    outboxCount: 1
  })

  let activationRequests = 0
  page.on('request', (browserRequest) => {
    if (new URL(browserRequest.url()).pathname === '/api/v1/auth/activation-requests') {
      ++activationRequests
    }
  })
  await page.goto('/activate')
  await waitForNuxtHydration(page)

  for (let requestNumber = 1; requestNumber <= 3; ++requestNumber) {
    const response = await submitActivationRequest(page, email, testPassword)
    expect(response.status()).toBe(202)
    await expect(page.getByRole('status')).toContainText('If the details can be used for activation')

    if (requestNumber < 3) {
      await page.getByRole('button', { name: 'Request another link' }).click()
    }
  }

  const stateAtLimit = await activationAcceptanceState(request, email)
  expect(stateAtLimit).toEqual({
    exists: true,
    active: false,
    tokenCount: 4,
    unfinishedTokenCount: 1,
    invalidatedTokenCount: 3,
    usedTokenCount: 0,
    outboxCount: 4
  })

  await page.getByRole('button', { name: 'Request another link' }).click()
  const rejectedResponse = await submitActivationRequest(page, email, testPassword)
  expect(rejectedResponse.status()).toBe(429)
  const rejectedBody = await rejectedResponse.json() as { error: { code: string } }
  expect(rejectedBody.error.code).toBe('RATE_LIMIT_EXCEEDED')
  const retryAfter = rejectedResponse.headers()['retry-after']
  expect(retryAfter).toMatch(/^\d+$/u)
  await expect(page.getByRole('alert').filter({ hasText: 'Please wait before trying again' }))
    .toContainText(`Try again in ${retryAfter} seconds.`)

  expect(activationRequests).toBe(4)
  expect(await activationAcceptanceState(request, email)).toEqual(stateAtLimit)
})
