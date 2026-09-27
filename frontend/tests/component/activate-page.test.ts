import { flushPromises } from '@vue/test-utils'
import { mockNuxtImport, mountSuspended } from '@nuxt/test-utils/runtime'
import { useRoute } from '#app'
import { beforeEach, describe, expect, it, vi } from 'vitest'
import ActivatePage from '../../app/pages/activate.vue'
import { ApiClientError } from '../../app/utils/api-error'

const { apiMock } = vi.hoisted(() => ({
  apiMock: vi.fn()
}))

mockNuxtImport('useNuxtApp', original => () => new Proxy(original(), {
  get(target, property, receiver) {
    return property === '$api' ? apiMock : Reflect.get(target, property, receiver)
  }
}))

describe('activation page', () => {
  beforeEach(() => {
    apiMock.mockReset()
  })

  it('removes the capability from the URL before showing successful activation', async () => {
    apiMock.mockResolvedValue(null)

    const wrapper = await mountSuspended(ActivatePage, {
      route: '/activate?token=v1.synthetic-test-capability'
    })
    await flushPromises()

    await vi.waitFor(() => {
      expect(useRoute().fullPath).toBe('/activate')
      expect(wrapper.get('[role="status"]').text()).toContain('Your account is active')
    })
    expect(apiMock).toHaveBeenCalledWith('/v1/auth/activate', {
      method: 'POST',
      body: { token: 'v1.synthetic-test-capability' }
    })
    expect(wrapper.text()).not.toContain('synthetic-test-capability')

    wrapper.unmount()
  })

  it('renders a terminal capability error with the safe activation request action', async () => {
    apiMock.mockRejectedValue(new ApiClientError({
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
      expect(apiMock).not.toHaveBeenCalled()
      expect(wrapper.text()).toContain('Activation link is missing')
      expect(wrapper.text()).toContain('Request another activation link')
    })

    wrapper.unmount()
  })
})
