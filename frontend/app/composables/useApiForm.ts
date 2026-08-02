import { reactive, ref, toRaw } from 'vue'
import type { ApiClientError, FieldErrorMap } from '~/utils/api-error'
import { toApiClientError } from '~/utils/api-error'

export interface FormSubmitResult<TResult> {
  ok: boolean
  data?: TResult
  reason?: 'busy' | 'validation' | 'canceled' | 'request'
}

export function useApiForm<TValues extends Record<string, unknown>>(initialValues: TValues) {
  const values = reactive({ ...initialValues }) as TValues
  const fieldErrors = ref<FieldErrorMap>({})
  const formError = ref<ApiClientError | null>(null)
  const isSubmitting = ref(false)

  function clearErrors(): void {
    fieldErrors.value = {}
    formError.value = null
  }

  function errorsFor(field: keyof TValues | string): readonly string[] {
    return fieldErrors.value[String(field)] ?? []
  }

  async function submit<TResult>(
    handler: (submittedValues: Readonly<TValues>) => Promise<TResult>,
    validate?: (submittedValues: Readonly<TValues>) => FieldErrorMap
  ): Promise<FormSubmitResult<TResult>> {
    if (isSubmitting.value) {
      return { ok: false, reason: 'busy' }
    }

    clearErrors()
    const submittedValues = { ...toRaw(values) } as Readonly<TValues>
    const clientErrors = validate?.(submittedValues) ?? {}

    if (Object.keys(clientErrors).length > 0) {
      fieldErrors.value = clientErrors
      return { ok: false, reason: 'validation' }
    }

    isSubmitting.value = true

    try {
      const data = await handler(submittedValues)
      return { ok: true, data }
    }
    catch (error: unknown) {
      const normalized = toApiClientError(error)

      if (normalized.isCanceled) {
        return { ok: false, reason: 'canceled' }
      }

      fieldErrors.value = normalized.fieldErrors
      formError.value = normalized

      return { ok: false, reason: 'request' }
    }
    finally {
      isSubmitting.value = false
    }
  }

  function reset(nextValues: TValues = initialValues): void {
    Object.assign(values, nextValues)
    clearErrors()
  }

  return {
    values,
    fieldErrors: readonly(fieldErrors),
    formError: readonly(formError),
    isSubmitting: readonly(isSubmitting),
    errorsFor,
    clearErrors,
    submit,
    reset
  }
}
