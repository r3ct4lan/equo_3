<script setup lang="ts">
const { $auth } = useNuxtApp()
const currentUser = useCurrentUser()
const isSubmitting = ref(false)
const confirming = ref(false)
const confirmation = useTemplateRef<HTMLDivElement>('confirmation')
const allButton = useTemplateRef<HTMLButtonElement>('allButton')

async function confirmAll(): Promise<void> {
  confirming.value = true
  await nextTick()
  confirmation.value?.focus()
}

async function cancelAll(): Promise<void> {
  confirming.value = false
  await nextTick()
  allButton.value?.focus()
}

async function signOut(all: boolean): Promise<void> {
  if (isSubmitting.value) {
    return
  }
  isSubmitting.value = true
  try {
    await (all ? $auth.logoutAll() : $auth.logout())
  }
  catch {
    // The auth action opens the dedicated result page with safe retry controls.
  }
  finally {
    isSubmitting.value = false
    confirming.value = false
  }
}
</script>

<template>
  <section
    v-if="currentUser.state.value.status === 'authenticated' || isSubmitting"
    class="panel stack"
    aria-label="Sign out"
    :aria-busy="isSubmitting"
  >
    <div class="form-actions">
      <button
        class="button button--secondary"
        type="button"
        :disabled="isSubmitting || confirming"
        @click="signOut(false)"
      >
        {{ isSubmitting ? 'Signing out…' : 'Sign out' }}
      </button>
      <button
        ref="allButton"
        class="button button--secondary"
        type="button"
        :disabled="isSubmitting || confirming"
        @click="confirmAll"
      >
        Sign out on all devices
      </button>
    </div>
    <div
      v-if="confirming"
      ref="confirmation"
      class="stack"
      role="group"
      aria-labelledby="logout-confirm-title"
      tabindex="-1"
      @keydown.esc="cancelAll"
    >
      <h2 id="logout-confirm-title">
        Sign out on all devices?
      </h2>
      <p>You will need to sign in again. Other devices may remain signed in for up to 15 minutes.</p>
      <div class="form-actions">
        <button
          class="button"
          type="button"
          :disabled="isSubmitting"
          @click="signOut(true)"
        >
          Confirm sign out
        </button>
        <button
          class="button button--secondary"
          type="button"
          :disabled="isSubmitting"
          @click="cancelAll"
        >
          Cancel
        </button>
      </div>
    </div>
    <p
      v-if="isSubmitting"
      role="status"
    >
      Signing out…
    </p>
  </section>
</template>
