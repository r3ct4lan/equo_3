<script setup lang="ts">
import { passwordChangeErrorPresentation, validatePasswordChange } from '~/utils/password-change'

const { $auth } = useNuxtApp()
const { values, fieldErrors, formError, isSubmitting, errorsFor, clearErrors, submit } = useApiForm({ currentPassword: '', newPassword: '' })
const saved = ref(false)
const presentation = computed(() => formError.value ? passwordChangeErrorPresentation(formError.value) : null)
const summary = computed(() => presentation.value?.message ?? (Object.keys(fieldErrors.value).length ? 'Correct the highlighted fields.' : null))
let disposed = false

onBeforeUnmount(() => {
  disposed = true
  values.currentPassword = ''
  values.newPassword = ''
})

async function changePassword(): Promise<void> {
  const outcome = await submit(async submitted => await $auth.changePassword(submitted.currentPassword, submitted.newPassword), validatePasswordChange)
  if (disposed || outcome.reason === 'busy' || outcome.reason === 'validation') return
  values.currentPassword = ''
  values.newPassword = ''
  saved.value = outcome.ok
}
</script>

<template>
  <div
    v-if="saved"
    role="status"
  >
    Password saved. Sign in with your new password.
  </div>
  <UiCard
    v-else
    as="form"
    class="stack"
    novalidate
    aria-label="Change password"
    @submit.prevent="changePassword"
  >
    <FormErrorSummary
      :title="presentation?.title ?? 'Check the form'"
      :message="summary"
    />
    <fieldset
      class="form-fields stack"
      :disabled="isSubmitting"
    >
      <legend class="visually-hidden">
        Password details
      </legend>
      <UiField>
        <label for="change-current-password">Current password</label>
        <UiInput
          id="change-current-password"
          v-model="values.currentPassword"
          name="currentPassword"
          type="password"
          autocomplete="current-password"
          required
          :aria-invalid="errorsFor('currentPassword').length
            > 0"
          :aria-describedby="errorsFor('currentPassword').length ? 'change-current-error' : undefined"
          @input="clearErrors"
        />
        <FormFieldError
          id="change-current-error"
          :messages="errorsFor('currentPassword')"
        />
      </UiField>
      <UiField>
        <label for="change-new-password">New password</label>
        <UiInput
          id="change-new-password"
          v-model="values.newPassword"
          name="newPassword"
          type="password"
          autocomplete="new-password"
          required
          :aria-invalid="errorsFor('newPassword').length
            > 0"
          :aria-describedby="errorsFor('newPassword').length ? 'change-new-error' : undefined"
          @input="clearErrors"
        />
        <FormFieldError
          id="change-new-error"
          :messages="errorsFor('newPassword')"
        />
      </UiField>
    </fieldset>
    <UiText
      v-if="formError?.requestId"
      class="support-reference"
    >
      Support reference: <code>{{ formError.requestId }}</code>
    </UiText>
    <FormSubmitButton
      compact
      :pending="isSubmitting"
      pending-label="Saving password…"
    >
      Save new password
    </FormSubmitButton>
  </UiCard>
</template>
