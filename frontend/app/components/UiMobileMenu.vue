<script setup lang="ts">
import { RiCloseLine } from '@remixicon/vue'
import type { LinkTo } from '~/types/ui'

defineProps<{ id: string, open: boolean, links: { label: string, to: LinkTo }[], preview?: boolean }>()
const emit = defineEmits<{ close: [restoreFocus: boolean] }>()
const menu = useTemplateRef<HTMLElement>('menu')
defineExpose({ focusFirst: () => menu.value?.querySelector<HTMLElement>('a, button')?.focus() })

function trapFocus(event: KeyboardEvent): void {
  const focusable = [...(menu.value?.querySelectorAll<HTMLElement>('a[href], button:not([disabled])') ?? [])]
  if (!focusable.length) return
  const index = focusable.indexOf(document.activeElement as HTMLElement)
  if (event.shiftKey && index <= 0) {
    event.preventDefault()
    focusable[focusable.length - 1]?.focus()
  }
  else if (!event.shiftKey && index === focusable.length - 1) {
    event.preventDefault()
    focusable[0]?.focus()
  }
}
</script>

<template>
  <div
    v-if="open"
    class="mobile-menu-backdrop"
    :class="{ 'mobile-menu-backdrop--preview': preview }"
    @click.self="emit('close', true)"
  >
    <div
      :id="id"
      ref="menu"
      class="mobile-menu"
      role="dialog"
      aria-modal="true"
      aria-label="Navigation menu"
      @keydown.tab="trapFocus"
      @keydown.esc.stop="emit('close', true)"
    >
      <UiButton
        variant="link"
        class="mobile-menu__close"
        aria-label="Close navigation menu"
        @click="emit('close', true)"
      >
        <RiCloseLine
          aria-hidden="true"
          focusable="false"
        />
      </UiButton>
      <nav
        v-if="links.length"
        aria-label="Mobile navigation"
        class="mobile-menu__links"
        @click="emit('close', false)"
      >
        <UiLink
          v-for="link in links"
          :key="link.label"
          variant="nav"
          :to="link.to"
        >
          {{ link.label }}
        </UiLink>
      </nav>
      <UiThemeToggle
        variant="link"
        class="mobile-menu__theme"
      />
    </div>
  </div>
</template>
