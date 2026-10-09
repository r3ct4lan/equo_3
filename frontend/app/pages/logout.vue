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
  <section
    class="panel stack"
    aria-labelledby="logout-result-title"
    :aria-busy="pending"
  >
    <h1 id="logout-result-title">
      You are signed out here
    </h1>
    <p role="alert">
      We could not confirm sign-out {{ all ? 'on all devices' : 'on the server' }}.
      Server access may still be available. Try again when the service is reachable.
    </p>
    <div class="form-actions">
      <button
        class="button"
        type="button"
        :disabled="pending"
        @click="retry"
      >
        {{ pending ? 'Trying again…' : 'Retry sign out' }}
      </button>
      <NuxtLink
        class="button button--secondary"
        to="/login?signedOut=1"
      >Go to sign in</NuxtLink>
    </div>
    <p
      v-if="pending"
      role="status"
    >
      Trying again…
    </p>
  </section>
</template>
