<script setup lang="ts">
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
      <p class="eyebrow">
        Your account
      </p>
      <h1 id="security-title">
        Account security
      </h1>
      <p class="hero__lede">
        Choose a new password using 12–128 characters with at least one non-space character. Saving signs you out on all devices. Sign in again with your new password.
      </p>
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
    <NuxtLink
      to="/me"
      class="button button--secondary"
    >Back to profile</NuxtLink>
  </section>
</template>
