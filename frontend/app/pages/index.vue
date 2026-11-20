<script setup lang="ts">
import type { ConnectView } from '~/types/connects'

useHead({ title: 'Equo' })
const { state } = useCurrentUser()
const isTelegramMiniApp = useState('telegram-mini-app', () => false)
const { $auth } = useNuxtApp()
const { items, pending, loaded, error, load } = useFinancialList<ConnectView>(
  () => '/v1/connects', () => ({ limit: '1' })
)

const { t } = useTranslation()
</script>

<template>
  <div v-if="state.status === 'authenticated'">
    <AppLoadingState
      v-if="pending || (!loaded && !error)"
      :message="t('Loading your connections…')"
    />
    <AppErrorState
      v-else-if="error"
      :title="t('Your connections could not be loaded')"
      :message="t('Try again to load your home page.')"
      :retry-label="t('Try again')"
      @retry="load()"
    />
    <HomeDashboard
      v-else-if="items.length"
      :key="state.user?.id"
    />
    <CreateInvitation
      v-else
      :key="state.user?.id"
      home
    />
  </div>
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
      :title="isTelegramMiniApp ? t('Telegram sign-in could not be completed') : t('Your session could not be checked')"
      :message="isTelegramMiniApp ? t('Try again or reopen Equo from Telegram.') : t('Try again to load your home page.')"
      :retry-label="t('Try again')"
      @retry="$auth.retryBootstrap()"
    />
  </section>
</template>
