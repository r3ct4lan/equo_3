import { flushPromises } from '@vue/test-utils'
import { mockNuxtImport, mountSuspended } from '@nuxt/test-utils/runtime'
import { beforeEach, describe, expect, it, vi } from 'vitest'
import PasswordChangeForm from '../../app/components/PasswordChangeForm.vue'
import SecurityPage from '../../app/pages/account/security.vue'
import { ApiClientError } from '../../app/utils/api-error'

const { authMock } = vi.hoisted(() => ({ authMock: { changePassword: vi.fn(), bootstrap: vi.fn(), retryBootstrap: vi.fn() } }))
mockNuxtImport('useNuxtApp', original => () => new Proxy(original(), {
  get(target, property, receiver) {
    return property === '$auth' ? authMock : Reflect.get(target, property, receiver)
  }
}))

describe('password change form', () => {
  beforeEach(() => {
    authMock.changePassword.mockReset().mockResolvedValue(undefined)
    authMock.bootstrap.mockReset().mockResolvedValue(undefined)
    useCurrentUser().reset()
  })

  it('validates required current and Unicode new passwords accessibly', async () => {
    const wrapper = await mountSuspended(PasswordChangeForm)
    expect(wrapper.get('#change-current-password').attributes('autocomplete')).toBe('current-password')
    expect(wrapper.get('#change-new-password').attributes('autocomplete')).toBe('new-password')
    await wrapper.get('form').trigger('submit')
    expect(authMock.changePassword).not.toHaveBeenCalled()
    expect(wrapper.get('#change-current-password').attributes('aria-invalid')).toBe('true')
    expect(wrapper.get('#change-new-password').attributes('aria-describedby')).toBe('change-new-error')
    await wrapper.get('#change-current-password').setValue(' old ')
    await wrapper.get('#change-new-password').setValue('short')
    await wrapper.get('form').trigger('submit')
    expect(authMock.changePassword).not.toHaveBeenCalled()
    await wrapper.get('#change-new-password').setValue('  New password 😀  ')
    await wrapper.get('form').trigger('submit')
    await flushPromises()
    expect(authMock.changePassword).toHaveBeenCalledWith(' old ', '  New password 😀  ')
    expect(wrapper.find('input').exists()).toBe(false)
    expect(wrapper.get('[role="status"]').text()).toContain('Password saved')
    wrapper.unmount()
  })

  it('disables fields and blocks duplicate submit until server result', async () => {
    let complete: () => void = () => undefined
    authMock.changePassword.mockReturnValue(new Promise<void>((resolve) => {
      complete = resolve
    }))
    const wrapper = await mountSuspended(PasswordChangeForm)
    await wrapper.get('#change-current-password').setValue('old')
    await wrapper.get('#change-new-password').setValue('New password!')
    await wrapper.get('form').trigger('submit')
    await wrapper.get('form').trigger('submit')
    expect(authMock.changePassword).toHaveBeenCalledTimes(1)
    expect(wrapper.get('fieldset').attributes('disabled')).toBeDefined()
    expect(wrapper.get('button[type="submit"]').attributes('aria-busy')).toBe('true')
    complete()
    await flushPromises()
    wrapper.unmount()
  })

  it.each(['INVALID_CREDENTIALS', 'NETWORK_ERROR', 'VALIDATION_ERROR'])('clears both secrets after %s and allows explicit corrected submit', async (code) => {
    authMock.changePassword.mockRejectedValueOnce(new ApiClientError({ kind: 'api', status: code === 'INVALID_CREDENTIALS' ? 401 : 500, code, message: 'Safe error' }))
    const wrapper = await mountSuspended(PasswordChangeForm)
    await wrapper.get('#change-current-password').setValue('wrong')
    await wrapper.get('#change-new-password').setValue('New password!')
    await wrapper.get('form').trigger('submit')
    await flushPromises()
    expect(wrapper.get('#change-current-password').element).toHaveProperty('value', '')
    expect(wrapper.get('#change-new-password').element).toHaveProperty('value', '')
    expect(wrapper.find('[role="alert"]').exists()).toBe(true)
    await wrapper.get('#change-current-password').setValue('correct')
    await wrapper.get('#change-new-password').setValue('Another password!')
    await wrapper.get('form').trigger('submit')
    await flushPromises()
    expect(authMock.changePassword).toHaveBeenLastCalledWith('correct', 'Another password!')
    wrapper.unmount()
  })

  it('keeps security form hidden while session is unknown or anonymous', async () => {
    const wrapper = await mountSuspended(SecurityPage, { route: '/account/security' })
    expect(wrapper.find('form').exists()).toBe(false)
    expect(wrapper.text()).toContain('Checking your session')
    useCurrentUser().setAnonymous()
    await flushPromises()
    expect(wrapper.find('form').exists()).toBe(false)
    wrapper.unmount()
  })
})
