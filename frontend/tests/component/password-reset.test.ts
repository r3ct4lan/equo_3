import { flushPromises } from '@vue/test-utils'
import { mockNuxtImport, mountSuspended } from '@nuxt/test-utils/runtime'
import { useRoute } from '#app'
import { beforeEach, describe, expect, it, vi } from 'vitest'
import ForgotPassword from '../../app/pages/forgot-password.vue'
import ResetPassword from '../../app/pages/password-reset.vue'
import { ApiClientError } from '../../app/utils/api-error'

const { apiMock, authMock } = vi.hoisted(() => ({ apiMock: vi.fn(), authMock: { resetPassword: vi.fn() } }))
mockNuxtImport('useNuxtApp', original => () => new Proxy(original(), {
  get(target, property, receiver) {
    if (property === '$api') return apiMock
    if (property === '$auth') return authMock
    return Reflect.get(target, property, receiver)
  }
}))

describe('password recovery', () => {
  beforeEach(() => {
    apiMock.mockReset().mockResolvedValue({ status: 'password_reset_email_scheduled' })
    authMock.resetPassword.mockReset().mockResolvedValue(undefined)
  })

  it('validates email accessibly and shows a neutral request result', async () => {
    const wrapper = await mountSuspended(ForgotPassword, { route: '/forgot-password' })
    await wrapper.get('form').trigger('submit')
    expect(apiMock).not.toHaveBeenCalled()
    expect(wrapper.get('input').attributes('aria-invalid')).toBe('true')
    expect(wrapper.get('[role="alert"]').text()).toContain('valid email')
    await wrapper.get('input').setValue('unknown@example.test')
    await wrapper.get('form').trigger('submit')
    await flushPromises()
    expect(wrapper.get('[role="status"]').text()).toContain('If an account uses this address')
    expect(apiMock).toHaveBeenCalledTimes(1)
    wrapper.unmount()
  })

  it('removes capability and all query before showing form, validates policy and blocks duplicate submit', async () => {
    let complete: () => void = () => undefined
    authMock.resetPassword.mockReturnValue(new Promise<void>((resolve) => {
      complete = resolve
    }))
    const wrapper = await mountSuspended(ResetPassword, { route: '/password-reset?token=v1.synthetic-capability&other=secret' })
    await flushPromises()
    await vi.waitFor(() => expect(useRoute().fullPath).toBe('/password-reset'))
    expect(wrapper.html()).not.toContain('synthetic-capability')
    await wrapper.get('input').setValue('short')
    await wrapper.get('form').trigger('submit')
    expect(authMock.resetPassword).not.toHaveBeenCalled()
    expect(wrapper.get('input').attributes('aria-describedby')).toBe('reset-password-error')
    for (const password of ['12345678', '1234567!', 'Password!', 'Password1 ']) {
      await wrapper.get('input').setValue(password)
      await wrapper.get('form').trigger('submit')
      expect(authMock.resetPassword).not.toHaveBeenCalled()
      expect(wrapper.get('input').attributes('aria-invalid')).toBe('true')
      expect(wrapper.get('#reset-password-error').text()).toContain('Include at least one')
    }
    await wrapper.get('input').setValue('  New password1 😀  ')
    await wrapper.get('form').trigger('submit')
    await wrapper.get('form').trigger('submit')
    expect(authMock.resetPassword).toHaveBeenCalledTimes(1)
    expect(authMock.resetPassword).toHaveBeenCalledWith('v1.synthetic-capability', '  New password1 😀  ')
    expect(wrapper.get('button[type="submit"]').attributes('aria-busy')).toBe('true')
    complete()
    await flushPromises()
    expect(wrapper.text()).toContain('Password saved')
    expect(wrapper.find('input').exists()).toBe(false)
    wrapper.unmount()
  })

  it.each(['INVALID_TOKEN', 'TOKEN_EXPIRED', 'TOKEN_USED', 'TOKEN_INVALIDATED'])('clears secret form and offers new link for %s', async (code) => {
    authMock.resetPassword.mockRejectedValue(new ApiClientError({ kind: 'api', status: 410, code, message: 'Unavailable' }))
    const wrapper = await mountSuspended(ResetPassword, { route: '/password-reset?token=v1.synthetic-capability' })
    await flushPromises()
    await vi.waitFor(() => expect(wrapper.find('input').exists()).toBe(true))
    await wrapper.get('input').setValue('New password1!')
    await wrapper.get('form').trigger('submit')
    await flushPromises()
    expect(wrapper.find('form').exists()).toBe(false)
    expect(wrapper.get('a[href="/forgot-password"]').text()).toContain('new recovery link')
    expect(wrapper.text()).not.toContain('synthetic-capability')
    expect(wrapper.html()).not.toContain('New password1!')
    wrapper.unmount()
  })

  it('keeps capability privately for technical retry but requires password re-entry', async () => {
    authMock.resetPassword.mockRejectedValueOnce(new ApiClientError({ kind: 'network', code: 'NETWORK_ERROR', message: 'Unavailable' }))
    const wrapper = await mountSuspended(ResetPassword, { route: '/password-reset?token=v1.synthetic-capability' })
    await flushPromises()
    await vi.waitFor(() => expect(wrapper.find('input').exists()).toBe(true))
    await wrapper.get('input').setValue('New password1!')
    await wrapper.get('form').trigger('submit')
    await flushPromises()
    expect(wrapper.get('input').element).toHaveProperty('value', '')
    expect(wrapper.get('[role="alert"]').text()).toContain('Recovery could not be confirmed')
    await wrapper.get('input').setValue('Another password1!')
    await wrapper.get('form').trigger('submit')
    await flushPromises()
    expect(authMock.resetPassword).toHaveBeenLastCalledWith('v1.synthetic-capability', 'Another password1!')
    wrapper.unmount()
  })

  it('does not expose a form or call auth without a capability', async () => {
    const wrapper = await mountSuspended(ResetPassword, { route: '/password-reset' })
    await flushPromises()
    await vi.waitFor(() => expect(wrapper.text()).toContain('Recovery link is missing'))
    expect(wrapper.find('form').exists()).toBe(false)
    expect(authMock.resetPassword).not.toHaveBeenCalled()
    wrapper.unmount()
  })
})
