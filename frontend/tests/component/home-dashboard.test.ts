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
const summary = { totalOwedAmount: 3000, totalReceivableAmount: 5000 }
const list = { items: expenses, pagination: { nextCursor: 'next', hasMore: true } }
function signedIn(id = 'self') {
  useCurrentUser().setAuthenticated({ id, name: 'Alex', email: 'self@example.test', isActive: true, createdAt: '2026-10-18T12:00:00Z' })
}
function respond(path: string) {
  return path === '/v1/transfers/balance-summary' ? summary : list
}

describe('home dashboard', () => {
  beforeEach(() => {
    auth.protectedRequest.mockReset().mockImplementation(async (path: string) => respond(path))
    auth.retryBootstrap.mockReset().mockResolvedValue(undefined)
    api.mockReset()
    signedIn()
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

  it('renders separate totals and five linked dated expenses through the shared client', async () => {
    const wrapper = await mountSuspended(HomePage)
    await flushPromises()
    expect(wrapper.get('[aria-labelledby="owed-title"]').text()).toContain('€30.00')
    expect(wrapper.get('[aria-labelledby="receivable-title"]').text()).toContain('€50.00')
    const connectionSummaryLinks = wrapper.findAll('a[href="/connects"]')
    expect(connectionSummaryLinks).toHaveLength(2)
    expect(connectionSummaryLinks.every(link => link.text() === 'Across all your connections')).toBe(true)
    expect(wrapper.get('a[href="/debts/new"]').text()).toBe('New expense')
    expect(wrapper.findAll('ol li')).toHaveLength(5)
    expect(wrapper.get('a[href="/debts/expense-0"]').text()).toBe('Dinner 0')
    expect(wrapper.get('time').attributes('datetime')).toBe('2026-10-18T12:00:00Z')
    expect(wrapper.text()).toContain('18 Oct 2026')
    expect(wrapper.text()).toContain('Paid by Boris')
    expect(wrapper.text()).toContain('€10.01')
    expect(wrapper.text()).not.toMatch(/Sign in|Create an account|API connection/)
    expect(auth.protectedRequest).toHaveBeenCalledWith('/v1/transfers/balance-summary')
    expect(auth.protectedRequest).toHaveBeenCalledWith('/v1/debts', { query: { limit: 5 } })
    expect(auth.protectedRequest).toHaveBeenCalledTimes(2)
    expect(api).not.toHaveBeenCalled()
    wrapper.unmount()
  })

  it('does not show zeros while pending, then shows an honest empty state', async () => {
    let resolve: (value: unknown) => void = () => undefined
    auth.protectedRequest.mockImplementation((path: string) => path === '/v1/transfers/balance-summary'
      ? new Promise((done) => { resolve = done })
      : Promise.resolve({ items: [], pagination: { nextCursor: null, hasMore: false } }))
    const wrapper = await mountSuspended(HomePage)
    expect(wrapper.text()).toContain('Loading your overview')
    expect(wrapper.text()).not.toContain('€0.00')
    resolve({ totalOwedAmount: 0, totalReceivableAmount: 0 })
    await flushPromises()
    expect(wrapper.text()).toContain('No expenses yet')
    expect(wrapper.get('a[href="/debts/new"]').text()).toBe('New expense')
    expect(wrapper.findAll('.ui-amount').map(node => node.text())).toEqual(['€0.00', '€0.00'])
    wrapper.unmount()
  })

  it('does not turn a failed expense request into zeros and retries explicitly', async () => {
    auth.protectedRequest.mockImplementation(async (path: string) => {
      if (path === '/v1/debts') throw new ApiClientError({ kind: 'network', code: 'NETWORK_ERROR', message: 'unsafe SQL details' })
      return summary
    })
    const wrapper = await mountSuspended(HomePage)
    await flushPromises()
    expect(wrapper.text()).toContain('Your overview could not be loaded')
    expect(wrapper.text()).not.toMatch(/€0.00|€30.00|unsafe SQL|No expenses yet/)
    expect(auth.protectedRequest).toHaveBeenCalledTimes(2)
    auth.protectedRequest.mockImplementation(async (path: string) => respond(path))
    await wrapper.get('button').trigger('click')
    await flushPromises()
    expect(wrapper.text()).toContain('€30.00')
    expect(auth.protectedRequest).toHaveBeenCalledTimes(4)
    wrapper.unmount()
  })

  it('discards old account responses and clears financial data on logout', async () => {
    const oldRequests: Array<(value: unknown) => void> = []
    auth.protectedRequest.mockImplementation(() => new Promise(done => oldRequests.push(done)))
    const wrapper = await mountSuspended(HomePage)
    auth.protectedRequest.mockImplementation(async (path: string) => path === '/v1/debts'
      ? { items: [], pagination: { nextCursor: null, hasMore: false } }
      : { totalOwedAmount: 0, totalReceivableAmount: 100 })
    signedIn('next-account')
    await flushPromises()
    expect(wrapper.text()).toContain('€1.00')
    oldRequests[0]!(summary)
    oldRequests[1]!(list)
    await flushPromises()
    expect(wrapper.text()).not.toMatch(/Dinner|€30.00|€50.00/)
    useCurrentUser().setAnonymous()
    await flushPromises()
    expect(wrapper.text()).toContain('Create an account')
    expect(wrapper.text()).not.toContain('€1.00')
    wrapper.unmount()
  })
})
