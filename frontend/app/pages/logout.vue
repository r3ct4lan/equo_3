<script setup lang="ts">
useHead({ title: 'Sign-out result · Equo' })
const { $auth } = useNuxtApp()
const route = useRoute()
const all = computed(() => route.query.scope === 'all')
const pending = ref(false)

async function retry(): Promise<void> {
  if (pending.value) {
    return
  }
  pending.value = true
  try {
    await (all.value ? $auth.logoutAll() : $auth.logout())
  }
  catch {
    // Keep the unconfirmed result visible; a retry never claims server success.
  }
  finally {
    pending.value = false
  }
}

const { t } = useTranslation()
</script>

<template>
  <UiCard
    as="section"
    class="stack"
    aria-labelledby="logout-result-title"
    :aria-busy="pending"
  >
    <UiText
      id="logout-result-title"
      variant="heading1"
    >
      {{ t('You are signed out here') }}
    </UiText>
    <UiText role="alert">
      {{ all ? t('We could not confirm sign-out on all devices.') : t('We could not confirm sign-out on the server.') }}
      Server access may still be available. Try again when the service is reachable.
    </UiText>
    <div class="form-actions">
      <UiButton
        :disabled="pending"
        @click="retry"
      >
        {{ pending ? t('Trying again…') : t('Retry sign out') }}
      </UiButton>
      <UiButton
        variant="secondary"
        to="/login?signedOut=1"
      >
        {{ t('Go to sign in') }}
      </UiButton>
    </div>
    <UiText
      v-if="pending"
      role="status"
    >
      {{ t('Trying again…') }}
    </UiText>
  </UiCard>
</template>
