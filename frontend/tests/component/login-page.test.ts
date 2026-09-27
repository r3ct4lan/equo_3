import { flushPromises } from '@vue/test-utils'
import { mockNuxtImport, mountSuspended } from '@nuxt/test-utils/runtime'
import { beforeEach, describe, expect, it, vi } from 'vitest'
import LoginPage from '../../app/pages/login.vue'
import { ApiClientError } from '../../app/utils/api-error'

const { authMock, navigateMock } = vi.hoisted(() => ({
  authMock: {
    bootstrap: vi.fn(),
    login: vi.fn()
  },
  navigateMock: vi.fn()
}))

mockNuxtImport('useNuxtApp', original => () => new Proxy(original(), {
  get(target, property, receiver) {
    return property === '$auth' ? authMock : Reflect.get(target, property, receiver)
  }
}))

mockNuxtImport('navigateTo', () => navigateMock)

describe('login page', () => {
  beforeEach(() => {
    authMock.bootstrap.mockReset().mockResolvedValue(undefined)
    authMock.login.mockReset().mockResolvedValue(undefined)
    navigateMock.mockReset()
    useCurrentUser().reset()
  })

  it('renders accessible fields and client validation without calling auth', async () => {
    const wrapper = await mountSuspended(LoginPage, { route: '/login' })
    await flushPromises()

    expect(wrapper.get('label[for="login-email"]').text()).toBe('Email')
    expect(wrapper.get('input[name="email"]').attributes('autocomplete')).toBe('email')
    expect(wrapper.get('label[for="login-password"]').text()).toBe('Password')
    expect(wrapper.get('input[name="password"]').attributes('autocomplete')).toBe('current-password')
    expect(wrapper.get('a[href="/activate"]').text()).toBe('Need another activation link?')

    await wrapper.get('form').trigger('submit')

    expect(authMock.login).not.toHaveBeenCalled()
    expect(wrapper.get('[role="alert"]').text()).toContain('Correct the highlighted fields')
    expect(wrapper.text()).toContain('Enter your email address.')
    expect(wrapper.text()).toContain('Enter your password.')
    wrapper.unmount()
  })

  it('blocks double submit, clears password on success and uses safe redirect', async () => {
    let resolveLogin: () => void = () => undefined
    authMock.login.mockReturnValue(new Promise<void>((resolve) => {
      resolveLogin = resolve
    }))
    const wrapper = await mountSuspended(LoginPage, {
      route: '/login?redirect=%2F%2Fevil.test'
    })
    await flushPromises()

    await wrapper.get('input[name="email"]').setValue('alex@example.test')
    await wrapper.get('input[name="password"]').setValue('short')
    await wrapper.get('form').trigger('submit')
    await wrapper.get('form').trigger('submit')

    expect(authMock.login).toHaveBeenCalledTimes(1)
    expect(authMock.login).toHaveBeenCalledWith({
      email: 'alex@example.test',
      password: 'short'
    }, {
      redirectTo: '/me'
    })
    expect(wrapper.get('button[type="submit"]').attributes('aria-busy')).toBe('true')

    resolveLogin()
    await flushPromises()

    expect(wrapper.get('input[name="password"]').element).toHaveProperty('value', '')
    expect(wrapper.text()).not.toContain('short')
    wrapper.unmount()
  })

  it('shows invalid credentials, inactive, rate and retryable technical states safely', async () => {
    for (const [code, title] of [
      ['INVALID_CREDENTIALS', 'Email or password is incorrect'],
      ['ACCOUNT_INACTIVE', 'Account is not active'],
      ['RATE_LIMIT_EXCEEDED', 'Too many sign-in attempts']
    ]) {
      authMock.login.mockRejectedValueOnce(new ApiClientError({
        kind: 'api',
        status: code === 'ACCOUNT_INACTIVE' ? 403 : code === 'RATE_LIMIT_EXCEEDED' ? 429 : 401,
        code,
        message: code,
        retryAfterSeconds: code === 'RATE_LIMIT_EXCEEDED' ? 11 : null
      }))
      const wrapper = await mountSuspended(LoginPage, { route: '/login' })
      await flushPromises()
      await wrapper.get('input[name="email"]').setValue('alex@example.test')
      await wrapper.get('input[name="password"]').setValue('secret-password')
      await wrapper.get('form').trigger('submit')
      await flushPromises()

      expect(wrapper.get('[role="alert"]').text()).toContain(title)
      expect(wrapper.text()).not.toContain('secret-password')
      wrapper.unmount()
    }

    authMock.login.mockRejectedValueOnce(new ApiClientError({
      kind: 'network',
      code: 'NETWORK_ERROR',
      message: 'Unable to reach the service.'
    }))
    const wrapper = await mountSuspended(LoginPage, { route: '/login' })
    await flushPromises()
    await wrapper.get('input[name="email"]').setValue('alex@example.test')
    await wrapper.get('input[name="password"]').setValue('secret-password')
    await wrapper.get('form').trigger('submit')
    await flushPromises()

    expect(wrapper.get('button.button--secondary').text()).toBe('Try again')
    wrapper.unmount()
  })
})
