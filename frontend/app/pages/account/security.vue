<script setup lang="ts">
import { PASSWORD_POLICY_HINT } from '~/utils/auth-flow'

definePageMeta({ middleware: 'auth' })
useHead({ title: 'Account security · Equo' })
const currentUser = useCurrentUser()
const { $auth } = useNuxtApp()
</script>

<template>
  <section
    class="stack stack--large"
    aria-labelledby="security-title"
  >
    <div class="hero">
      <UiText variant="eyebrow">
        Your account
      </UiText>
      <UiText
        id="security-title"
        variant="heading1"
      >
        Account security
      </UiText>
      <UiText variant="lede">
        {{ PASSWORD_POLICY_HINT }} Saving signs you out on all devices. Sign in again with your new password.
      </UiText>
    </div>
    <PasswordChangeForm v-if="currentUser.state.value.status === 'authenticated'" />
    <AppErrorState
      v-else-if="currentUser.state.value.status === 'error'"
      title="We could not check your session"
      message="Try again to check your session."
      retry-label="Try again"
      @retry="$auth.retryBootstrap"
    />
    <AppLoadingState
      v-else-if="currentUser.state.value.status === 'unknown'"
      message="Checking your session…"
    />
    <UiButton
      variant="secondary"
      to="/me"
    >
      Back to profile
    </UiButton>
  </section>
</template>
