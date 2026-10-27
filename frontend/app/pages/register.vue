<script setup lang="ts">
import type { FocusTarget } from '~/types/ui'
import type { RegisterRequest, RegisterResponse } from '~/types/api'
import { forgetInvitation, recalledInvitation } from '~/utils/connect-invitations'
import {
  createRegistrationAttemptKeys,
  PASSWORD_POLICY_HINT,
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
const successPanel = useTemplateRef<FocusTarget>('successPanel')
const activationRequest = useTemplateRef<{ focusEmail: () => void }>('activationRequest')
const activationRequestOpen = ref(false)

async function openActivationRequest(): Promise<void> {
  activationRequestOpen.value = true
  await nextTick()
  activationRequest.value?.focusEmail()
}

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
  const invitationToken = recalledInvitation()

  const outcome = await submit<RegisterResponse>(
    submittedValues => $api<RegisterResponse>(REGISTER_ENDPOINT, {
      method: 'POST',
      headers: {
        'Idempotency-Key': attemptKeys.forSubmission()
      },
      body: {
        name: submittedValues.name,
        email: submittedValues.email,
        password: submittedValues.password,
        ...(invitationToken ? { invitationToken } : {})
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
  forgetInvitation()
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
      <UiText variant="eyebrow">
        Your Equo account
      </UiText>
      <UiText
        id="registration-title"
        variant="heading1"
      >
        Create an account
      </UiText>
      <UiText variant="lede">
        Register first, then use the one-time link sent to your email to activate your account.
      </UiText>
    </div>

    <div
      v-if="registration"
      class="stack"
    >
      <UiCard
        ref="successPanel"
        as="section"
        class="stack"
        role="status"
        aria-live="polite"
        tabindex="-1"
      >
        <UiText variant="eyebrow">
          Activation required
        </UiText>
        <UiText variant="heading2">
          Check your email
        </UiText>
        <UiText>
          We created the account for <strong>{{ registration.user.email }}</strong>, but it is not active yet.
        </UiText>
        <UiText>
          Open the activation email and follow its one-time link. Receiving the email does not sign you in.
        </UiText>
      </UiCard>

      <ActivationRequestForm
        v-if="activationRequestOpen"
        ref="activationRequest"
        :initial-email="registration.user.email"
      />
      <UiButton
        v-else
        variant="link"
        class="activation-request-link"
        @click="openActivationRequest"
      >
        Request another activation link
      </UiButton>
    </div>

    <UiCard
      v-else
      as="form"
      class="stack"
      novalidate
      aria-labelledby="registration-form-title"
      @submit.prevent="submitRegistration"
    >
      <div>
        <UiText
          id="registration-form-title"
          variant="heading2"
        >
          Registration details
        </UiText>
        <UiText variant="help">
          All fields are required.
        </UiText>
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

        <UiField>
          <label for="registration-name">Name</label>
          <UiInput
            id="registration-name"
            v-model="values.name"
            name="name"
            type="text"
            autocomplete="name"
            required
            :aria-invalid="errorsFor('name').length
              > 0"
            :aria-describedby="errorsFor('name').length > 0 ? 'registration-name-error' : undefined"
            @input="handleFormChange"
          />
          <FormFieldError
            id="registration-name-error"
            :messages="errorsFor('name')"
          />
        </UiField>

        <UiField>
          <label for="registration-email">Email</label>
          <UiInput
            id="registration-email"
            v-model="values.email"
            name="email"
            type="email"
            inputmode="email"
            autocomplete="email"
            required
            :aria-invalid="errorsFor('email').length
              > 0"
            :aria-describedby="errorsFor('email').length > 0 ? 'registration-email-error' : undefined"
            @input="handleFormChange"
          />
          <FormFieldError
            id="registration-email-error"
            :messages="errorsFor('email')"
          />
        </UiField>

        <UiField>
          <label for="registration-password">Password</label>
          <UiText
            id="registration-password-hint"
            variant="help"
          >
            {{ PASSWORD_POLICY_HINT }}
          </UiText>
          <UiInput
            id="registration-password"
            v-model="values.password"
            name="password"
            type="password"
            autocomplete="new-password"
            minlength="8"
            maxlength="128"
            required
            :aria-invalid="errorsFor('password').length
              > 0"
            :aria-describedby="errorsFor('password').length > 0
              ? 'registration-password-hint registration-password-error'
              : 'registration-password-hint'"
            @input="handleFormChange"
          />
          <FormFieldError
            id="registration-password-error"
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
          pending-label="Creating account…"
        >
          Create account
        </FormSubmitButton>
        <UiButton
          v-if="errorPresentation?.retryable"
          variant="secondary"
          :disabled="isSubmitting"
          @click="submitRegistration"
        >
          Try the same attempt again
        </UiButton>
      </div>
      <UiText>
        Already have an account?
        <UiLink to="/login">
          Sign in
        </UiLink>
      </UiText>
    </UiCard>
  </section>
</template>
