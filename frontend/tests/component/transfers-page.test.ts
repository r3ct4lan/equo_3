import { flushPromises } from '@vue/test-utils'
import { mockNuxtImport, mountSuspended } from '@nuxt/test-utils/runtime'
import { beforeEach, describe, expect, it, vi } from 'vitest'
import TransfersPage from '../../app/pages/transfers/index.vue'

const { auth } = vi.hoisted(() => ({ auth: { protectedRequest: vi.fn(), bootstrap: vi.fn().mockResolvedValue(undefined) } }))
mockNuxtImport('useNuxtApp', original => () => new Proxy(original(), {
  get(target, property, receiver) {
    return property === '$auth' ? auth : Reflect.get(target, property, receiver)
  }
}))

describe('transfers page', () => {
  beforeEach(() => {
    useCurrentUser().setAuthenticated({ id: 'self', name: 'Alex', email: 'self@example.test', isActive: true, createdAt: '' })
    auth.protectedRequest.mockReset()
  })

  it('loads outgoing transfers with a cursor and displays removed items', async () => {
    const item = { id: 't1', type: 'MANUAL', amount: 700, sender: { name: 'Alex' }, receiver: { name: 'Sam' }, isDeleted: true, createdAt: '2026-10-28T00:00:00Z' }
    auth.protectedRequest.mockResolvedValueOnce({ items: [item], pagination: { nextCursor: 'next', hasMore: true } })
      .mockResolvedValueOnce({ items: [], pagination: { nextCursor: null, hasMore: false } })
    const wrapper = await mountSuspended(TransfersPage, { route: '/transfers?direction=outgoing' })
    useCurrentUser().setAuthenticated({ id: 'viewer', name: 'Alex', email: 'self@example.test', isActive: true, createdAt: '' })
    await flushPromises()
    expect(auth.protectedRequest).toHaveBeenCalledWith('/v1/transfers', { query: { direction: 'outgoing' } })
    expect(wrapper.get('a[href="/transfers/t1"]').text()).toContain('€7.00')
    expect(wrapper.text()).toContain('Removed · does not affect the balance')
    await wrapper.get('button').trigger('click')
    await flushPromises()
    expect(auth.protectedRequest).toHaveBeenLastCalledWith('/v1/transfers', { query: { direction: 'outgoing', cursor: 'next' } })
    wrapper.unmount()
  })

  it('shows a retryable error without exposing internal details', async () => {
    auth.protectedRequest.mockRejectedValue(new Error('private details'))
    const wrapper = await mountSuspended(TransfersPage, { route: '/transfers' })
    useCurrentUser().setAuthenticated({ id: 'viewer', name: 'Alex', email: 'self@example.test', isActive: true, createdAt: '' })
    await flushPromises()
    expect(wrapper.text()).toContain('Transfers could not be loaded')
    expect(wrapper.text()).not.toContain('private details')
    auth.protectedRequest.mockResolvedValue({ items: [], pagination: { nextCursor: null, hasMore: false } })
    await wrapper.get('button').trigger('click')
    await flushPromises()
    expect(wrapper.text()).toContain('No transfers yet')
    wrapper.unmount()
  })
})
