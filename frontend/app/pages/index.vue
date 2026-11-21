<script setup lang="ts">
import type { ConnectView } from '~/types/connects'

useHead({ title: 'Equo' })
const { state } = useCurrentUser()
const isTelegramMiniApp = useState('telegram-mini-app', () => false)
const { $auth } = useNuxtApp()
const { items, pending, loaded, error, load } = useFinancialList<ConnectView>(
  () => '/v1/connects', () => ({ limit: '1' })
)

const telegramInactive = computed(() => isTelegramMiniApp.value && state.value.error?.code === 'ACCOUNT_INACTIVE')
const reactivating = ref(false)
const reactivationFailed = ref(false)

async function reactivate(): Promise<void> {
  if (reactivating.value) return
  reactivating.value = true
  reactivationFailed.value = false
  try {
    await $auth.reactivateTelegram()
  }
  catch {
    reactivationFailed.value = true
  }
  finally {
    reactivating.value = false
  }
}

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
    <div
      v-else-if="telegramInactive"
      class="stack"
    >
      <UiText>{{ t('Account is not active') }}</UiText>
      <UiNotice
        v-if="reactivationFailed"
        tone="error"
      >
        {{ t('Could not activate the account. Reopen Equo from Telegram and try again.') }}
      </UiNotice>
      <UiButton
        :pending="reactivating"
        @click="reactivate"
      >
        {{ t('Activate and sign in') }}
      </UiButton>
    </div>
    <AppErrorState
      v-else
      :title="isTelegramMiniApp ? t('Telegram sign-in could not be completed') : t('Your session could not be checked')"
      :message="isTelegramMiniApp ? t('Try again or reopen Equo from Telegram.') : t('Try again to load your home page.')"
      :retry-label="t('Try again')"
      @retry="$auth.retryBootstrap()"
    />
  </section>
</template>
