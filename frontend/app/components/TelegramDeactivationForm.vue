<script setup lang="ts">
import { toApiClientError, type ApiClientError } from '~/utils/api-error'
import { accountDeactivationErrorPresentation } from '~/utils/profile-settings'

defineProps<{ confirmed: boolean }>()
const emit = defineEmits<{ cancelled: [] }>()
const { $auth } = useNuxtApp()
const { t } = useTranslation()
const pending = ref(false)
const failure = shallowRef<ApiClientError | null>(null)
const endpoint = '/v1/account/credentials/telegram/deactivation'

async function submit(action: 'start' | 'confirm' | 'cancel'): Promise<void> {
  if (pending.value) return
  pending.value = true
  failure.value = null
  try {
    if (action === 'confirm') {
      await $auth.deactivateWithTelegram()
    }
    else if (action === 'cancel') {
      await $auth.protectedRequest(endpoint + '/cancel', { method: 'POST' })
      emit('cancelled')
    }
    else {
      const { url } = await $auth.protectedRequest<{ url: string }>(endpoint + '/start', { method: 'POST' })
      window.location.assign(url)
    }
  }
  catch (error) {
    failure.value = toApiClientError(error)
  }
  finally {
    pending.value = false
  }
}
</script>

<template>
  <section
    class="stack"
    aria-labelledby="telegram-deactivation-title"
  >
    <UiText
      id="telegram-deactivation-title"
      variant="heading2"
    >
      {{ t('Deactivate account') }}
    </UiText>
    <UiText>
      {{ confirmed
        ? t('Deactivation ends all your sessions and keeps your expense history.')
        : t('Confirm your Telegram account before deactivating Equo.') }}
    </UiText>
    <AppErrorState
      v-if="failure"
      :title="t('Account could not be deactivated')"
      :message="t(accountDeactivationErrorPresentation(failure).message)"
    />
    <div class="form-actions">
      <UiButton
        v-if="confirmed"
        variant="danger"
        :pending="pending"
        @click="submit('confirm')"
      >
        {{ t('Deactivate account') }}
      </UiButton>
      <UiButton
        v-else
        variant="secondary"
        :pending="pending"
        @click="submit('start')"
      >
        {{ t('Confirm with Telegram') }}
      </UiButton>
      <UiButton
        v-if="confirmed"
        variant="secondary"
        :disabled="pending"
        @click="submit('cancel')"
      >
        {{ t('Cancel') }}
      </UiButton>
    </div>
  </section>
</template>
