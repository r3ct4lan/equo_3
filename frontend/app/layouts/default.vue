<template>
  <div class="app-shell">
    <UiLink
      variant="skip"
      href="#main-content"
    >
      Skip to content
    </UiLink>

    <header
      class="app-header"
      @keydown.esc="closeMenu(true)"
    >
      <div class="page-container app-header__inner">
        <UiButton
          ref="menuButton"
          variant="link"
          class="app-header__menu-toggle"
          :aria-expanded="menuOpen"
          aria-controls="mobile-navigation"
          aria-label="Open navigation menu"
          @click="toggleMenu"
        >
          <RiMenuLine
            aria-hidden="true"
            focusable="false"
          />
        </UiButton>
        <UiLink
          variant="brand"
          to="/"
          aria-label="Equo home"
        >
          Equo
        </UiLink>

        <nav
          v-if="session.status === 'authenticated'"
          id="primary-navigation"
          class="app-header__navigation"
          aria-label="Primary navigation"
        >
          <UiLink
            variant="nav"
            to="/connects"
          >
            Connections
          </UiLink>
          <UiLink
            variant="nav"
            to="/debts"
          >
            Expenses
          </UiLink>
          <UiLink
            variant="nav"
            to="/transfers"
          >
            Transfers
          </UiLink>
          <UiLink
            variant="nav"
            to="/me"
          >
            My profile
          </UiLink>
        </nav>
        <UiThemeToggle class="app-header__theme" />
      </div>
      <UiMobileMenu
        id="mobile-navigation"
        ref="mobileMenu"
        :open="menuOpen"
        :links="mobileLinks"
        @close="closeMenu"
      />
    </header>

    <main
      id="main-content"
      class="page-container app-main"
      tabindex="-1"
    >
      <slot />
    </main>

    <footer class="app-footer">
      <div class="page-container">
        <UiText
          as="small"
          variant="caption"
        >
          Equo keeps shared expenses understandable.
        </UiText>
      </div>
    </footer>
  </div>
</template>

<script setup lang="ts">
import { RiMenuLine } from '@remixicon/vue'
import type { FocusTarget } from '~/types/ui'

const { state } = useCurrentUser()
const session = computed(() => state.value)
const menuOpen = ref(false)
const menuButton = useTemplateRef<FocusTarget>('menuButton')
const mobileMenu = useTemplateRef<{ focusFirst: () => void }>('mobileMenu')
const mobileLinks = computed(() => session.value.status === 'authenticated'
  ? [
      { label: 'Connections', to: '/connects' }, { label: 'Expenses', to: '/debts' },
      { label: 'Transfers', to: '/transfers' }, { label: 'My profile', to: '/me' }
    ]
  : [])
const route = useRoute()
function closeMenu(restoreFocus: boolean): void {
  if (!menuOpen.value) return
  menuOpen.value = false
  if (restoreFocus) void nextTick(() => menuButton.value?.focus())
}
function toggleMenu(): void {
  menuOpen.value = !menuOpen.value
  if (menuOpen.value) void nextTick(() => mobileMenu.value?.focusFirst())
}
watch([() => route.fullPath, () => session.value.user?.id], () => closeMenu(false))
let desktopMedia: MediaQueryList | undefined
function onViewportChange(event: MediaQueryListEvent): void {
  if (event.matches) closeMenu(false)
}
onMounted(() => {
  desktopMedia = window.matchMedia('(min-width: 36.001rem)')
  desktopMedia.addEventListener('change', onViewportChange)
})
onBeforeUnmount(() => desktopMedia?.removeEventListener('change', onViewportChange))
</script>
