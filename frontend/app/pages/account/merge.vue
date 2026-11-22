<script setup lang="ts">
import { toApiClientError, type ApiClientError } from '~/utils/api-error'
import { formatDateTime } from '~/utils/locale'
import { formatEuro } from '~/utils/money'

definePageMeta({ middleware: 'auth' })
useHead({ title: 'Review account merge · Equo' })

type MergeOperation = {
  id: string
  createdAt: string
  amount: number
}

type MergePreview = {
  version: string
  mode: string
  email: string
  discardedEmail: string | null
  replacesTelegram: boolean
  discardsTelegram: boolean
  disappearingTransfers: MergeOperation[]
  disappearingExpenses: (MergeOperation & { title: string })[]
}

const { $auth } = useNuxtApp()
const { locale } = useLocale()
const { t } = useTranslation()
const preview = shallowRef<MergePreview | null>(null)
const loading = ref(true)
const cancelling = ref(false)
const confirming = ref(false)
const changed = ref(false)
const error = shallowRef<ApiClientError | null>(null)
const credentials = '/v1/account/credentials'
const confirmationUnavailable = computed(() => error.value?.code === 'INVALID_TOKEN' || error.value?.code === 'CREDENTIAL_CONFLICT')
const errorMessage = computed(() => {
  if (confirmationUnavailable.value) return 'Confirm access to both accounts again from your profile.'
  if (error.value?.code === 'MERGE_BUSY') return 'These accounts are being updated. Try confirming again.'
  return 'Check your connection and try again.'
})

async function load(): Promise<void> {
  loading.value = true
  changed.value = false
  error.value = null
  try {
    preview.value = await $auth.protectedRequest<MergePreview>(credentials + '/merge')
  }
  catch (failure) {
    preview.value = null
    error.value = toApiClientError(failure)
  }
  finally {
    loading.value = false
  }
}

async function confirm(): Promise<void> {
  if (!preview.value || confirming.value || cancelling.value) return
  const version = preview.value.version
  confirming.value = true
  changed.value = false
  error.value = null
  try {
    const result = await $auth.protectedRequest<{ status: 'merged' } | { status: 'preview_changed', preview: MergePreview }>(credentials + '/merge/confirm', {
      method: 'POST',
      body: { version }
    })
    if (result.status === 'preview_changed') {
      preview.value = result.preview
      changed.value = true
    }
    else {
      preview.value = null
      await navigateTo('/me?credentials=merged')
    }
  }
  catch (failure) {
    error.value = toApiClientError(failure)
    if (confirmationUnavailable.value) preview.value = null
  }
  finally {
    confirming.value = false
  }
}

async function cancel(): Promise<void> {
  if (cancelling.value || confirming.value) return
  cancelling.value = true
  error.value = null
  try {
    await $auth.protectedRequest(credentials + '/cancel', { method: 'POST' })
    preview.value = null
    await navigateTo('/me')
  }
  catch (failure) {
    error.value = toApiClientError(failure)
  }
  finally {
    cancelling.value = false
  }
}

onMounted(() => {
  void load()
})
</script>

<template>
  <section
    class="stack stack--large"
    aria-labelledby="merge-title"
  >
    <UiText
      id="merge-title"
      variant="heading1"
    >
      {{ t('Review account merge') }}
    </UiText>
    <AppLoadingState
      v-if="loading"
      :message="t('Loading merge preview…')"
    />
    <AppErrorState
      v-if="error"
      :title="t('Could not review the account merge')"
      :message="t(errorMessage)"
      :retry-label="confirmationUnavailable || preview ? undefined : t('Try again')"
      @retry="load"
    />
    <UiLink
      v-if="confirmationUnavailable"
      to="/me"
    >
      {{ t('Back to profile') }}
    </UiLink>
    <template v-if="preview">
      <UiNotice
        v-if="changed"
        tone="warning"
      >
        {{ t('The operations changed. Review the updated preview before confirming.') }}
      </UiNotice>
      <UiCard class="stack">
        <UiText variant="heading2">
          {{ t('Sign-in methods after the merge') }}
        </UiText>
        <UiDescriptionList>
          <div>
            <dt>{{ t('Email that stays available') }}</dt>
            <dd>{{ preview.email }}</dd>
          </div>
          <div v-if="preview.discardedEmail">
            <dt>{{ t('Email that will stop working') }}</dt>
            <dd>{{ preview.discardedEmail }}</dd>
          </div>
          <div>
            <dt>Telegram</dt>
            <dd>{{ t(preview.replacesTelegram ? 'Your new Telegram account will replace the current one.' : 'You can sign in with the confirmed Telegram account.') }}</dd>
          </div>
        </UiDescriptionList>
        <UiNotice
          v-if="preview.discardsTelegram"
          tone="warning"
        >
          {{ t('Telegram from the account being merged will stop working.') }}
        </UiNotice>
      </UiCard>
      <UiCard
        v-if="preview.disappearingTransfers.length || preview.disappearingExpenses.length"
        class="stack"
      >
        <UiText variant="heading2">
          {{ t('Operations that will disappear') }}
        </UiText>
        <UiText>{{ t('These transfers and expenses only involve the accounts being merged.') }}</UiText>
        <UiText
          v-if="preview.disappearingTransfers.length"
          variant="heading3"
        >
          {{ t('Transfers') }}
        </UiText>
        <ul
          v-if="preview.disappearingTransfers.length"
          class="stack"
        >
          <li
            v-for="transfer in preview.disappearingTransfers"
            :key="transfer.id"
          >
            <time :datetime="transfer.createdAt">{{ formatDateTime(new Date(transfer.createdAt), locale) }}</time>
            · {{ formatEuro(transfer.amount, locale) }}
          </li>
        </ul>
        <UiText
          v-if="preview.disappearingExpenses.length"
          variant="heading3"
        >
          {{ t('Expenses') }}
        </UiText>
        <ul
          v-if="preview.disappearingExpenses.length"
          class="stack"
        >
          <li
            v-for="expense in preview.disappearingExpenses"
            :key="expense.id"
          >
            {{ expense.title }} · {{ formatEuro(expense.amount, locale) }}
            · <time :datetime="expense.createdAt">{{ formatDateTime(new Date(expense.createdAt), locale) }}</time>
          </li>
        </ul>
      </UiCard>
      <div class="form-actions">
        <UiButton
          variant="danger"
          :pending="confirming"
          :disabled="cancelling"
          @click="confirm"
        >
          {{ t('Confirm merge') }}
        </UiButton>
        <UiButton
          variant="secondary"
          :pending="cancelling"
          :disabled="confirming"
          @click="cancel"
        >
          {{ t('Cancel merge') }}
        </UiButton>
      </div>
    </template>
  </section>
</template>
