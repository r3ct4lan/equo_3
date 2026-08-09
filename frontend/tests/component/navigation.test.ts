import { mountSuspended } from '@nuxt/test-utils/runtime'
import { nextTick } from 'vue'
import { beforeEach, describe, expect, it } from 'vitest'
import DefaultLayout from '../../app/layouts/default.vue'
import { ApiClientError } from '../../app/utils/api-error'

describe('navigation session states', () => {
  beforeEach(() => {
    useCurrentUser().reset()
  })

  it('shows neutral navigation while session is unknown', async () => {
    const wrapper = await mountSuspended(DefaultLayout)
    useCurrentUser().reset()
    await nextTick()

    expect(wrapper.text()).toContain('Checking session')
    expect(wrapper.text()).not.toContain('My profile')
    wrapper.unmount()
  })

  it('shows login and register when anonymous', async () => {
    useCurrentUser().setAnonymous()
    const wrapper = await mountSuspended(DefaultLayout)

    expect(wrapper.text()).toContain('Login')
    expect(wrapper.text()).toContain('Register')
    wrapper.unmount()
  })

  it('shows profile when authenticated and does not claim logout on error', async () => {
    useCurrentUser().setAuthenticated({
      id: 'u1',
      name: 'Alex',
      email: 'alex@example.test',
      isActive: true,
      createdAt: '2026-08-08T12:00:00Z'
    })
    const authenticated = await mountSuspended(DefaultLayout)
    expect(authenticated.text()).toContain('My profile')
    authenticated.unmount()

    useCurrentUser().setError(new ApiClientError({
      kind: 'network',
      code: 'NETWORK_ERROR',
      message: 'Unable to reach the service.'
    }))
    const error = await mountSuspended(DefaultLayout)
    expect(error.text()).toContain('Login')
    expect(error.text()).not.toContain('Logout')
    error.unmount()
  })
})
