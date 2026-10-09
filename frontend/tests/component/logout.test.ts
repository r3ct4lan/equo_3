import { flushPromises } from '@vue/test-utils'
import { mockNuxtImport, mountSuspended } from '@nuxt/test-utils/runtime'
import { beforeEach, describe, expect, it, vi } from 'vitest'
import LogoutActions from '../../app/components/LogoutActions.vue'
import LogoutPage from '../../app/pages/logout.vue'

const { authMock } = vi.hoisted(() => ({
  authMock: { logout: vi.fn(), logoutAll: vi.fn(), bootstrap: vi.fn() }
}))

mockNuxtImport('useNuxtApp', original => () => new Proxy(original(), {
  get(target, property, receiver) {
    return property === '$auth' ? authMock : Reflect.get(target, property, receiver)
  }
}))

describe('sign-out controls', () => {
  beforeEach(() => {
    authMock.logout.mockReset().mockResolvedValue(undefined)
    authMock.logoutAll.mockReset().mockResolvedValue(undefined)
    authMock.bootstrap.mockReset()
    useCurrentUser().setAuthenticated({
      id: 'u1', name: 'User', email: 'u@example.test', isActive: true, createdAt: '2026-10-09T12:00:00Z'
    })
  })

  it('current sign-out has no confirmation and pending prevents repeated submits', async () => {
    let complete: () => void = () => undefined
    authMock.logout.mockReturnValue(new Promise<void>((resolve) => {
      complete = resolve
    }))
    const wrapper = await mountSuspended(LogoutActions)
    await flushPromises()
    useCurrentUser().setAuthenticated({ id: 'u1', name: 'User', email: 'u@example.test', isActive: true, createdAt: '' })
    await flushPromises()
    await wrapper.get('button').trigger('click')
    await wrapper.get('button').trigger('click')
    expect(authMock.logout).toHaveBeenCalledTimes(1)
    expect(wrapper.find('[role="group"]').exists()).toBe(false)
    expect(wrapper.get('section').attributes('aria-busy')).toBe('true')
    expect(wrapper.findAll('button').every(button => button.attributes('disabled') !== undefined)).toBe(true)
    complete()
    await flushPromises()
    wrapper.unmount()
  })

  it('all devices requires confirmation, supports Escape/cancel and restores focus', async () => {
    const wrapper = await mountSuspended(LogoutActions, { attachTo: document.body })
    const allButton = wrapper.findAll('button')[1]!
    await allButton.trigger('click')
    expect(authMock.logoutAll).not.toHaveBeenCalled()
    expect(wrapper.get('[role="group"]').element).toBe(document.activeElement)
    expect(wrapper.text()).toContain('up to 15 minutes')
    await wrapper.get('[role="group"]').trigger('keydown', { key: 'Escape' })
    expect(wrapper.find('[role="group"]').exists()).toBe(false)
    expect(document.activeElement).toBe(allButton.element)
    await allButton.trigger('click')
    const confirm = wrapper.findAll('button').find(button => button.text() === 'Confirm sign out')!
    await confirm.trigger('click')
    await flushPromises()
    expect(authMock.logoutAll).toHaveBeenCalledTimes(1)
    wrapper.unmount()
  })

  it('safe-error page distinguishes local sign-out and retries explicitly without bootstrap', async () => {
    authMock.logoutAll.mockRejectedValue(new Error('private technical detail'))
    const wrapper = await mountSuspended(LogoutPage, { route: '/logout?scope=all' })
    expect(wrapper.get('h1').text()).toBe('You are signed out here')
    expect(wrapper.get('[role="alert"]').text()).toContain('could not confirm sign-out on all devices')
    await wrapper.get('button').trigger('click')
    await flushPromises()
    expect(authMock.logoutAll).toHaveBeenCalledTimes(1)
    expect(authMock.bootstrap).not.toHaveBeenCalled()
    expect(wrapper.text()).not.toContain('private technical detail')
    expect(wrapper.get('a').attributes('href')).toBe('/login?signedOut=1')
    wrapper.unmount()
  })
})
