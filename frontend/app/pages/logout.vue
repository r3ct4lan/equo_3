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
      You are signed out here
    </UiText>
    <UiText role="alert">
      We could not confirm sign-out {{ all ? 'on all devices' : 'on the server' }}.
      Server access may still be available. Try again when the service is reachable.
    </UiText>
    <div class="form-actions">
      <UiButton
        :disabled="pending"
        @click="retry"
      >
        {{ pending ? 'Trying again…' : 'Retry sign out' }}
      </UiButton>
      <UiButton
        variant="secondary"
        to="/login?signedOut=1"
      >
        Go to sign in
      </UiButton>
    </div>
    <UiText
      v-if="pending"
      role="status"
    >
      Trying again…
    </UiText>
  </UiCard>
</template>
