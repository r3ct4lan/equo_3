<script setup lang="ts">
import type { DebtView } from '~/types/finance'
import { validDebtEtag } from '~/utils/debt-update'

const props = defineProps<{ debt: DebtView, etag?: string | null, removable?: boolean }>()
const emit = defineEmits<{ reload: [] }>()
const { $auth } = useNuxtApp()
const { state } = useCurrentUser()
const { t } = useTranslation()
const images = ref<Record<number, string>>({})
const pending = ref<number | null>(null)
const message = ref('')
let generation = 0
async function show(number: number) {
  if (pending.value !== null) return
  if (images.value[number]) {
    images.value = Object.fromEntries(Object.entries(images.value).filter(([key]) => Number(key) !== number))
    return
  }
  const current = generation
  pending.value = number
  message.value = ''
  try {
    const photo = await $auth.protectedRequest<{ image: string }>('/v1/debts/' + props.debt.id + '/photos/' + number)
    if (current === generation) images.value[number] = photo.image
  }
  catch {
    if (current === generation) message.value = 'Could not open photo.'
  }
  finally {
    if (current === generation) pending.value = null
  }
}
async function remove(number: number) {
  if (pending.value !== null || !props.debt.canEdit || !props.etag || !validDebtEtag(props.etag)) return
  const current = generation
  pending.value = number
  message.value = ''
  try {
    await $auth.protectedRequest('/v1/debts/' + props.debt.id + '/photos/' + number, { method: 'DELETE', headers: { 'If-Match': props.etag } })
    if (current === generation) emit('reload')
  }
  catch {
    if (current === generation) message.value = 'Could not remove photo. Reload the expense.'
  }
  finally {
    if (current === generation) pending.value = null
  }
}
watch([() => props.debt.id, () => props.debt.version, () => state.value.user?.id], () => {
  ++generation
  images.value = {}
  pending.value = null
  message.value = ''
})
onBeforeUnmount(() => {
  ++generation
})
</script>

<template>
  <div class="stack">
    <div
      v-for="number in debt.receipt?.photos"
      :key="number"
      data-testid="saved-receipt-photo"
      class="stack"
    >
      <div class="flex flex-wrap gap-2">
        <UiButton
          variant="secondary"
          :disabled="pending !== null"
          @click="show(number)"
        >
          {{ t('Photo') }} {{ number }}
        </UiButton>
        <UiButton
          v-if="removable && debt.canEdit"
          variant="link"
          :disabled="pending !== null"
          @click="remove(number)"
        >
          {{ t('Remove photo') }} {{ number }}
        </UiButton>
      </div>
      <img
        v-if="images[number]"
        :src="images[number]"
        :alt="t('Receipt photo') + ' ' + number"
        class="max-w-full"
      >
    </div>
    <UiText
      v-if="message"
      role="alert"
    >
      {{ t(message) }}
    </UiText>
  </div>
</template>
