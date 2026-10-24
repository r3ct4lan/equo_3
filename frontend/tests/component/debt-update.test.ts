import { flushPromises } from '@vue/test-utils'
import { mockNuxtImport, mountSuspended } from '@nuxt/test-utils/runtime'
import { beforeEach, describe, expect, it, vi } from 'vitest'
import EditDebtForm from '../../app/components/EditDebtForm.vue'
import EditPage from '../../app/pages/debts/[id]/edit.vue'
import DebtCard from '../../app/pages/debts/[id]/index.vue'
import { ApiClientError } from '../../app/utils/api-error'

const { auth, navigation } = vi.hoisted(() => ({ auth: { protectedRequest: vi.fn(), bootstrap: vi.fn() }, navigation: vi.fn() }))
mockNuxtImport('useNuxtApp', original => () => new Proxy(original(), {
  get(target, property, receiver) {
    if (property === '$auth') return auth
    return Reflect.get(target, property, receiver)
  }
}))
mockNuxtImport('navigateTo', () => navigation)
const self = { id: 'self', name: 'Alex', isActive: true }
const payer = { id: 'payer', name: 'Boris', isActive: true }
const member = { id: 'member', name: 'Casey', isActive: false }
const newPerson = { id: 'new', name: 'Dana', isActive: true }
const debt = {
  id: 'debt', title: 'Dinner', description: 'Food', totalAmount: 1001, payer, createdBy: self,
  participantCount: 3, shareAmount: 334, includePayerInSplit: true, canEdit: true, canDelete: true, isDeleted: false, version: 1, createdAt: '2026-10-12T12:00:00Z',
  participants: [
    { debtParticipantId: 'p0', user: payer, isPayer: true, isAuthor: false, shareAmount: 334, transferId: null, connectId: 'connect-payer' },
    { debtParticipantId: 'p1', user: self, isPayer: false, isAuthor: true, shareAmount: 334, transferId: 's1', connectId: null },
    { debtParticipantId: 'p2', user: member, isPayer: false, isAuthor: false, shareAmount: 334, transferId: 's2', connectId: null }
  ]
}
const page = (items: unknown[]) => ({ items, pagination: { nextCursor: null, hasMore: false } })
function signedIn(id = 'self'): void {
  useCurrentUser().setAuthenticated({ id, name: 'Alex', email: 'private@example.test', isActive: true, createdAt: debt.createdAt })
}
function options(path: string) {
  if (path === '/v1/connects') return page([{ otherUser: payer }, { otherUser: newPerson }])
  if (path === '/v1/debts') return page([debt])
  return debt
}
async function form() {
  const wrapper = await mountSuspended(EditDebtForm, { props: { debt, etag: '"7"' } })
  signedIn()
  await flushPromises()
  return wrapper
}
describe('debt editing and actual response ETag', () => {
  beforeEach(() => {
    auth.protectedRequest.mockReset().mockImplementation(async (path, requestOptions) => {
      if (typeof requestOptions?.onResponse === 'function') requestOptions.onResponse({ response: { ok: true, headers: new Headers({ ETag: '"7"' }) } })
      return options(path)
    })
    auth.bootstrap.mockReset().mockResolvedValue(undefined)
    navigation.mockReset().mockResolvedValue(undefined)
    signedIn()
  })
  it('prefills current inactive people, fixes payer, previews all shares and sends one version-checked command', async () => {
    let resolve: (value: unknown) => void = () => undefined
    auth.protectedRequest.mockImplementation((path, requestOptions) => requestOptions?.method === 'PUT'
      ? new Promise((done) => { resolve = done })
      : Promise.resolve(options(path)))
    const wrapper = await form()
    expect(wrapper.get('#edit-amount').element.value).toBe('10.01')
    expect(wrapper.get('input[value="member"]').element.checked).toBe(true)
    expect(wrapper.text()).toContain('Casey (inactive)')
    expect(wrapper.get('input[value="self"]').attributes('disabled')).toBeDefined()
    expect(wrapper.find('select').exists()).toBe(false)
    await wrapper.get('#edit-amount').setValue('12.01')
    await wrapper.get('input[value="member"]').setValue(false)
    await wrapper.get('input[value="new"]').setValue(true)
    const payerToggle = wrapper.findAll('input[type="checkbox"]').find(input => !input.attributes('value'))!
    await payerToggle.setValue(false)
    expect(wrapper.text()).toContain('Will be removed')
    expect(wrapper.text()).toContain('Will be added or restored')
    expect(wrapper.text()).toContain('Each share: €6.01')
    await wrapper.get('form').trigger('submit')
    await wrapper.get('form').trigger('submit')
    const calls = auth.protectedRequest.mock.calls.filter(call => call[1]?.method === 'PUT')
    expect(calls).toHaveLength(1)
    expect(calls[0]![1].headers).toEqual({ 'If-Match': '"7"' })
    expect(calls[0]![1].body).toEqual({ title: 'Dinner', description: 'Food', totalAmount: 1201, participantIds: ['new', 'self'], includePayerInSplit: false })
    expect(wrapper.emitted('saved')).toBeUndefined()
    resolve({ ...debt, version: 8 })
    await flushPromises()
    expect(wrapper.emitted('saved')).toHaveLength(1)
    wrapper.unmount()
  })
  it.each([
    [409, 'VERSION_CONFLICT', 'Someone changed this expense'],
    [null, 'NETWORK_ERROR', 'The result is not confirmed'],
    [500, 'INTERNAL_SERVER_ERROR', 'The result is not confirmed'],
    [409, 'DEBT_FROZEN', 'This expense can no longer be edited']
  ])('blocks %s/%s without silent retry and offers explicit reload', async (status, code, message) => {
    auth.protectedRequest.mockImplementation(async (path, requestOptions) => {
      if (requestOptions?.method === 'PUT') throw new ApiClientError({ kind: status === null ? 'network' : 'api', status: status as number | null, code: code as string, message: 'Unsafe SQL' })
      return options(path)
    })
    const wrapper = await form()
    await wrapper.get('form').trigger('submit')
    await flushPromises()
    expect(wrapper.text()).toContain(message)
    expect(wrapper.text()).not.toContain('Unsafe SQL')
    expect(wrapper.get('.edit-fields').attributes('disabled')).toBeDefined()
    await wrapper.get('form').trigger('submit')
    expect(auth.protectedRequest.mock.calls.filter(call => call[1]?.method === 'PUT')).toHaveLength(1)
    await wrapper.findAll('button').find(button => button.text().includes('Reload expense and discard'))!.trigger('click')
    expect(wrapper.emitted('reload')).toHaveLength(1)
    wrapper.unmount()
  })
  it.each(['session', 'resource', 'unmount'])('discards a late update response after %s changes', async (change) => {
    let resolve: (value: unknown) => void = () => undefined
    auth.protectedRequest.mockImplementation((path, requestOptions) => requestOptions?.method === 'PUT'
      ? new Promise((done) => { resolve = done })
      : Promise.resolve(options(path)))
    const wrapper = await form()
    await wrapper.get('form').trigger('submit')
    if (change === 'session') signedIn('other')
    else if (change === 'resource') await wrapper.setProps({ debt: { ...debt, id: 'second', title: 'Another' } })
    else wrapper.unmount()
    await flushPromises()
    resolve({ ...debt, version: 8 })
    await flushPromises()
    expect(wrapper.emitted('saved')).toBeUndefined()
    if (change !== 'unmount') wrapper.unmount()
  })
  it('reloads a conflict from the server and requires a new explicit save using the newly received ETag', async () => {
    let changed = false
    let attempts = 0
    auth.protectedRequest.mockImplementation(async (path, requestOptions) => {
      if (requestOptions?.method === 'PUT') {
        if (++attempts === 1) {
          changed = true
          throw new ApiClientError({ kind: 'api', status: 409, code: 'VERSION_CONFLICT', message: 'Safe error' })
        }
        return { ...debt, title: 'Changed by another editor', version: 8 }
      }
      if (typeof requestOptions?.onResponse === 'function') requestOptions.onResponse({ response: { ok: true, headers: new Headers({ ETag: changed ? '"8"' : '"7"' }) } })
      return path === '/v1/debts/debt' && changed ? { ...debt, title: 'Changed by another editor', version: 8 } : options(path)
    })
    const wrapper = await mountSuspended(EditPage, { route: '/debts/debt/edit' })
    signedIn()
    await flushPromises()
    await wrapper.get('form').trigger('submit')
    await flushPromises()
    expect(attempts).toBe(1)
    expect(navigation).not.toHaveBeenCalled()
    await wrapper.findAll('button').find(button => button.text().includes('Reload expense and discard'))!.trigger('click')
    await flushPromises()
    expect(wrapper.get('#edit-title').element.value).toBe('Changed by another editor')
    expect(attempts).toBe(1)
    await wrapper.get('form').trigger('submit')
    await flushPromises()
    const calls = auth.protectedRequest.mock.calls.filter(call => call[1]?.method === 'PUT')
    expect(calls[0]![1].headers['If-Match']).toBe('"7"')
    expect(calls[1]![1].headers['If-Match']).toBe('"8"')
    expect(navigation).toHaveBeenCalledWith('/debts/debt')
    wrapper.unmount()
  })
  it('does not derive an editing version from body when the actual ETag is missing', async () => {
    auth.protectedRequest.mockImplementation(async path => options(path))
    const wrapper = await mountSuspended(EditPage, { route: '/debts/debt/edit' })
    signedIn()
    await flushPromises()
    expect(wrapper.find('form').exists()).toBe(false)
    expect(wrapper.text()).toContain('Reload the expense before saving changes')
    expect(auth.protectedRequest.mock.calls.filter(call => call[1]?.method === 'PUT')).toHaveLength(0)
    wrapper.unmount()
  })
  it('renders an author edit action but never for a reader or removed expense', async () => {
    const wrapper = await mountSuspended(DebtCard, { route: '/debts/debt' })
    signedIn()
    await flushPromises()
    expect(wrapper.find('a[href="/debts/debt/edit"]').exists()).toBe(true)
    signedIn('member')
    await flushPromises()
    expect(wrapper.find('a[href="/debts/debt/edit"]').exists()).toBe(false)
    wrapper.unmount()
  })
})
