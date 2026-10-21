import { flushPromises } from '@vue/test-utils'
import { mockNuxtImport, mountSuspended } from '@nuxt/test-utils/runtime'
import { beforeEach, describe, expect, it, vi } from 'vitest'
import CreateInvitation from '../../app/components/CreateInvitation.vue'
import InvitationPreview from '../../app/components/InvitationPreview.vue'
import ConnectsPage from '../../app/pages/connects/index.vue'
import { ApiClientError } from '../../app/utils/api-error'
import { forgetInvitation, recalledInvitation, takeInvitationFeedback } from '../../app/utils/connect-invitations'

const { api, auth, navigation } = vi.hoisted(() => ({
  api: vi.fn(),
  auth: { protectedRequest: vi.fn(), bootstrap: vi.fn(), retryBootstrap: vi.fn() },
  navigation: vi.fn()
}))
mockNuxtImport('useNuxtApp', original => () => new Proxy(original(), {
  get(target, property, receiver) {
    if (property === '$api') return api
    if (property === '$auth') return auth
    return Reflect.get(target, property, receiver)
  }
}))
mockNuxtImport('navigateTo', () => navigation)

const preview = { inviter: { id: 'author', name: 'Alex' }, expiresAt: '2026-10-17T12:00:00Z', canAccept: true }
const connect = { id: 'connection', otherUser: { id: 'author', name: 'Alex', isActive: true }, balanceAmount: 0, canCreateDebt: true, canCreateTransfer: true, createdAt: '2026-10-10T12:00:00Z' }
function signedIn(id = 'acceptor'): void {
  useCurrentUser().setAuthenticated({ id, name: 'Person', email: 'person@example.test', isActive: true, createdAt: '2026-10-10T12:00:00Z' })
}

describe('Connect invitation journey', () => {
  beforeEach(() => {
    api.mockReset().mockResolvedValue(preview)
    auth.protectedRequest.mockReset().mockResolvedValue(connect)
    auth.bootstrap.mockReset().mockResolvedValue(undefined)
    auth.retryBootstrap.mockReset()
    navigation.mockReset().mockResolvedValue(undefined)
    forgetInvitation()
    takeInvitationFeedback()
    useCurrentUser().setAnonymous()
  })

  it('loads a safe preview and keeps invitation continuation out of the login URL', async () => {
    const wrapper = await mountSuspended(InvitationPreview, { props: { token: 'private-invitation' } })
    await flushPromises()
    useCurrentUser().setAnonymous()
    await flushPromises()
    expect(wrapper.text()).toContain('Alex invited you')
    expect(wrapper.text()).not.toContain('private-invitation')
    expect(wrapper.text()).not.toContain('@example.test')
    await wrapper.get('button').trigger('click')
    expect(navigation).toHaveBeenCalledWith('/login?redirect=/connect-invitations/continue')
    expect(recalledInvitation()).toBe('private-invitation')
    wrapper.unmount()
  })

  it('offers registration while preserving the invitation only in memory', async () => {
    const wrapper = await mountSuspended(InvitationPreview, { props: { token: 'private-invitation' } })
    await flushPromises()
    await wrapper.findAll('button').find(button => button.text() === 'Create an account')!.trigger('click')
    expect(navigation).toHaveBeenCalledWith('/register')
    expect(recalledInvitation()).toBe('private-invitation')
    wrapper.unmount()
  })

  it('accepts once automatically for an active session and reports confirmed success', async () => {
    signedIn()
    let resolve: (value: typeof connect) => void = () => undefined
    auth.protectedRequest.mockReturnValue(new Promise<typeof connect>((done) => {
      resolve = done
    }))
    const wrapper = await mountSuspended(InvitationPreview, { props: { token: 'private-invitation' } })
    await flushPromises()
    expect(auth.protectedRequest).toHaveBeenCalledTimes(1)
    expect(auth.protectedRequest).toHaveBeenCalledWith('/v1/connect-invitations/private-invitation/accept', { method: 'POST' })
    expect(navigation).not.toHaveBeenCalled()
    resolve(connect)
    await flushPromises()
    expect(navigation).toHaveBeenCalledWith('/connects')
    expect(takeInvitationFeedback()).toEqual({ kind: 'success', message: 'Connection created successfully.' })
    wrapper.unmount()
  })

  it.each(['TOKEN_USED', 'TOKEN_EXPIRED', 'RESOURCE_NOT_FOUND', 'RATE_LIMIT_EXCEEDED'])('shows safe %s without automatic retries', async (code) => {
    api.mockRejectedValue(new ApiClientError({ kind: 'api', status: code === 'RATE_LIMIT_EXCEEDED' ? 429 : 410, code, message: 'Unsafe private-invitation SQL details', retryAfterSeconds: 60 }))
    const wrapper = await mountSuspended(InvitationPreview, { props: { token: 'private-invitation' } })
    await flushPromises()
    expect(wrapper.get('[role="alert"]').text()).not.toContain('SQL')
    expect(wrapper.text()).not.toContain('private-invitation')
    expect(api).toHaveBeenCalledTimes(1)
    expect(wrapper.text()).not.toContain('Accept invitation')
    wrapper.unmount()
  })

  it('reports a failed automatic acceptance without retrying or exposing the link', async () => {
    signedIn()
    auth.protectedRequest.mockRejectedValue(new ApiClientError({ kind: 'network', code: 'NETWORK_ERROR', message: 'Unsafe private-invitation SQL details' }))
    const wrapper = await mountSuspended(InvitationPreview, { props: { token: 'private-invitation' } })
    await flushPromises()
    expect(auth.protectedRequest).toHaveBeenCalledTimes(1)
    expect(navigation).toHaveBeenCalledWith('/connects')
    const feedback = takeInvitationFeedback()
    expect(feedback?.kind).toBe('error')
    expect(feedback?.message).not.toContain('private-invitation')
    expect(feedback?.message).not.toContain('SQL')
    wrapper.unmount()
  })

  it('does not offer registration or sign-in for an inactive sender', async () => {
    api.mockResolvedValue({ ...preview, canAccept: false })
    const inactive = await mountSuspended(InvitationPreview, { props: { token: 'private-invitation' } })
    await flushPromises()
    expect(inactive.text()).toContain('sender\'s account must be active')
    expect(inactive.text()).not.toContain('Create an account')
    expect(inactive.text()).not.toContain('Sign in to connect')
    inactive.unmount()
  })

  it('creates an invitation once while pending and copies without rendering the capability', async () => {
    signedIn()
    const writeText = vi.fn().mockResolvedValue(undefined)
    Object.defineProperty(navigator, 'clipboard', { configurable: true, value: { writeText } })
    let resolve: (value: { token: string, url: string, expiresAt: string, created: boolean }) => void = () => undefined
    auth.protectedRequest.mockReturnValue(new Promise((done) => {
      resolve = done
    }))
    const wrapper = await mountSuspended(CreateInvitation)
    await wrapper.get('button').trigger('click')
    await wrapper.get('button').trigger('click')
    expect(auth.protectedRequest).toHaveBeenCalledTimes(1)
    resolve({ token: 'private-invitation', url: 'https://equo.test/connect-invitations/private-invitation', expiresAt: preview.expiresAt, created: true })
    await flushPromises()
    expect(wrapper.text()).not.toContain('private-invitation')
    await wrapper.findAll('button').find(button => button.text() === 'Copy invitation')!.trigger('click')
    await flushPromises()
    expect(writeText).toHaveBeenCalledWith('https://equo.test/connect-invitations/private-invitation')
    expect(wrapper.text()).toContain('Invitation copied')
    wrapper.unmount()
  })

  it('loads empty and paginated connections with explicit retry and preserves previous items', async () => {
    signedIn()
    auth.protectedRequest.mockResolvedValueOnce({ items: [], pagination: { nextCursor: null, hasMore: false } })
    const empty = await mountSuspended(ConnectsPage, { route: '/connects' })
    await flushPromises()
    expect(empty.text()).toContain('No connections yet')
    empty.unmount()
    auth.protectedRequest.mockResolvedValueOnce({ items: [connect], pagination: { nextCursor: 'page-two', hasMore: true } })
      .mockRejectedValueOnce(new ApiClientError({ kind: 'network', code: 'NETWORK_ERROR', message: 'Safe failure' }))
      .mockResolvedValueOnce({ items: [{ ...connect, id: 'second', otherUser: { ...connect.otherUser, name: 'Boris', isActive: false } }], pagination: { nextCursor: null, hasMore: false } })
    const wrapper = await mountSuspended(ConnectsPage, { route: '/connects' })
    await flushPromises()
    await wrapper.findAll('button').find(button => button.text() === 'Load more')!.trigger('click')
    await flushPromises()
    expect(wrapper.text()).toContain('Alex')
    await wrapper.findAll('button').find(button => button.text() === 'Try again')!.trigger('click')
    await flushPromises()
    expect(wrapper.text()).toContain('Boris')
    expect(wrapper.text()).toContain('inactive')
    expect(wrapper.text()).not.toContain('Delete')
    expect(wrapper.text()).not.toContain('Revoke')
    wrapper.unmount()
  })
  it('ignores a preview response from a previously opened invitation', async () => {
    let complete: (value: typeof preview) => void = () => undefined
    api.mockReturnValueOnce(new Promise<typeof preview>((resolve) => {
      complete = resolve
    })).mockResolvedValueOnce({ ...preview, inviter: { id: 'new-author', name: 'Boris' } })
    const wrapper = await mountSuspended(InvitationPreview, { props: { token: 'first' } })
    await wrapper.setProps({ token: 'second' })
    await flushPromises()
    complete(preview)
    await flushPromises()
    expect(wrapper.text()).toContain('Boris invited you')
    expect(wrapper.text()).not.toContain('Alex invited you')
    wrapper.unmount()
  })

  it('ignores a connections response from a previous signed-in account', async () => {
    signedIn('first-account')
    let complete: (value: { items: typeof connect[], pagination: { nextCursor: null, hasMore: boolean } }) => void = () => undefined
    auth.protectedRequest.mockReturnValueOnce(new Promise((resolve) => {
      complete = resolve
    })).mockResolvedValueOnce({ items: [{ ...connect, otherUser: { ...connect.otherUser, name: 'Boris' } }], pagination: { nextCursor: null, hasMore: false } })
    const wrapper = await mountSuspended(ConnectsPage, { route: '/connects' })
    signedIn('second-account')
    await flushPromises()
    complete({ items: [connect], pagination: { nextCursor: null, hasMore: false } })
    await flushPromises()
    expect(wrapper.text()).toContain('Boris')
    expect(wrapper.text()).not.toContain('Alex')
    wrapper.unmount()
  })
})
