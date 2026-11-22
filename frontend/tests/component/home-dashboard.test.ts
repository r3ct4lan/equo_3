import { enableAutoUnmount, flushPromises } from '@vue/test-utils'
import { mockNuxtImport, mountSuspended } from '@nuxt/test-utils/runtime'
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import HomePage from '../../app/pages/index.vue'
import { ApiClientError } from '../../app/utils/api-error'

enableAutoUnmount(afterEach)

const { auth, api } = vi.hoisted(() => ({
  auth: { protectedRequest: vi.fn(), retryBootstrap: vi.fn() },
  api: vi.fn()
}))
mockNuxtImport('useNuxtApp', original => () => new Proxy(original(), {
  get(target, property, receiver) {
    if (property === '$auth') return auth
    if (property === '$api') return api
    return Reflect.get(target, property, receiver)
  }
}))
const expenses = Array.from({ length: 5 }, (_, i) => ({
  id: `expense-${i}`, title: `Dinner ${i}`, totalAmount: 1001,
  payer: { id: 'other', name: 'Boris', isActive: true },
  participantCount: 2, shareAmount: 501, canEdit: false, version: 1,
  createdAt: '2026-10-18T12:00:00Z'
}))
const connections = { items: [{ id: 'connect' }], pagination: { nextCursor: null, hasMore: false } }
const emptyPage = { items: [], pagination: { nextCursor: null, hasMore: false } }
const summary = { totalOwedAmount: 3000, totalReceivableAmount: 5000 }
const list = { items: expenses, pagination: { nextCursor: 'next', hasMore: true } }
function signedIn(id = 'self') {
  useCurrentUser().setAuthenticated({ id, name: 'Alex', email: 'self@example.test', isActive: true, createdAt: '2026-10-18T12:00:00Z' })
}
function respond(path: string) {
  if (path === '/v1/connects') return connections
  return path === '/v1/transfers/balance-summary' ? summary : list
}

describe('home dashboard', () => {
  beforeEach(() => {
    auth.protectedRequest.mockReset().mockImplementation(async (path: string) => respond(path))
    auth.retryBootstrap.mockReset().mockResolvedValue(undefined)
    api.mockReset()
    signedIn()
    useState('telegram-mini-app', () => false).value = false
  })

  it('shows guest actions without a health panel or any financial requests', async () => {
    useCurrentUser().setAnonymous()
    const wrapper = await mountSuspended(HomePage)
    await flushPromises()
    // Initial Nuxt bootstrap can finish after mounting; establish the tested session afterwards.
    useCurrentUser().setAnonymous()
    await flushPromises()
    expect(wrapper.find('a[href="/login"]').exists()).toBe(true)
    expect(wrapper.find('a[href="/register"]').exists()).toBe(true)
    expect(wrapper.text()).not.toMatch(/API connection|API available|You owe/)
    expect(auth.protectedRequest).not.toHaveBeenCalled()
    expect(api).not.toHaveBeenCalled()
    wrapper.unmount()
  })

  it('keeps session uncertainty neutral and lets a session error retry bootstrap', async () => {
    useCurrentUser().reset()
    const wrapper = await mountSuspended(HomePage)
    useCurrentUser().reset()
    await flushPromises()
    expect(wrapper.text()).toContain('Checking your session')
    expect(wrapper.find('a[href="/login"]').exists()).toBe(false)
    useCurrentUser().setError(new ApiClientError({ kind: 'network', code: 'NETWORK_ERROR', message: 'private details' }))
    await flushPromises()
    expect(wrapper.text()).toContain('Your session could not be checked')
    expect(wrapper.text()).not.toContain('private details')
    await wrapper.get('button').trigger('click')
    expect(auth.retryBootstrap).toHaveBeenCalledOnce()
    expect(auth.protectedRequest).not.toHaveBeenCalled()
    wrapper.unmount()
  })

  it('offers a Telegram retry without displaying another account during a failed launch', async () => {
    useState('telegram-mini-app', () => false).value = true
    const wrapper = await mountSuspended(HomePage)
    useCurrentUser().setError(new ApiClientError({
      kind: 'api',
      status: 401,
      code: 'TELEGRAM_AUTHENTICATION_FAILED',
      message: 'private launch details'
    }))
    await flushPromises()
    auth.protectedRequest.mockClear()

    expect(wrapper.get('[role="alert"]').text()).toContain('Telegram sign-in could not be completed')
    expect(wrapper.text()).not.toMatch(/Your overview|private launch details/)
    await wrapper.get('button').trigger('click')
    expect(auth.retryBootstrap).toHaveBeenCalledOnce()
    expect(auth.protectedRequest).not.toHaveBeenCalled()
    wrapper.unmount()
  })

  it('offers an invitation after a confirmed empty connections list and copies its link', async () => {
    const url = 'https://example.test/connect-invitations/sample'
    const writeText = vi.fn().mockResolvedValue(undefined)
    vi.spyOn(navigator.clipboard, 'writeText').mockImplementation(writeText)
    try {
      auth.protectedRequest.mockImplementation(async (path: string) => {
        if (path === '/v1/connects') return emptyPage
        if (path === '/v1/connect-invitations') {
          return { url, token: 'sample', expiresAt: '2026-11-27T00:00:00Z', created: true }
        }
        return respond(path)
      })
      const wrapper = await mountSuspended(HomePage)
      await flushPromises()
      expect(wrapper.text()).toContain('Invite a friend')
      expect(wrapper.text()).not.toContain('Your overview')
      expect(auth.protectedRequest.mock.calls).toEqual([['/v1/connects', { query: { limit: '1' } }]])
      await wrapper.get('button').trigger('click')
      await flushPromises()
      expect(auth.protectedRequest).toHaveBeenLastCalledWith('/v1/connect-invitations', { method: 'POST' })
      await wrapper.findAll('button').find(button => button.text() === 'Copy invitation')!.trigger('click')
      await flushPromises()
      expect(writeText).toHaveBeenCalledWith(url)
      expect(wrapper.get('[role="status"]').text()).toBe('Invitation copied.')
    }
    finally {
      vi.restoreAllMocks()
    }
  })

  it('waits for the connections check and retries after a failed request', async () => {
    let reject: (failure: Error) => void = () => undefined
    auth.protectedRequest.mockImplementationOnce(() => new Promise((_, fail) => {
      reject = fail
    }))
    const wrapper = await mountSuspended(HomePage)
    expect(wrapper.text()).not.toMatch(/Invite a friend|Your overview/)
    expect(wrapper.text()).toContain('Loading your connections')
    reject(new Error('private details'))
    await flushPromises()
    expect(wrapper.text()).toContain('Your connections could not be loaded')
    expect(wrapper.text()).not.toMatch(/Invite a friend|private details/)
    await wrapper.get('button').trigger('click')
    await flushPromises()
    expect(wrapper.text()).toContain('Your overview')
  })

  it('renders separate totals and five linked dated expenses through the shared client', async () => {
    const wrapper = await mountSuspended(HomePage)
    await flushPromises()
    expect(wrapper.get('[aria-labelledby="owed-title"]').text()).toContain('€30.00')
    expect(wrapper.get('[aria-labelledby="receivable-title"]').text()).toContain('€50.00')
    const connectionSummaryLinks = wrapper.findAll('a[href="/connects"]')
    expect(connectionSummaryLinks).toHaveLength(2)
    expect(connectionSummaryLinks.every(link => link.text() === 'Across all your connections')).toBe(true)
    expect(wrapper.get('a[href="/debts/new"]').text()).toBe('New expense')
    expect(wrapper.get('a[href="/debts/new"] svg').attributes('aria-hidden')).toBe('true')
    expect(wrapper.findAll('ol li')).toHaveLength(5)
    expect(wrapper.get('a[href="/debts"]').text()).toBe('View all')
    expect(wrapper.get('a[href="/debts/expense-0"]').text()).toBe('Dinner 0')
    expect(wrapper.get('time').attributes('datetime')).toBe('2026-10-18T12:00:00Z')
    expect(wrapper.get('time').text()).toBe(new Date().getUTCFullYear() === 2026 ? '18 Oct' : '18 Oct 2026')
    expect(wrapper.text()).toContain('Paid by Boris')
    expect(wrapper.text()).toContain('€10.01')
    expect(wrapper.text()).not.toMatch(/Sign in|Create an account|API connection/)
    expect(auth.protectedRequest).toHaveBeenCalledWith('/v1/transfers/balance-summary')
    expect(auth.protectedRequest).toHaveBeenCalledWith('/v1/debts', { query: { limit: 5, status: 'active' } })
    expect(auth.protectedRequest).toHaveBeenCalledTimes(3)
    expect(api).not.toHaveBeenCalled()
    wrapper.unmount()
  })

  it('does not show zeros while pending, then shows an honest empty state', async () => {
    let resolve: (value: unknown) => void = () => undefined
    auth.protectedRequest.mockImplementation((path: string) => path === '/v1/transfers/balance-summary'
      ? new Promise((done) => { resolve = done })
      : Promise.resolve(path === '/v1/connects' ? connections : emptyPage))

    const wrapper = await mountSuspended(HomePage)
    await flushPromises()
    expect(wrapper.text()).toContain('Loading your overview')
    expect(wrapper.text()).not.toContain('€0.00')
    resolve({ totalOwedAmount: 0, totalReceivableAmount: 0 })
    await flushPromises()
    expect(wrapper.text()).toContain('No expenses yet')
    expect(wrapper.find('a[href="/debts"]').exists()).toBe(false)
    expect(wrapper.get('a[href="/debts/new"]').text()).toBe('New expense')
    expect(wrapper.findAll('.ui-amount').map(node => node.text())).toEqual(['€0.00', '€0.00'])
    wrapper.unmount()
  })

  it('does not turn a failed expense request into zeros and retries explicitly', async () => {
    auth.protectedRequest.mockImplementation(async (path: string) => {
      if (path === '/v1/debts') throw new ApiClientError({ kind: 'network', code: 'NETWORK_ERROR', message: 'unsafe SQL details' })
      return respond(path)
    })
    const wrapper = await mountSuspended(HomePage)
    await flushPromises()
    expect(wrapper.text()).toContain('Your overview could not be loaded')
    expect(wrapper.text()).not.toMatch(/€0.00|€30.00|unsafe SQL|No expenses yet/)
    expect(auth.protectedRequest).toHaveBeenCalledTimes(3)
    auth.protectedRequest.mockImplementation(async (path: string) => respond(path))
    await wrapper.get('button').trigger('click')
    await flushPromises()
    expect(wrapper.text()).toContain('€30.00')
    expect(auth.protectedRequest).toHaveBeenCalledTimes(5)
    wrapper.unmount()
  })

  it('discards old account responses and clears financial data on logout', async () => {
    const oldRequests: Array<(value: unknown) => void> = []
    auth.protectedRequest.mockImplementation(() => new Promise(done => oldRequests.push(done)))
    const wrapper = await mountSuspended(HomePage)
    auth.protectedRequest.mockImplementation(async (path: string) => path === '/v1/debts'
      ? { items: [], pagination: { nextCursor: null, hasMore: false } }
      : path === '/v1/connects' ? connections : { totalOwedAmount: 0, totalReceivableAmount: 100 })
    signedIn('next-account')
    await flushPromises()
    expect(wrapper.text()).toContain('€1.00')
    oldRequests.forEach(resolve => resolve(emptyPage))
    await flushPromises()
    expect(wrapper.text()).not.toMatch(/Dinner|€30.00|€50.00/)
    useCurrentUser().setAnonymous()
    await flushPromises()
    expect(wrapper.text()).toContain('Create an account')
    expect(wrapper.text()).not.toContain('€1.00')
    wrapper.unmount()
  })
})
