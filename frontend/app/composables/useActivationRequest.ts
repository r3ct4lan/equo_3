import { computed, ref } from 'vue'
import type { ActivationRequestRequest, ActivationRequestResponse } from '~/types/api'
import {
  ACTIVATION_REQUEST_ENDPOINT,
  activationRequestErrorPresentation,
  type ActivationRequestValues,
  validateActivationRequest
} from '~/utils/auth-flow'

export function useActivationRequest(initialEmail = '') {
  const { $api } = useNuxtApp()
  const form = useApiForm<ActivationRequestValues>({
    email: initialEmail,
    password: ''
  })
  const succeeded = ref(false)
  const wasCanceled = ref(false)
  let requestController: AbortController | null = null

  const errorPresentation = computed(() => form.formError.value
    ? activationRequestErrorPresentation(form.formError.value)
    : null)

  async function submitRequest(): Promise<void> {
    succeeded.value = false
    wasCanceled.value = false

    const outcome = await form.submit<ActivationRequestResponse>(
      async (submittedValues) => {
        const controller = new AbortController()
        requestController = controller

        try {
          return await $api<ActivationRequestResponse>(ACTIVATION_REQUEST_ENDPOINT, {
            method: 'POST',
            signal: controller.signal,
            body: {
              email: submittedValues.email,
              password: submittedValues.password
            } satisfies ActivationRequestRequest
          })
        }
        finally {
          if (requestController === controller) {
            requestController = null
          }
        }
      },
      validateActivationRequest
    )

    if (outcome.ok) {
      form.values.password = ''
      succeeded.value = true
      return
    }

    wasCanceled.value = outcome.reason === 'canceled'
  }

  function prepareAnotherRequest(): void {
    form.values.password = ''
    form.clearErrors()
    succeeded.value = false
    wasCanceled.value = false
  }

  function dispose(): void {
    requestController?.abort()
    requestController = null
    form.values.password = ''
  }

  return {
    ...form,
    succeeded: readonly(succeeded),
    wasCanceled: readonly(wasCanceled),
    errorPresentation,
    submitRequest,
    prepareAnotherRequest,
    dispose
  }
}
