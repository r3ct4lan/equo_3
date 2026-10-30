<script setup lang="ts">
useHead({ title: 'Equo' })
const { state } = useCurrentUser()
const { $auth } = useNuxtApp()

const { t } = useTranslation()
</script>

<template>
  <HomeDashboard v-if="state.status === 'authenticated'" />
  <section
    v-else-if="state.status === 'anonymous'"
    class="hero"
    aria-labelledby="home-title"
  >
    <UiText variant="eyebrow">
      {{ t('Shared expenses, clearly') }}
    </UiText>
    <UiText
      id="home-title"
      variant="heading1"
    >
      {{ t('Welcome to Equo') }}
    </UiText>
    <UiText variant="lede">
      {{ t('Create an account, confirm your email, and get ready to make shared expenses easier to understand.') }}
    </UiText>
    <div class="hero__actions">
      <UiButton
        to="/register"
      >
        {{ t('Create an account') }}
      </UiButton>
      <UiButton
        variant="secondary"
        to="/login"
      >
        {{ t('Sign in') }}
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
      :message="t('Checking your session…')"
    />
    <AppErrorState
      v-else
      :title="t('Your session could not be checked')"
      :message="t('Try again to load your home page.')"
      :retry-label="t('Try again')"
      @retry="$auth.retryBootstrap()"
    />
  </section>
</template>
