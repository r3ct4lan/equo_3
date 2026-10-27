<script setup lang="ts">
import type { FocusTarget } from '~/types/ui'
import { accountDeactivationErrorPresentation, validateAccountDeactivation } from '~/utils/profile-settings'

const { $auth } = useNuxtApp()
const { values, fieldErrors, formError, isSubmitting, errorsFor, clearErrors, submit } = useApiForm({ currentPassword: '' })
const confirming = ref(false)
const trigger = useTemplateRef<FocusTarget>('trigger')
const confirmation = useTemplateRef<FocusTarget>('confirmation')
const presentation = computed(() => formError.value ? accountDeactivationErrorPresentation(formError.value) : null)
const summary = computed(() => presentation.value?.message ?? (Object.keys(fieldErrors.value).length ? 'Correct the highlighted field.' : null))

async function showConfirmation(): Promise<void> {
  confirming.value = true
  await nextTick()
  confirmation.value?.focus()
}

async function cancel(): Promise<void> {
  confirming.value = false
  values.currentPassword = ''
  clearErrors()
  await nextTick()
  trigger.value?.focus()
}

async function deactivate(): Promise<void> {
  const outcome = await submit(async submitted => await $auth.deactivateAccount(submitted.currentPassword), validateAccountDeactivation)
  if (outcome.reason === 'busy' || outcome.reason === 'validation') return
  values.currentPassword = ''
}

onBeforeUnmount(() => {
  values.currentPassword = ''
})
</script>

<template>
  <UiCard
    as="section"
    class="stack"
    aria-labelledby="deactivate-title"
  >
    <div>
      <UiText variant="eyebrow">
        Account lifecycle
      </UiText>
      <UiText
        id="deactivate-title"
        variant="heading2"
      >
        Deactivate account
      </UiText>
      <UiText>Pause new expenses and payments without deleting your connections, balances, or history.</UiText>
    </div>
    <UiButton
      v-if="!confirming"
      ref="trigger"
      compact
      variant="secondary"
      @click="showConfirmation"
    >
      Deactivate account
    </UiButton>
    <form
      v-else
      ref="confirmation"
      class="stack"
      novalidate
      aria-labelledby="deactivate-confirm-title"
      tabindex="-1"
      @submit.prevent="deactivate"
      @keydown.esc="cancel"
    >
      <div>
        <UiText
          id="deactivate-confirm-title"
          variant="heading3"
        >
          Confirm account deactivation
        </UiText>
        <UiText>You will be signed out on every device. To return, request an activation link using your email and password.</UiText>
      </div>
      <FormErrorSummary
        :title="presentation?.title ?? 'Check the form'"
        :message="summary"
        :request-id="formError?.requestId"
      />
      <fieldset
        class="form-fields stack"
        :disabled="isSubmitting"
      >
        <legend class="visually-hidden">
          Deactivation confirmation
        </legend>
        <UiField>
          <label for="deactivate-current-password">Current password</label>
          <UiInput
            id="deactivate-current-password"
            v-model="values.currentPassword"
            name="currentPassword"
            type="password"
            autocomplete="current-password"
            required
            :aria-invalid="errorsFor('currentPassword').length
              > 0"
            :aria-describedby="errorsFor('currentPassword').length ? 'deactivate-current-password-error' : undefined"
            @input="clearErrors"
          />
          <FormFieldError
            id="deactivate-current-password-error"
            :messages="errorsFor('currentPassword')"
          />
        </UiField>
      </fieldset>
      <div class="form-actions">
        <FormSubmitButton
          :pending="isSubmitting"
          pending-label="Deactivating…"
        >
          Confirm deactivation
        </FormSubmitButton>
        <UiButton
          variant="secondary"
          :disabled="isSubmitting"
          @click="cancel"
        >
          Cancel
        </UiButton>
      </div>
    </form>
  </UiCard>
</template>
