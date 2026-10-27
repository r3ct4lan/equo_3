<script setup lang="ts">
import type { LoginValues } from '~/types/session'
import { loginErrorPresentation, safeRedirectPath, validateLogin } from '~/utils/auth-session'

useHead({
  title: 'Sign in · Equo'
})

const route = useRoute()
const { $auth } = useNuxtApp()
const currentUser = useCurrentUser()
const {
  values,
  fieldErrors,
  formError,
  isSubmitting,
  errorsFor,
  clearErrors,
  submit
} = useApiForm<LoginValues>({
  email: '',
  password: ''
})

const redirectTarget = computed(() => safeRedirectPath(route.query.redirect))
const hasFieldErrors = computed(() => Object.keys(fieldErrors.value).length > 0)
const rateLimitRemainingSeconds = ref<number | null>(null)
let rateLimitInterval: ReturnType<typeof setInterval> | null = null
let rateLimitDeadline: number | null = null

const errorPresentation = computed(() => formError.value ? loginErrorPresentation(formError.value) : null)
const summaryMessage = computed(() => errorPresentation.value?.message
  ?? (hasFieldErrors.value ? 'Correct the highlighted fields and submit again.' : null))
const summaryTitle = computed(() => errorPresentation.value?.title ?? 'Check the form')

function clearRateLimitCountdown(): void {
  if (rateLimitInterval !== null) {
    clearInterval(rateLimitInterval)
    rateLimitInterval = null
  }
  rateLimitDeadline = null
  rateLimitRemainingSeconds.value = null
}

function startRateLimitCountdown(seconds: number | null): void {
  if (seconds === null || seconds <= 0 || !Number.isSafeInteger(seconds * 1000)) {
    return
  }

  const now = performance.now()
  const candidateDeadline = now + seconds * 1000
  rateLimitDeadline = rateLimitDeadline !== null && rateLimitDeadline > now
    ? Math.min(rateLimitDeadline, candidateDeadline)
    : candidateDeadline
  rateLimitRemainingSeconds.value = Math.max(0, Math.ceil((rateLimitDeadline - now) / 1000))
  if (rateLimitInterval !== null) {
    clearInterval(rateLimitInterval)
  }
  rateLimitInterval = setInterval(() => {
    const deadline = rateLimitDeadline
    if (deadline === null) {
      return
    }
    rateLimitRemainingSeconds.value = Math.max(0, Math.ceil((deadline - performance.now()) / 1000))
    if (rateLimitRemainingSeconds.value === 0 && rateLimitInterval !== null) {
      clearInterval(rateLimitInterval)
      rateLimitInterval = null
    }
  }, 250)
}

function handleFormChange(): void {
  clearErrors()
}

async function submitLogin(): Promise<void> {
  if (rateLimitRemainingSeconds.value === 0) {
    clearRateLimitCountdown()
  }
  const outcome = await submit<undefined>(
    async (submittedValues) => {
      await $auth.login(submittedValues, { redirectTo: redirectTarget.value })
      return undefined
    },
    validateLogin
  )

  if (outcome.ok) {
    values.password = ''
  }
  else if (outcome.reason === 'request' && formError.value?.code === 'RATE_LIMIT_EXCEEDED') {
    startRateLimitCountdown(formError.value.retryAfterSeconds)
  }
}

async function retryLogin(): Promise<void> {
  await submitLogin()
}

onMounted(async () => {
  await $auth.bootstrap()
  if (currentUser.state.value.status === 'authenticated') {
    await navigateTo(redirectTarget.value)
  }
})

onUnmounted(clearRateLimitCountdown)
</script>

<template>
  <section
    class="auth-grid"
    aria-labelledby="login-title"
  >
    <div class="auth-intro stack">
      <UiText variant="eyebrow">
        Welcome back
      </UiText>
      <UiText
        id="login-title"
        variant="heading1"
      >
        Sign in
      </UiText>
      <UiText variant="lede">
        Use your activated account to continue to your Equo profile.
      </UiText>
      <UiText
        v-if="route.query.signedOut === '1'"
        role="status"
      >
        {{ (route.query.passwordReset === '1' || route.query.passwordChanged === '1') ? 'Your password has been saved. Sign in with your new password.' : 'You are signed out.' }}
      </UiText>
    </div>

    <UiCard
      as="form"
      class="stack"
      novalidate
      aria-labelledby="login-form-title"
      @submit.prevent="submitLogin"
    >
      <div>
        <UiText
          id="login-form-title"
          variant="heading2"
        >
          Account details
        </UiText>
        <UiText variant="help">
          Both fields are required.
        </UiText>
      </div>

      <FormErrorSummary
        :title="summaryTitle"
        :message="summaryMessage"
      />

      <UiText
        v-if="rateLimitRemainingSeconds !== null"
        variant="help"
        role="timer"
        aria-live="off"
      >
        {{ rateLimitRemainingSeconds > 0
          ? `Try again in ${rateLimitRemainingSeconds} ${rateLimitRemainingSeconds === 1 ? 'second' : 'seconds'}.`
          : 'You can try again now.' }}
      </UiText>

      <fieldset
        class="form-fields stack"
        :disabled="isSubmitting"
      >
        <legend class="visually-hidden">
          Sign-in information
        </legend>

        <UiField>
          <label for="login-email">Email</label>
          <UiInput
            id="login-email"
            v-model="values.email"
            name="email"
            type="email"
            inputmode="email"
            autocomplete="email"
            required
            :aria-invalid="errorsFor('email').length
              > 0"
            :aria-describedby="errorsFor('email').length > 0 ? 'login-email-error' : undefined"
            @input="handleFormChange"
          />
          <FormFieldError
            id="login-email-error"
            :messages="errorsFor('email')"
          />
        </UiField>

        <UiField>
          <label for="login-password">Password</label>
          <UiInput
            id="login-password"
            v-model="values.password"
            name="password"
            type="password"
            autocomplete="current-password"
            required
            :aria-invalid="errorsFor('password').length
              > 0"
            :aria-describedby="errorsFor('password').length > 0 ? 'login-password-error' : undefined"
            @input="handleFormChange"
          />
          <FormFieldError
            id="login-password-error"
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
        <UiButton
          variant="secondary"
          to="/forgot-password"
        >
          Forgot your password?
        </UiButton>
        <FormSubmitButton
          :pending="isSubmitting"
          pending-label="Signing in…"
        >
          Sign in
        </FormSubmitButton>
        <UiButton
          v-if="errorPresentation?.retryable"
          variant="secondary"
          :disabled="isSubmitting"
          @click="retryLogin"
        >
          Try again
        </UiButton>
        <UiButton
          variant="secondary"
          to="/activate"
        >
          Need another activation link?
        </UiButton>
      </div>
      <UiText>
        Don't have an account?
        <UiLink to="/register">
          Create an account
        </UiLink>
      </UiText>
    </UiCard>
  </section>
</template>
