import { flushPromises } from '@vue/test-utils'
import { mockNuxtImport, mountSuspended } from '@nuxt/test-utils/runtime'
import { beforeEach, describe, expect, it, vi } from 'vitest'
import CreateManualTransferForm from '../../app/components/CreateManualTransferForm.vue'
import ConnectCard from '../../app/pages/connects/[id].vue'
import { ApiClientError } from '../../app/utils/api-error'

const { auth } = vi.hoisted(() => ({ auth: { protectedRequest: vi.fn(), bootstrap: vi.fn() } }))
mockNuxtImport('useNuxtApp', original => () => new Proxy(original(), {
  get(target, property, receiver) {
    if (property === '$auth') return auth
    return Reflect.get(target, property, receiver)
  }
}))
const self = { id: 'self', name: 'Alex', isActive: true }
const other = { id: 'other', name: 'Boris', isActive: true }
const connect = { id: 'connect', otherUser: other, balanceAmount: 501, canCreateDebt: true, canCreateTransfer: true, createdAt: '2026-10-11T12:00:00Z' }
const transfer = { id: 'manual', connectId: 'connect', sender: other, receiver: self, amount: 700, type: 'MANUAL', createdBy: self, debtReference: null, relatedTransferId: null, isDeleted: false, canEdit: true, canDelete: true, version: 1, createdAt: connect.createdAt }
function signedIn(id = 'self'): void {
  useCurrentUser().setAuthenticated({ id, name: 'Alex', email: 'private@example.test', isActive: true, createdAt: connect.createdAt })
}
async function mountForm() {
  const wrapper = await mountSuspended(CreateManualTransferForm, { props: { connect } })
  signedIn()
  await flushPromises()
  return wrapper
}
async function fill(wrapper: Awaited<ReturnType<typeof mountForm>>): Promise<void> {
  await wrapper.get('#payment-direction').setValue('other')
  await wrapper.get('#payment-amount').setValue('7.00')
}
describe('manual payment components', () => {
  beforeEach(() => {
    auth.protectedRequest.mockReset().mockResolvedValue(transfer)
    auth.bootstrap.mockReset().mockResolvedValue(undefined)
    signedIn()
  })
  it('validates, previews overpayment and guards double submission until confirmed', async () => {
    let resolve: (value: unknown) => void = () => undefined
    auth.protectedRequest.mockImplementation(() => new Promise((done) => {
      resolve = done
    }))
    const wrapper = await mountForm()
    await wrapper.get('form').trigger('submit')
    expect(auth.protectedRequest).not.toHaveBeenCalled()
    await fill(wrapper)
    expect(wrapper.text()).toContain('Expected balance: -€1.99')
    expect(wrapper.text()).toContain('You would owe them')
    await wrapper.get('form').trigger('submit')
    await wrapper.get('form').trigger('submit')
    expect(auth.protectedRequest).toHaveBeenCalledTimes(1)
    expect(wrapper.emitted('created')).toBeUndefined()
    expect(auth.protectedRequest.mock.calls[0]![1].body).toEqual({ senderId: 'other', receiverId: 'self', amount: 700 })
    resolve(transfer)
    await flushPromises()
    expect(wrapper.emitted('created')).toEqual([[transfer]])
    wrapper.unmount()
  })
  it.each([null, 500])('explicitly retries the identical frozen payment after uncertain %s', async (status) => {
    auth.protectedRequest.mockRejectedValueOnce(new ApiClientError({ kind: status === null ? 'network' : 'api', status, code: 'INTERNAL_SERVER_ERROR', message: 'Unsafe SQL trace' })).mockResolvedValue(transfer)
    const wrapper = await mountForm()
    await fill(wrapper)
    await wrapper.get('form').trigger('submit')
    await flushPromises()
    expect(wrapper.text()).toContain('Retry same payment')
    expect(wrapper.text()).not.toContain('SQL')
    expect(wrapper.get('.payment-fields').attributes('disabled')).toBeDefined()
    expect(auth.protectedRequest).toHaveBeenCalledTimes(1)
    await wrapper.get('form').trigger('submit')
    await flushPromises()
    expect(auth.protectedRequest.mock.calls[0]).toEqual(auth.protectedRequest.mock.calls[1])
    expect(wrapper.emitted('created')).toHaveLength(1)
    wrapper.unmount()
  })
  it('uses a fresh key for a corrected definitively rejected command', async () => {
    auth.protectedRequest.mockRejectedValueOnce(new ApiClientError({ kind: 'api', status: 422, code: 'VALIDATION_ERROR', message: 'Unsafe details' })).mockResolvedValue(transfer)
    const wrapper = await mountForm()
    await fill(wrapper)
    await wrapper.get('form').trigger('submit')
    await flushPromises()
    expect(wrapper.get('.payment-fields').attributes('disabled')).toBeUndefined()
    await wrapper.get('#payment-amount').setValue('8.00')
    await wrapper.get('#payment-direction').setValue('you')
    await wrapper.get('form').trigger('submit')
    await flushPromises()
    const [first, second] = auth.protectedRequest.mock.calls
    expect(first![1].headers['Idempotency-Key']).not.toEqual(second![1].headers['Idempotency-Key'])
    expect(second![1].body).toEqual({ senderId: 'self', receiverId: 'other', amount: 800 })
    wrapper.unmount()
  })
  it.each(['session', 'connect', 'unmount'])('discards late success after %s changes', async (change) => {
    let resolve: (value: unknown) => void = () => undefined
    auth.protectedRequest.mockImplementation(() => new Promise((done) => {
      resolve = done
    }))
    const wrapper = await mountForm()
    await fill(wrapper)
    await wrapper.get('form').trigger('submit')
    if (change === 'session') signedIn('new-user')
    else if (change === 'connect') await wrapper.setProps({ connect: { ...connect, id: 'other-connect' } })
    else wrapper.unmount()
    await flushPromises()
    resolve(transfer)
    await flushPromises()
    expect(wrapper.emitted('created')).toBeUndefined()
    if (change !== 'unmount') wrapper.unmount()
  })
  it('refreshes balance and operations from the server after a confirmed payment', async () => {
    let created = false
    auth.protectedRequest.mockImplementation(async (path, options) => {
      if (options?.method === 'POST') {
        created = true
        return transfer
      }
      if (path === '/v1/connects/connect') return { ...connect, balanceAmount: created ? -199 : 501 }
      return { items: created ? [transfer] : [], pagination: { nextCursor: null, hasMore: false } }
    })
    const wrapper = await mountSuspended(ConnectCard, { route: '/connects/connect' })
    signedIn()
    await flushPromises()
    expect(wrapper.text()).toContain('Balance: €5.01')
    expect(wrapper.text()).toContain('They owe you.')
    expect((wrapper.get('dialog').element as HTMLDialogElement).open).toBe(false)
    await wrapper.findAll('button').find(button => button.text() === 'Make a payment')!.trigger('click')
    await flushPromises()
    expect((wrapper.get('dialog').element as HTMLDialogElement).open).toBe(true)
    await wrapper.get('#payment-direction').setValue('other')
    await wrapper.get('#payment-amount').setValue('7.00')
    await wrapper.get('form').trigger('submit')
    await flushPromises()
    expect(wrapper.text()).toContain('Payment recorded')
    expect(wrapper.text()).toContain('Balance: -€1.99')
    expect(wrapper.text()).toContain('You owe them.')
    expect((wrapper.get('dialog').element as HTMLDialogElement).open).toBe(false)
    expect(wrapper.text()).toContain('Payment · €7.00')
    expect(auth.protectedRequest.mock.calls.filter(call => call[0] === '/v1/connects/connect').length).toBeGreaterThanOrEqual(2)
    expect(auth.protectedRequest.mock.calls.filter(call => call[0] === '/v1/connects/connect/transfers' && call[1]?.method !== 'POST').length).toBeGreaterThanOrEqual(2)
    expect(wrapper.text()).not.toContain('private@example.test')
    wrapper.unmount()
  })
  it('opens as a dialog, preserves a draft across close and restores trigger focus', async () => {
    auth.protectedRequest.mockImplementation(async path => path === '/v1/connects/connect'
      ? connect
      : { items: [], pagination: { nextCursor: null, hasMore: false } })
    const wrapper = await mountSuspended(ConnectCard, { route: '/connects/connect' })
    signedIn()
    await flushPromises()
    const trigger = wrapper.findAll('button').find(button => button.text() === 'Make a payment')!
    const directionFocus = vi.spyOn(wrapper.get('#payment-direction').element as HTMLSelectElement, 'focus')
    const triggerFocus = vi.spyOn(trigger.element as HTMLButtonElement, 'focus')
    await trigger.trigger('click')
    await flushPromises()
    const dialog = wrapper.get('dialog')
    expect((dialog.element as HTMLDialogElement).open).toBe(true)
    expect(directionFocus).toHaveBeenCalledOnce()
    await wrapper.get('#payment-amount').setValue('7.00')
    await dialog.trigger('cancel')
    await flushPromises()
    expect((dialog.element as HTMLDialogElement).open).toBe(false)
    expect(triggerFocus).toHaveBeenCalledOnce()
    await trigger.trigger('click')
    await flushPromises()
    expect(wrapper.get('#payment-amount').element.value).toBe('7.00')
    await wrapper.get('button[aria-label="Close payment form"]').trigger('click')
    expect((dialog.element as HTMLDialogElement).open).toBe(false)
    wrapper.unmount()
  })
  it('preserves the identical retry after an uncertain payment while the dialog is closed', async () => {
    let attempts = 0
    auth.protectedRequest.mockImplementation(async (path, options) => {
      if (options?.method === 'POST') {
        if (++attempts === 1) throw new ApiClientError({ kind: 'network', code: 'NETWORK_ERROR', message: 'Unsafe details' })
        return transfer
      }
      if (path === '/v1/connects/connect') return connect
      return { items: [], pagination: { nextCursor: null, hasMore: false } }
    })
    const wrapper = await mountSuspended(ConnectCard, { route: '/connects/connect' })
    signedIn()
    await flushPromises()
    const trigger = wrapper.findAll('button').find(button => button.text() === 'Make a payment')!
    await trigger.trigger('click')
    await wrapper.get('#payment-direction').setValue('other')
    await wrapper.get('#payment-amount').setValue('7.00')
    await wrapper.get('form').trigger('submit')
    await flushPromises()
    expect(wrapper.text()).toContain('Retry same payment')
    const firstPost = auth.protectedRequest.mock.calls.find(call => call[1]?.method === 'POST')!
    await wrapper.get('button[aria-label="Close payment form"]').trigger('click')
    await trigger.trigger('click')
    await wrapper.get('form').trigger('submit')
    await flushPromises()
    const posts = auth.protectedRequest.mock.calls.filter(call => call[1]?.method === 'POST')
    expect(posts).toHaveLength(2)
    expect(posts[1]).toEqual(firstPost)
    expect(wrapper.text()).toContain('Payment recorded')
    wrapper.unmount()
  })
  it('hides the form for an inactive counterpart while preserving history', async () => {
    auth.protectedRequest.mockImplementation(async path => path === '/v1/connects/connect'
      ? { ...connect, otherUser: { ...other, isActive: false }, canCreateTransfer: false, canCreateDebt: false }
      : { items: [transfer], pagination: { nextCursor: null, hasMore: false } })
    const wrapper = await mountSuspended(ConnectCard, { route: '/connects/connect' })
    signedIn()
    await flushPromises()
    expect(wrapper.find('form').exists()).toBe(false)
    expect(wrapper.text()).toContain('This account is inactive')
    expect(wrapper.text()).toContain('Payment · €7.00')
    wrapper.unmount()
  })
})
