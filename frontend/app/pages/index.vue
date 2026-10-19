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
    <p class="eyebrow">
      Shared expenses, clearly
    </p>
    <h1 id="home-title">
      Welcome to Equo
    </h1>
    <p class="hero__lede">
      Create an account, confirm your email, and get ready to make shared expenses easier to understand.
    </p>
    <div class="hero__actions">
      <NuxtLink
        class="button"
        to="/register"
      >Create an account</NuxtLink>
      <NuxtLink
        class="button button--secondary"
        to="/login"
      >Sign in</NuxtLink>
    </div>
  </section>
  <section
    v-else
    class="stack"
    aria-labelledby="home-session-title"
  >
    <h1 id="home-session-title">
      Equo
    </h1>
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
