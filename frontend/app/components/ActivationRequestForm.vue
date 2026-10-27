<script setup lang="ts">
import type { FocusTarget } from '~/types/ui'

const props = withDefaults(defineProps<{
  initialEmail?: string
}>(), {
  initialEmail: ''
})

const {
  values,
  fieldErrors,
  formError,
  isSubmitting,
  succeeded,
  errorPresentation,
  errorsFor,
  clearErrors,
  submitRequest,
  prepareAnotherRequest,
  dispose
} = useActivationRequest(props.initialEmail)

const successPanel = useTemplateRef<FocusTarget>('successPanel')
const emailInput = useTemplateRef<FocusTarget>('emailInput')
defineExpose({ focusEmail: () => emailInput.value?.focus() })
const hasFieldErrors = computed(() => Object.keys(fieldErrors.value).length > 0)
const summaryMessage = computed(() => errorPresentation.value?.message
  ?? (hasFieldErrors.value ? 'Correct the highlighted fields and submit again.' : null))
const summaryTitle = computed(() => errorPresentation.value?.title ?? 'Check the form')

function handleFormChange(): void {
  clearErrors()
}

async function handleSubmit(): Promise<void> {
  await submitRequest()

  if (succeeded.value) {
    await nextTick()
    successPanel.value?.focus()
  }
}

async function requestAnother(): Promise<void> {
  prepareAnotherRequest()
  await nextTick()
  emailInput.value?.focus()
}

onBeforeUnmount(dispose)
</script>

<template>
  <UiCard
    as="section"
    class="stack"
    aria-labelledby="activation-request-title"
  >
    <div>
      <UiText variant="eyebrow">
        Activation email
      </UiText>
      <UiText
        id="activation-request-title"
        variant="heading2"
      >
        Request another activation link
      </UiText>
      <UiText variant="help">
        Enter the email and current password for the account. The result does not confirm whether the account exists or an email was sent.
      </UiText>
    </div>

    <UiNotice
      v-if="succeeded"
      ref="successPanel"
      tone="success"
      role="status"
      aria-live="polite"
      tabindex="-1"
    >
      <div class="stack">
        <div>
          <strong>Request received</strong>
          <UiText>If the details can be used for activation, we will send instructions to the email address you provided.</UiText>
        </div>
        <UiButton
          variant="secondary"
          @click="requestAnother"
        >
          Request another link
        </UiButton>
      </div>
    </UiNotice>

    <form
      v-else
      class="stack"
      novalidate
      aria-labelledby="activation-request-title"
      @submit.prevent="handleSubmit"
    >
      <FormErrorSummary
        :title="summaryTitle"
        :message="summaryMessage"
      />

      <fieldset
        class="form-fields stack"
        :disabled="isSubmitting"
      >
        <legend class="visually-hidden">
          Activation request details
        </legend>

        <UiField>
          <label for="activation-request-email">Email</label>
          <UiInput
            id="activation-request-email"
            ref="emailInput"
            v-model="values.email"
            name="email"
            type="email"
            inputmode="email"
            autocomplete="email"
            required
            :aria-invalid="errorsFor('email').length
              > 0"
            :aria-describedby="errorsFor('email').length > 0 ? 'activation-request-email-error' : undefined"
            @input="handleFormChange"
          />
          <FormFieldError
            id="activation-request-email-error"
            :messages="errorsFor('email')"
          />
        </UiField>

        <UiField>
          <label for="activation-request-password">Current password</label>
          <UiInput
            id="activation-request-password"
            v-model="values.password"
            name="password"
            type="password"
            autocomplete="current-password"
            required
            :aria-invalid="errorsFor('password').length
              > 0"
            :aria-describedby="errorsFor('password').length > 0 ? 'activation-request-password-error' : undefined"
            @input="handleFormChange"
          />
          <FormFieldError
            id="activation-request-password-error"
            :messages="errorsFor('password')"
          />
        </UiField>
      </fieldset>

      <UiText
        v-if="formError?.requestId"
        class="support-reference"
      >
        Support reference: <code>{{ formError.requestId }}</code>
      </UiText>

      <div class="form-actions">
        <FormSubmitButton
          :pending="isSubmitting"
          pending-label="Requesting link…"
        >
          Request activation link
        </FormSubmitButton>
        <UiButton
          v-if="errorPresentation?.retryable"
          variant="secondary"
          :disabled="isSubmitting"
          @click="handleSubmit"
        >
          Try again
        </UiButton>
      </div>
    </form>
  </UiCard>
</template>
