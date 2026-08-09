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
const errorPresentation = computed(() => formError.value ? loginErrorPresentation(formError.value) : null)
const summaryMessage = computed(() => errorPresentation.value?.message
  ?? (hasFieldErrors.value ? 'Correct the highlighted fields and submit again.' : null))
const summaryTitle = computed(() => errorPresentation.value?.title ?? 'Check the form')

function handleFormChange(): void {
  clearErrors()
}

async function submitLogin(): Promise<void> {
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
</script>

<template>
  <section
    class="auth-grid"
    aria-labelledby="login-title"
  >
    <div class="auth-intro stack">
      <p class="eyebrow">
        Welcome back
      </p>
      <h1 id="login-title">
        Sign in
      </h1>
      <p class="hero__lede">
        Use your activated account to continue to your Equo profile.
      </p>
    </div>

    <form
      class="panel stack"
      novalidate
      aria-labelledby="login-form-title"
      @submit.prevent="submitLogin"
    >
      <div>
        <h2 id="login-form-title">
          Account details
        </h2>
        <p class="form-help">
          Both fields are required.
        </p>
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
          Sign-in information
        </legend>

        <div class="form-field">
          <label for="login-email">Email</label>
          <input
            id="login-email"
            v-model="values.email"
            name="email"
            type="email"
            inputmode="email"
            autocomplete="email"
            required
            :aria-invalid="errorsFor('email').length > 0"
            :aria-describedby="errorsFor('email').length > 0 ? 'login-email-error' : undefined"
            @input="handleFormChange"
          >
          <FormFieldError
            id="login-email-error"
            :messages="errorsFor('email')"
          />
        </div>

        <div class="form-field">
          <label for="login-password">Password</label>
          <input
            id="login-password"
            v-model="values.password"
            name="password"
            type="password"
            autocomplete="current-password"
            required
            :aria-invalid="errorsFor('password').length > 0"
            :aria-describedby="errorsFor('password').length > 0 ? 'login-password-error' : undefined"
            @input="handleFormChange"
          >
          <FormFieldError
            id="login-password-error"
            :messages="errorsFor('password')"
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
          pending-label="Signing in…"
        >
          Sign in
        </FormSubmitButton>
        <button
          v-if="errorPresentation?.retryable"
          class="button button--secondary"
          type="button"
          :disabled="isSubmitting"
          @click="retryLogin"
        >
          Try again
        </button>
      </div>
    </form>
  </section>
</template>
