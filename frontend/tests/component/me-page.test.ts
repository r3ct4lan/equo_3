import { flushPromises } from '@vue/test-utils'
import { mockNuxtImport, mountSuspended } from '@nuxt/test-utils/runtime'
import { nextTick } from 'vue'
import { beforeEach, describe, expect, it, vi } from 'vitest'
import MePage from '../../app/pages/me.vue'
import { ApiClientError } from '../../app/utils/api-error'

const { authMock } = vi.hoisted(() => ({
  authMock: {
    bootstrap: vi.fn(),
    protectedRequest: vi.fn(),
    retryBootstrap: vi.fn()
  }
}))

mockNuxtImport('useNuxtApp', original => () => new Proxy(original(), {
  get(target, property, receiver) {
    return property === '$auth' ? authMock : Reflect.get(target, property, receiver)
  }
}))

const profile = {
  id: '00000000-0000-4000-8000-000000000001',
  name: 'Alex Doe',
  email: 'alex@example.test',
  isActive: true,
  createdAt: '2026-08-08T12:00:00Z'
}

describe('me page', () => {
  beforeEach(() => {
    authMock.protectedRequest.mockReset().mockResolvedValue(profile)
    authMock.bootstrap.mockReset().mockResolvedValue(undefined)
    authMock.retryBootstrap.mockReset().mockResolvedValue(undefined)
    useCurrentUser().reset()
  })

  it('shows loading state while session is unknown', async () => {
    const wrapper = await mountSuspended(MePage, { route: '/me' })
    useCurrentUser().reset()
    await nextTick()

    expect(wrapper.text()).toContain('Checking your session')
    wrapper.unmount()
  })

  it('loads and renders the authenticated profile without token fields', async () => {
    useCurrentUser().setAuthenticated(profile)
    const wrapper = await mountSuspended(MePage, { route: '/me' })
    await flushPromises()

    expect(authMock.protectedRequest).toHaveBeenCalledWith('/v1/me', { method: 'GET' })
    expect(wrapper.text()).toContain('Alex Doe')
    expect(wrapper.text()).toContain('alex@example.test')
    expect(wrapper.text()).toContain('Active')
    expect(wrapper.text()).not.toMatch(/token|session|cookie|jwt/i)
    wrapper.unmount()
  })

  it('shows retryable bootstrap error state', async () => {
    useCurrentUser().setError(new ApiClientError({
      kind: 'network',
      code: 'NETWORK_ERROR',
      message: 'Unable to reach the service.'
    }))
    const wrapper = await mountSuspended(MePage, { route: '/me' })
    await flushPromises()

    expect(wrapper.get('[role="alert"]').text()).toContain('We could not check your session')
    await wrapper.get('button').trigger('click')
    expect(authMock.retryBootstrap).toHaveBeenCalledTimes(1)
    wrapper.unmount()
  })
})
