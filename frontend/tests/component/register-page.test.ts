import { flushPromises } from '@vue/test-utils'
import { mockNuxtImport, mountSuspended } from '@nuxt/test-utils/runtime'
import { beforeEach, describe, expect, it, vi } from 'vitest'
import RegisterPage from '../../app/pages/register.vue'
import { ApiClientError } from '../../app/utils/api-error'
import { forgetInvitation, rememberInvitation } from '../../app/utils/connect-invitations'

const { apiMock } = vi.hoisted(() => ({
  apiMock: vi.fn()
}))

mockNuxtImport('useNuxtApp', original => () => new Proxy(original(), {
  get(target, property, receiver) {
    return property === '$api' ? apiMock : Reflect.get(target, property, receiver)
  }
}))

const validResponse = {
  user: {
    id: '00000000-0000-4000-8000-000000000001',
    name: 'Alex Doe',
    email: 'alex@example.test',
    isActive: false,
    createdAt: '2026-08-02T10:00:00Z'
  },
  activationRequired: true
}

async function validForm(wrapper: Awaited<ReturnType<typeof mountSuspended>>): Promise<void> {
  await wrapper.get('input[name="name"]').setValue('Alex Doe')
  await wrapper.get('input[name="email"]').setValue('alex@example.test')
  await wrapper.get('input[name="password"]').setValue('A2345678901!')
}

describe('registration page', () => {
  beforeEach(() => {
    apiMock.mockReset()
    forgetInvitation()
  })

  it('binds an opened invitation to the new account without putting it in the page', async () => {
    rememberInvitation('private-invitation')
    apiMock.mockResolvedValue(validResponse)
    const wrapper = await mountSuspended(RegisterPage, { route: '/register' })
    await validForm(wrapper)
    await wrapper.get('form').trigger('submit')
    await flushPromises()
    expect(apiMock).toHaveBeenCalledWith('/v1/auth/register', expect.objectContaining({
      body: expect.objectContaining({ invitationToken: 'private-invitation' })
    }))
    expect(wrapper.text()).not.toContain('private-invitation')
    wrapper.unmount()
  })

  it('renders an accessible empty form and associates client errors with every required field', async () => {
    const wrapper = await mountSuspended(RegisterPage, { route: '/register' })

    expect(wrapper.get('h1').text()).toBe('Create an account')
    expect(wrapper.get('label[for="registration-name"]').text()).toBe('Name')
    expect(wrapper.get('label[for="registration-email"]').text()).toBe('Email')
    expect(wrapper.get('label[for="registration-password"]').text()).toBe('Password')
    expect(wrapper.get('button[type="submit"]').text()).toBe('Create account')
    expect(wrapper.get('form a[href="/login"]').text()).toBe('Sign in')

    await wrapper.get('form').trigger('submit')

    expect(apiMock).not.toHaveBeenCalled()
    expect(wrapper.get('[role="alert"]').text()).toContain('Correct the highlighted fields')
    expect(wrapper.get('input[name="name"]').attributes('aria-invalid')).toBe('true')
    expect(wrapper.get('input[name="email"]').attributes('aria-invalid')).toBe('true')
    expect(wrapper.get('input[name="password"]').attributes('aria-invalid')).toBe('true')
    expect(wrapper.text()).toContain('Enter your name.')
    expect(wrapper.text()).toContain('Enter your email address.')
    expect(wrapper.text()).toContain('Enter a password.')

    wrapper.unmount()
  })

  it('blocks a second submission while pending and renders the server result', async () => {
    let resolveRequest: (value: typeof validResponse) => void = () => undefined
    apiMock.mockReturnValue(new Promise<typeof validResponse>((resolve) => {
      resolveRequest = resolve
    }))
    const wrapper = await mountSuspended(RegisterPage, { route: '/register' })
    await validForm(wrapper)

    await wrapper.get('form').trigger('submit')
    await wrapper.get('form').trigger('submit')

    expect(apiMock).toHaveBeenCalledTimes(1)
    expect(wrapper.get('fieldset').attributes()).toHaveProperty('disabled')
    expect(wrapper.get('button[type="submit"]').attributes('aria-busy')).toBe('true')

    resolveRequest(validResponse)
    await flushPromises()

    expect(wrapper.get('[role="status"]').text()).toContain('Check your email')
    expect(wrapper.get('[role="status"]').text()).toContain('alex@example.test')
    expect(wrapper.find('h2#activation-request-title').exists()).toBe(false)
    const openRequest = wrapper.get('button.activation-request-link')
    expect(openRequest.text()).toBe('Request another activation link')
    await openRequest.trigger('click')
    await flushPromises()
    expect(apiMock).toHaveBeenCalledTimes(1)
    expect(wrapper.find('button.activation-request-link').exists()).toBe(false)
    expect(wrapper.get('h2#activation-request-title').text()).toBe('Request another activation link')
    expect(wrapper.get('input#activation-request-email').element).toHaveProperty('value', 'alex@example.test')
    expect(wrapper.text()).not.toContain('A2345678901!')
    expect(apiMock).toHaveBeenCalledWith('/v1/auth/register', expect.objectContaining({
      method: 'POST',
      body: {
        name: 'Alex Doe',
        email: 'alex@example.test',
        password: 'A2345678901!',
        locale: 'en'
      }
    }))

    wrapper.unmount()
  })

  it.each(['12345678', '1234567!', 'Password!', 'Password1 '])('rejects a password missing required character classes before sending registration', async (password) => {
    const wrapper = await mountSuspended(RegisterPage, { route: '/register' })
    await validForm(wrapper)
    await wrapper.get('input[name="password"]').setValue(password)
    await wrapper.get('form').trigger('submit')

    expect(apiMock).not.toHaveBeenCalled()
    expect(wrapper.get('input[name="password"]').attributes('aria-invalid')).toBe('true')
    expect(wrapper.get('#registration-password-error').text()).toContain('Include at least one')
    wrapper.unmount()
  })

  it('shows backend field errors without losing correctable input', async () => {
    apiMock.mockRejectedValue(new ApiClientError({
      kind: 'api',
      status: 422,
      code: 'VALIDATION_ERROR',
      message: 'Request validation failed.',
      requestId: '01KZ1TESTREQUEST00000000000',
      violations: [{
        field: 'email',
        code: 'INVALID_EMAIL',
        message: 'Email has invalid format.'
      }]
    }))
    const wrapper = await mountSuspended(RegisterPage, { route: '/register' })
    await validForm(wrapper)

    await wrapper.get('form').trigger('submit')
    await flushPromises()

    expect(wrapper.text()).toContain('Email has invalid format.')
    expect(wrapper.get('[role="alert"]').text()).toContain('Support reference: 01KZ1TESTREQUEST00000000000')
    expect(wrapper.get('input[name="email"]').element).toHaveProperty('value', 'alex@example.test')
    expect(wrapper.get('input[name="password"]').element).toHaveProperty('value', 'A2345678901!')
    expect(wrapper.get('input[name="email"]').attributes('aria-describedby')).toContain('registration-email-error')

    wrapper.unmount()
  })

  it('renders a business conflict separately from field validation', async () => {
    apiMock.mockRejectedValue(new ApiClientError({
      kind: 'api',
      status: 409,
      code: 'EMAIL_ALREADY_EXISTS',
      message: 'Email already exists.'
    }))
    const wrapper = await mountSuspended(RegisterPage, { route: '/register' })
    await validForm(wrapper)

    await wrapper.get('form').trigger('submit')
    await flushPromises()

    expect(wrapper.get('[role="alert"]').text()).toContain('Email already registered')
    expect(wrapper.findAll('[role="alert"]')).toHaveLength(1)
    expect(wrapper.get('input[name="email"]').element).toHaveProperty('value', 'alex@example.test')

    wrapper.unmount()
  })
})
