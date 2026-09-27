import { flushPromises } from '@vue/test-utils'
import { mockNuxtImport, mountSuspended } from '@nuxt/test-utils/runtime'
import { useRoute } from '#app'
import { beforeEach, describe, expect, it, vi } from 'vitest'
import ActivationRequestForm from '../../app/components/ActivationRequestForm.vue'
import { ApiClientError } from '../../app/utils/api-error'

const { apiMock } = vi.hoisted(() => ({
  apiMock: vi.fn()
}))

mockNuxtImport('useNuxtApp', original => () => new Proxy(original(), {
  get(target, property, receiver) {
    return property === '$api' ? apiMock : Reflect.get(target, property, receiver)
  }
}))

async function fillForm(
  wrapper: Awaited<ReturnType<typeof mountSuspended>>,
  password = 'synthetic-current-password'
): Promise<void> {
  await wrapper.get('input[name="email"]').setValue('alex@example.test')
  await wrapper.get('input[name="password"]').setValue(password)
}

describe('activation request form', () => {
  beforeEach(() => {
    apiMock.mockReset()
    localStorage.clear()
    sessionStorage.clear()
  })

  it('renders a semantic accessible form and associates client errors with its fields', async () => {
    const wrapper = await mountSuspended(ActivationRequestForm, { route: '/activate' })

    expect(wrapper.get('form').attributes('aria-labelledby')).toBe('activation-request-title')
    expect(wrapper.get('label[for="activation-request-email"]').text()).toBe('Email')
    expect(wrapper.get('label[for="activation-request-password"]').text()).toBe('Current password')
    expect(wrapper.get('input[name="email"]').attributes('autocomplete')).toBe('email')
    expect(wrapper.get('input[name="password"]').attributes('autocomplete')).toBe('current-password')

    await wrapper.get('form').trigger('submit')

    expect(apiMock).not.toHaveBeenCalled()
    expect(wrapper.get('[role="alert"]').text()).toContain('Correct the highlighted fields')
    expect(wrapper.get('input[name="email"]').attributes('aria-invalid')).toBe('true')
    expect(wrapper.get('input[name="password"]').attributes('aria-invalid')).toBe('true')
    expect(wrapper.get('input[name="email"]').attributes('aria-describedby')).toBe('activation-request-email-error')
    expect(wrapper.get('input[name="password"]').attributes('aria-describedby')).toBe('activation-request-password-error')
    expect(wrapper.text()).toContain('Enter your email address.')
    expect(wrapper.text()).toContain('Enter your current password.')

    await wrapper.get('input[name="email"]').setValue('not-an-email')
    await wrapper.get('input[name="password"]').setValue('x')
    await wrapper.get('form').trigger('submit')

    expect(wrapper.text()).toContain('Enter an email address in a valid format.')
    expect(wrapper.text()).not.toContain('Use at least')

    wrapper.unmount()
  })

  it('sends the exact public request once while pending and shows a neutral success state', async () => {
    let resolveRequest: (value: { status: 'activation_email_scheduled' }) => void = () => undefined
    apiMock.mockReturnValue(new Promise((resolve) => {
      resolveRequest = resolve
    }))
    const wrapper = await mountSuspended(ActivationRequestForm, { route: '/activate' })
    const password = 'synthetic-current-password'
    const indexedDbOpen = vi.fn()
    vi.stubGlobal('indexedDB', { open: indexedDbOpen })
    await fillForm(wrapper, password)

    await wrapper.get('form').trigger('submit')
    await wrapper.get('form').trigger('submit')

    expect(apiMock).toHaveBeenCalledTimes(1)
    expect(apiMock).toHaveBeenCalledWith('/v1/auth/activation-requests', {
      method: 'POST',
      signal: expect.any(AbortSignal),
      body: {
        email: 'alex@example.test',
        password
      }
    })
    expect(wrapper.get('fieldset').attributes()).toHaveProperty('disabled')
    expect(wrapper.get('button[type="submit"]').attributes('aria-busy')).toBe('true')

    resolveRequest({ status: 'activation_email_scheduled' })
    await flushPromises()

    const success = wrapper.get('[role="status"]')
    expect(success.attributes('aria-live')).toBe('polite')
    expect(success.text()).toContain('If the details can be used for activation')
    expect(success.text()).not.toMatch(/account exists|password (?:is|was) correct/i)
    expect(wrapper.text()).not.toContain(password)
    expect(useRoute().fullPath).toBe('/activate')
    expect(JSON.stringify(Object.values(localStorage))).not.toContain(password)
    expect(JSON.stringify(Object.values(sessionStorage))).not.toContain(password)
    expect(document.cookie).not.toContain(password)
    expect(indexedDbOpen).not.toHaveBeenCalled()

    await wrapper.get('button').trigger('click')
    expect(wrapper.get('input[name="email"]').element).toHaveProperty('value', 'alex@example.test')
    expect(wrapper.get('input[name="password"]').element).toHaveProperty('value', '')

    wrapper.unmount()
    vi.unstubAllGlobals()
  })

  it('maps backend field errors while preserving correctable form values', async () => {
    apiMock.mockRejectedValue(new ApiClientError({
      kind: 'api',
      status: 422,
      code: 'VALIDATION_ERROR',
      message: 'Request validation failed.',
      violations: [
        { field: 'email', code: 'INVALID_EMAIL', message: 'Email has invalid format.' },
        { field: 'password', code: 'REQUIRED', message: 'Value is required.' }
      ]
    }))
    const wrapper = await mountSuspended(ActivationRequestForm, { route: '/activate' })
    await fillForm(wrapper)

    await wrapper.get('form').trigger('submit')
    await flushPromises()

    expect(wrapper.text()).toContain('Email has invalid format.')
    expect(wrapper.text()).toContain('Value is required.')
    expect(wrapper.get('input[name="email"]').element).toHaveProperty('value', 'alex@example.test')
    expect(wrapper.get('input[name="password"]').element).toHaveProperty('value', 'synthetic-current-password')
    expect(wrapper.get('input[name="email"]').attributes('aria-describedby')).toBe('activation-request-email-error')
    expect(wrapper.get('input[name="password"]').attributes('aria-describedby')).toBe('activation-request-password-error')

    wrapper.unmount()
  })

  it('shows Retry-After safely without an automatic retry', async () => {
    apiMock.mockRejectedValue(new ApiClientError({
      kind: 'api',
      status: 429,
      code: 'RATE_LIMIT_EXCEEDED',
      message: 'Too many requests.',
      retryAfterSeconds: 3599
    }))
    const wrapper = await mountSuspended(ActivationRequestForm, { route: '/activate' })
    await fillForm(wrapper)

    await wrapper.get('form').trigger('submit')
    await flushPromises()

    expect(apiMock).toHaveBeenCalledTimes(1)
    expect(wrapper.get('[role="alert"]').text()).toContain('Try again in 3599 seconds.')
    expect(wrapper.get('[role="alert"]').text()).not.toMatch(/account|password/i)

    await flushPromises()
    expect(apiMock).toHaveBeenCalledTimes(1)

    wrapper.unmount()
  })

  it('offers only a manual retry after a safe technical error', async () => {
    apiMock
      .mockRejectedValueOnce(new ApiClientError({
        kind: 'network',
        code: 'NETWORK_ERROR',
        message: 'Private network detail that must not be shown.'
      }))
      .mockResolvedValueOnce({ status: 'activation_email_scheduled' })
    const wrapper = await mountSuspended(ActivationRequestForm, { route: '/activate' })
    await fillForm(wrapper)

    await wrapper.get('form').trigger('submit')
    await flushPromises()

    expect(wrapper.get('[role="alert"]').text()).toContain('We could not complete the request')
    expect(wrapper.text()).not.toContain('Private network detail')
    expect(apiMock).toHaveBeenCalledTimes(1)

    await wrapper.get('button.button--secondary').trigger('click')
    await flushPromises()

    expect(apiMock).toHaveBeenCalledTimes(2)
    expect(wrapper.get('[role="status"]').text()).toContain('Request received')

    wrapper.unmount()
  })

  it('aborts an in-flight request when leaving the form', async () => {
    let requestSignal: AbortSignal | undefined
    apiMock.mockImplementation((_path: string, options: { signal?: AbortSignal }) => new Promise((_resolve, reject) => {
      requestSignal = options.signal
      requestSignal?.addEventListener('abort', () => {
        reject(new DOMException('Canceled by navigation', 'AbortError'))
      })
    }))
    const wrapper = await mountSuspended(ActivationRequestForm, { route: '/activate' })
    await fillForm(wrapper)

    await wrapper.get('form').trigger('submit')
    expect(requestSignal?.aborted).toBe(false)

    wrapper.unmount()
    await flushPromises()

    expect(requestSignal?.aborted).toBe(true)
  })
})
