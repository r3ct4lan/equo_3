<script setup lang="ts">
import { emailChangeErrorPresentation, validateEmailChange } from '~/utils/profile-settings'

const { $auth } = useNuxtApp()
const { values, fieldErrors, formError, isSubmitting, errorsFor, clearErrors, submit } = useApiForm({ newEmail: '', currentPassword: '' })
const scheduled = ref(false)
const presentation = computed(() => formError.value ? emailChangeErrorPresentation(formError.value) : null)
const summary = computed(() => presentation.value?.message ?? (Object.keys(fieldErrors.value).length ? 'Correct the highlighted fields.' : null))

async function requestChange(): Promise<void> {
  scheduled.value = false
  const outcome = await submit(
    submitted => $auth.protectedRequest<{ status: 'email_confirmation_scheduled' }>('/v1/account/email-change-requests', {
      method: 'POST',
      body: { currentPassword: submitted.currentPassword, newEmail: submitted.newEmail }
    }),
    validateEmailChange
  )
  values.currentPassword = ''
  if (outcome.ok) {
    values.newEmail = ''
    scheduled.value = true
  }
}
</script>

<template>
  <UiCard
    as="form"
    class="stack"
    novalidate
    aria-label="Change email"
    @submit.prevent="requestChange"
  >
    <div>
      <UiText variant="eyebrow">
        Sign-in address
      </UiText>
      <UiText variant="heading2">
        Change email
      </UiText>
      <UiText>Your current email stays active until you confirm the link sent to the new address.</UiText>
    </div>
    <FormErrorSummary
      :title="presentation?.title ?? 'Check the form'"
      :message="summary"
    />
    <fieldset
      class="form-fields stack"
      :disabled="isSubmitting"
    >
      <legend class="visually-hidden">
        Email change details
      </legend>
      <UiField>
        <label for="profile-new-email">New email</label>
        <UiInput
          id="profile-new-email"
          v-model="values.newEmail"
          name="newEmail"
          type="email"
          autocomplete="email"
          required
          :aria-invalid="errorsFor('newEmail').length
            > 0"
          :aria-describedby="errorsFor('newEmail').length ? 'profile-new-email-error' : undefined"
          @input="clearErrors(); scheduled = false"
        />
        <FormFieldError
          id="profile-new-email-error"
          :messages="errorsFor('newEmail')"
        />
      </UiField>
      <UiField>
        <label for="profile-current-password">Current password</label>
        <UiInput
          id="profile-current-password"
          v-model="values.currentPassword"
          name="currentPassword"
          type="password"
          autocomplete="current-password"
          required
          :aria-invalid="errorsFor('currentPassword').length
            > 0"
          :aria-describedby="errorsFor('currentPassword').length ? 'profile-current-password-error' : undefined"
          @input="clearErrors(); scheduled = false"
        />
        <FormFieldError
          id="profile-current-password-error"
          :messages="errorsFor('currentPassword')"
        />
      </UiField>
    </fieldset>
    <UiText
      v-if="scheduled"
      role="status"
    >
      Confirmation email scheduled. Open the link sent to your new address.
    </UiText>
    <UiText
      v-if="formError?.requestId"
      class="support-reference"
    >
      Support reference: <code>{{ formError.requestId }}</code>
    </UiText>
    <FormSubmitButton
      compact
      :pending="isSubmitting"
      pending-label="Sending confirmation…"
    >
      Send confirmation email
    </FormSubmitButton>
  </UiCard>
</template>
