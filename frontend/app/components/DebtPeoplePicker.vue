<script setup lang="ts">
import type { BriefUser, Page } from '~/types/finance'
import type { SelectOption } from '~/types/ui'

type Person = BriefUser & { email?: string | null }
const props = withDefaults(defineProps<{
  label: string
  debtId?: string
  excludeIds?: string[]
  lockedIds?: string[]
  single?: boolean
  choices?: Person[]
  disabled?: boolean
  emptyText?: string
  notFoundText?: string
}>(), {
  debtId: undefined,
  excludeIds: () => [],
  lockedIds: () => [],
  single: false,
  choices: undefined,
  disabled: false,
  emptyText: undefined,
  notFoundText: undefined
})
const selected = defineModel<Person[]>({ required: true })
const { $auth } = useNuxtApp()
const { state } = useCurrentUser()
const open = ref(false)
const query = ref('')
const results = ref<Person[]>([])
const cursor = ref<string | null>(null)
const loading = ref(false)
const failed = ref(false)
const choiceLimit = ref(5)
const panelId = useId()
const picker = useTemplateRef<{ close: () => void, focus: () => void }>('picker')
let retryCursor: string | null = null
let controller: AbortController | null = null
let timer: ReturnType<typeof setTimeout> | undefined
let generation = 0
const matchingChoices = computed(() => (props.choices ?? []).filter(person => person.name.toLocaleLowerCase().includes(query.value.trim().toLocaleLowerCase()) || person.email?.toLocaleLowerCase().includes(query.value.trim().toLocaleLowerCase())))
const visible = computed(() => (props.choices ? matchingChoices.value.slice(0, choiceLimit.value) : results.value).filter(person => !props.excludeIds.includes(person.id)))
function option(person: Person): SelectOption {
  return { value: person.id, label: `${person.id === state.value.user?.id ? t('You') : person.name}${person.isActive ? '' : t(' (inactive)')}`, description: person.email, $isDisabled: props.lockedIds.includes(person.id) }
}
const options = computed(() => visible.value.map(option))
const selection = computed(() => props.single ? (selected.value[0] ? option(selected.value[0]) : null) : selected.value.map(option))
const emptyText = computed(() => {
  if (loading.value || failed.value) {
    return ''
  }
  else {
    const defaultText = t('No people found')
    if (query.value !== '') {
      return props.notFoundText !== undefined ? props.notFoundText : defaultText
    }
    else {
      return props.emptyText !== undefined ? props.emptyText : defaultText
    }
  }
})
function update(value: SelectOption | SelectOption[] | null): void {
  if (props.disabled) return
  const people = new Map([...selected.value, ...visible.value].map(person => [person.id, person]))
  const values = value ? (Array.isArray(value) ? value : [value]) : []
  const next = values.flatMap(item => people.has(item.value) ? [people.get(item.value)!] : [])
  const locked = selected.value.filter(person => props.lockedIds.includes(person.id) && !next.some(item => item.id === person.id))
  selected.value = [...locked, ...next]
}
function cancel(): void {
  ++generation
  controller?.abort()
  clearTimeout(timer)
  loading.value = false
}
async function load(next: string | null = null): Promise<void> {
  if (props.disabled || props.choices || !open.value || state.value.status !== 'authenticated') return
  controller?.abort()
  const current = ++generation
  const viewer = state.value.user?.id
  controller = new AbortController()
  retryCursor = next
  loading.value = true
  failed.value = false
  try {
    const page = await $auth.protectedRequest<Page<Person>>('/v1/debt-people', {
      query: { q: query.value.trim(), ...(props.debtId ? { debtId: props.debtId } : {}), ...(next ? { cursor: next } : {}) }, signal: controller.signal
    })
    if (current !== generation || viewer !== state.value.user?.id) return
    results.value = next ? [...results.value, ...page.items] : page.items
    cursor.value = page.pagination.nextCursor
    const updates = new Map(page.items.map(person => [person.id, person]))
    selected.value = selected.value.map(person => updates.get(person.id) ?? person)
  }
  catch {
    if (current === generation) failed.value = true
  }
  finally {
    if (current === generation) loading.value = false
  }
}
function onOpen(): void {
  open.value = true
  void load()
}
function onClose(): void {
  open.value = false
  cancel()
}
function loadPage(next: string | null): void {
  picker.value?.focus()
  void load(next)
}
function moreChoices(): void {
  picker.value?.focus()
  choiceLimit.value += 5
}
watch(query, () => {
  cancel()
  results.value = []
  cursor.value = null
  choiceLimit.value = 5
  failed.value = false
  if (!props.choices && open.value) {
    loading.value = true
    timer = setTimeout(() => {
      void load()
    }, 250)
  }
})
watch(() => [state.value.status, state.value.user?.id, props.debtId], () => {
  cancel()
  results.value = []
  cursor.value = null
  open.value = false
  query.value = ''
  failed.value = false
  picker.value?.close()
})
onBeforeUnmount(cancel)

const { t } = useTranslation()
</script>

<template>
  <div class="people-picker">
    <label :for="panelId">{{ label }}</label>
    <UiMultiselect
      :id="panelId"
      :key="`${state.status}:${state.user?.id}:${debtId}`"
      ref="picker"
      detailed
      :label="label"
      :model-value="selection"
      :options="options"
      :multiple="!single"
      :internal-search="false"
      :loading="loading"
      :disabled="disabled"
      :empty-text="emptyText"
      :placeholder="t('Choose people')"
      @update:model-value="update"
      @search="query = $event"
      @open="onOpen"
      @close="onClose"
    >
      <template
        v-if="loading || failed || (cursor && !loading && !failed) || (choices && matchingChoices.length > choiceLimit)"
        #footer
      >
        <div
          role="status"
          aria-live="polite"
        >
          <span v-if="loading">{{ t('Loading people…') }}</span>
          <span v-else-if="failed">{{ t('People could not be loaded.') }}</span>
        </div>
        <UiButton
          v-if="failed"
          variant="secondary"
          @click="loadPage(retryCursor)"
        >
          {{ t('Retry') }}
        </UiButton>
        <UiButton
          v-if="cursor && !loading && !failed"
          variant="secondary"
          @click="loadPage(cursor)"
        >
          {{ t('Load more') }}
        </UiButton>
        <UiButton
          v-if="choices && matchingChoices.length > choiceLimit"
          variant="secondary"
          @click="moreChoices"
        >
          {{ t('Load more') }}
        </UiButton>
      </template>
    </UiMultiselect>
  </div>
</template>
