<script setup lang="ts">
useHead({ title: 'Equo' })
const { state } = useCurrentUser()
const { $auth } = useNuxtApp()
</script>

<template>
  <HomeDashboard v-if="state.status === 'authenticated'" />
  <section
    v-else-if="state.status === 'anonymous'"
    class="hero"
    aria-labelledby="home-title"
  >
    <UiText variant="eyebrow">
      Shared expenses, clearly
    </UiText>
    <UiText
      id="home-title"
      variant="heading1"
    >
      Welcome to Equo
    </UiText>
    <UiText variant="lede">
      Create an account, confirm your email, and get ready to make shared expenses easier to understand.
    </UiText>
    <div class="hero__actions">
      <UiButton
        to="/register"
      >
        Create an account
      </UiButton>
      <UiButton
        variant="secondary"
        to="/login"
      >
        Sign in
      </UiButton>
    </div>
  </section>
  <section
    v-else
    class="stack"
    aria-labelledby="home-session-title"
  >
    <UiText
      id="home-session-title"
      variant="heading1"
    >
      Equo
    </UiText>
    <AppLoadingState
      v-if="state.status === 'unknown'"
      message="Checking your session…"
    />
    <AppErrorState
      v-else
      title="Your session could not be checked"
      message="Try again to load your home page."
      retry-label="Try again"
      @retry="$auth.retryBootstrap()"
    />
  </section>
</template>
