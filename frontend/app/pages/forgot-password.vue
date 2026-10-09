<script setup lang="ts">
import { PASSWORD_RESET_REQUEST_ENDPOINT, passwordResetErrorPresentation, validateRecoveryEmail } from '~/utils/password-reset'

useHead({ title: 'Recover your account · Equo' })
const { $api } = useNuxtApp()
const { values, fieldErrors, formError, isSubmitting, errorsFor, clearErrors, submit } = useApiForm({ email: '' })
const succeeded = ref(false)
const resultPanel = useTemplateRef<HTMLElement>('resultPanel')
const presentation = computed(() => formError.value ? passwordResetErrorPresentation(formError.value) : null)
const summary = computed(() => presentation.value?.message ?? (Object.keys(fieldErrors.value).length ? 'Enter a valid email address.' : null))
let controller: AbortController | null = null

async function sendLink(): Promise<void> {
  const outcome = await submit(async (submitted) => {
    controller = new AbortController()
    try {
      return await $api(PASSWORD_RESET_REQUEST_ENDPOINT, { method: 'POST', body: { email: submitted.email }, signal: controller.signal })
    }
    finally { controller = null }
  }, validateRecoveryEmail)
  if (outcome.ok) {
    succeeded.value = true
    await nextTick()
    resultPanel.value?.focus()
  }
}
onBeforeUnmount(() => controller?.abort())
</script>

<template>
  <section
    class="auth-grid"
    aria-labelledby="recovery-title"
  >
    <div class="auth-intro stack">
      <p class="eyebrow">
        Account recovery
      </p>
      <h1 id="recovery-title">
        Forgot your password?
      </h1>
      <p class="hero__lede">
        Enter your email to request a link for choosing a new password.
      </p>
    </div>
    <section class="panel stack">
      <div
        v-if="succeeded"
        ref="resultPanel"
        class="status-card status-card--success"
        role="status"
        aria-live="polite"
        tabindex="-1"
      >
        <div>
          <strong>Request received</strong>
          <p>If an account uses this address, we will send recovery instructions. The link expires in 30 minutes.</p>
        </div>
      </div>
      <form
        v-else
        class="stack"
        novalidate
        aria-label="Request recovery link"
        @submit.prevent="sendLink"
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
            Recovery email
          </legend>
          <div class="form-field">
            <label for="recovery-email">Email</label>
            <input
              id="recovery-email"
              v-model="values.email"
              name="email"
              type="email"
              inputmode="email"
              autocomplete="email"
              required
              :aria-invalid="errorsFor('email').length > 0"
              :aria-describedby="errorsFor('email').length ? 'recovery-email-error' : undefined"
              @input="clearErrors"
            >
            <FormFieldError
              id="recovery-email-error"
              :messages="errorsFor('email')"
            />
          </div>
        </fieldset>
        <p
          v-if="formError?.requestId"
          class="support-reference"
        >
          Support reference: <code>{{ formError.requestId }}</code>
        </p>
        <FormSubmitButton
          :pending="isSubmitting"
          pending-label="Requesting link…"
        >
          Send recovery link
        </FormSubmitButton>
      </form>
      <NuxtLink
        to="/login"
        class="button button--secondary"
      >Back to sign in</NuxtLink>
    </section>
  </section>
</template>
