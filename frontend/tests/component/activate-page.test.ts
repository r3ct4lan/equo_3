import { flushPromises } from '@vue/test-utils'
import { mockNuxtImport, mountSuspended } from '@nuxt/test-utils/runtime'
import { useRoute } from '#app'
import { beforeEach, describe, expect, it, vi } from 'vitest'
import ActivatePage from '../../app/pages/activate.vue'
import { ApiClientError } from '../../app/utils/api-error'
import { takeInvitationFeedback } from '../../app/utils/connect-invitations'

const { activateMock, protectedRequestMock, navigation } = vi.hoisted(() => ({
  activateMock: vi.fn(),
  protectedRequestMock: vi.fn(),
  navigation: vi.fn()
}))

mockNuxtImport('useNuxtApp', original => () => new Proxy(original(), {
  get(target, property, receiver) {
    return property === '$auth' ? { activate: activateMock, protectedRequest: protectedRequestMock } : Reflect.get(target, property, receiver)
  }
}))
mockNuxtImport('navigateTo', () => navigation)

describe('activation page', () => {
  beforeEach(() => {
    activateMock.mockReset()
    protectedRequestMock.mockReset().mockResolvedValue(undefined)
    navigation.mockReset().mockResolvedValue(undefined)
    takeInvitationFeedback()
  })

  it('removes the capability from the URL and waits for a click before activation', async () => {
    activateMock.mockResolvedValue(undefined)

    const wrapper = await mountSuspended(ActivatePage, {
      route: '/activate?token=v1.synthetic-test-capability'
    })
    await flushPromises()

    await vi.waitFor(() => {
      expect(useRoute().fullPath).toBe('/activate')
      expect(wrapper.get('button').text()).toBe('Activate and sign in')
    })
    expect(activateMock).not.toHaveBeenCalled()
    expect(wrapper.text()).toContain('Opening the link has not activated your account')
    expect(wrapper.text()).not.toContain('synthetic-test-capability')

    await wrapper.get('button').trigger('click')
    await flushPromises()

    expect(activateMock).toHaveBeenCalledWith('v1.synthetic-test-capability')
    expect(protectedRequestMock).toHaveBeenCalledWith('/v1/connect-invitations/pending/accept', { method: 'POST' })
    expect(wrapper.get('[role="status"]').text()).toContain('Your account is active')
    expect(wrapper.text()).toContain('You are signed in')
    expect(wrapper.text()).not.toContain('synthetic-test-capability')

    wrapper.unmount()
  })

  it('continues a registered recipient to connections after activation', async () => {
    activateMock.mockResolvedValue(undefined)
    protectedRequestMock.mockResolvedValue({ id: 'new-connect' })
    const wrapper = await mountSuspended(ActivatePage, { route: '/activate?token=v1.invited-capability' })
    await flushPromises()
    await vi.waitFor(() => {
      expect(wrapper.get('button').text()).toBe('Activate and sign in')
    })
    await wrapper.get('button').trigger('click')
    await flushPromises()
    expect(navigation).toHaveBeenCalledWith('/connects')
    expect(takeInvitationFeedback()).toEqual({ kind: 'success', message: 'Connection created successfully.' })
    wrapper.unmount()
  })

  it('renders a terminal capability error with the safe activation request action', async () => {
    activateMock.mockRejectedValue(new ApiClientError({
      kind: 'api',
      status: 400,
      code: 'INVALID_TOKEN',
      message: 'Token is invalid.'
    }))

    const wrapper = await mountSuspended(ActivatePage, {
      route: '/activate?token=v1.invalid-test-capability'
    })
    await flushPromises()

    await vi.waitFor(() => {
      expect(wrapper.get('button').text()).toBe('Activate and sign in')
    })
    expect(activateMock).not.toHaveBeenCalled()
    await wrapper.get('button').trigger('click')
    await flushPromises()

    await vi.waitFor(() => {
      expect(wrapper.text()).toContain('Activation link is invalid')
    })
    expect(wrapper.text()).not.toContain('invalid-test-capability')
    expect(wrapper.get('h2#activation-request-title').text()).toBe('Request another activation link')
    expect(wrapper.get('form[aria-labelledby="activation-request-title"]').exists()).toBe(true)

    wrapper.unmount()
  })

  it('does not call the API when the activation capability is missing', async () => {
    const wrapper = await mountSuspended(ActivatePage, { route: '/activate' })
    await flushPromises()

    await vi.waitFor(() => {
      expect(activateMock).not.toHaveBeenCalled()
      expect(wrapper.text()).toContain('Activation link is missing')
      expect(wrapper.text()).toContain('Request another activation link')
    })

    wrapper.unmount()
  })
})
