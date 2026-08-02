import type { APIRequestContext, Page } from '@playwright/test'
import { expect, test } from './fixtures'

interface MailpitSummary {
  ID: string
  To: Array<{ Address: string }>
}

interface MailpitMessage {
  HTML: string
}

const mailpitUrl = process.env.E2E_MAILPIT_URL ?? 'http://mailpit:8025'

function uniqueEmail(prefix: string): string {
  return `${prefix}-${Date.now()}-${Math.random().toString(16).slice(2)}@example.test`
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
  await page.getByLabel('Password').fill('A2345678901!')
}

async function waitForMail(request: APIRequestContext, recipient: string): Promise<MailpitMessage> {
  let messageId: string | null = null

  await expect.poll(async () => {
    const response = await request.get(`${mailpitUrl}/api/v1/messages`)
    if (!response.ok()) {
      return false
    }

    const body = await response.json() as { messages: MailpitSummary[] }
    messageId = body.messages.find(message => message.To.some(to => to.Address === recipient))?.ID ?? null

    return messageId !== null
  }, {
    message: `activation email for ${recipient} was not handed to Mailpit`,
    timeout: 30_000
  }).toBe(true)

  const response = await request.get(`${mailpitUrl}/api/v1/message/${messageId}`)
  expect(response.ok()).toBe(true)

  return await response.json() as MailpitMessage
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

test('E2E-01 completes registration, Mailpit delivery and one-time activation', async ({ page, request }) => {
  const email = uniqueEmail('happy')
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
  const activationResponsePromise = page.waitForResponse(response =>
    new URL(response.url()).pathname === '/api/v1/auth/activate'
  )
  await followSecretUrl(page, activationUrl)
  const activationResponse = await activationResponsePromise

  expect(activationResponse.status()).toBe(204)
  await expect(page).toHaveURL(/\/activate$/u)
  await expect(page.getByRole('status')).toContainText('Your account is active')
  await expectNoSessionOrStoredCapability(page)

  await page.reload()
  await expect(page.getByText('Activation link is missing')).toBeVisible()

  const reusedResponsePromise = page.waitForResponse(response =>
    new URL(response.url()).pathname === '/api/v1/auth/activate'
  )
  await followSecretUrl(page, activationUrl)
  const reusedResponse = await reusedResponsePromise

  expect(reusedResponse.status()).toBe(410)
  await expect(page.getByText('Activation link was already used')).toBeVisible()
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
  await expect(page.locator('body')).not.toContainText(invalidCapability)
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
