<script setup lang="ts">
import type { FocusTarget } from '~/types/ui'
import { PASSWORD_POLICY_HINT } from '~/utils/auth-flow'
import { isTerminalRecoveryError, passwordResetErrorPresentation, validateNewPassword } from '~/utils/password-reset'
import { toApiClientError, type ApiClientError } from '~/utils/api-error'

useHead({ title: 'Choose a new password · Equo', meta: [{ name: 'referrer', content: 'no-referrer' }] })
const route = useRoute()
const router = useRouter()
const { $auth, $api } = useNuxtApp()
const { values, fieldErrors, formError, isSubmitting, errorsFor, clearErrors, submit } = useApiForm({ newPassword: '' })
const state = ref<'preparing' | 'ready' | 'missing' | 'terminal' | 'check-error' | 'success'>('preparing')
const checkError = ref<ApiClientError | null>(null)
const resultPanel = useTemplateRef<FocusTarget>('resultPanel')
const presentation = computed(() => formError.value || checkError.value ? passwordResetErrorPresentation((formError.value ?? checkError.value)!) : null)
const summary = computed(() => presentation.value?.message ?? (Object.keys(fieldErrors.value).length ? 'Correct the highlighted field.' : null))
let capability: string | null = null
let disposed = false

onMounted(async () => {
  const raw = route.query.token
  capability = typeof raw === 'string' && raw !== '' ? raw : null
  await router.replace({ path: '/password-reset' })
  if (disposed) return
  if (!capability) state.value = 'missing'
  else await checkLink()
})
onBeforeUnmount(() => {
  disposed = true
  capability = null
  values.newPassword = ''
})

async function checkLink(): Promise<void> {
  if (!capability || disposed) return
  state.value = 'preparing'
  checkError.value = null
  try {
    await $api('/v1/auth/password-reset/check', { method: 'POST', body: { token: capability } })
    if (!disposed) state.value = 'ready'
  }
  catch (failure) {
    if (disposed) return
    checkError.value = toApiClientError(failure)
    if (isTerminalRecoveryError(checkError.value.code)) {
      capability = null
      state.value = 'terminal'
    }
    else state.value = 'check-error'
  }
}

async function resetPassword(): Promise<void> {
  if (!capability || disposed) return
  const outcome = await submit(async (submitted) => {
    if (capability) await $auth.resetPassword(capability, submitted.newPassword)
  }, validateNewPassword)
  if (disposed || outcome.reason === 'busy' || outcome.reason === 'validation') return
  values.newPassword = ''
  if (outcome.ok) {
    capability = null
    state.value = 'success'
  }
  else if (formError.value && isTerminalRecoveryError(formError.value.code)) {
    capability = null
    state.value = 'terminal'
  }
  await nextTick()
  resultPanel.value?.focus()
}

const { t } = useTranslation()
</script>

<template>
  <section
    class="auth-grid"
    aria-labelledby="reset-title"
  >
    <div class="auth-intro stack">
      <UiText variant="eyebrow">
        {{ t('Account recovery') }}
      </UiText>
      <UiText
        id="reset-title"
        variant="heading1"
      >
        {{ t('Choose a new password') }}
      </UiText>
      <UiText variant="lede">
        {{ t(PASSWORD_POLICY_HINT) }} {{ t('After saving, sign in with your new password.') }}
      </UiText>
    </div>
    <UiCard
      as="section"
      class="stack"
    >
      <AppLoadingState
        v-if="state === 'preparing'"
        :message="t('Preparing recovery…')"
      />
      <div
        v-else-if="state === 'missing'"
        ref="resultPanel"
        tabindex="-1"
      >
        <AppErrorState
          :title="t('Recovery link is missing')"
          :message="t('Open the complete link from your recovery email.')"
        />
      </div>
      <div
        v-else-if="state === 'terminal'"
        ref="resultPanel"
        tabindex="-1"
      >
        <AppErrorState
          :title="presentation?.title ?? 'Recovery link is unavailable'"
          :message="presentation?.message ?? 'Request a new recovery link.'"
        />
      </div>
      <AppErrorState
        v-else-if="state === 'check-error'"
        :title="presentation?.title ?? 'Recovery link could not be checked'"
        :message="presentation?.message ?? 'Try checking the link again.'"
        :retry-label="t('Try again')"
        @retry="checkLink"
      />
      <div
        v-else-if="state === 'success'"
        ref="resultPanel"
        role="status"
        aria-live="polite"
        tabindex="-1"
      >
        <strong>{{ t('Password saved') }}</strong>
        <UiText>{{ t('Sign in with your new password. If your account is inactive, activate it separately.') }}</UiText>
      </div>
      <form
        v-else
        class="stack"
        novalidate
        :aria-label="t('Choose a new password')"
        @submit.prevent="resetPassword"
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
            {{ t('New password') }}
          </legend>
          <UiField>
            <label for="reset-password">{{ t('New password') }}</label>
            <UiInput
              id="reset-password"
              v-model="values.newPassword"
              name="newPassword"
              type="password"
              autocomplete="new-password"
              required
              :aria-invalid="errorsFor('newPassword').length
                > 0"
              :aria-describedby="errorsFor('newPassword').length ? 'reset-password-error' : undefined"
              @input="clearErrors"
            />
            <FormFieldError
              id="reset-password-error"
              :messages="errorsFor('newPassword')"
            />
          </UiField>
        </fieldset>
        <FormSubmitButton
          :pending="isSubmitting"
          :pending-label="t('Saving password…')"
        >
          {{ t('Save new password') }}
        </FormSubmitButton>
      </form>
      <UiButton
        v-if="state === 'missing' || state === 'terminal'"
        variant="secondary"
        to="/forgot-password"
      >
        {{ t('Request a new recovery link') }}
      </UiButton>
      <UiButton
        variant="secondary"
        to="/login"
      >
        {{ t('Back to sign in') }}
      </UiButton>
    </UiCard>
  </section>
</template>
