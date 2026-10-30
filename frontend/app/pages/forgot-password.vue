<script setup lang="ts">
import type { FocusTarget } from '~/types/ui'
import { PASSWORD_RESET_REQUEST_ENDPOINT, passwordResetErrorPresentation, validateRecoveryEmail } from '~/utils/password-reset'

useHead({ title: 'Recover your account · Equo' })
const { $api } = useNuxtApp()
const { values, fieldErrors, formError, isSubmitting, errorsFor, clearErrors, submit } = useApiForm({ email: '' })
const succeeded = ref(false)
const resultPanel = useTemplateRef<FocusTarget>('resultPanel')
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

const { t } = useTranslation()
</script>

<template>
  <section
    class="auth-grid"
    aria-labelledby="recovery-title"
  >
    <div class="auth-intro stack">
      <UiText variant="eyebrow">
        {{ t('Account recovery') }}
      </UiText>
      <UiText
        id="recovery-title"
        variant="heading1"
      >
        {{ t('Forgot your password?') }}
      </UiText>
      <UiText variant="lede">
        {{ t('Enter your email to request a link for choosing a new password.') }}
      </UiText>
    </div>
    <UiCard
      as="section"
      class="stack"
    >
      <UiNotice
        v-if="succeeded"
        ref="resultPanel"
        tone="success"
        role="status"
        aria-live="polite"
        tabindex="-1"
      >
        <div>
          <strong>{{ t('Request received') }}</strong>
          <UiText>{{ t('If an account uses this address, we will send recovery instructions. The link expires in 30 minutes.') }}</UiText>
        </div>
      </UiNotice>
      <form
        v-else
        class="stack"
        novalidate
        :aria-label="t('Request recovery link')"
        @submit.prevent="sendLink"
      >
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
            {{ t('Recovery email') }}
          </legend>
          <UiField>
            <label for="recovery-email">{{ t('Email') }}</label>
            <UiInput
              id="recovery-email"
              v-model="values.email"
              name="email"
              type="email"
              inputmode="email"
              autocomplete="email"
              required
              :aria-invalid="errorsFor('email').length
                > 0"
              :aria-describedby="errorsFor('email').length ? 'recovery-email-error' : undefined"
              @input="clearErrors"
            />
            <FormFieldError
              id="recovery-email-error"
              :messages="errorsFor('email')"
            />
          </UiField>
        </fieldset>
        <FormSubmitButton
          :pending="isSubmitting"
          :pending-label="t('Requesting link…')"
        >
          {{ t('Send recovery link') }}
        </FormSubmitButton>
      </form>
      <UiButton
        variant="secondary"
        to="/login"
      >
        {{ t('Back to sign in') }}
      </UiButton>
    </UiCard>
  </section>
</template>
