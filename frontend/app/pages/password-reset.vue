<script setup lang="ts">
import { isTerminalRecoveryError, passwordResetErrorPresentation, validateNewPassword } from '~/utils/password-reset'

useHead({ title: 'Choose a new password · Equo', meta: [{ name: 'referrer', content: 'no-referrer' }] })
const route = useRoute()
const router = useRouter()
const { $auth } = useNuxtApp()
const { values, fieldErrors, formError, isSubmitting, errorsFor, clearErrors, submit } = useApiForm({ newPassword: '' })
const state = ref<'preparing' | 'ready' | 'missing' | 'terminal' | 'success'>('preparing')
const resultPanel = useTemplateRef<HTMLElement>('resultPanel')
const presentation = computed(() => formError.value ? passwordResetErrorPresentation(formError.value) : null)
const summary = computed(() => presentation.value?.message ?? (Object.keys(fieldErrors.value).length ? 'Correct the highlighted field.' : null))
let capability: string | null = null
let disposed = false

onMounted(async () => {
  const raw = route.query.token
  capability = typeof raw === 'string' && raw !== '' ? raw : null
  await router.replace({ path: '/password-reset' })
  if (!disposed) state.value = capability ? 'ready' : 'missing'
})
onBeforeUnmount(() => {
  disposed = true
  capability = null
  values.newPassword = ''
})

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
</script>

<template>
  <section
    class="auth-grid"
    aria-labelledby="reset-title"
  >
    <div class="auth-intro stack">
      <p class="eyebrow">
        Account recovery
      </p>
      <h1 id="reset-title">
        Choose a new password
      </h1>
      <p class="hero__lede">
        Use 8–128 characters with at least one non-space character. After saving, sign in with your new password.
      </p>
    </div>
    <section class="panel stack">
      <AppLoadingState
        v-if="state === 'preparing'"
        message="Preparing recovery…"
      />
      <div
        v-else-if="state === 'missing'"
        ref="resultPanel"
        tabindex="-1"
      >
        <AppErrorState
          title="Recovery link is missing"
          message="Open the complete link from your recovery email."
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
      <div
        v-else-if="state === 'success'"
        ref="resultPanel"
        role="status"
        aria-live="polite"
        tabindex="-1"
      >
        <strong>Password saved</strong>
        <p>Sign in with your new password. If your account is inactive, activate it separately.</p>
      </div>
      <form
        v-else
        class="stack"
        novalidate
        aria-label="Choose a new password"
        @submit.prevent="resetPassword"
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
            New password
          </legend>
          <div class="form-field">
            <label for="reset-password">New password</label>
            <input
              id="reset-password"
              v-model="values.newPassword"
              name="newPassword"
              type="password"
              autocomplete="new-password"
              required
              :aria-invalid="errorsFor('newPassword').length > 0"
              :aria-describedby="errorsFor('newPassword').length ? 'reset-password-error' : undefined"
              @input="clearErrors"
            >
            <FormFieldError
              id="reset-password-error"
              :messages="errorsFor('newPassword')"
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
          pending-label="Saving password…"
        >
          Save new password
        </FormSubmitButton>
      </form>
      <NuxtLink
        v-if="state === 'missing' || state === 'terminal'"
        to="/forgot-password"
        class="button button--secondary"
      >Request a new recovery link</NuxtLink>
      <NuxtLink
        to="/login"
        class="button button--secondary"
      >Back to sign in</NuxtLink>
    </section>
  </section>
</template>
