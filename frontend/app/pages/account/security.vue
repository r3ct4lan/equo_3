<script setup lang="ts">
import { PASSWORD_POLICY_HINT } from '~/utils/auth-flow'

definePageMeta({ middleware: 'auth' })
useHead({ title: 'Account security · Equo' })
const currentUser = useCurrentUser()
const { $auth } = useNuxtApp()

const { t } = useTranslation()
</script>

<template>
  <section
    class="stack stack--large"
    aria-labelledby="security-title"
  >
    <div class="hero">
      <UiText variant="eyebrow">
        {{ t('Your account') }}
      </UiText>
      <UiText
        id="security-title"
        variant="heading1"
      >
        {{ t('Account security') }}
      </UiText>
      <UiText variant="lede">
        {{ t(PASSWORD_POLICY_HINT) }} {{ t('Saving signs you out on all devices. Sign in again with your new password.') }}
      </UiText>
    </div>
    <PasswordChangeForm v-if="currentUser.state.value.status === 'authenticated'" />
    <AppErrorState
      v-else-if="currentUser.state.value.status === 'error'"
      :title="t('We could not check your session')"
      :message="t('Try again to check your session.')"
      :retry-label="t('Try again')"
      @retry="$auth.retryBootstrap"
    />
    <AppLoadingState
      v-else-if="currentUser.state.value.status === 'unknown'"
      :message="t('Checking your session…')"
    />
    <UiButton
      variant="secondary"
      to="/me"
    >
      {{ t('Back to profile') }}
    </UiButton>
  </section>
</template>
