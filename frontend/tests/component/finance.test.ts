import {
  flushPromises
} from '@vue/test-utils'
import {
  mockNuxtImport, mountSuspended
} from '@nuxt/test-utils/runtime'
import {
  beforeEach, describe, expect, it, vi
} from 'vitest'
import CreateDebtForm from '../../app/components/CreateDebtForm.vue'
import DebtsPage from '../../app/pages/debts/index.vue'
import ConnectsPage from '../../app/pages/connects/index.vue'
import DebtCard from '../../app/pages/debts/[id].vue'
import TransferCard from '../../app/pages/transfers/[id].vue'
import TransferHistory from '../../app/components/TransferHistory.vue'
import ConnectBalance from '../../app/components/ConnectBalance.vue'
import {
  ApiClientError
} from '../../app/utils/api-error'

const {
  auth, navigation
} = vi.hoisted(() => ({
  auth: {
    protectedRequest: vi.fn(), bootstrap: vi.fn()
  }, navigation: vi.fn()
}))
mockNuxtImport('useNuxtApp', original => () => new Proxy(original(), {
  get(target, property, receiver) {
    if (property === '$auth') return auth
    return Reflect.get(target, property, receiver)
  }
}))
mockNuxtImport('navigateTo', () => navigation)
const self = {
  id: 'self', name: 'Alex', isActive: true
}
const other = {
  id: 'other', name: 'Boris', isActive: true
}
const debt = {
  id: 'debt', title: 'Dinner', description: 'Food', totalAmount: 1001, payer: self, createdBy: self, participantCount: 2, shareAmount: 501, includePayerInSplit: true, canEdit: true, canDelete: true, isDeleted: false, version: 1, createdAt: '2026-10-11T12:00:00Z', participants: [{
    debtParticipantId: 'p0', user: self, isPayer: true, isAuthor: true, shareAmount: 501, transferId: null
  }, {
    debtParticipantId: 'p1', user: other, isPayer: false, isAuthor: false, shareAmount: 501, transferId: 'transfer'
  }]
}
const transfer = {
  id: 'transfer', connectId: 'connect', sender: self, receiver: other, amount: 501, type: 'DEBT_SHARE', debtReference: {
    id: 'debt', isAccessible: true, title: 'Dinner', isDeleted: false
  }, createdBy: null, relatedTransferId: null, isDeleted: false, canEdit: false, canDelete: false, version: 1, createdAt: debt.createdAt
}
const page = (items: unknown[], nextCursor: string | null = null) => ({
  items, pagination: {
    nextCursor, hasMore: !!nextCursor
  }
})
function signedIn(id = 'self'): void {
  useCurrentUser().setAuthenticated({
    id, name: 'Alex', email: 'private@example.test', isActive: true, createdAt: debt.createdAt
  })
}
function options(path: string) {
  if (path === '/v1/connects') return page([{
    otherUser: other
  }])
  if (path === '/v1/debts') return page([])
  return debt
}
async function fill(wrapper: Awaited<ReturnType<typeof mountSuspended>>): Promise<void> {
  await wrapper.get('#debt-title').setValue('Dinner')
  await wrapper.get('#debt-amount').setValue('10.01')
  await wrapper.get('input[value="other"]').setValue(true)
}
describe('financial journey components', () => {
  beforeEach(() => {
    auth.protectedRequest.mockReset().mockImplementation(async (path: string) => options(path))
    auth.bootstrap.mockReset().mockResolvedValue(undefined)
    navigation.mockReset().mockResolvedValue(undefined)
    signedIn()
  })
  it('creates once, previews rounding and waits for confirmation before navigation', async () => {
    let resolve: (value: typeof debt) => void = () => undefined
    auth.protectedRequest.mockImplementation((path, options_) => options_?.method === 'POST'
      ? new Promise((done) => {
          resolve = done
        })
      : Promise.resolve(options(path)))
    const wrapper = await mountSuspended(CreateDebtForm)
    signedIn()
    await flushPromises()
    await fill(wrapper)
    expect(wrapper.text()).toContain('Each share: €5.01')
    await wrapper.get('form').trigger('submit')
    await wrapper.get('form').trigger('submit')
    expect(auth.protectedRequest.mock.calls.filter(call => call[1]?.method === 'POST')).toHaveLength(1)
    expect(navigation).not.toHaveBeenCalled()
    resolve(debt)
    await flushPromises()
    expect(navigation).toHaveBeenCalledWith('/debts/debt')
    const command = auth.protectedRequest.mock.calls.find(call => call[1]?.method === 'POST')![1]
    expect(command.body.totalAmount).toBe(1001)
    expect(command.body.participantIds).toEqual(['other'])
    wrapper.unmount()
  })
  it('freezes an uncertain command and reuses its body and key only on explicit retry', async () => {
    let attempts = 0
    auth.protectedRequest.mockImplementation(async (path, options_) => {
      if (options_?.method !== 'POST') return options(path)
      if (++attempts === 1) throw new ApiClientError({
        kind: 'network', code: 'NETWORK_ERROR', message: 'Unsafe SQL details'
      })
      return debt
    })
    const wrapper = await mountSuspended(CreateDebtForm)
    signedIn()
    await flushPromises()
    await fill(wrapper)
    await wrapper.get('form').trigger('submit')
    await flushPromises()
    expect(wrapper.text()).toContain('Retry same expense')
    expect(wrapper.text()).not.toContain('SQL')
    expect(wrapper.get('.expense-fields').attributes('disabled')).toBeDefined()
    expect(attempts).toBe(1)
    await wrapper.get('form').trigger('submit')
    await flushPromises()
    const calls = auth.protectedRequest.mock.calls.filter(call => call[1]?.method === 'POST')
    expect(calls[0]).toEqual(calls[1])
    expect(navigation).toHaveBeenCalledWith('/debts/debt')
    wrapper.unmount()
  })
  it('discards a create response after session changes', async () => {
    let resolve: (value: typeof debt) => void = () => undefined
    auth.protectedRequest.mockImplementation((path, options_) => options_?.method === 'POST'
      ? new Promise((done) => {
          resolve = done
        })
      : Promise.resolve(options(path)))
    const wrapper = await mountSuspended(CreateDebtForm)
    signedIn()
    await flushPromises()
    await fill(wrapper)
    await wrapper.get('form').trigger('submit')
    useCurrentUser().setAnonymous()
    await flushPromises()
    resolve(debt)
    await flushPromises()
    expect(navigation).not.toHaveBeenCalled()
    expect(wrapper.text()).not.toContain('Boris')
    wrapper.unmount()
  })
  it('validates before writing and requires an author who is not payer in the split', async () => {
    const wrapper = await mountSuspended(CreateDebtForm)
    signedIn()
    await flushPromises()
    await wrapper.get('form').trigger('submit')
    expect(wrapper.text()).toContain('Enter a title')
    await fill(wrapper)
    await wrapper.get('#debt-payer').setValue('other')
    await wrapper.get('form').trigger('submit')
    expect(auth.protectedRequest.mock.calls.filter(call => call[1]?.method === 'POST')).toHaveLength(0)
    wrapper.unmount()
  })
  it('loads debt pages and clears old data on an account switch', async () => {
    auth.protectedRequest.mockResolvedValueOnce(page([debt], 'next')).mockResolvedValueOnce(page([{
      ...debt, id: 'second', title: 'Lunch'
    }])).mockResolvedValue(page([]))
    const wrapper = await mountSuspended(DebtsPage)
    await flushPromises()
    expect(wrapper.text()).toContain('Dinner')
    await wrapper.get('button').trigger('click')
    await flushPromises()
    expect(wrapper.text()).toContain('Lunch')
    signedIn('new-account')
    await flushPromises()
    expect(wrapper.text()).not.toContain('Dinner')
    expect(wrapper.text()).toContain('No expenses yet')
    wrapper.unmount()
  })
  it('shows roles, inactive people and removed expense without premature writer controls', async () => {
    auth.protectedRequest.mockResolvedValue({
      ...debt, isDeleted: true, payer: {
        ...self, isActive: false
      }
    })
    const wrapper = await mountSuspended(DebtCard, {
      route: '/debts/debt'
    })
    await flushPromises()
    expect(wrapper.text()).toContain('Removed expense')
    expect(wrapper.text()).toContain('payer')
    expect(wrapper.text()).toContain('author')
    expect(wrapper.text()).toContain('(inactive)')
    expect(wrapper.text()).toContain('€5.01')
    expect(wrapper.text()).not.toContain('private@example.test')
    expect(wrapper.findAll('button')).toHaveLength(0)
    wrapper.unmount()
  })
  it('hides details of an inaccessible debt and safely reports an unavailable card', async () => {
    auth.protectedRequest.mockRejectedValue(new ApiClientError({
      kind: 'api', code: 'DEBT_NOT_AVAILABLE', status: 404, message: 'Unsafe Dinner'
    }))
    const wrapper = await mountSuspended(DebtCard, {
      route: '/debts/debt'
    })
    await flushPromises()
    expect(wrapper.text()).toContain('Expense unavailable')
    expect(wrapper.text()).not.toContain('Dinner')
    wrapper.unmount()
  })
  it('renders removed operation history and links only an accessible expense', async () => {
    auth.protectedRequest.mockResolvedValue(page([{
      ...transfer, isDeleted: true, debtReference: {
        id: 'debt', isAccessible: false
      }
    }]))
    const wrapper = await mountSuspended(TransferHistory, {
      props: {
        connectId: 'connect'
      }
    })
    await flushPromises()
    expect(wrapper.text()).toContain('Removed')
    expect(wrapper.text()).toContain('Related expense unavailable')
    expect(wrapper.text()).not.toContain('Dinner')
    expect(wrapper.find('a[href="/debts/debt"]').exists()).toBe(false)
    wrapper.unmount()
  })
  it('renders the operation card and displays payment as a distinct type', async () => {
    auth.protectedRequest.mockResolvedValue({
      ...transfer, type: 'MANUAL', createdBy: self, debtReference: null, isDeleted: true
    })
    const wrapper = await mountSuspended(TransferCard, {
      route: '/transfers/transfer'
    })
    await flushPromises()
    expect(wrapper.text()).toContain('Payment · €5.01')
    expect(wrapper.text()).toContain('Recorded by Alex')
    expect(wrapper.text()).toContain('does not affect the balance')
    wrapper.unmount()
  })

  it('removes newly inactive participants when people are reloaded after a definitive rejection', async () => {
    let reloaded = false
    auth.protectedRequest.mockImplementation(async (path, options_) => {
      if (options_?.method === 'POST') {
        reloaded = true
        throw new ApiClientError({ kind: 'api', status: 422, code: 'INACTIVE_USER', message: 'Safe error' })
      }
      if (path === '/v1/connects') return page([{ otherUser: { ...other, isActive: !reloaded } }])
      return page([])
    })
    const wrapper = await mountSuspended(CreateDebtForm)
    signedIn()
    await flushPromises()
    await fill(wrapper)
    await wrapper.get('form').trigger('submit')
    await flushPromises()
    await wrapper.findAll('button').find(button => button.text() === 'Reload people')!.trigger('click')
    await flushPromises()
    expect(wrapper.find('input[value="other"]').exists()).toBe(false)
    await wrapper.get('form').trigger('submit')
    expect(auth.protectedRequest.mock.calls.filter(call => call[1]?.method === 'POST')).toHaveLength(1)
    expect(wrapper.text()).toContain('Enter a title')
    wrapper.unmount()
  })
  it('clears connection balances immediately when a new account list is still pending', async () => {
    let resolve: (value: unknown) => void = () => undefined
    auth.protectedRequest.mockResolvedValueOnce(page([{ id: 'connect', otherUser: other, balanceAmount: 501, canCreateDebt: true, canCreateTransfer: true, createdAt: debt.createdAt }]))
      .mockImplementation(() => new Promise((done) => { resolve = done }))
    const wrapper = await mountSuspended(ConnectsPage)
    await flushPromises()
    expect(wrapper.text()).toContain('Boris')
    signedIn('other-viewer')
    await flushPromises()
    expect(wrapper.text()).not.toContain('Boris')
    expect(wrapper.text()).not.toContain('€5.01')
    resolve(page([]))
    await flushPromises()
    expect(wrapper.text()).toContain('No connections yet')
    wrapper.unmount()
  })

  it.each([[501, 'They owe you €5.01'], [-501, 'You owe them €5.01'], [0, 'You are settled up']])('explains balance %s from the viewer perspective', async (amount, text) => {
    const wrapper = await mountSuspended(ConnectBalance, {
      props: {
        amount: amount as number
      }
    })
    expect(wrapper.text()).toContain(text)
    wrapper.unmount()
  })
})
