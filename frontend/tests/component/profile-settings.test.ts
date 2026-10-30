import { flushPromises } from '@vue/test-utils'
import { mockNuxtImport, mountSuspended } from '@nuxt/test-utils/runtime'
import { useRoute } from '#app'
import { beforeEach, describe, expect, it, vi } from 'vitest'
import ProfileNameForm from '../../app/components/ProfileNameForm.vue'
import ProfileLanguageForm from '../../app/components/ProfileLanguageForm.vue'
import EmailChangeForm from '../../app/components/EmailChangeForm.vue'
import EmailChangePage from '../../app/pages/email-change.vue'
import AccountDeactivationForm from '../../app/components/AccountDeactivationForm.vue'
import { ApiClientError } from '../../app/utils/api-error'
import { selectValue } from '../helpers/select'

const { apiMock, authMock } = vi.hoisted(() => ({ apiMock: vi.fn(), authMock: { protectedRequest: vi.fn(), deactivateAccount: vi.fn() } }))
mockNuxtImport('useNuxtApp', original => () => new Proxy(original(), {
  get(target, property, receiver) {
    if (property === '$api') return apiMock
    if (property === '$auth') return authMock
    return Reflect.get(target, property, receiver)
  }
}))

const user = {
  id: '00000000-0000-4000-8000-000000000001',
  name: 'Old Name',
  email: 'old@example.test',
  isActive: true,
  locale: 'en' as const,
  createdAt: '2026-08-08T12:00:00Z'
}

describe('profile settings', () => {
  beforeEach(() => {
    apiMock.mockReset().mockResolvedValue(undefined)
    authMock.protectedRequest.mockReset()
    authMock.deactivateAccount.mockReset().mockResolvedValue(undefined)
    useCurrentUser().reset()
    useLocale().setLocale('en')
  })

  it('saves the language and applies the returned profile', async () => {
    const updated = { ...user, locale: 'ru' as const }
    useCurrentUser().setAuthenticated(user)
    authMock.protectedRequest.mockResolvedValue(updated)
    const wrapper = await mountSuspended(ProfileLanguageForm, { props: { user } })
    await selectValue(wrapper, 'profile-language', 'ru')
    await wrapper.get('form').trigger('submit')
    await flushPromises()
    expect(authMock.protectedRequest).toHaveBeenCalledWith('/v1/me/locale', { method: 'PATCH', body: { locale: 'ru' } })
    expect(useCurrentUser().state.value.user?.locale).toBe('ru')
    expect(wrapper.get('[role="status"]').text()).toContain('Язык сохранён')
    wrapper.unmount()
  })

  it('validates and saves a display name into current profile state', async () => {
    authMock.protectedRequest.mockResolvedValue({ ...user, name: '  New Name 😀  ' })
    useCurrentUser().setAuthenticated(user)
    const wrapper = await mountSuspended(ProfileNameForm, { props: { user } })
    await wrapper.get('input').setValue('   ')
    await wrapper.get('form').trigger('submit')
    expect(authMock.protectedRequest).not.toHaveBeenCalled()
    expect(wrapper.get('input').attributes('aria-invalid')).toBe('true')
    await wrapper.get('input').setValue('  New Name 😀  ')
    await wrapper.get('form').trigger('submit')
    await flushPromises()
    expect(authMock.protectedRequest).toHaveBeenCalledWith('/v1/me', { method: 'PATCH', body: { name: '  New Name 😀  ' } })
    expect(useCurrentUser().state.value.user?.name).toBe('  New Name 😀  ')
    expect(wrapper.get('[role="status"]').text()).toContain('Name saved')
    wrapper.unmount()
  })

  it('requests email confirmation and clears the password', async () => {
    authMock.protectedRequest.mockResolvedValue({ status: 'email_confirmation_scheduled' })
    const wrapper = await mountSuspended(EmailChangeForm, { attachTo: document.body })
    expect(wrapper.find('form').exists()).toBe(false)
    await wrapper.get('button').trigger('click')
    expect(authMock.protectedRequest).not.toHaveBeenCalled()
    expect(document.activeElement).toBe(wrapper.get('#profile-new-email').element)
    await wrapper.get('form').trigger('submit')
    expect(authMock.protectedRequest).not.toHaveBeenCalled()
    await wrapper.get('#profile-new-email').setValue('new@example.test')
    await wrapper.get('#profile-current-password').setValue('Current password!')
    await wrapper.get('form').trigger('submit')
    await flushPromises()
    expect(authMock.protectedRequest).toHaveBeenCalledWith('/v1/account/email-change-requests', {
      method: 'POST', body: { currentPassword: 'Current password!', newEmail: 'new@example.test' }
    })
    expect(wrapper.get('#profile-current-password').element).toHaveProperty('value', '')
    expect(wrapper.get('[role="status"]').text()).toContain('Confirmation email scheduled')
    await wrapper.findAll('button').find(button => button.text() === 'Cancel')!.trigger('click')
    expect(wrapper.find('form').exists()).toBe(false)
    expect(document.activeElement).toBe(wrapper.get('button').element)
    await wrapper.get('button').trigger('click')
    expect(wrapper.get('#profile-new-email').element).toHaveProperty('value', '')
    wrapper.unmount()
  })

  it('shows safe request conflicts and clears the password', async () => {
    authMock.protectedRequest.mockRejectedValue(new ApiClientError({ kind: 'api', status: 409, code: 'EMAIL_ALREADY_IN_USE', message: 'Conflict' }))
    const wrapper = await mountSuspended(EmailChangeForm)
    await wrapper.get('button').trigger('click')
    await wrapper.get('#profile-new-email').setValue('used@example.test')
    await wrapper.get('#profile-current-password').setValue('Current password!')
    await wrapper.get('form').trigger('submit')
    await flushPromises()
    expect(wrapper.get('[role="alert"]').text()).toContain('Email is already in use')
    expect(wrapper.get('#profile-current-password').element).toHaveProperty('value', '')
    wrapper.unmount()
  })

  it('requires an explicit confirmation and current password before deactivation', async () => {
    const wrapper = await mountSuspended(AccountDeactivationForm)
    expect(wrapper.find('form').exists()).toBe(false)
    await wrapper.get('button').trigger('click')
    expect(wrapper.get('h3').text()).toContain('Confirm account deactivation')
    await wrapper.get('form').trigger('submit')
    expect(authMock.deactivateAccount).not.toHaveBeenCalled()
    expect(wrapper.get('#deactivate-current-password').attributes('aria-invalid')).toBe('true')
    await wrapper.get('#deactivate-current-password').setValue('Current password!')
    await wrapper.get('form').trigger('submit')
    await flushPromises()
    expect(authMock.deactivateAccount).toHaveBeenCalledWith('Current password!')
    expect(wrapper.get('#deactivate-current-password').element).toHaveProperty('value', '')
    wrapper.unmount()
  })

  it('keeps the account form available after a wrong password and clears the secret', async () => {
    authMock.deactivateAccount.mockRejectedValue(new ApiClientError({ kind: 'api', status: 401, code: 'INVALID_CREDENTIALS', message: 'Safe error' }))
    const wrapper = await mountSuspended(AccountDeactivationForm)
    await wrapper.get('button').trigger('click')
    await wrapper.get('#deactivate-current-password').setValue('Wrong password!')
    await wrapper.get('form').trigger('submit')
    await flushPromises()
    expect(wrapper.get('[role="alert"]').text()).toContain('Current password is incorrect')
    expect(wrapper.get('#deactivate-current-password').element).toHaveProperty('value', '')
    wrapper.unmount()
  })

  it('scrubs the confirmation token and confirms only after the user clicks', async () => {
    const wrapper = await mountSuspended(EmailChangePage, { route: '/email-change?token=v1.synthetic-capability&other=secret' })
    await flushPromises()
    await vi.waitFor(() => expect(useRoute().fullPath).toBe('/email-change'))
    expect(apiMock).not.toHaveBeenCalled()
    expect(wrapper.text()).not.toContain('synthetic-capability')
    await wrapper.get('button').trigger('click')
    await flushPromises()
    expect(apiMock).toHaveBeenCalledWith('/v1/auth/email-change-confirmations', expect.objectContaining({ method: 'POST', body: { token: 'v1.synthetic-capability' } }))
    expect(wrapper.get('[role="status"]').text()).toContain('Email changed')
    wrapper.unmount()
  })

  it('removes terminal confirmation capabilities', async () => {
    apiMock.mockRejectedValue(new ApiClientError({ kind: 'api', status: 410, code: 'TOKEN_EXPIRED', message: 'Expired' }))
    const wrapper = await mountSuspended(EmailChangePage, { route: '/email-change?token=v1.synthetic-capability' })
    await flushPromises()
    await vi.waitFor(() => expect(wrapper.text()).toContain('Ready to confirm'))
    await wrapper.get('button').trigger('click')
    await flushPromises()
    expect(wrapper.get('[role="alert"]').text()).toContain('Confirmation link is unavailable')
    expect(wrapper.text()).not.toContain('synthetic-capability')
    expect(wrapper.find('button').exists()).toBe(false)
    wrapper.unmount()
  })
})
