import { describe, expect, it } from 'vitest'
import { useApiForm } from '../../app/composables/useApiForm'

describe('useApiForm', () => {
  it('keeps submitted values and exposes no error after caller cancellation', async () => {
    const form = useApiForm({ email: 'kept@example.test' })

    const result = await form.submit(async () => {
      throw new DOMException('Canceled by test caller', 'AbortError')
    })

    expect(result).toEqual({ ok: false, reason: 'canceled' })
    expect(form.values.email).toBe('kept@example.test')
    expect(form.formError.value).toBeNull()
    expect(form.fieldErrors.value).toEqual({})
    expect(form.isSubmitting.value).toBe(false)
  })

  it('passes an immutable submission snapshot to the request handler', async () => {
    const form = useApiForm({ name: 'Before' })
    let submittedName = ''

    const result = await form.submit(async (submitted) => {
      form.values.name = 'After'
      submittedName = submitted.name
      return 'ok'
    })

    expect(result).toEqual({ ok: true, data: 'ok' })
    expect(submittedName).toBe('Before')
    expect(form.values.name).toBe('After')
  })
})
