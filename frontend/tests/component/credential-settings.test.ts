import { enableAutoUnmount, flushPromises } from '@vue/test-utils'
import { mockNuxtImport, mountSuspended } from '@nuxt/test-utils/runtime'
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import CredentialSettings from '../../app/components/CredentialSettings.vue'
import { ApiClientError } from '../../app/utils/api-error'

enableAutoUnmount(afterEach)

const { auth } = vi.hoisted(() => ({ auth: { protectedRequest: vi.fn() } }))
mockNuxtImport('useNuxtApp', original => () => new Proxy(original(), {
  get(target, property, receiver) {
    return property === '$auth' ? auth : Reflect.get(target, property, receiver)
  }
}))

describe('account sign-in methods', () => {
  beforeEach(() => {
    auth.protectedRequest.mockReset()
    Object.assign(useRuntimeConfig().public, { telegramLoginEnabled: true })
  })

  it('offers email confirmation for Telegram-only accounts and reports safe failures', async () => {
    auth.protectedRequest.mockResolvedValueOnce({ hasEmail: false, hasTelegram: true, mergePending: false })
    auth.protectedRequest.mockRejectedValueOnce(new ApiClientError({ kind: 'api', status: 422, code: 'PASSWORD_POLICY_VIOLATION', message: 'Private details' }))
    auth.protectedRequest.mockResolvedValueOnce({ status: 'email_confirmation_scheduled' })
    const wrapper = await mountSuspended(CredentialSettings)
    await flushPromises()

    expect(wrapper.text()).toContain('Add email and password')
    expect(wrapper.text()).not.toContain('Change Telegram')
    await wrapper.get('input[type="email"]').setValue('new@example.test')
    await wrapper.get('input[type="password"]').setValue('Example1!')
    await wrapper.get('form').trigger('submit')
    await flushPromises()
    expect(wrapper.get('[role="alert"]').text()).toContain('Could not update sign-in methods')
    expect(wrapper.text()).not.toContain('Private details')

    await wrapper.get('input[type="password"]').setValue('Example1!')
    await wrapper.get('form').trigger('submit')
    await flushPromises()
    expect(wrapper.get('[role="status"]').text()).toContain('Confirmation email scheduled')
    expect(wrapper.get('input[type="password"]').element).toHaveProperty('value', '')
  })

  it('allows confirmation from the original session when mail opened in another browser', async () => {
    auth.protectedRequest.mockResolvedValueOnce({ hasEmail: false, hasTelegram: true, emailPending: true, mergePending: false })
    auth.protectedRequest.mockResolvedValueOnce({ status: 'linked' })
    auth.protectedRequest.mockResolvedValueOnce({ hasEmail: true, hasTelegram: true, emailPending: false, mergePending: false })
    const wrapper = await mountSuspended(CredentialSettings)
    await flushPromises()

    const input = wrapper.get('#link-email-confirmation')
    await input.setValue('https://equo.test/account/link-email#token=example-confirmation')
    await wrapper.get('#link-email-confirmation-form').trigger('submit')
    await flushPromises()

    expect(auth.protectedRequest).toHaveBeenCalledWith('/v1/account/credentials/email/confirm', {
      method: 'POST', body: { token: 'example-confirmation' }
    })
    expect(wrapper.emitted('linked')).toHaveLength(1)
    expect(wrapper.text()).toContain('Sign-in method updated.')
  })

  it('offers Telegram replacement with a password only when both methods are available', async () => {
    auth.protectedRequest.mockResolvedValue({ hasEmail: true, hasTelegram: true, mergePending: false })
    const wrapper = await mountSuspended(CredentialSettings)
    await flushPromises()
    expect(wrapper.text()).toContain('Change Telegram')
    expect(wrapper.get('input[type="password"]').attributes('autocomplete')).toBe('current-password')
    expect(wrapper.find('input[type="email"]').exists()).toBe(false)
  })
})
