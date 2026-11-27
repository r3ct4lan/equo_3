<script setup lang="ts">
import { generateIdempotencyKey } from '~/utils/auth-flow'
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
    reader.onload = () => {
      const data = String(reader.result)
      const bytes = data.slice(data.indexOf(',') + 1)
      const header = atob(bytes.slice(0, 16))
      const mime = header.startsWith('\xff\xd8\xff')
        ? 'image/jpeg'
        : header.startsWith('\x89PNG\r\n\x1a\n')
          ? 'image/png'
          : header.startsWith('RIFF') && header.slice(8, 12) === 'WEBP' ? 'image/webp' : ''
      resolve('data:' + mime + ';base64,' + bytes)
    }
    reader.onerror = () => reject(new Error('Read failed'))
    reader.readAsDataURL(file)
  })
}
async function choose(files: File[]) {
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
  if (files.some(file => file.size > 4194304)) {
    message.value = 'Each receipt photo must be 4 MiB or smaller.'
    return
  }
  busy.value = true
  try {
    const images = await Promise.all(files.map(imageUrl))
    if (generation !== current) return
    if (images.some(image => !/^data:image\/(jpeg|png|webp);base64,/u.test(image))) {
      message.value = 'Use JPEG, PNG or WebP images up to 4 MiB each.'
      busy.value = false
      return
    }
    photos.value = images
    key = generateIdempotencyKey()
    await recognize()
  }
  catch {
    if (generation === current) {
      busy.value = false
      message.value = 'The photos could not be read.'
    }
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
    if (retry) key = generateIdempotencyKey()
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
        try {
          const catalog = await $auth.protectedRequest<string[]>('/v1/currencies/options', { signal })
          if (catalog.includes(code)) currency = code
          else message.value = 'Choose a supported receipt currency.'
        }
        catch {
          message.value = 'Could not load currencies.'
        }
        if (current !== generation) return
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
    <UiFileDrop
      ref="input"
      :label="t('Receipt photos')"
      :hint="t('Choose or drop up to 3 photos · JPEG, PNG, WebP · 4 MiB each')"
      :invalid="Boolean(message)"
      accept="image/jpeg,image/png,image/webp"
      @select="choose"
    />
    <UiNotice
      v-if="message"
      tone="error"
    >
      {{ t(message) }}
    </UiNotice>
    <UiNotice
      v-if="busy"
      busy
    >
      {{ t('Reading receipt…') }}
    </UiNotice>
    <UiNotice
      v-else-if="job?.state === 'ready'"
      tone="success"
    >
      {{ t('Receipt ready. Review the items below.') }}
    </UiNotice>
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
