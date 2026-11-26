<script setup lang="ts">
import type { ReceiptJob } from '~/types/receipt'

const emit = defineEmits<{ ready: [job: ReceiptJob, currency: string], reset: [] }>()
const { $auth } = useNuxtApp()
const { t } = useTranslation()
const photos = ref<string[]>([])
const job = ref<ReceiptJob | null>(null)
const message = ref('')
const busy = ref(false)
const input = useTemplateRef<{ chooseFile: () => void }>('input')
let generation = 0
let key = ''
let controller: AbortController | undefined
const endpoint = '/v1/debts/receipt-uploads'

function imageUrl(file: File): Promise<string> {
  return new Promise((resolve, reject) => {
    const reader = new FileReader()
    reader.onload = () => resolve(String(reader.result))
    reader.onerror = () => reject(new Error('Read failed'))
    reader.readAsDataURL(file)
  })
}
async function choose(event: Event) {
  const files = Array.from((event.target as HTMLInputElement).files ?? [])
  if (!files.length) return
  const current = ++generation
  controller?.abort()
  photos.value = []
  job.value = null
  busy.value = false
  emit('reset')
  message.value = ''
  if (files.length > 3) {
    message.value = 'Choose one to three receipt photos.'
    return
  }
  if (files.some(file => !['image/jpeg', 'image/png', 'image/webp'].includes(file.type) || file.size > 4194304)) {
    message.value = 'Use JPEG, PNG or WebP images up to 4 MiB each.'
    return
  }
  try {
    const images = await Promise.all(files.map(imageUrl))
    if (generation !== current) return
    photos.value = images
    key = crypto.randomUUID()
    await recognize()
  }
  catch {
    if (generation === current) message.value = 'The photos could not be read.'
  }
}
async function recognize() {
  const current = ++generation
  controller?.abort()
  controller = new AbortController()
  const signal = controller.signal
  busy.value = true
  message.value = ''
  try {
    const retry = job.value?.state === 'failed'
    if (retry) key = crypto.randomUUID()
    let result = await $auth.protectedRequest<ReceiptJob>(retry ? endpoint + '/' + job.value!.id + '/retry' : endpoint, {
      method: 'POST', headers: { 'Idempotency-Key': key }, body: retry ? {} : { photos: photos.value }, signal
    })
    while (current === generation && ['pending', 'reading'].includes(result.state)) {
      job.value = result
      await new Promise(resolve => setTimeout(resolve, 900))
      if (signal.aborted) return
      result = await $auth.protectedRequest<ReceiptJob>(endpoint + '/' + result.id, { signal })
    }
    if (current !== generation) return
    job.value = result
    if (result.state === 'ready') {
      const code = result.draft?.currency
      let currency = ''
      if (code) {
        const catalog = await $auth.protectedRequest<string[]>('/v1/currencies/options', { signal })
        if (current !== generation) return
        if (catalog.includes(code)) currency = code
        else message.value = 'Choose a supported receipt currency.'
      }
      emit('ready', result, currency)
    }
  }
  catch {
    if (current === generation) message.value = 'Could not load the receipt. Try again.'
  }
  finally {
    if (current === generation) busy.value = false
  }
}
function manual() {
  if (!job.value) return
  ++generation
  controller?.abort()
  busy.value = false
  emit('ready', { ...job.value, draft: null }, '')
}
onBeforeUnmount(() => {
  ++generation
  controller?.abort()
})
</script>

<template>
  <div class="stack">
    <UiField>
      <label for="receipt-files">{{ t('Receipt photos') }}</label>
      <UiInput
        id="receipt-files"
        ref="input"
        type="file"
        multiple
        accept="image/jpeg,image/png,image/webp"
        @change="choose"
      />
    </UiField>
    <div
      v-if="photos.length"
      class="flex flex-wrap gap-2"
    >
      <details
        v-for="(photo, i) in photos"
        :key="i"
      >
        <summary>{{ t('Photo') }} {{ i + 1 }}</summary>
        <img
          :src="photo"
          :alt="t('Receipt photo') + ' ' + (i + 1)"
          class="max-w-full"
        >
      </details>
    </div>
    <UiText
      v-if="message"
      role="alert"
    >
      {{ t(message) }}
    </UiText>
    <UiText
      v-if="busy"
      role="status"
    >
      {{ t('Reading receipt…') }}
    </UiText>
    <UiButton
      v-if="!busy && photos.length && (message || job?.state === 'failed')"
      variant="secondary"
      @click="recognize"
    >
      {{ t('Try again') }}
    </UiButton>
    <template v-if="job?.state === 'failed'">
      <UiText>{{ t('The receipt could not be read.') }}</UiText>
      <UiButton
        variant="secondary"
        @click="input?.chooseFile()"
      >
        {{ t('Try another photo') }}
      </UiButton>
    </template>
    <UiButton
      v-if="job && job.state !== 'ready'"
      variant="secondary"
      @click="manual"
    >
      {{ t('Enter items manually') }}
    </UiButton>
  </div>
</template>
