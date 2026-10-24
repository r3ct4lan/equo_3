<script setup lang="ts">
import { accountDeactivationErrorPresentation, validateAccountDeactivation } from '~/utils/profile-settings'

const { $auth } = useNuxtApp()
const { values, fieldErrors, formError, isSubmitting, errorsFor, clearErrors, submit } = useApiForm({ currentPassword: '' })
const confirming = ref(false)
const trigger = useTemplateRef<HTMLButtonElement>('trigger')
const confirmation = useTemplateRef<HTMLElement>('confirmation')
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
  <section
    class="panel stack"
    aria-labelledby="deactivate-title"
  >
    <div>
      <p class="eyebrow">
        Account lifecycle
      </p>
      <h2 id="deactivate-title">
        Deactivate account
      </h2>
      <p>Pause new expenses and payments without deleting your connections, balances, or history.</p>
    </div>
    <button
      v-if="!confirming"
      ref="trigger"
      type="button"
      class="button button--secondary"
      @click="showConfirmation"
    >
      Deactivate account
    </button>
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
        <h3 id="deactivate-confirm-title">
          Confirm account deactivation
        </h3>
        <p>You will be signed out on every device. To return, request an activation link using your email and password.</p>
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
          Deactivation confirmation
        </legend>
        <div class="form-field">
          <label for="deactivate-current-password">Current password</label>
          <input
            id="deactivate-current-password"
            v-model="values.currentPassword"
            name="currentPassword"
            type="password"
            autocomplete="current-password"
            required
            :aria-invalid="errorsFor('currentPassword').length > 0"
            :aria-describedby="errorsFor('currentPassword').length ? 'deactivate-current-password-error' : undefined"
            @input="clearErrors"
          >
          <FormFieldError
            id="deactivate-current-password-error"
            :messages="errorsFor('currentPassword')"
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
          pending-label="Deactivating…"
        >
          Confirm deactivation
        </FormSubmitButton>
        <button
          type="button"
          class="button button--secondary"
          :disabled="isSubmitting"
          @click="cancel"
        >
          Cancel
        </button>
      </div>
    </form>
  </section>
</template>
