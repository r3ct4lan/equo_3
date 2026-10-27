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
const testPassword = 'A234567!'
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

async function routeNextRegistrationFromUniqueIp(page: Page): Promise<void> {
  await page.route('**/api/v1/auth/register', async (route) => {
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
    message: `action email for ${recipient} was not handed to Mailpit`,
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

async function createActiveUser(request: APIRequestContext, email = uniqueEmail('auth')): Promise<string> {
  await registerInactiveUser(request, email, 'E2E User')
  const activationUrl = new URL(activationUrlFrom(await waitForMail(request, email)))
  const token = activationUrl.searchParams.get('token')
  expect(token).not.toBeNull()
  const response = await request.post(`${baseUrl}/api/v1/auth/activate`, {
    headers: { 'X-Forwarded-For': nextForwardedFor() },
    data: { token }
  })
  expect(response.status()).toBe(200)
  return email
}

async function registerInactiveUser(request: APIRequestContext, email = uniqueEmail('inactive'), name = 'Inactive E2E User'): Promise<string> {
  const response = await request.post(`${baseUrl}/api/v1/auth/register`, {
    headers: {
      'Content-Type': 'application/json',
      'Idempotency-Key': randomUUID(),
      'X-Forwarded-For': nextForwardedFor()
    },
    data: {
      name,
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

async function ensureActiveUser(request: APIRequestContext): Promise<string> {
  if (sharedActiveEmail) {
    return sharedActiveEmail
  }
  sharedActiveEmail = await createActiveUser(request, uniqueEmail('shared-auth'))
  return sharedActiveEmail
}

async function login(page: Page, email: string, redirect = '/me', expectProfile = true, password = testPassword): Promise<string> {
  await page.goto(`/login?redirect=${encodeURIComponent(redirect)}`)
  await waitForNuxtHydration(page)
  await page.getByLabel('Email').fill(email)
  await page.getByLabel('Password').fill(password)
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

async function loginViaApi(request: APIRequestContext, email: string): Promise<string> {
  const response = await request.post(`${baseUrl}/api/v1/auth/login`, {
    headers: { 'X-Forwarded-For': nextForwardedFor() },
    data: { email, password: testPassword }
  })
  expect(response.status()).toBe(200)
  return (await response.json() as { accessToken: string }).accessToken
}

async function followSecretUrl(page: Page, url: string): Promise<void> {
  await page.evaluate((target) => {
    window.location.assign(target)
  }, url)
}

async function requestRecovery(page: Page, request: APIRequestContext, email: string, excluded = new Set<string>()): Promise<MailpitMessage> {
  await page.goto('/forgot-password')
  await waitForNuxtHydration(page)
  await page.getByLabel('Email', { exact: true }).fill(email)
  const response = page.waitForResponse(sent => new URL(sent.url()).pathname === '/api/v1/auth/password-reset-requests')
  await page.getByRole('button', { name: 'Send recovery link' }).click()
  expect((await response).status()).toBe(202)
  await expect(page.getByRole('status')).toContainText('If an account uses this address')
  return await waitForMail(request, email, excluded)
}

function recoveryUrlFrom(message: MailpitMessage): string {
  const match = message.HTML.match(/href=["']([^"']*\/password-reset\?token=[^"']+)["']/u)
  if (!match?.[1]) throw new Error('Recovery email has no recovery link.')
  const url = new URL(match[1].replaceAll('&amp;', '&'))
  expect(url.pathname).toBe('/password-reset')
  expect(/^v\d+\.[A-Za-z0-9_-]{43}$/u.test(url.searchParams.get('token') ?? '')).toBe(true)
  return url.toString()
}

function emailChangeUrlFrom(message: MailpitMessage): string {
  const match = message.HTML.match(/href=["']([^"']*\/email-change\?token=[^"']+)["']/u)
  if (!match?.[1]) throw new Error('Email change message has no confirmation link.')
  const url = new URL(match[1].replaceAll('&amp;', '&'))
  expect(url.pathname).toBe('/email-change')
  expect(/^v\d+\.[A-Za-z0-9_-]{43}$/u.test(url.searchParams.get('token') ?? '')).toBe(true)
  return url.toString()
}

async function submitRecovery(page: Page, url: string, password: string): Promise<APIResponse> {
  await followSecretUrl(page, url)
  await expect(page).toHaveURL(/\/password-reset$/u)
  await page.getByLabel('New password', { exact: true }).fill(password)
  const response = page.waitForResponse(sent => new URL(sent.url()).pathname === '/api/v1/auth/password-reset')
  await page.getByRole('button', { name: 'Save new password' }).click()
  return await response
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
  expect(storage, 'persistent storage must not contain refresh token').not.toMatch(/\brt(?:2\.[0-9a-f-]{36})?\.[A-Za-z0-9_-]{20,}\b/u)
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

test('single card actions stay bounded and readable', async ({ page, request }) => {
  const email = await createActiveUser(request, uniqueEmail('compact-actions'))
  await login(page, email)
  for (const viewport of [{ width: 1280, height: 720 }, { width: 320, height: 720 }]) {
    await page.setViewportSize(viewport)
    for (const [path, labels] of [
      ['/connects', ['Get invitation']],
      ['/me', ['Save name', 'Send confirmation email', 'Deactivate account']]
    ] as const) {
      await page.goto(path)
      for (const label of labels) {
        const button = page.getByRole('button', { name: label, exact: true })
        await expect(button).toBeVisible()
        const dimensions = await button.evaluate((element) => {
          const style = getComputedStyle(element)
          const rect = element.getBoundingClientRect()
          return { width: rect.width, height: rect.height, max: parseFloat(style.fontSize) * 18, fits: element.scrollWidth <= element.clientWidth }
        })
        expect(dimensions.width).toBeLessThanOrEqual(dimensions.max + 1)
        expect(dimensions.height).toBeGreaterThanOrEqual(44)
        expect(dimensions.fits).toBe(true)
      }
    }
    await page.goto('/ui-kit')
    const longAction = page.getByRole('button', { name: 'A longer action label that can wrap when needed' })
    expect(await longAction.evaluate(element => element.scrollWidth <= element.clientWidth)).toBe(true)
    expect(await page.evaluate(() => document.documentElement.scrollWidth <= window.innerWidth)).toBe(true)
  }
})

test('login primary action precedes recovery links on desktop and mobile', async ({ page }) => {
  for (const viewport of [{ width: 1280, height: 720 }, { width: 390, height: 844 }]) {
    await page.setViewportSize(viewport)
    await page.goto('/login')
    await waitForNuxtHydration(page)
    const password = page.getByLabel('Password', { exact: true })
    await password.focus()
    await page.keyboard.press('Tab')
    await expect(page.getByRole('button', { name: 'Sign in', exact: true })).toBeFocused()
    await page.keyboard.press('Tab')
    await expect(page.getByRole('link', { name: 'Forgot your password?', exact: true })).toBeFocused()
    await page.keyboard.press('Tab')
    await expect(page.getByRole('link', { name: 'Need another activation link?', exact: true })).toBeFocused()
    await page.keyboard.press('Tab')
    await expect(page.getByRole('link', { name: 'Create an account', exact: true })).toBeFocused()
    expect(await page.evaluate(() => document.documentElement.scrollWidth <= window.innerWidth)).toBe(true)
  }
})

test('E2E-01 completes register, activation sign-in, reload and authenticated profile', async ({ page, request }) => {
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

  await page.setViewportSize({ width: 390, height: 844 })
  const activationRequestLink = page.getByRole('button', { name: 'Request another activation link', exact: true })
  await expect(activationRequestLink).toBeVisible()
  await expect(page.getByLabel('Current password', { exact: true })).toHaveCount(0)
  await activationRequestLink.focus()
  await page.keyboard.press('Enter')
  await expect(page.getByLabel('Email', { exact: true })).toBeFocused()
  await expect(page.getByLabel('Email', { exact: true })).toHaveValue(email)
  await expect(activationRequestLink).toHaveCount(0)
  expect(requests.filter(path => path.includes('/activation-requests'))).toHaveLength(0)
  expect(await page.evaluate(() => document.documentElement.scrollWidth <= window.innerWidth)).toBe(true)

  await page.reload()
  await expect(page.getByRole('button', { name: 'Create account' })).toBeVisible()

  const message = await waitForMail(request, email)
  const activationUrl = activationUrlFrom(message)
  expect(new URL(activationUrl).origin).toBe(baseUrl)
  const activationResponsePromise = page.waitForResponse(response =>
    new URL(response.url()).pathname === '/api/v1/auth/activate'
  )
  await followSecretUrl(page, activationUrl)
  await expect(page).toHaveURL(/\/activate$/u)
  await expect(page.getByRole('button', { name: 'Activate and sign in' })).toBeVisible()
  expect(requests).not.toContain('POST /api/v1/auth/activate')
  expect(await activationAcceptanceState(request, email)).toEqual({
    exists: true,
    active: false,
    tokenCount: 1,
    unfinishedTokenCount: 1,
    invalidatedTokenCount: 0,
    usedTokenCount: 0,
    outboxCount: 1
  })
  await expectNoSessionOrStoredCapability(page)
  await page.getByRole('button', { name: 'Activate and sign in' }).click()
  const activationResponse = await activationResponsePromise

  expect(activationResponse.status()).toBe(200)
  await expect(page).toHaveURL(/\/activate$/u)
  await expect(page.getByRole('status')).toContainText('Your account is active')
  const activationBody = await activationResponse.json() as { accessToken: string, user: { email: string, isActive: boolean } }
  expect(activationBody.user.email).toBe(email)
  expect(activationBody.user.isActive).toBe(true)
  const cookiesAfterActivation = await sessionCookies(page.context())
  expectSessionCookieAttributes(cookiesAfterActivation.refresh, cookiesAfterActivation.csrf)
  await expectNoPersistentSecrets(page, [activationBody.accessToken, cookiesAfterActivation.refresh.value, cookiesAfterActivation.csrf.value, testPassword])
  sharedActiveEmail = email
  await page.getByRole('link', { name: 'Open your account' }).click()
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
  expect(cookiesAfterReload.refresh.value === cookiesAfterActivation.refresh.value, 'refresh cookie must rotate on reload').toBe(false)
  expect(cookiesAfterReload.csrf.value === cookiesAfterActivation.csrf.value, 'CSRF cookie must rotate on reload').toBe(false)
  expect(Math.abs(cookiesAfterReload.refresh.expires - cookiesAfterActivation.refresh.expires), 'refresh expiry must not be extended').toBeLessThanOrEqual(2)
  await expectNoPersistentSecrets(page, [
    activationBody.accessToken,
    cookiesAfterActivation.refresh.value,
    cookiesAfterActivation.csrf.value,
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
  await page.getByLabel('Password').fill('😀'.repeat(7))
  await page.getByRole('button', { name: 'Create account' }).click()
  await expect(page.getByText('Enter an email address in a valid format.')).toBeVisible()
  await expect(page.getByText('Use at least 8 characters.')).toBeVisible()

  await page.getByLabel('Email').fill(email)
  await page.getByLabel('Password').fill('😀'.repeat(8))
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
  await page.evaluate((token) => {
    history.replaceState(null, '', `/activate?token=${encodeURIComponent(token)}`)
    location.reload()
  }, invalidCapability)
  await expect(page).toHaveURL(/\/activate$/u)
  await expect(page.getByRole('button', { name: 'Activate and sign in' })).toBeVisible()
  const responsePromise = page.waitForResponse(response =>
    new URL(response.url()).pathname === '/api/v1/auth/activate'
  )
  await page.getByRole('button', { name: 'Activate and sign in' }).click()
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

test('E2E-05 redirects anonymous protected navigation and accepts only safe login redirects', async ({ browser, request }) => {
  const email = await ensureActiveUser(request)

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
  const activeEmail = await ensureActiveUser(request)
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
  const signIn = page.getByRole('button', { name: 'Sign in' })
  await expect(signIn).toBeEnabled()
  const successfulLogin = page.waitForResponse(response =>
    new URL(response.url()).pathname === '/api/v1/auth/login'
  )
  await signIn.evaluate((button: HTMLButtonElement) => {
    button.click()
    button.click()
  })
  expect((await successfulLogin).status()).toBe(200)
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

test('login rate-limit countdown follows elapsed time without automatic retry', async ({ page }) => {
  await page.goto('/login')
  await waitForNuxtHydration(page)
  await page.clock.install()

  let loginRequests = 0
  await page.route('**/api/v1/auth/login', async (route) => {
    ++loginRequests
    await route.fulfill({
      status: 429,
      contentType: 'application/json',
      headers: { 'Retry-After': '10' },
      body: JSON.stringify({ error: {
        code: 'RATE_LIMIT_EXCEEDED',
        message: 'Too many requests.',
        ...(loginRequests === 2 ? { requestId: 'repeat-login-e2e' } : {})
      } })
    })
  })

  await page.getByLabel('Email').fill('countdown@example.test')
  await page.getByLabel('Password').fill(wrongPassword)
  await page.getByRole('button', { name: 'Sign in' }).click()
  await expect(page.getByRole('alert')).toContainText('Too many sign-in attempts')
  await expect(page.getByRole('timer')).toHaveText('Try again in 10 seconds.')

  await page.clock.runFor(6_100)
  await expect(page.getByRole('timer')).toHaveText('Try again in 4 seconds.')
  await page.getByRole('button', { name: 'Sign in' }).click()
  await expect(page.getByText('Support reference: repeat-login-e2e')).toBeVisible()
  await expect(page.getByRole('timer')).toHaveText('Try again in 4 seconds.')
  expect(loginRequests).toBe(2)
  await page.clock.runFor(4_100)
  await expect(page.getByRole('timer')).toHaveText('You can try again now.')
  expect(loginRequests).toBe(2)
})

test('E2E-07 coordinates refresh across two tabs without persistent secrets', async ({ context, page, request }) => {
  const email = await ensureActiveUser(request)
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
  const email = await ensureActiveUser(request)
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
  const email = await ensureActiveUser(request)
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

test('E2E-10 replaces an activation link and signs in through the new link', async ({ page, request }) => {
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
  await page.getByRole('button', { name: 'Activate and sign in' }).click()
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
  await page.getByRole('button', { name: 'Activate and sign in' }).click()
  const newActivationResponse = await newActivationResponsePromise
  expect(newActivationResponse.status()).toBe(200)
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

  const accessToken = (await newActivationResponse.json() as { accessToken: string }).accessToken
  await page.getByRole('link', { name: 'Open your account' }).click()
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

  const activeEmail = await createActiveUser(request, uniqueEmail('active-activation-resend'))
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

test('current logout clears cookies and protected access stays signed out after reload', async ({ page, request }) => {
  const email = await ensureActiveUser(request)
  const access = await login(page, email)
  const cookies = await sessionCookies(page.context())
  const responsePromise = page.waitForResponse(response => new URL(response.url()).pathname === '/api/v1/auth/logout')
  await page.getByRole('button', { name: 'Sign out', exact: true }).click()
  const response = await responsePromise
  expect(response.status()).toBe(204)
  expect(response.headers()['content-type']).toBeUndefined()
  expect(Boolean(response.headers()['x-request-id'])).toBe(true)
  await expect(page).toHaveURL(/\/login\?signedOut=1$/u)
  expect(await page.context().cookies()).toEqual([])
  await expectNoPersistentSecrets(page, [access, cookies.refresh.value, cookies.csrf.value])
  let refreshes = 0
  page.on('request', (sent) => {
    if (new URL(sent.url()).pathname === '/api/v1/auth/refresh') {
      ++refreshes
    }
  })
  await page.reload()
  await waitForNuxtHydration(page)
  await page.goto('/me')
  await expect(page).toHaveURL(/\/login/u)
  await expect(page.getByRole('heading', { name: 'Sign in', exact: true })).toBeVisible()
  expect(refreshes).toBe(0)
})

test('logout-all revokes independent browser sessions while previously issued JWT lives until exp', async ({ browser, page, request }) => {
  const email = await ensureActiveUser(request)
  await login(page, email)
  const other = await newSecureContext(browser)
  try {
    const otherPage = await other.newPage()
    const otherAccess = await login(otherPage, email)
    const otherCookies = await sessionCookies(other)
    await page.getByRole('button', { name: 'Sign out on all devices', exact: true }).click()
    await page.getByRole('button', { name: 'Cancel', exact: true }).click()
    await expect(page).toHaveURL(/\/me$/u)
    await page.getByRole('button', { name: 'Sign out on all devices', exact: true }).click()
    const responsePromise = page.waitForResponse(response => new URL(response.url()).pathname === '/api/v1/auth/logout-all')
    await page.getByRole('button', { name: 'Confirm sign out', exact: true }).click()
    expect((await responsePromise).status()).toBe(204)
    await expect(page).toHaveURL(/\/login\?signedOut=1$/u)
    const me = await other.request.get('/api/v1/me', { headers: { Authorization: `Bearer ${otherAccess}` } })
    expect(me.status(), 'logout-all does not blacklist an already issued JWT').toBe(200)
    const refresh = await other.request.post('/api/v1/auth/refresh', {
      headers: { 'Origin': baseUrl, 'Sec-Fetch-Site': 'same-origin', 'X-CSRF-Token': otherCookies.csrf.value }
    })
    expect(refresh.status()).toBe(401)
    expect((await refresh.json() as { error: { code: string } }).error.code).toBe('INVALID_REFRESH_TOKEN')
    await otherPage.reload()
    await expect(otherPage).toHaveURL(/\/login/u)
    await expect(otherPage.getByRole('heading', { name: 'Sign in', exact: true })).toBeVisible()
  }
  finally {
    await other.close()
  }
})

test('confirmed logout ends other tabs without an automatic refresh or repeated logout', async ({ page, request }) => {
  const email = await ensureActiveUser(request)
  await login(page, email)
  const second = await page.context().newPage()
  try {
    await second.goto('/me')
    await expect(second.getByText(email)).toBeVisible()
    let refreshes = 0
    let logouts = 0
    second.on('request', (sent) => {
      const path = new URL(sent.url()).pathname
      if (path === '/api/v1/auth/refresh') {
        ++refreshes
      }
      if (path === '/api/v1/auth/logout') {
        ++logouts
      }
    })
    await page.getByRole('button', { name: 'Sign out', exact: true }).click()
    await expect(second).toHaveURL(/\/login\?signedOut=1$/u)
    await second.reload()
    await waitForNuxtHydration(second)
    expect(refreshes).toBe(0)
    expect(logouts).toBe(0)
    await expectStorageHasNoAuthMaterial(second)
  }
  finally {
    await second.close()
  }
})

test('recovery resets credentials, revokes independent sessions and ends sibling tabs', async ({ page, browser, request }) => {
  const email = await createActiveUser(request, uniqueEmail('recovery'))
  await login(page, email)
  const other = await newSecureContext(browser)
  const sibling = await page.context().newPage()
  try {
    const otherPage = await other.newPage()
    const otherAccess = await login(otherPage, email)
    const oldCookies = await sessionCookies(other)
    await sibling.goto('/me')
    await expect(sibling.getByText(email)).toBeVisible()
    const activation = await waitForMail(request, email)
    const mail = await requestRecovery(page, request, email, new Set([activation.testMessageId]))
    const url = recoveryUrlFrom(mail)
    const token = new URL(url).searchParams.get('token') ?? ''
    const newPassword = '  a1😀d  '
    expect((await submitRecovery(page, url, newPassword)).status()).toBe(204)
    await expect(page).toHaveURL(/\/login\?signedOut=1&passwordReset=1$/u)
    await expect(page.getByRole('status')).toContainText('Your password has been saved')
    await expect(sibling).toHaveURL(/\/login\?signedOut=1$/u)
    expect(await page.context().cookies()).toEqual([])
    await expectNoPersistentSecrets(page, [token, newPassword])
    expect((await other.request.get('/api/v1/me', { headers: { Authorization: `Bearer ${otherAccess}` } })).status()).toBe(200)
    const refresh = await other.request.post('/api/v1/auth/refresh', {
      headers: { 'Origin': baseUrl, 'Sec-Fetch-Site': 'same-origin', 'X-CSRF-Token': oldCookies.csrf.value }
    })
    expect(refresh.status()).toBe(401)
    expect((await refresh.json() as { error: { code: string } }).error.code).toBe('INVALID_REFRESH_TOKEN')
    await page.getByLabel('Email', { exact: true }).fill(email)
    await page.getByLabel('Password', { exact: true }).fill(testPassword)
    await routeNextLoginFromUniqueIp(page)
    const failedLogin = page.waitForResponse(sent => new URL(sent.url()).pathname === '/api/v1/auth/login')
    await page.getByRole('button', { name: 'Sign in', exact: true }).click()
    expect((await failedLogin).status()).toBe(401)
    await expect(page.getByRole('alert')).toContainText('Email or password is incorrect')
    await login(page, email, '/me', true, newPassword)
    expect((await submitRecovery(page, url, 'Another valid password!')).status()).toBe(410)
    await expect(page.getByRole('alert')).toContainText('Recovery link was already used')
    await expect(page.getByRole('link', { name: 'Request a new recovery link' })).toBeVisible()
    await expectNoPersistentSecrets(page, [token, newPassword])
  }
  finally {
    await sibling.close()
    await other.close()
  }
})

test('new recovery email replaces old reset link and inactive account remains inactive', async ({ page, request }) => {
  const email = await registerInactiveUser(request, uniqueEmail('inactive-recovery'))
  const activation = await waitForMail(request, email)
  await page.goto('/forgot-password')
  const firstMail = await requestRecovery(page, request, email, new Set([activation.testMessageId]))
  const firstUrl = recoveryUrlFrom(firstMail)
  const secondMail = await requestRecovery(page, request, email, new Set([activation.testMessageId, firstMail.testMessageId]))
  const secondUrl = recoveryUrlFrom(secondMail)
  expect((await submitRecovery(page, firstUrl, 'New recovery password1!')).status()).toBe(410)
  await expect(page.getByRole('alert')).toContainText('Recovery link was replaced')
  const password = 'New recovery password1!'
  expect((await submitRecovery(page, secondUrl, password)).status()).toBe(204)
  await expect(page).toHaveURL(/\/login\?signedOut=1&passwordReset=1$/u)
  await page.getByLabel('Email', { exact: true }).fill(email)
  await page.getByLabel('Password', { exact: true }).fill(password)
  await routeNextLoginFromUniqueIp(page)
  const inactiveLogin = page.waitForResponse(sent => new URL(sent.url()).pathname === '/api/v1/auth/login')
  await page.getByRole('button', { name: 'Sign in', exact: true }).click()
  expect((await inactiveLogin).status()).toBe(403)
  await expect(page.getByRole('alert')).toContainText('Account is not active')
  await followSecretUrl(page, activationUrlFrom(activation))
  await page.getByRole('button', { name: 'Activate and sign in' }).click()
  await expect(page.getByRole('status')).toContainText('Your account is active')
  await page.getByRole('link', { name: 'Open your account' }).click()
  await expect(page).toHaveURL(/\/me$/u)
  await expect(page.getByText(email)).toBeVisible()
  await page.reload()
  await expect(page.getByText(email)).toBeVisible()
})

test('unknown recovery stays neutral and capability is absent from SSR URL and storage', async ({ page, request }) => {
  await page.goto('/login')
  await page.getByRole('link', { name: 'Forgot your password?' }).click()
  await expect(page).toHaveURL(/\/forgot-password$/u)
  await waitForNuxtHydration(page)
  await page.getByLabel('Email', { exact: true }).fill(uniqueEmail('unknown-recovery'))
  const accepted = page.waitForResponse(sent => new URL(sent.url()).pathname === '/api/v1/auth/password-reset-requests')
  await page.getByRole('button', { name: 'Send recovery link' }).click()
  expect((await accepted).status()).toBe(202)
  await expect(page.getByRole('status')).toContainText('If an account uses this address')
  const synthetic = 'v1.synthetic-recovery-capability'
  const ssr = await request.get(`${baseUrl}/password-reset?token=${synthetic}`)
  expect(ssr.status()).toBe(200)
  expect((await ssr.text()).includes(synthetic), 'SSR must not serialize the capability').toBe(false)
  const activationSsr = await request.get(`${baseUrl}/activate?token=${synthetic}`)
  expect(activationSsr.status()).toBe(200)
  expect((await activationSsr.text()).includes(synthetic), 'Activation SSR must also exclude the capability').toBe(false)
  await followSecretUrl(page, `${baseUrl}/password-reset?token=${synthetic}`)
  await expect(page).toHaveURL(/\/password-reset$/u)
  await expectNoPersistentSecrets(page, [synthetic])
  await page.getByLabel('New password', { exact: true }).fill('New password1!')
  await page.getByRole('button', { name: 'Save new password' }).click()
  await expect(page.getByRole('alert')).toContainText('Recovery link is invalid')
  await expect(page.locator('input[name="newPassword"]')).toHaveCount(0)
  await page.reload()
  await expect(page.getByRole('alert')).toContainText('Recovery link is missing')
})

test('failed server logout stays locally signed out across reload and explicit retry confirms revoke', async ({ page, request }) => {
  const email = await ensureActiveUser(request)
  await login(page, email)
  await page.route('**/api/v1/auth/logout', async (route) => {
    await route.fulfill({
      status: 403,
      contentType: 'application/json',
      body: JSON.stringify({ error: { code: 'FORBIDDEN', message: 'Operation is forbidden.', requestId: 'e2e-safe-reference' } })
    })
  }, { times: 1 })
  let refreshes = 0
  page.on('request', (sent) => {
    if (new URL(sent.url()).pathname === '/api/v1/auth/refresh') {
      ++refreshes
    }
  })
  await page.getByRole('button', { name: 'Sign out', exact: true }).click()
  await expect(page).toHaveURL(/\/logout\?scope=current$/u)
  await expect(page.getByRole('alert')).toContainText('could not confirm sign-out')
  await page.reload()
  await waitForNuxtHydration(page)
  await expect(page.getByRole('heading', { name: 'You are signed out here' })).toBeVisible()
  expect(refreshes).toBe(0)
  const responsePromise = page.waitForResponse(response => new URL(response.url()).pathname === '/api/v1/auth/logout')
  await page.getByRole('button', { name: 'Retry sign out' }).click()
  expect((await responsePromise).status()).toBe(204)
  await expect(page).toHaveURL(/\/login\?signedOut=1$/u)
  expect(await page.context().cookies()).toEqual([])
})

test('profile name and confirmed email change keep the active session', async ({ page, request }) => {
  const oldEmail = await createActiveUser(request, uniqueEmail('profile-change'))
  const newEmail = uniqueEmail('profile-changed')
  await login(page, oldEmail)

  await page.getByLabel('Name', { exact: true }).fill('Updated E2E Name')
  const renamed = page.waitForResponse(sent => new URL(sent.url()).pathname === '/api/v1/me' && sent.request().method() === 'PATCH')
  await page.getByRole('button', { name: 'Save name', exact: true }).click()
  expect((await renamed).status()).toBe(200)
  await expect(page.getByRole('status')).toContainText('Name saved')
  await expect(page.getByRole('heading', { name: 'Updated E2E Name' })).toBeVisible()

  await page.getByLabel('New email', { exact: true }).fill(newEmail)
  await page.getByLabel('Current password', { exact: true }).fill(testPassword)
  const requested = page.waitForResponse(sent => new URL(sent.url()).pathname === '/api/v1/account/email-change-requests')
  await page.getByRole('button', { name: 'Send confirmation email', exact: true }).click()
  expect((await requested).status()).toBe(202)
  await expect(page.getByText('Confirmation email scheduled. Open the link sent to your new address.', { exact: true })).toBeVisible()
  await expect(page.getByText(oldEmail)).toBeVisible()

  const confirmationUrl = emailChangeUrlFrom(await waitForMail(request, newEmail))
  const capability = new URL(confirmationUrl).searchParams.get('token') ?? ''
  await followSecretUrl(page, confirmationUrl)
  await expect(page).toHaveURL(/\/email-change$/u)
  await expect(page.getByRole('button', { name: 'Confirm new email' })).toBeVisible()
  const confirmed = page.waitForResponse(sent => new URL(sent.url()).pathname === '/api/v1/auth/email-change-confirmations')
  await page.getByRole('button', { name: 'Confirm new email' }).click()
  expect((await confirmed).status()).toBe(204)
  await expect(page.getByRole('status')).toContainText('Email changed')
  await page.getByRole('link', { name: 'Open your profile' }).click()
  await expect(page).toHaveURL(/\/me$/u)
  await expect(page.getByText(newEmail)).toBeVisible()
  await expect(page.getByRole('heading', { name: 'Updated E2E Name' })).toBeVisible()

  const oldLogin = await request.post(`${baseUrl}/api/v1/auth/login`, {
    headers: { 'X-Forwarded-For': nextForwardedFor() }, data: { email: oldEmail, password: testPassword }
  })
  expect(oldLogin.status()).toBe(401)
  const newLogin = await request.post(`${baseUrl}/api/v1/auth/login`, {
    headers: { 'X-Forwarded-For': nextForwardedFor() }, data: { email: newEmail, password: testPassword }
  })
  expect(newLogin.status()).toBe(200)
  await page.reload()
  await waitForNuxtHydration(page)
  await expect(page.getByText(newEmail)).toBeVisible()
  await expectNoPersistentSecrets(page, [capability, testPassword])
})

test('account deactivation freezes new operations, preserves peer history and reactivates with a new session', async ({ page, request }) => {
  test.setTimeout(150_000)
  const ownerEmail = await createActiveUser(request, uniqueEmail('deactivation-owner'))
  const peerEmail = await createActiveUser(request, uniqueEmail('deactivation-peer'))
  const ownerAccess = await login(page, ownerEmail)
  const peerAccess = await loginViaApi(request, peerEmail)
  const profile = async (access: string) => {
    const response = await request.get(baseUrl + '/api/v1/me', { headers: { Authorization: 'Bearer ' + access } })
    expect(response.status()).toBe(200)
    return (await response.json() as { id: string }).id
  }
  const ownerId = await profile(ownerAccess)
  const peerId = await profile(peerAccess)
  const invited = await request.post(baseUrl + '/api/v1/connect-invitations', {
    headers: { 'Authorization': 'Bearer ' + ownerAccess, 'X-Forwarded-For': nextForwardedFor() }
  })
  expect(invited.status()).toBe(200)
  const invitationToken = (await invited.json() as { token: string }).token
  const accepted = await request.post(baseUrl + '/api/v1/connect-invitations/' + invitationToken + '/accept', {
    headers: { 'Authorization': 'Bearer ' + peerAccess, 'X-Forwarded-For': nextForwardedFor() }
  })
  expect(accepted.status()).toBe(201)
  const connectId = (await accepted.json() as { id: string }).id
  const payment = await request.post(baseUrl + '/api/v1/connects/' + connectId + '/transfers', {
    headers: { 'Authorization': 'Bearer ' + ownerAccess, 'Idempotency-Key': randomUUID() },
    data: { senderId: ownerId, receiverId: peerId, amount: 123 }
  })
  expect(payment.status()).toBe(201)
  const peerConnectBeforeResponse = await request.get(baseUrl + '/api/v1/connects/' + connectId, {
    headers: { Authorization: 'Bearer ' + peerAccess }
  })
  const peerHistoryBeforeResponse = await request.get(baseUrl + '/api/v1/connects/' + connectId + '/transfers', {
    headers: { Authorization: 'Bearer ' + peerAccess }
  })
  expect(peerConnectBeforeResponse.status()).toBe(200)
  expect(peerHistoryBeforeResponse.status()).toBe(200)
  const peerConnectBefore = await peerConnectBeforeResponse.json() as { balanceAmount: number }
  const peerHistoryBefore = await peerHistoryBeforeResponse.json() as { items: Array<{ id: string }> }

  await page.getByRole('button', { name: 'Deactivate account', exact: true }).click()
  await page.locator('#deactivate-current-password').fill(testPassword)
  const deactivated = page.waitForResponse(sent => new URL(sent.url()).pathname === '/api/v1/account/deactivate')
  await page.getByRole('button', { name: 'Confirm deactivation', exact: true }).click()
  expect((await deactivated).status()).toBe(204)
  await expect(page.getByRole('alert')).toContainText('Your account is deactivated')
  expect(await page.context().cookies()).toEqual([])

  const staleCommand = await request.post(baseUrl + '/api/v1/connects/' + connectId + '/transfers', {
    headers: { 'Authorization': 'Bearer ' + ownerAccess, 'Idempotency-Key': randomUUID() },
    data: { senderId: ownerId, receiverId: peerId, amount: 100 }
  })
  expect(staleCommand.status()).toBe(401)
  const peerConnectAfterResponse = await request.get(baseUrl + '/api/v1/connects/' + connectId, {
    headers: { Authorization: 'Bearer ' + peerAccess }
  })
  const peerHistoryAfterResponse = await request.get(baseUrl + '/api/v1/connects/' + connectId + '/transfers', {
    headers: { Authorization: 'Bearer ' + peerAccess }
  })
  expect(peerConnectAfterResponse.status()).toBe(200)
  expect(peerHistoryAfterResponse.status()).toBe(200)
  const peerConnectAfter = await peerConnectAfterResponse.json() as { otherUser: { isActive: boolean }, balanceAmount: number, canCreateDebt: boolean, canCreateTransfer: boolean }
  const peerHistoryAfter = await peerHistoryAfterResponse.json() as { items: Array<{ id: string }> }
  expect(peerConnectAfter.otherUser.isActive).toBe(false)
  expect(peerConnectAfter.balanceAmount).toBe(peerConnectBefore.balanceAmount)
  expect(peerConnectAfter.canCreateDebt).toBe(false)
  expect(peerConnectAfter.canCreateTransfer).toBe(false)
  expect(peerHistoryAfter.items.map(item => item.id)).toEqual(peerHistoryBefore.items.map(item => item.id))

  const mailBeforeRequest = await request.get(`${mailpitUrl}/api/v1/messages`)
  expect(mailBeforeRequest.ok()).toBe(true)
  const excludedMail = new Set((await mailBeforeRequest.json() as { messages: MailpitSummary[] }).messages
    .filter(message => message.To.some(to => to.Address === ownerEmail))
    .map(message => message.ID))
  const activationRequest = await submitActivationRequest(page, ownerEmail, testPassword)
  expect(activationRequest.status()).toBe(202)
  const activationUrl = activationUrlFrom(await waitForMail(request, ownerEmail, excludedMail))
  const activationCapability = new URL(activationUrl).searchParams.get('token') ?? ''
  await followSecretUrl(page, activationUrl)
  await expect(page.getByRole('button', { name: 'Activate and sign in', exact: true })).toBeVisible()
  const activated = page.waitForResponse(sent => new URL(sent.url()).pathname === '/api/v1/auth/activate')
  await page.getByRole('button', { name: 'Activate and sign in', exact: true }).click()
  expect((await activated).status()).toBe(200)
  await page.getByRole('link', { name: 'Open your account', exact: true }).click()
  await expect(page.getByText(ownerEmail)).toBeVisible()
  await page.getByRole('link', { name: 'Connections', exact: true }).click()
  await expect(page).toHaveURL(/\/connects$/u)
  await page.getByRole('link', { name: 'E2E User', exact: true }).click()
  await expect(page).toHaveURL(new RegExp('/connects/' + connectId + '$', 'u'))
  await expect(page.getByRole('link', { name: 'Payment · €1.23', exact: true })).toBeVisible()
  await expect(page.getByRole('button', { name: 'Make a payment', exact: true })).toBeVisible()
  await expectNoPersistentSecrets(page, [ownerAccess, peerAccess, invitationToken, activationCapability, testPassword])
})

test('password change revokes both browser sessions, ends sibling tabs and requires new login', async ({ page, browser, request }) => {
  const email = await createActiveUser(request, uniqueEmail('password-change'))
  const access = await login(page, email)
  const other = await newSecureContext(browser)
  const sibling = await page.context().newPage()
  try {
    const otherPage = await other.newPage()
    const otherAccess = await login(otherPage, email)
    const otherCookies = await sessionCookies(other)
    await sibling.goto('/me')
    await expect(sibling.getByText(email)).toBeVisible()
    const currentCookies = await sessionCookies(page.context())
    await page.getByRole('link', { name: 'Change password', exact: true }).click()
    await expect(page).toHaveURL(/\/account\/security$/u)
    const newPassword = '  x1😀z  '
    await page.getByLabel('Current password', { exact: true }).fill(testPassword)
    await page.getByLabel('New password', { exact: true }).fill(newPassword)
    let commands = 0
    let release: () => void = () => undefined
    let arrived: () => void = () => undefined
    const gate = new Promise<void>((resolve) => {
      release = resolve
    })
    const started = new Promise<void>((resolve) => {
      arrived = resolve
    })
    await page.route('**/api/v1/account/password-change', async (route) => {
      ++commands
      arrived()
      await gate
      await route.continue()
    })
    const changed = page.waitForResponse(sent => new URL(sent.url()).pathname === '/api/v1/account/password-change')
    await page.getByRole('button', { name: 'Save new password', exact: true }).click()
    await started
    await expect(page.getByRole('button', { name: 'Saving password…' })).toBeDisabled()
    await page.locator('form').dispatchEvent('submit')
    release()
    expect((await changed).status()).toBe(204)
    expect(commands).toBe(1)
    await expect(page).toHaveURL(/\/login\?signedOut=1&passwordChanged=1$/u)
    await expect(page.getByRole('status')).toContainText('Your password has been saved')
    await expect(sibling).toHaveURL(/\/login\?signedOut=1$/u)
    expect((await page.context().cookies()).length).toBe(0)
    await expectNoPersistentSecrets(page, [newPassword, testPassword, access, currentCookies.refresh.value, currentCookies.csrf.value])
    expect((await other.request.get('/api/v1/me', { headers: { Authorization: `Bearer ${otherAccess}` } })).status()).toBe(200)
    const refresh = await other.request.post('/api/v1/auth/refresh', {
      headers: { 'Origin': baseUrl, 'Sec-Fetch-Site': 'same-origin', 'X-CSRF-Token': otherCookies.csrf.value }
    })
    expect(refresh.status()).toBe(401)
    expect((await refresh.json() as { error: { code: string } }).error.code).toBe('INVALID_REFRESH_TOKEN')
    const currentRefresh = await request.post(`${baseUrl}/api/v1/auth/refresh`, {
      headers: {
        'Origin': baseUrl, 'Sec-Fetch-Site': 'same-origin', 'X-CSRF-Token': currentCookies.csrf.value,
        'Cookie': `equo_refresh=${currentCookies.refresh.value}; __Host-equo_csrf=${currentCookies.csrf.value}`
      }
    })
    expect(currentRefresh.status()).toBe(401)
    let refreshes = 0
    sibling.on('request', (sent) => {
      if (new URL(sent.url()).pathname === '/api/v1/auth/refresh') ++refreshes
    })
    await sibling.reload()
    await waitForNuxtHydration(sibling)
    expect(refreshes).toBe(0)
    await otherPage.reload()
    await expect(otherPage).toHaveURL(/\/login/u)
    await page.getByLabel('Email', { exact: true }).fill(email)
    await page.getByLabel('Password', { exact: true }).fill(testPassword)
    await routeNextLoginFromUniqueIp(page)
    const oldLogin = page.waitForResponse(sent => new URL(sent.url()).pathname === '/api/v1/auth/login')
    await page.getByRole('button', { name: 'Sign in', exact: true }).click()
    expect((await oldLogin).status()).toBe(401)
    await expect(page.getByRole('alert')).toContainText('Email or password is incorrect')
    await login(page, email, '/me', true, newPassword)
    await expectNoPersistentSecrets(page, [newPassword, testPassword, otherAccess, otherCookies.refresh.value, otherCookies.csrf.value])
  }
  finally {
    await sibling.close()
    await other.close()
  }
})

test('password change validation and credential or transport errors preserve auth without automatic retry', async ({ page, request }) => {
  const email = await ensureActiveUser(request)
  await login(page, email)
  await page.getByRole('link', { name: 'Change password', exact: true }).click()
  await expect(page).toHaveURL(/\/account\/security$/u)
  let commands = 0
  let refreshes = 0
  page.on('request', (sent) => {
    const path = new URL(sent.url()).pathname
    if (path === '/api/v1/account/password-change') ++commands
    if (path === '/api/v1/auth/refresh') ++refreshes
  })
  await page.getByRole('button', { name: 'Save new password', exact: true }).click()
  await expect(page.getByLabel('Current password', { exact: true })).toHaveAttribute('aria-invalid', 'true')
  expect(commands).toBe(0)
  await page.getByLabel('Current password', { exact: true }).fill(wrongPassword)
  await page.getByLabel('New password', { exact: true }).fill('😀'.repeat(7))
  await page.getByRole('button', { name: 'Save new password', exact: true }).click()
  await expect(page.getByLabel('New password', { exact: true })).toHaveAttribute('aria-invalid', 'true')
  expect(commands).toBe(0)
  await page.getByLabel('New password', { exact: true }).fill('A valid new password1!')
  const rejected = page.waitForResponse(sent => new URL(sent.url()).pathname === '/api/v1/account/password-change')
  await page.getByRole('button', { name: 'Save new password', exact: true }).click()
  expect((await rejected).status()).toBe(401)
  await expect(page.getByRole('alert')).toContainText('Current password is incorrect')
  await expect.poll(async () => await page.getByLabel('Current password', { exact: true }).inputValue() === '').toBe(true)
  await expect.poll(async () => await page.getByLabel('New password', { exact: true }).inputValue() === '').toBe(true)
  expect(commands).toBe(1)
  expect(refreshes).toBe(0)
  await page.route('**/api/v1/account/password-change', safeRouteAbort, { times: 1 })
  await page.getByLabel('Current password', { exact: true }).fill(testPassword)
  await page.getByLabel('New password', { exact: true }).fill('A valid new password1!')
  await page.getByRole('button', { name: 'Save new password', exact: true }).click()
  await expect(page.getByRole('alert')).toContainText('Password change could not be confirmed')
  await expect.poll(async () => await page.getByLabel('Current password', { exact: true }).inputValue() === '').toBe(true)
  await expect.poll(async () => await page.getByLabel('New password', { exact: true }).inputValue() === '').toBe(true)
  expect(commands).toBe(2)
  expect(refreshes).toBe(0)
  await page.getByRole('link', { name: 'Back to profile' }).click()
  await expect(page.getByText(email)).toBeVisible()
  await expectNoPersistentSecrets(page, [wrongPassword, 'A valid new password1!'])
})

test('connect invitation joins two users and retries a lost accept response without duplicating the connection', async ({ page, browser, request }) => {
  test.setTimeout(120_000)
  const authorEmail = await createActiveUser(request, uniqueEmail('connect-author'))
  const authorAccess = await login(page, authorEmail)
  await page.goto('/connects')
  await waitForNuxtHydration(page)
  await expect(page.getByText('No connections yet', { exact: true })).toBeVisible()
  const createResponse = page.waitForResponse(response => new URL(response.url()).pathname === '/api/v1/connect-invitations')
  await page.getByRole('button', { name: 'Get invitation', exact: true }).click()
  const first = await (await createResponse).json() as { token: string, url: string, expiresAt: string, created: boolean }
  expect(first.created).toBe(true)
  const repeatResponse = page.waitForResponse(response => new URL(response.url()).pathname === '/api/v1/connect-invitations')
  await page.getByRole('button', { name: 'Get invitation', exact: true }).click()
  const repeat = await (await repeatResponse).json() as typeof first
  expect(repeat.created).toBe(false)
  expect(repeat.token === first.token).toBe(true)
  expect(repeat.expiresAt === first.expiresAt).toBe(true)
  await page.context().grantPermissions(['clipboard-read', 'clipboard-write'])
  await page.getByRole('button', { name: 'Copy invitation', exact: true }).click()
  await expect(page.getByRole('status')).toContainText('Invitation copied')
  expect((await page.evaluate(() => navigator.clipboard.readText())) === first.url).toBe(true)
  await expectNoPersistentSecrets(page, [first.token, authorAccess, testPassword])
  const ssr = await request.get(first.url)
  expect(ssr.status()).toBe(200)
  expect((await ssr.text()).includes(first.token), 'SSR must not serialize the invitation capability').toBe(false)
  expect(ssr.headers()['referrer-policy']).toBe('no-referrer')
  expect(ssr.headers()['cache-control']?.includes('no-store')).toBe(true)

  const recipientContext = await browser.newContext({ ignoreHTTPSErrors: true })
  try {
    const recipient = await recipientContext.newPage()
    const recipientEmail = await createActiveUser(request, uniqueEmail('connect-recipient'))
    await followSecretUrl(recipient, first.url)
    await waitForNuxtHydration(recipient)
    await expect(recipient.getByText('E2E User invited you to connect.', { exact: true })).toBeVisible()
    await recipient.getByRole('button', { name: 'Sign in to connect', exact: true }).click()
    await expect(recipient).toHaveURL(/\/login\?redirect=\/connect-invitations\/continue$/u)
    await recipient.getByLabel('Email', { exact: true }).fill(recipientEmail)
    await recipient.getByLabel('Password', { exact: true }).fill(testPassword)
    await routeNextLoginFromUniqueIp(recipient)
    let connectionId = ''
    await recipient.route('**/api/v1/connect-invitations/*/accept', async (route) => {
      const response = await route.fetch()
      expect(response.status()).toBe(201)
      connectionId = (await response.json() as { id: string }).id
      await route.abort('failed')
    }, { times: 1 })
    await recipient.getByRole('button', { name: 'Sign in', exact: true }).click()
    await expect(recipient).toHaveURL(/\/connects$/u)
    await expect(recipient.getByRole('alert')).toContainText('Try again to check the same invitation')
    await expectNoPersistentSecrets(recipient, [first.token, testPassword])
    const retry = recipient.waitForResponse(response => new URL(response.url()).pathname.endsWith('/accept'))
    await followSecretUrl(recipient, first.url)
    expect((await retry).status()).toBe(200)
    await expect(recipient).toHaveURL(/\/connects$/u)
    await expect(recipient.locator('.invitation-toast')).toContainText('Connection created successfully')
    await expect(recipient.getByRole('link', { name: 'E2E User', exact: true })).toHaveAttribute('href', '/connects/' + connectionId)
    await expectNoPersistentSecrets(recipient, [first.token, testPassword])
    await page.reload()
    await expect(page.locator('li').filter({ hasText: 'E2E User' })).toHaveCount(1)
    await page.getByRole('link', { name: 'E2E User', exact: true }).click()
    await expect(page).toHaveURL(/\/connects\/[0-9a-f-]{36}$/u)
    expect(new URL(page.url()).pathname === '/connects/' + connectionId).toBe(true)

    const otherEmail = await ensureActiveUser(request)
    await page.goto('/me')
    await page.getByRole('button', { name: 'Sign out', exact: true }).click()
    await expect(page).toHaveURL(/\/login\?signedOut=1$/u)
    const otherAccess = await login(page, otherEmail)
    const hidden = await request.get(baseUrl + '/api/v1/connects/' + connectionId, { headers: { Authorization: 'Bearer ' + otherAccess } })
    expect(hidden.status()).toBe(404)
    const usedByOther = await request.post(baseUrl + '/api/v1/connect-invitations/' + first.token + '/accept', { headers: { 'Authorization': 'Bearer ' + otherAccess, 'X-Forwarded-For': nextForwardedFor() } })
    expect(usedByOther.status()).toBe(410)
    await followSecretUrl(page, first.url)
    await expect(page).toHaveURL(/\/connects$/u)
    await expect(page.getByRole('alert')).toContainText('This invitation has already been accepted')
  }
  finally {
    await recipientContext.close()
  }
})

test('invitation preview safely handles an unknown link without exposing the capability', async ({ page }) => {
  await page.goto('/connect-invitations/invalid')
  await expect(page.getByRole('alert')).toContainText('Invitation unavailable')
  await expect(page.getByRole('button', { name: 'Create an account', exact: true })).toHaveCount(0)
  await expect(page.getByRole('button', { name: 'Try again', exact: true })).toHaveCount(0)
})

test('invited newcomer registers, activates in another tab and connects automatically', async ({ page, browser, request }) => {
  test.setTimeout(120_000)
  const authorEmail = await createActiveUser(request, uniqueEmail('inviting-author'))
  await login(page, authorEmail)
  await page.goto('/connects')
  const invitationResponse = page.waitForResponse(response => new URL(response.url()).pathname === '/api/v1/connect-invitations')
  await page.getByRole('button', { name: 'Get invitation', exact: true }).click()
  const invitation = await (await invitationResponse).json() as { token: string, url: string }

  const recipientContext = await browser.newContext({ ignoreHTTPSErrors: true })
  try {
    const recipient = await recipientContext.newPage()
    await recipient.goto(invitation.url)
    await waitForNuxtHydration(recipient)
    await expect(recipient.getByText('E2E User invited you to connect.', { exact: true })).toBeVisible()
    await recipient.getByRole('button', { name: 'Create an account', exact: true }).click()
    await expect(recipient).toHaveURL(/\/register$/u)
    const email = uniqueEmail('invited-newcomer')
    await recipient.getByLabel('Name').fill('Invited User')
    await recipient.getByLabel('Email').fill(email)
    await recipient.getByLabel('Password').fill(testPassword)
    await routeNextRegistrationFromUniqueIp(recipient)
    const registrationResponse = recipient.waitForResponse(response => new URL(response.url()).pathname === '/api/v1/auth/register')
    await recipient.getByRole('button', { name: 'Create account', exact: true }).click()
    expect((await registrationResponse).status()).toBe(201)
    await expect(recipient.getByRole('status')).toContainText('Check your email')
    await expectNoPersistentSecrets(recipient, [invitation.token, testPassword])

    const activation = activationUrlFrom(await waitForMail(request, email))
    const activationTab = await recipientContext.newPage()
    await followSecretUrl(activationTab, activation)
    await expect(activationTab.getByRole('button', { name: 'Activate and sign in' })).toBeVisible()
    const pendingAccept = activationTab.waitForResponse(response => new URL(response.url()).pathname === '/api/v1/connect-invitations/pending/accept')
    await activationTab.getByRole('button', { name: 'Activate and sign in' }).click()
    expect((await pendingAccept).status()).toBe(201)
    await expect(activationTab).toHaveURL(/\/connects$/u)
    await expect(activationTab.locator('.invitation-toast')).toContainText('Connection created successfully')
    await expect(activationTab.getByRole('link', { name: 'E2E User', exact: true })).toBeVisible()
    await expectNoPersistentSecrets(activationTab, [invitation.token, testPassword])
  }
  finally {
    await recipientContext.close()
  }
})

test('signed-in recipient opens an invitation and reaches connections automatically', async ({ page, browser, request }) => {
  test.setTimeout(120_000)
  const authorEmail = await createActiveUser(request, uniqueEmail('direct-author'))
  await login(page, authorEmail)
  await page.goto('/connects')
  const invitationResponse = page.waitForResponse(response => new URL(response.url()).pathname === '/api/v1/connect-invitations')
  await page.getByRole('button', { name: 'Get invitation', exact: true }).click()
  const invitation = await (await invitationResponse).json() as { token: string, url: string }

  const recipientContext = await browser.newContext({ ignoreHTTPSErrors: true })
  try {
    const recipient = await recipientContext.newPage()
    const recipientEmail = await createActiveUser(request, uniqueEmail('direct-recipient'))
    await login(recipient, recipientEmail)
    const acceptance = recipient.waitForResponse(response => new URL(response.url()).pathname.endsWith('/accept'))
    await followSecretUrl(recipient, invitation.url)
    expect((await acceptance).status()).toBe(201)
    await expect(recipient).toHaveURL(/\/connects$/u)
    await expect(recipient.locator('.invitation-toast')).toContainText('Connection created successfully')
    await expect(recipient.getByRole('link', { name: 'E2E User', exact: true })).toBeVisible()
    await expectNoPersistentSecrets(recipient, [invitation.token, testPassword])

    const repeatedInvitationResponse = page.waitForResponse(response => new URL(response.url()).pathname === '/api/v1/connect-invitations')
    await page.getByRole('button', { name: 'Get invitation', exact: true }).click()
    const repeatedInvitation = await (await repeatedInvitationResponse).json() as { token: string, url: string }
    expect(repeatedInvitation.token).not.toBe(invitation.token)
    const existingAcceptance = recipient.waitForResponse(response => new URL(response.url()).pathname.endsWith('/accept'))
    await followSecretUrl(recipient, repeatedInvitation.url)
    const existingResponse = await existingAcceptance
    expect(existingResponse.status()).toBe(200)
    expect((await existingResponse.json() as { invitationOutcome: string }).invitationOutcome).toBe('already_connected')
    await expect(recipient).toHaveURL(/\/connects$/u)
    await expect(recipient.locator('.invitation-toast')).toContainText('You are already connected with E2E User')
    await expect(recipient.getByRole('link', { name: 'E2E User', exact: true })).toHaveCount(1)
    await expectNoPersistentSecrets(recipient, [repeatedInvitation.token, testPassword])
  }
  finally {
    await recipientContext.close()
  }
})

test('financial expense survives a lost create response, updates both balances and stays private to participants', async ({ page, browser, request }) => {
  test.setTimeout(150_000)
  const authorEmail = await createActiveUser(request, uniqueEmail('debt-author'))
  const authorAccess = await login(page, authorEmail)
  const recipientContext = await browser.newContext({ ignoreHTTPSErrors: true })
  try {
    const recipient = await recipientContext.newPage()
    const recipientEmail = await createActiveUser(request, uniqueEmail('debt-recipient'))
    const recipientAccess = await login(recipient, recipientEmail)
    const recipientProfile = await request.get(baseUrl + '/api/v1/me', { headers: { Authorization: 'Bearer ' + recipientAccess } })
    const recipientId = (await recipientProfile.json() as { id: string }).id
    await page.goto('/connects')
    await waitForNuxtHydration(page)
    const invited = page.waitForResponse(response => new URL(response.url()).pathname === '/api/v1/connect-invitations')
    await page.getByRole('button', { name: 'Get invitation', exact: true }).click()
    const invitation = await (await invited).json() as { url: string }
    const accepted = recipient.waitForResponse(response => new URL(response.url()).pathname.endsWith('/accept'))
    await followSecretUrl(recipient, invitation.url)
    const connectId = (await (await accepted).json() as { id: string }).id
    await expect(recipient).toHaveURL(/\/connects$/u)
    await page.goto('/debts/new')
    await waitForNuxtHydration(page)
    await expect(page.locator('input[value="' + recipientId + '"]')).toBeEnabled()
    const title = 'Private dinner ' + Date.now()
    await page.getByLabel('Title', { exact: true }).fill(title)
    await page.getByLabel('Description (optional)', { exact: true }).fill('Shared restaurant bill')
    await page.getByLabel('Amount in EUR', { exact: true }).fill('10.01')
    await page.locator('input[value="' + recipientId + '"]').check()
    await expect(page.getByText('Each share: €5.01. Shares round up to the next cent.', { exact: true })).toBeVisible()
    let debtId = ''
    let firstCommand = ''
    let firstKey = ''
    let commands = 0
    page.on('request', (sent) => {
      if (sent.method() === 'POST' && new URL(sent.url()).pathname === '/api/v1/debts') ++commands
    })
    await page.route('**/api/v1/debts', async (route) => {
      if (route.request().method() !== 'POST') {
        await route.continue()
        return
      }
      firstCommand = route.request().postData() ?? ''
      firstKey = route.request().headers()['idempotency-key'] ?? ''
      const response = await route.fetch()
      expect(response.status()).toBe(201)
      expect(response.headers()['etag']).toBe('"1"')
      debtId = (await response.json() as { id: string }).id
      await route.abort('failed')
    }, { times: 1 })
    await page.getByRole('button', { name: 'Create expense', exact: true }).click()
    await expect(page.getByRole('alert')).toContainText('The result is not confirmed')
    await expect(page.getByLabel('Amount in EUR', { exact: true })).toBeDisabled()
    expect(commands).toBe(1)
    const retry = page.waitForResponse(response => new URL(response.url()).pathname === '/api/v1/debts' && response.request().method() === 'POST')
    await page.getByRole('button', { name: 'Retry same expense', exact: true }).click()
    const replay = await retry
    expect(replay.status()).toBe(201)
    expect(replay.request().postData() === firstCommand).toBe(true)
    expect(replay.request().headers()['idempotency-key'] === firstKey && firstKey.length > 0).toBe(true)
    expect((await replay.json() as { id: string }).id === debtId).toBe(true)
    expect(replay.headers()['location']).toBe('/api/v1/debts/' + debtId)
    expect(replay.headers()['etag']).toBe('"1"')
    await expect(page).toHaveURL(/\/debts\/[0-9a-f-]{36}$/u)
    await expect(page.getByRole('heading', { level: 1 })).toHaveText(title)
    await expect(page.getByText('2 people · €5.01 each', { exact: true })).toBeVisible()
    expect(commands).toBe(2)
    await expect(page.getByRole('button', { name: /edit|delete|pay/iu })).toHaveCount(0)
    const participantDebt = await request.get(baseUrl + '/api/v1/debts/' + debtId, { headers: { Authorization: 'Bearer ' + recipientAccess } })
    expect(participantDebt.status()).toBe(200)
    const body = await participantDebt.json() as { participants: Array<{ user: { id: string }, transferId: string | null }> }
    const transferId = body.participants.find(p => p.user.id === recipientId)?.transferId
    expect(typeof transferId === 'string').toBe(true)
    await page.getByRole('link', { name: 'Expenses', exact: true }).click()
    await expect(page.getByRole('link', { name: title, exact: true })).toHaveCount(1)
    for (const [viewer, balance, meaning] of [[page, 'Balance: €5.01', 'They owe you.'], [recipient, 'Balance: -€5.01', 'You owe them.']] as const) {
      await viewer.goto('/connects/' + connectId)
      await expect(viewer.getByText(balance, { exact: true })).toBeVisible()
      await expect(viewer.getByText(meaning, { exact: true })).toBeVisible()
      await expect(viewer.getByRole('link', { name: title, exact: true })).toBeVisible()
      await viewer.getByRole('link', { name: 'Expense share · €5.01', exact: true }).click()
      await expect(viewer.getByRole('heading', { name: 'Expense share · €5.01', exact: true })).toBeVisible()
      await viewer.getByRole('link', { name: title, exact: true }).click()
      await expect(viewer.getByRole('heading', { level: 1 })).toHaveText(title)
      expect((await viewer.locator('main').innerText()).includes(authorEmail) || (await viewer.locator('main').innerText()).includes(recipientEmail)).toBe(false)
    }
    const strangerEmail = await ensureActiveUser(request)
    await page.goto('/me')
    await page.getByRole('button', { name: 'Sign out', exact: true }).click()
    await expect(page).toHaveURL(/\/login\?signedOut=1$/u)
    const strangerAccess = await login(page, strangerEmail)
    await page.goto('/debts/' + debtId)
    await expect(page.getByRole('alert')).toContainText('Expense unavailable')
    expect((await page.locator('main').innerText()).includes(title)).toBe(false)
    await page.goto('/transfers/' + transferId)
    await expect(page.getByRole('alert')).toContainText('Operation unavailable')
    await page.goto('/debts')
    await expect(page.getByText('No expenses yet', { exact: true })).toBeVisible()
    await expectNoPersistentSecrets(page, [authorAccess, recipientAccess, strangerAccess, testPassword])
  }
  finally {
    await recipientContext.close()
  }
})

test('manual payment survives a lost response and refreshes both histories after an overpayment', async ({ page, browser, request }) => {
  test.setTimeout(150_000)
  const authorEmail = await createActiveUser(request, uniqueEmail('manual-author'))
  const authorAccess = await login(page, authorEmail)
  const otherContext = await browser.newContext({ ignoreHTTPSErrors: true })
  try {
    const other = await otherContext.newPage()
    const otherEmail = await createActiveUser(request, uniqueEmail('manual-other'))
    const otherAccess = await login(other, otherEmail)
    const otherProfile = await request.get(baseUrl + '/api/v1/me', { headers: { Authorization: 'Bearer ' + otherAccess } })
    const otherId = (await otherProfile.json() as { id: string }).id
    await page.goto('/connects')
    await waitForNuxtHydration(page)
    const invited = page.waitForResponse(response => new URL(response.url()).pathname === '/api/v1/connect-invitations')
    await page.getByRole('button', { name: 'Get invitation', exact: true }).click()
    const invitation = await (await invited).json() as { url: string }
    const accepted = other.waitForResponse(response => new URL(response.url()).pathname.endsWith('/accept'))
    await followSecretUrl(other, invitation.url)
    const connectId = (await (await accepted).json() as { id: string }).id
    await expect(other).toHaveURL(/\/connects$/u)
    await page.goto('/debts/new')
    await waitForNuxtHydration(page)
    await expect(page.locator('input[value="' + otherId + '"]')).toBeEnabled()
    const title = 'Payment dinner ' + Date.now()
    await page.getByLabel('Title', { exact: true }).fill(title)
    await page.getByLabel('Amount in EUR', { exact: true }).fill('10.01')
    await page.locator('input[value="' + otherId + '"]').check()
    const debtCreated = page.waitForResponse(response => new URL(response.url()).pathname === '/api/v1/debts' && response.request().method() === 'POST')
    await page.getByRole('button', { name: 'Create expense', exact: true }).click()
    const debtResponse = await debtCreated
    expect(debtResponse.status()).toBe(201)
    await expect(page).toHaveURL(/\/debts\/[0-9a-f-]{36}$/u)
    await page.goto('/connects/' + connectId)
    await waitForNuxtHydration(page)
    await expect(page.getByText('Balance: €5.01', { exact: true })).toBeVisible()
    await expect(page.getByText('They owe you.', { exact: true })).toBeVisible()
    await page.getByRole('button', { name: 'Make a payment', exact: true }).click()
    await expect(page.getByRole('dialog')).toBeVisible()
    for (const viewport of [{ width: 1280, height: 720 }, { width: 390, height: 400 }]) {
      await page.setViewportSize(viewport)
      const bounds = await page.getByRole('dialog').boundingBox()
      expect(bounds).not.toBeNull()
      expect(Math.abs(bounds!.x + bounds!.width / 2 - viewport.width / 2)).toBeLessThanOrEqual(1)
      expect(Math.abs(bounds!.y + bounds!.height / 2 - viewport.height / 2)).toBeLessThanOrEqual(1)
      expect(bounds!.x).toBeGreaterThanOrEqual(16)
      expect(bounds!.y).toBeGreaterThanOrEqual(16)
      expect(bounds!.x + bounds!.width).toBeLessThanOrEqual(viewport.width - 16)
      expect(bounds!.y + bounds!.height).toBeLessThanOrEqual(viewport.height - 16)
      if (viewport.height === 400) {
        expect(await page.getByRole('dialog').evaluate(element => element.scrollHeight > element.clientHeight)).toBe(true)
      }
    }
    await page.setViewportSize({ width: 1280, height: 720 })
    await page.getByLabel('Who transferred money?', { exact: true }).selectOption('other')
    await page.getByLabel('Payment amount in EUR', { exact: true }).fill('7.00')
    await expect(page.getByText(/Expected balance: -€1.99/u)).toBeVisible()
    const path = '/api/v1/connects/' + connectId + '/transfers'
    let firstBody = ''
    let firstKey = ''
    let transferId = ''
    let commands = 0
    page.on('request', (sent) => {
      if (sent.method() === 'POST' && new URL(sent.url()).pathname === path) ++commands
    })
    await page.route('**' + path, async (route) => {
      if (route.request().method() !== 'POST') {
        await route.continue()
        return
      }
      firstBody = route.request().postData() ?? ''
      firstKey = route.request().headers()['idempotency-key'] ?? ''
      const response = await route.fetch()
      expect(response.status()).toBe(201)
      transferId = (await response.json() as { id: string }).id
      await route.abort('failed')
    }, { times: 1 })
    await page.getByRole('button', { name: 'Record payment', exact: true }).click()
    await expect(page.getByRole('alert')).toContainText('The result is not confirmed')
    await expect(page.getByLabel('Payment amount in EUR', { exact: true })).toBeDisabled()
    expect(commands).toBe(1)
    const repeated = page.waitForResponse(response => new URL(response.url()).pathname === path && response.request().method() === 'POST')
    await page.getByRole('button', { name: 'Retry same payment', exact: true }).click()
    const replay = await repeated
    expect(replay.status()).toBe(201)
    expect(replay.request().postData() === firstBody).toBe(true)
    expect(firstKey.length > 0 && replay.request().headers()['idempotency-key'] === firstKey).toBe(true)
    const payment = await replay.json() as { id: string }
    expect(payment.id).toBe(transferId)
    await expect(page.getByText('Payment recorded.', { exact: true })).toBeVisible()
    await expect(page.getByText('Balance: -€1.99', { exact: true })).toBeVisible()
    await expect(page.getByText('You owe them.', { exact: true })).toBeVisible()
    await expect(page.getByRole('link', { name: 'Payment · €7.00', exact: true })).toHaveCount(1)
    expect(commands).toBe(2)
    for (const [viewer, expected, canManage] of [[page, -199, true], [other, 199, false]] as const) {
      await viewer.goto('/connects/' + connectId)
      await expect(viewer.getByText(expected < 0 ? 'Balance: -€1.99' : 'Balance: €1.99', { exact: true })).toBeVisible()
      await expect(viewer.getByText(expected < 0 ? 'You owe them.' : 'They owe you.', { exact: true })).toBeVisible()
      await expect(viewer.getByRole('link', { name: 'Expense share · €5.01', exact: true })).toBeVisible()
      await viewer.getByRole('link', { name: 'Payment · €7.00', exact: true }).click()
      await expect(viewer.getByRole('heading', { name: 'Payment · €7.00', exact: true })).toBeVisible()
      await expect(viewer.getByText(/Recorded by/u)).toBeVisible()
      await expect(viewer.getByRole('button', { name: 'Edit payment', exact: true })).toHaveCount(canManage ? 1 : 0)
      await expect(viewer.getByRole('button', { name: 'Remove payment', exact: true })).toHaveCount(canManage ? 1 : 0)
    }
    await expectNoPersistentSecrets(page, [authorAccess, otherAccess, testPassword])
    await expectNoPersistentSecrets(other, [authorAccess, otherAccess, testPassword])
  }
  finally {
    await otherContext.close()
  }
})

test('debt editing recalculates all shares, restores history and rejects a stale editor without changing manual payments', async ({ page, browser, request }) => {
  test.setTimeout(200_000)
  const authorEmail = await createActiveUser(request, uniqueEmail('edit-author'))
  const authorAccess = await login(page, authorEmail)
  const profile = async (access: string) => {
    const response = await request.get(baseUrl + '/api/v1/me', { headers: { Authorization: 'Bearer ' + access } })
    return (await response.json() as { id: string }).id
  }
  const authorId = await profile(authorAccess)
  const payerContext = await browser.newContext({ ignoreHTTPSErrors: true })
  const memberContext = await browser.newContext({ ignoreHTTPSErrors: true })
  try {
    const payer = await payerContext.newPage()
    const member = await memberContext.newPage()
    const payerEmail = await createActiveUser(request, uniqueEmail('edit-payer'))
    const payerAccess = await login(payer, payerEmail)
    const payerId = await profile(payerAccess)
    const memberEmail = await createActiveUser(request, uniqueEmail('edit-member'))
    const memberAccess = await login(member, memberEmail)
    const memberId = await profile(memberAccess)
    async function connectWith(recipient: Page): Promise<string> {
      await page.goto('/connects')
      await waitForNuxtHydration(page)
      const invited = page.waitForResponse(response => new URL(response.url()).pathname === '/api/v1/connect-invitations')
      await page.getByRole('button', { name: 'Get invitation', exact: true }).click()
      const invitation = await (await invited).json() as { url: string }
      const accepted = recipient.waitForResponse(response => new URL(response.url()).pathname.endsWith('/accept'))
      await followSecretUrl(recipient, invitation.url)
      const result = (await (await accepted).json() as { id: string }).id
      await expect(recipient).toHaveURL(/\/connects$/u)
      return result
    }
    const authorPayerConnect = await connectWith(payer)
    await connectWith(member)
    await page.goto('/debts/new')
    await waitForNuxtHydration(page)
    await expect(page.getByLabel('Who paid?', { exact: true })).toBeEnabled()
    await page.getByLabel('Who paid?', { exact: true }).selectOption(payerId)
    await page.locator('input[value="' + authorId + '"]').check()
    await page.locator('input[value="' + memberId + '"]').check()
    const title = 'Editable shared dinner ' + Date.now()
    await page.getByLabel('Title', { exact: true }).fill(title)
    await page.getByLabel('Amount in EUR', { exact: true }).fill('10.01')
    const created = page.waitForResponse(response => new URL(response.url()).pathname === '/api/v1/debts' && response.request().method() === 'POST')
    await page.getByRole('button', { name: 'Create expense', exact: true }).click()
    const debtResponse = await created
    expect(debtResponse.status()).toBe(201)
    const initial = await debtResponse.json() as { id: string, participants: Array<{ user: { id: string }, debtParticipantId: string, transferId: string | null }> }
    const debtId = initial.id
    const debtPath = '/api/v1/debts/' + debtId
    await expect(page).toHaveURL(/\/debts\/[0-9a-f-]{36}$/u)
    await expect(page.getByText('3 people · €3.34 each', { exact: true })).toBeVisible()
    const memberPart = initial.participants.find(part => part.user.id === memberId)!
    const originalShareResponse = await request.get(baseUrl + '/api/v1/transfers/' + memberPart.transferId, { headers: { Authorization: 'Bearer ' + memberAccess } })
    const originalShare = await originalShareResponse.json() as { id: string, connectId: string }
    const manualResponse = await request.post(baseUrl + '/api/v1/connects/' + authorPayerConnect + '/transfers', {
      headers: { 'Authorization': 'Bearer ' + authorAccess, 'Idempotency-Key': randomUUID() },
      data: { senderId: authorId, receiverId: payerId, amount: 100 }
    })
    expect(manualResponse.status()).toBe(201)
    const staleEditor = await page.context().newPage()
    await staleEditor.goto('/debts/' + debtId + '/edit')
    await waitForNuxtHydration(staleEditor)
    await expect(staleEditor.getByRole('button', { name: 'Save changes', exact: true })).toBeEnabled()
    await page.getByRole('link', { name: 'Edit expense', exact: true }).click()
    await waitForNuxtHydration(page)
    await expect(page.getByRole('button', { name: 'Save changes', exact: true })).toBeEnabled()
    await expect(page.locator('input[value="' + authorId + '"]')).toBeDisabled()
    await expect(page.getByLabel('Who paid?', { exact: true })).toHaveCount(0)
    await page.getByLabel('Amount in EUR', { exact: true }).fill('12.01')
    await page.locator('input[value="' + memberId + '"]').uncheck()
    await page.getByLabel('Include the payer in the split', { exact: true }).uncheck()
    await expect(page.getByText('Each share: €12.01. Every current share will be recalculated.', { exact: true })).toBeVisible()
    const updated = page.waitForResponse(response => new URL(response.url()).pathname === debtPath && response.request().method() === 'PUT')
    await page.getByRole('button', { name: 'Save changes', exact: true }).click()
    const saved = await updated
    expect(saved.status()).toBe(200)
    expect(saved.request().headers()['if-match']).toBe('"1"')
    expect(saved.headers()['etag']).toBe('"2"')
    await expect(page).toHaveURL(new RegExp('/debts/' + debtId + '$', 'u'))
    await expect(page.getByText('1 people · €12.01 each', { exact: true })).toBeVisible()
    await member.goto('/debts/' + debtId)
    await expect(member.getByRole('alert')).toContainText('Expense unavailable')
    await member.goto('/transfers/' + originalShare.id)
    await expect(member.getByText('Removed · does not affect the balance', { exact: true })).toBeVisible()
    await expect(member.getByRole('link', { name: title, exact: true })).toHaveCount(0)
    await member.goto('/connects/' + originalShare.connectId)
    await expect(member.getByText('Balance: €0.00', { exact: true })).toBeVisible()
    await expect(member.getByText('You are settled up.', { exact: true })).toBeVisible()
    await expect(member.getByRole('link', { name: 'Expense share · €3.34', exact: true })).toBeVisible()
    await payer.goto('/connects/' + authorPayerConnect)
    await expect(payer.getByText('Balance: €11.01', { exact: true })).toBeVisible()
    await expect(payer.getByText('They owe you.', { exact: true })).toBeVisible()
    let staleCommands = 0
    staleEditor.on('request', (sent) => {
      if (sent.method() === 'PUT' && new URL(sent.url()).pathname === debtPath) ++staleCommands
    })
    await staleEditor.getByLabel('Title', { exact: true }).fill('Stale overwrite')
    const conflicted = staleEditor.waitForResponse(response => new URL(response.url()).pathname === debtPath && response.request().method() === 'PUT')
    await staleEditor.getByRole('button', { name: 'Save changes', exact: true }).click()
    const conflict = await conflicted
    expect(conflict.status()).toBe(409)
    expect(conflict.request().headers()['if-match']).toBe('"1"')
    expect((await conflict.json() as { error: { code: string } }).error.code).toBe('VERSION_CONFLICT')
    await expect(staleEditor.getByRole('alert')).toContainText('Someone changed this expense')
    await expect(staleEditor.getByRole('button', { name: 'Save changes', exact: true })).toBeDisabled()
    expect(staleCommands).toBe(1)
    await staleEditor.getByRole('button', { name: 'Reload expense and discard draft', exact: true }).click()
    await expect(staleEditor.getByLabel('Title', { exact: true })).toHaveValue(title)
    await expect(staleEditor.getByLabel('Amount in EUR', { exact: true })).toHaveValue('12.01')
    await expect(staleEditor.getByRole('button', { name: 'Save changes', exact: true })).toBeEnabled()
    expect(staleCommands).toBe(1)
    await staleEditor.locator('input[value="' + memberId + '"]').check()
    await staleEditor.getByLabel('Include the payer in the split', { exact: true }).check()
    await staleEditor.getByLabel('Amount in EUR', { exact: true }).fill('13.01')
    const restored = staleEditor.waitForResponse(response => new URL(response.url()).pathname === debtPath && response.request().method() === 'PUT')
    await staleEditor.getByRole('button', { name: 'Save changes', exact: true }).click()
    const restoredResponse = await restored
    expect(restoredResponse.status()).toBe(200)
    expect(restoredResponse.request().headers()['if-match']).toBe('"2"')
    expect(restoredResponse.headers()['etag']).toBe('"3"')
    await expect(staleEditor).toHaveURL(new RegExp('/debts/' + debtId + '$', 'u'))
    await expect(staleEditor.getByText('3 people · €4.34 each', { exact: true })).toBeVisible()
    expect(staleCommands).toBe(2)
    for (const viewer of [payer, member]) {
      await viewer.goto('/debts/' + debtId)
      await expect(viewer.getByRole('heading', { name: title, exact: true })).toBeVisible()
      await expect(viewer.getByText('3 people · €4.34 each', { exact: true })).toBeVisible()
      await expect(viewer.getByRole('link', { name: 'Edit expense', exact: true })).toHaveCount(0)
    }
    for (const [viewer, balance, meaning] of [[payer, 'Balance: €4.34', 'They owe you.'], [member, 'Balance: -€4.34', 'You owe them.']] as const) {
      await viewer.goto('/connects/' + originalShare.connectId)
      await expect(viewer.getByText(balance, { exact: true })).toBeVisible()
      await expect(viewer.getByText(meaning, { exact: true })).toBeVisible()
      await expect(viewer.getByRole('link', { name: 'Expense share · €4.34', exact: true })).toHaveCount(1)
    }
    await page.goto('/connects/' + authorPayerConnect)
    await expect(page.getByText('Balance: -€3.34', { exact: true })).toBeVisible()
    await expect(page.getByText('You owe them.', { exact: true })).toBeVisible()
    await expect(page.getByRole('link', { name: 'Expense share · €4.34', exact: true })).toBeVisible()
    await expect(page.getByRole('link', { name: 'Payment · €1.00', exact: true })).toBeVisible()
    await expectNoPersistentSecrets(page, [authorAccess, payerAccess, memberAccess, testPassword])
    await expectNoPersistentSecrets(staleEditor, [authorAccess, payerAccess, memberAccess, testPassword])
    await expectNoPersistentSecrets(payer, [authorAccess, payerAccess, memberAccess, testPassword])
    await expectNoPersistentSecrets(member, [authorAccess, payerAccess, memberAccess, testPassword])
    await staleEditor.close()
  }
  finally {
    await payerContext.close()
    await memberContext.close()
  }
})

test('expense removal drops automatic shares while preserving payment history and a read-only direct view', async ({ page, request }) => {
  test.setTimeout(120_000)
  const authorEmail = await createActiveUser(request, uniqueEmail('remove-author'))
  const peerEmail = await createActiveUser(request, uniqueEmail('remove-peer'))
  const authorAccess = await login(page, authorEmail)
  const peerAccess = await loginViaApi(request, peerEmail)
  const profile = async (access: string) => {
    const response = await request.get(baseUrl + '/api/v1/me', { headers: { Authorization: 'Bearer ' + access } })
    expect(response.status()).toBe(200)
    return (await response.json() as { id: string }).id
  }
  const authorId = await profile(authorAccess)
  const peerId = await profile(peerAccess)
  const invited = await request.post(baseUrl + '/api/v1/connect-invitations', {
    headers: { 'Authorization': 'Bearer ' + authorAccess, 'X-Forwarded-For': nextForwardedFor() }
  })
  expect(invited.status()).toBe(200)
  const token = (await invited.json() as { token: string }).token
  const accepted = await request.post(baseUrl + '/api/v1/connect-invitations/' + token + '/accept', {
    headers: { 'Authorization': 'Bearer ' + peerAccess, 'X-Forwarded-For': nextForwardedFor() }
  })
  expect(accepted.status()).toBe(201)
  const connectId = (await accepted.json() as { id: string }).id
  const title = 'Removed dinner ' + Date.now()
  const created = await request.post(baseUrl + '/api/v1/debts', {
    headers: { 'Authorization': 'Bearer ' + authorAccess, 'Idempotency-Key': randomUUID() },
    data: { title, description: 'Keep this historical context', totalAmount: 501, payerId: authorId, participantIds: [peerId], includePayerInSplit: false }
  })
  expect(created.status()).toBe(201)
  const debt = await created.json() as { id: string, participants: Array<{ user: { id: string }, transferId: string | null }> }
  const shareId = debt.participants.find(participant => participant.user.id === peerId)?.transferId
  expect(typeof shareId).toBe('string')
  const payment = await request.post(baseUrl + '/api/v1/connects/' + connectId + '/transfers', {
    headers: { 'Authorization': 'Bearer ' + peerAccess, 'Idempotency-Key': randomUUID() },
    data: { senderId: peerId, receiverId: authorId, amount: 100 }
  })
  expect(payment.status()).toBe(201)
  const paymentId = (await payment.json() as { id: string }).id

  const debtPath = '/api/v1/debts/' + debt.id
  await page.goto('/debts/' + debt.id)
  await waitForNuxtHydration(page)
  await expect(page.getByRole('heading', { name: title, exact: true })).toBeVisible()
  let deleteCommands = 0
  page.on('request', (sent) => {
    if (sent.method() === 'DELETE' && new URL(sent.url()).pathname === debtPath) ++deleteCommands
  })
  await page.getByRole('button', { name: 'Remove expense', exact: true }).click()
  await expect(page.getByRole('alertdialog')).toContainText('This cannot be undone')
  expect(deleteCommands).toBe(0)
  const removed = page.waitForResponse(response => response.request().method() === 'DELETE' && new URL(response.url()).pathname === debtPath)
  await page.getByRole('button', { name: 'Yes, remove expense', exact: true }).click()
  const removedResponse = await removed
  expect(removedResponse.status()).toBe(204)
  expect(removedResponse.request().headers()['if-match']).toBe('"1"')
  await expect(page.getByText('Removed expense · the saved participant list is shown below.', { exact: true })).toBeVisible()
  await expect(page.getByRole('link', { name: 'Edit expense', exact: true })).toHaveCount(0)
  await expect(page.getByRole('button', { name: 'Remove expense', exact: true })).toHaveCount(0)
  expect(deleteCommands).toBe(1)
  await page.goto('/debts')
  await expect(page.getByRole('link', { name: title, exact: true })).toHaveCount(0)

  const peerDebt = await request.get(baseUrl + debtPath, { headers: { Authorization: 'Bearer ' + peerAccess } })
  expect(peerDebt.status()).toBe(200)
  expect(peerDebt.headers()['etag']).toBe('"2"')
  const peerDebtBody = await peerDebt.json() as { isDeleted: boolean, canEdit: boolean, canDelete: boolean, participantCount: number }
  expect(peerDebtBody).toMatchObject({ isDeleted: true, canEdit: false, canDelete: false, participantCount: 1 })
  const peerConnect = await request.get(baseUrl + '/api/v1/connects/' + connectId, { headers: { Authorization: 'Bearer ' + peerAccess } })
  expect(peerConnect.status()).toBe(200)
  expect((await peerConnect.json() as { balanceAmount: number }).balanceAmount).toBe(100)
  const history = await request.get(baseUrl + '/api/v1/connects/' + connectId + '/transfers', { headers: { Authorization: 'Bearer ' + peerAccess } })
  expect(history.status()).toBe(200)
  const operations = (await history.json() as { items: Array<{ id: string, isDeleted: boolean }> }).items
  expect(operations.find(operation => operation.id === shareId)?.isDeleted).toBe(true)
  expect(operations.find(operation => operation.id === paymentId)?.isDeleted).toBe(false)
  await expectNoPersistentSecrets(page, [authorAccess, peerAccess, testPassword])
})

test('manual payment correction updates balance, rejects a stale editor and preserves removed history', async ({ page, request }) => {
  test.setTimeout(150_000)
  const authorEmail = await createActiveUser(request, uniqueEmail('correct-payment-author'))
  const peerEmail = await createActiveUser(request, uniqueEmail('correct-payment-peer'))
  const authorAccess = await login(page, authorEmail)
  const peerAccess = await loginViaApi(request, peerEmail)
  const profile = async (access: string) => {
    const response = await request.get(baseUrl + '/api/v1/me', { headers: { Authorization: 'Bearer ' + access } })
    expect(response.status()).toBe(200)
    return (await response.json() as { id: string }).id
  }
  const authorId = await profile(authorAccess)
  const peerId = await profile(peerAccess)
  const invited = await request.post(baseUrl + '/api/v1/connect-invitations', {
    headers: { 'Authorization': 'Bearer ' + authorAccess, 'X-Forwarded-For': nextForwardedFor() }
  })
  expect(invited.status()).toBe(200)
  const token = (await invited.json() as { token: string }).token
  const accepted = await request.post(baseUrl + '/api/v1/connect-invitations/' + token + '/accept', {
    headers: { 'Authorization': 'Bearer ' + peerAccess, 'X-Forwarded-For': nextForwardedFor() }
  })
  expect(accepted.status()).toBe(201)
  const connectId = (await accepted.json() as { id: string }).id
  const created = await request.post(baseUrl + '/api/v1/connects/' + connectId + '/transfers', {
    headers: { 'Authorization': 'Bearer ' + authorAccess, 'Idempotency-Key': randomUUID() },
    data: { senderId: peerId, receiverId: authorId, amount: 700 }
  })
  expect(created.status()).toBe(201)
  const transferId = (await created.json() as { id: string }).id
  const transferPath = '/api/v1/transfers/' + transferId
  await page.goto('/transfers/' + transferId)
  await waitForNuxtHydration(page)
  await expect(page.getByRole('heading', { name: 'Payment · €7.00', exact: true })).toBeVisible()
  const staleEditor = await page.context().newPage()
  await staleEditor.goto('/transfers/' + transferId)
  await waitForNuxtHydration(staleEditor)
  await staleEditor.getByRole('button', { name: 'Edit payment', exact: true }).click()
  await expect(staleEditor.getByRole('button', { name: 'Save payment changes', exact: true })).toBeEnabled()

  await page.getByRole('button', { name: 'Edit payment', exact: true }).click()
  await page.locator('#edit-payment-direction').selectOption(authorId)
  await page.getByLabel('Payment amount in EUR', { exact: true }).fill('8.00')
  const updated = page.waitForResponse(response => response.request().method() === 'PUT' && new URL(response.url()).pathname === transferPath)
  await page.getByRole('button', { name: 'Save payment changes', exact: true }).click()
  const updatedResponse = await updated
  expect(updatedResponse.status()).toBe(200)
  expect(updatedResponse.request().headers()['if-match']).toBe('"1"')
  expect(updatedResponse.headers()['etag']).toBe('"2"')
  await expect(page.getByRole('heading', { name: 'Payment · €8.00', exact: true })).toBeVisible()

  await staleEditor.getByLabel('Payment amount in EUR', { exact: true }).fill('9.00')
  const conflicted = staleEditor.waitForResponse(response => response.request().method() === 'PUT' && new URL(response.url()).pathname === transferPath)
  await staleEditor.getByRole('button', { name: 'Save payment changes', exact: true }).click()
  const conflict = await conflicted
  expect(conflict.status()).toBe(409)
  expect(conflict.request().headers()['if-match']).toBe('"1"')
  expect((await conflict.json() as { error: { code: string } }).error.code).toBe('VERSION_CONFLICT')
  await expect(staleEditor.getByRole('alert')).toContainText('Someone changed this payment')
  await staleEditor.getByRole('button', { name: 'Reload payment', exact: true }).click()
  await expect(staleEditor.getByRole('heading', { name: 'Payment · €8.00', exact: true })).toBeVisible()

  const authorConnect = await request.get(baseUrl + '/api/v1/connects/' + connectId, { headers: { Authorization: 'Bearer ' + authorAccess } })
  const peerConnect = await request.get(baseUrl + '/api/v1/connects/' + connectId, { headers: { Authorization: 'Bearer ' + peerAccess } })
  expect((await authorConnect.json() as { balanceAmount: number }).balanceAmount).toBe(800)
  expect((await peerConnect.json() as { balanceAmount: number }).balanceAmount).toBe(-800)
  const peerTransfer = await request.get(baseUrl + transferPath, { headers: { Authorization: 'Bearer ' + peerAccess } })
  expect(peerTransfer.status()).toBe(200)
  expect(await peerTransfer.json()).toMatchObject({ amount: 800, version: 2, isDeleted: false, canEdit: false, canDelete: false, sender: { id: authorId }, receiver: { id: peerId } })

  await page.getByRole('button', { name: 'Remove payment', exact: true }).click()
  await expect(page.getByRole('alertdialog')).toContainText('will remain in history')
  const removed = page.waitForResponse(response => response.request().method() === 'DELETE' && new URL(response.url()).pathname === transferPath)
  await page.getByRole('button', { name: 'Yes, remove payment', exact: true }).click()
  const removedResponse = await removed
  expect(removedResponse.status()).toBe(204)
  expect(removedResponse.request().headers()['if-match']).toBe('"2"')
  await expect(page.getByText('Removed · does not affect the balance', { exact: true })).toBeVisible()
  await expect(page.getByRole('button', { name: /edit payment|remove payment/iu })).toHaveCount(0)
  const history = await request.get(baseUrl + '/api/v1/connects/' + connectId + '/transfers', { headers: { Authorization: 'Bearer ' + peerAccess } })
  const operations = (await history.json() as { items: Array<{ id: string, isDeleted: boolean, version: number }> }).items
  expect(operations.find(operation => operation.id === transferId)).toMatchObject({ isDeleted: true, version: 3 })
  const settled = await request.get(baseUrl + '/api/v1/connects/' + connectId, { headers: { Authorization: 'Bearer ' + authorAccess } })
  expect((await settled.json() as { balanceAmount: number }).balanceAmount).toBe(0)

  const debt = await request.post(baseUrl + '/api/v1/debts', {
    headers: { 'Authorization': 'Bearer ' + authorAccess, 'Idempotency-Key': randomUUID() },
    data: { title: 'Immutable share', description: null, totalAmount: 100, payerId: authorId, participantIds: [peerId], includePayerInSplit: false }
  })
  expect(debt.status()).toBe(201)
  const shareId = (await debt.json() as { participants: Array<{ transferId: string | null }> }).participants[0]!.transferId
  expect(typeof shareId).toBe('string')
  await page.goto('/transfers/' + shareId)
  await expect(page.getByRole('heading', { name: 'Expense share · €1.00', exact: true })).toBeVisible()
  await expect(page.getByRole('button', { name: /edit payment|remove payment/iu })).toHaveCount(0)
  const forbiddenShareDelete = await request.delete(baseUrl + '/api/v1/transfers/' + shareId, { headers: { 'Authorization': 'Bearer ' + authorAccess, 'If-Match': '"1"' } })
  expect(forbiddenShareDelete.status()).toBe(403)
  expect((await forbiddenShareDelete.json() as { error: { code: string } }).error.code).toBe('AUTOMATIC_TRANSFER_IMMUTABLE')
  await expectNoPersistentSecrets(page, [authorAccess, peerAccess, testPassword])
  await expectNoPersistentSecrets(staleEditor, [authorAccess, peerAccess, testPassword])
  await staleEditor.close()
})

test('home dashboard separates debts, shows five recent expenses and clears on logout', async ({ page, request }, testInfo) => {
  test.setTimeout(150_000)
  const apiPaths: string[] = []
  page.on('request', sent => apiPaths.push(new URL(sent.url()).pathname))
  await page.goto('/')
  await expect(page.getByRole('link', { name: 'Create an account', exact: true })).toBeVisible()
  await expect(page.getByText('API connection', { exact: true })).toHaveCount(0)
  expect(apiPaths).not.toContain('/api/v1/transfers/balance-summary')

  const ownerEmail = await createActiveUser(request, uniqueEmail('dashboard-owner'))
  const ownerAccess = await login(page, ownerEmail, '/', false)
  await expect(page.getByRole('heading', { name: 'Your overview', exact: true })).toBeVisible()
  await expect(page.getByRole('region', { name: 'You owe', exact: true })).toContainText('€0.00')
  await expect(page.getByRole('region', { name: 'You are owed', exact: true })).toContainText('€0.00')
  await expect(page.getByText('No expenses yet', { exact: true })).toBeVisible()
  await expect(page.getByRole('link', { name: 'Sign in', exact: true })).toHaveCount(0)
  const ownerProfile = await request.get(baseUrl + '/api/v1/me', { headers: { Authorization: 'Bearer ' + ownerAccess } })
  const ownerId = (await ownerProfile.json() as { id: string }).id
  {
    const peers: Array<{ id: string, access: string, connectId: string }> = []
    for (let peer = 0; peer < 2; ++peer) {
      const email = await createActiveUser(request, uniqueEmail('dashboard-peer'))
      const access = await loginViaApi(request, email)
      const profile = await request.get(baseUrl + '/api/v1/me', { headers: { Authorization: 'Bearer ' + access } })
      const id = (await profile.json() as { id: string }).id
      const invited = await request.post(baseUrl + '/api/v1/connect-invitations', {
        headers: { 'Authorization': 'Bearer ' + ownerAccess, 'X-Forwarded-For': nextForwardedFor() }
      })
      expect(invited.status()).toBe(200)
      const token = (await invited.json() as { token: string }).token
      const accepted = await request.post(baseUrl + '/api/v1/connect-invitations/' + token + '/accept', {
        headers: { 'Authorization': 'Bearer ' + access, 'X-Forwarded-For': nextForwardedFor() }
      })
      expect(accepted.status()).toBe(201)
      peers.push({ id, access, connectId: (await accepted.json() as { id: string }).id })
    }
    const created: Array<{ id: string, title: string, createdAt: string }> = []
    for (let n = 0; n < 7; ++n) {
      const debt = await request.post(baseUrl + '/api/v1/debts', {
        headers: { 'Authorization': 'Bearer ' + ownerAccess, 'Idempotency-Key': randomUUID() },
        data: {
          title: `Dashboard expense ${n}`, description: null, totalAmount: 1000,
          payerId: n === 6 ? peers[1]!.id : ownerId,
          participantIds: [n === 6 ? ownerId : peers[0]!.id], includePayerInSplit: false
        }
      })
      expect(debt.status()).toBe(201)
      created.push(await debt.json() as typeof created[number])
    }
    const payment = await request.post(baseUrl + '/api/v1/connects/' + peers[0]!.connectId + '/transfers', {
      headers: { 'Authorization': 'Bearer ' + ownerAccess, 'Idempotency-Key': randomUUID() },
      data: { senderId: peers[0]!.id, receiverId: ownerId, amount: 5300 }
    })
    expect(payment.status()).toBe(201)
    const latest = created.sort((a, b) => b.createdAt.localeCompare(a.createdAt) || b.id.localeCompare(a.id)).slice(0, 5)
    await page.reload()
    await expect(page.getByRole('region', { name: 'You owe', exact: true })).toContainText('€10.00')
    await expect(page.getByRole('region', { name: 'You are owed', exact: true })).toContainText('€7.00')
    const rows = page.getByRole('list', { name: 'Latest expenses', exact: true }).locator('li')
    await expect(rows).toHaveCount(5)
    expect(await rows.locator('a').allTextContents()).toEqual(latest.map(expense => expense.title))
    await expect(rows.first().locator('time')).toHaveAttribute('datetime', latest[0]!.createdAt)
    await expect(rows.first()).toContainText('Paid by E2E User')
    await expect(page.getByText('API connection', { exact: true })).toHaveCount(0)
    await page.screenshot({ path: testInfo.outputPath('dashboard-desktop.png'), fullPage: true })
    await page.setViewportSize({ width: 390, height: 844 })
    expect(await page.evaluate(() => document.documentElement.scrollWidth <= window.innerWidth)).toBe(true)
    await page.screenshot({ path: testInfo.outputPath('dashboard-mobile.png'), fullPage: true })
    await rows.first().getByRole('link').click()
    await expect(page.getByRole('heading', { level: 1 })).toHaveText(latest[0]!.title)
    await page.route('**/api/v1/transfers/balance-summary', safeRouteAbort, { times: 1 })
    await page.goto('/')
    await expect(page.getByRole('alert')).toContainText('Your overview could not be loaded')
    await expect(page.getByText('€0.00', { exact: true })).toHaveCount(0)
    await page.getByRole('button', { name: 'Try again', exact: true }).click()
    await expect(page.getByRole('region', { name: 'You are owed', exact: true })).toContainText('€7.00')
    const ssr = await request.get(baseUrl + '/')
    expect((await ssr.text()).includes('Dashboard expense')).toBe(false)
    await page.goto('/me')
    await page.getByRole('button', { name: 'Sign out', exact: true }).click()
    await expect(page).toHaveURL(/\/login\?signedOut=1$/u)
    await page.goto('/')
    await expect(page.getByRole('link', { name: 'Create an account', exact: true })).toBeVisible()
    await expect(page.getByRole('heading', { name: 'Your overview', exact: true })).toHaveCount(0)
    expect(await page.locator('main').innerText()).not.toContain('Dashboard expense')
    expect(apiPaths).not.toContain('/api/health')
    await expectNoPersistentSecrets(page, [ownerAccess, ...peers.map(peer => peer.access), testPassword])
  }
})
