<script setup lang="ts">
import Multiselect from 'vue-multiselect'
import type { SelectOption } from '~/types/ui'

defineOptions({ inheritAttrs: false })
const props = withDefaults(defineProps<{
  id?: string
  label: string
  options: SelectOption[]
  multiple?: boolean
  detailed?: boolean
  internalSearch?: boolean
  loading?: boolean
  disabled?: boolean
  required?: boolean
  placeholder?: string
  emptyText?: string
}>(), { id: undefined, multiple: false, detailed: false, internalSearch: true, loading: false, disabled: false, required: false, placeholder: 'Type to search', emptyText: 'No options found' })
const model = defineModel<SelectOption | SelectOption[] | null>({ default: null })
const emit = defineEmits<{ open: [], close: [], search: [query: string] }>()
const attrs = useAttrs()
const { t } = useTranslation()
const generatedId = useId()
const inputId = computed(() => props.id ?? generatedId)
const root = useTemplateRef<HTMLElement>('root')
const select = useTemplateRef<{ activate: () => void, deactivate: () => void }>('select')
const open = ref(false)
const optionHeight = ref(40)

function blocked(): boolean {
  return props.disabled || !!root.value?.closest('fieldset:disabled')
}
function guard(event: Event): void {
  if (!blocked()) return
  event.preventDefault()
  event.stopImmediatePropagation()
}
function onClick(event: MouseEvent): void {
  if ((event.target as HTMLElement).classList.contains('multiselect__tags')) select.value?.activate()
}
function onKeydown(event: KeyboardEvent): void {
  if (open.value && event.key === 'Escape') {
    event.preventDefault()
    event.stopPropagation()
    close()
  }
}
function update(value: SelectOption | SelectOption[] | null): void {
  if (!blocked()) model.value = value
}
function setOpen(value: boolean): void {
  open.value = value
  if (value) emit('open')
  else emit('close')
}
function close(): void {
  select.value?.deactivate()
}
// Keep the popup open when keyboard focus moves to a tag or pagination action.
function onBlur(event: FocusEvent): void {
  if (event.relatedTarget instanceof Node && root.value?.contains(event.relatedTarget)) event.stopPropagation()
}
function onFocusOut(event: FocusEvent): void {
  if (!(event.relatedTarget instanceof Node) || !root.value?.contains(event.relatedTarget)) close()
}
function syncInput(): void {
  const input = root.value?.querySelector('input')
  if (!input) return
  input.setAttribute('role', 'combobox')
  input.setAttribute('aria-label', props.label)
  input.setAttribute('aria-expanded', String(open.value))
  input.setAttribute('aria-autocomplete', 'list')
  const activeOption = root.value?.querySelector('.multiselect')?.getAttribute('aria-activedescendant')
  if (open.value && activeOption) input.setAttribute('aria-activedescendant', activeOption)
  else input.removeAttribute('aria-activedescendant')
  root.value?.querySelectorAll('.multiselect__element').forEach((option) => {
    option.setAttribute('role', 'option')
    option.setAttribute('aria-disabled', String(!!option.querySelector('.multiselect__option--disabled')))
  })
  for (const name of ['aria-describedby', 'aria-invalid']) {
    if (attrs[name] !== undefined) input.setAttribute(name, String(attrs[name]))
    else input.removeAttribute(name)
  }
  input.maxLength = 100
  const height = root.value?.querySelector('.multiselect__option')?.getBoundingClientRect().height
  if (height) optionHeight.value = Math.ceil(height)
}
onMounted(syncInput)
onUpdated(syncInput)
watch(() => props.disabled, (disabled) => {
  if (disabled) close()
})
defineExpose({ focus: () => root.value?.querySelector('input')?.focus(), close })
</script>

<template>
  <div
    ref="root"
    class="ui-multiselect"
    :data-open="open"
    :class="[$attrs.class, { 'ui-multiselect--detailed': detailed }]"
    @click.capture="guard"
    @click="onClick"
    @keydown.capture="guard"
    @keydown="onKeydown"
    @blur.capture="onBlur"
    @focusout="onFocusOut"
  >
    <Multiselect
      :id="inputId"
      ref="select"
      :model-value="model"
      :options="options"
      :options-limit="options.length"
      :option-height="optionHeight"
      :multiple="multiple"
      :internal-search="internalSearch"
      :close-on-select="!multiple"
      :clear-on-select="false"
      :preserve-search="true"
      :loading="loading"
      :disabled="disabled"
      :required="required"
      :placeholder="t(placeholder)"
      :name="label"
      :show-labels="false"
      :allow-empty="multiple"
      label="label"
      track-by="value"
      role="group"
      @update:model-value="update"
      @open="setOpen(true)"
      @close="setOpen(false)"
      @search-change="emit('search', $event)"
    >
      <template #option="{ option }">
        <span
          :data-value="option.value"
          :title="option.label"
        >{{ option.label }}</span>
        <UiText
          v-if="option.description"
          as="small"
          variant="caption"
          :title="option.description"
        >
          {{ option.description }}
        </UiText>
      </template>
      <template #tag="{ option, remove }">
        <span class="multiselect__tag">
          <span>{{ option.label }}</span>
          <UiButton
            v-if="!option.$isDisabled"
            variant="link"
            size="xs"
            icon="close"
            :aria-label="`${t('Remove')} ${option.label}`"
            @mousedown.prevent
            @click.stop="remove(option)"
          />
        </span>
      </template>
      <template #noResult>
        {{ t(emptyText) }}
      </template>
      <template #noOptions>
        {{ t(emptyText) }}
      </template>
      <template #afterList>
        <li
          v-if="$slots.footer"
          role="presentation"
          class="ui-multiselect__footer"
        >
          <slot name="footer" />
        </li>
      </template>
    </Multiselect>
  </div>
</template>
