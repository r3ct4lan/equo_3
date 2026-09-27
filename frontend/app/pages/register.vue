<script setup lang="ts">
import type { RegisterRequest, RegisterResponse } from '~/types/api'
import {
  createRegistrationAttemptKeys,
  REGISTER_ENDPOINT,
  registrationErrorPresentation,
  type RegistrationValues,
  validateRegistration
} from '~/utils/auth-flow'

useHead({
  title: 'Create an account · Equo'
})

const { $api } = useNuxtApp()
const {
  values,
  fieldErrors,
  formError,
  isSubmitting,
  errorsFor,
  clearErrors,
  submit
} = useApiForm<RegistrationValues>({
  name: '',
  email: '',
  password: ''
})

const attemptKeys = createRegistrationAttemptKeys()
const registration = ref<RegisterResponse | null>(null)
const successPanel = useTemplateRef<HTMLElement>('successPanel')

const hasFieldErrors = computed(() => Object.keys(fieldErrors.value).length > 0)
const errorPresentation = computed(() => formError.value
  ? registrationErrorPresentation(formError.value)
  : null)
const summaryMessage = computed(() => errorPresentation.value?.message
  ?? (hasFieldErrors.value ? 'Correct the highlighted fields and submit again.' : null))
const summaryTitle = computed(() => errorPresentation.value?.title ?? 'Check the form')

function handleFormChange(): void {
  attemptKeys.formChanged()
  clearErrors()
}

async function submitRegistration(): Promise<void> {
  registration.value = null

  const outcome = await submit<RegisterResponse>(
    submittedValues => $api<RegisterResponse>(REGISTER_ENDPOINT, {
      method: 'POST',
      headers: {
        'Idempotency-Key': attemptKeys.forSubmission()
      },
      body: {
        name: submittedValues.name,
        email: submittedValues.email,
        password: submittedValues.password
      } satisfies RegisterRequest
    }),
    validateRegistration
  )

  if (!outcome.ok || !outcome.data) {
    return
  }

  attemptKeys.complete()
  values.password = ''
  registration.value = outcome.data
  await nextTick()
  successPanel.value?.focus()
}
</script>

<template>
  <section
    class="auth-grid"
    aria-labelledby="registration-title"
  >
    <div class="auth-intro stack">
      <p class="eyebrow">
        Your Equo account
      </p>
      <h1 id="registration-title">
        Create an account
      </h1>
      <p class="hero__lede">
        Register first, then use the one-time link sent to your email to activate your account.
      </p>
    </div>

    <div
      v-if="registration"
      class="stack"
    >
      <section
        ref="successPanel"
        class="panel stack"
        role="status"
        aria-live="polite"
        tabindex="-1"
      >
        <p class="eyebrow">
          Activation required
        </p>
        <h2>Check your email</h2>
        <p>
          We created the account for <strong>{{ registration.user.email }}</strong>, but it is not active yet.
        </p>
        <p>
          Open the activation email and follow its one-time link. Receiving the email does not sign you in.
        </p>
      </section>

      <ActivationRequestForm :initial-email="registration.user.email" />
    </div>

    <form
      v-else
      class="panel stack"
      novalidate
      aria-labelledby="registration-form-title"
      @submit.prevent="submitRegistration"
    >
      <div>
        <h2 id="registration-form-title">
          Registration details
        </h2>
        <p class="form-help">
          All fields are required.
        </p>
      </div>

      <FormErrorSummary
        :title="summaryTitle"
        :message="summaryMessage"
      />

      <fieldset
        class="form-fields stack"
        :disabled="isSubmitting"
      >
        <legend class="visually-hidden">
          Account information
        </legend>

        <div class="form-field">
          <label for="registration-name">Name</label>
          <input
            id="registration-name"
            v-model="values.name"
            name="name"
            type="text"
            autocomplete="name"
            required
            :aria-invalid="errorsFor('name').length > 0"
            :aria-describedby="errorsFor('name').length > 0 ? 'registration-name-error' : undefined"
            @input="handleFormChange"
          >
          <FormFieldError
            id="registration-name-error"
            :messages="errorsFor('name')"
          />
        </div>

        <div class="form-field">
          <label for="registration-email">Email</label>
          <input
            id="registration-email"
            v-model="values.email"
            name="email"
            type="email"
            inputmode="email"
            autocomplete="email"
            required
            :aria-invalid="errorsFor('email').length > 0"
            :aria-describedby="errorsFor('email').length > 0 ? 'registration-email-error' : undefined"
            @input="handleFormChange"
          >
          <FormFieldError
            id="registration-email-error"
            :messages="errorsFor('email')"
          />
        </div>

        <div class="form-field">
          <label for="registration-password">Password</label>
          <p
            id="registration-password-hint"
            class="form-help"
          >
            Use 12–128 characters and at least one non-space character.
          </p>
          <input
            id="registration-password"
            v-model="values.password"
            name="password"
            type="password"
            autocomplete="new-password"
            minlength="12"
            maxlength="128"
            required
            :aria-invalid="errorsFor('password').length > 0"
            :aria-describedby="errorsFor('password').length > 0
              ? 'registration-password-hint registration-password-error'
              : 'registration-password-hint'"
            @input="handleFormChange"
          >
          <FormFieldError
            id="registration-password-error"
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
          pending-label="Creating account…"
        >
          Create account
        </FormSubmitButton>
        <button
          v-if="errorPresentation?.retryable"
          class="button button--secondary"
          type="button"
          :disabled="isSubmitting"
          @click="submitRegistration"
        >
          Try the same attempt again
        </button>
      </div>
    </form>
  </section>
</template>
