<script setup lang="ts">
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

const successPanel = useTemplateRef<HTMLElement>('successPanel')
const emailInput = useTemplateRef<HTMLInputElement>('emailInput')
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
  <section
    class="panel stack"
    aria-labelledby="activation-request-title"
  >
    <div>
      <p class="eyebrow">
        Activation email
      </p>
      <h2 id="activation-request-title">
        Request another activation link
      </h2>
      <p class="form-help">
        Enter the email and current password for the account. The result does not confirm whether the account exists or an email was sent.
      </p>
    </div>

    <div
      v-if="succeeded"
      ref="successPanel"
      class="status-card status-card--success"
      role="status"
      aria-live="polite"
      tabindex="-1"
    >
      <span
        class="status-card__marker"
        aria-hidden="true"
      />
      <div class="stack">
        <div>
          <strong>Request received</strong>
          <p>If the details can be used for activation, we will send instructions to the email address you provided.</p>
        </div>
        <button
          class="button button--secondary"
          type="button"
          @click="requestAnother"
        >
          Request another link
        </button>
      </div>
    </div>

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

        <div class="form-field">
          <label for="activation-request-email">Email</label>
          <input
            id="activation-request-email"
            ref="emailInput"
            v-model="values.email"
            name="email"
            type="email"
            inputmode="email"
            autocomplete="email"
            required
            :aria-invalid="errorsFor('email').length > 0"
            :aria-describedby="errorsFor('email').length > 0 ? 'activation-request-email-error' : undefined"
            @input="handleFormChange"
          >
          <FormFieldError
            id="activation-request-email-error"
            :messages="errorsFor('email')"
          />
        </div>

        <div class="form-field">
          <label for="activation-request-password">Current password</label>
          <input
            id="activation-request-password"
            v-model="values.password"
            name="password"
            type="password"
            autocomplete="current-password"
            required
            :aria-invalid="errorsFor('password').length > 0"
            :aria-describedby="errorsFor('password').length > 0 ? 'activation-request-password-error' : undefined"
            @input="handleFormChange"
          >
          <FormFieldError
            id="activation-request-password-error"
            :messages="errorsFor('password')"
          />
        </div>
      </fieldset>

      <p
        v-if="formError?.requestId"
        class="support-reference"
      >
        Support reference: <code>{{ formError.requestId }}</code>
      </p>

      <div class="form-actions">
        <FormSubmitButton
          :pending="isSubmitting"
          pending-label="Requesting link…"
        >
          Request activation link
        </FormSubmitButton>
        <button
          v-if="errorPresentation?.retryable"
          class="button button--secondary"
          type="button"
          :disabled="isSubmitting"
          @click="handleSubmit"
        >
          Try again
        </button>
      </div>
    </form>
  </section>
</template>
