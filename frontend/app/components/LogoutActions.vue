<script setup lang="ts">
import type { FocusTarget } from '~/types/ui'

const { $auth } = useNuxtApp()
const currentUser = useCurrentUser()
const isSubmitting = ref(false)
const confirming = ref(false)
const confirmation = useTemplateRef<HTMLDivElement>('confirmation')
const allButton = useTemplateRef<FocusTarget>('allButton')

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

const { t } = useTranslation()
</script>

<template>
  <UiCard
    v-if="currentUser.state.value.status === 'authenticated' || isSubmitting"
    as="section"
    class="stack"
    :aria-label="t('Sign out')"
    :aria-busy="isSubmitting"
  >
    <div class="form-actions">
      <UiButton
        variant="danger"
        :disabled="isSubmitting || confirming"
        @click="signOut(false)"
      >
        {{ isSubmitting ? t('Signing out…') : t('Sign out') }}
      </UiButton>
      <UiButton
        ref="allButton"
        variant="secondary"
        :disabled="isSubmitting || confirming"
        @click="confirmAll"
      >
        {{ t('Sign out on all devices') }}
      </UiButton>
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
      <UiText
        id="logout-confirm-title"
        variant="heading2"
      >
        {{ t('Sign out on all devices?') }}
      </UiText>
      <UiText>{{ t('You will need to sign in again. Other devices may remain signed in for up to 15 minutes.') }}</UiText>
      <div class="form-actions">
        <UiButton
          :disabled="isSubmitting"
          @click="signOut(true)"
        >
          {{ t('Confirm sign out') }}
        </UiButton>
        <UiButton
          variant="secondary"
          :disabled="isSubmitting"
          @click="cancelAll"
        >
          {{ t('Cancel') }}
        </UiButton>
      </div>
    </div>
    <UiText
      v-if="isSubmitting"
      role="status"
    >
      {{ t('Signing out…') }}
    </UiText>
  </UiCard>
</template>
