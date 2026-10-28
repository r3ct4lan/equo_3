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
        <UiButton
          variant="secondary"
          class="app-header__theme"
          :aria-label="dark ? 'Switch to light theme' : 'Switch to dark theme'"
          :aria-pressed="dark"
          @click="toggle"
        >
          <RiMoonClearLine
            v-if="dark"
            aria-hidden="true"
            focusable="false"
          />
          <RiSunLine
            v-else
            aria-hidden="true"
            focusable="false"
          />
        </UiButton>
      </div>
      <div
        v-if="menuOpen"
        class="mobile-menu-backdrop"
        @click.self="closeMenu(true)"
      >
        <div
          id="mobile-navigation"
          ref="mobileMenu"
          class="mobile-menu"
          role="dialog"
          aria-modal="true"
          aria-label="Navigation menu"
          @keydown.tab="trapFocus"
        >
          <UiButton
            variant="link"
            class="mobile-menu__close"
            aria-label="Close navigation menu"
            @click="closeMenu(true)"
          >
            <RiCloseLine
              aria-hidden="true"
              focusable="false"
            />
          </UiButton>
          <nav
            v-if="session.status === 'authenticated'"
            aria-label="Mobile navigation"
            class="mobile-menu__links"
            @click="closeMenu(false)"
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
          <UiButton
            variant="link"
            class="mobile-menu__theme"
            :aria-label="dark ? 'Switch to light theme' : 'Switch to dark theme'"
            :aria-pressed="dark"
            @click="toggle"
          >
            <RiMoonClearLine
              v-if="dark"
              aria-hidden="true"
              focusable="false"
            />
            <RiSunLine
              v-else
              aria-hidden="true"
              focusable="false"
            />
          </UiButton>
        </div>
      </div>
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
import { RiCloseLine, RiMenuLine, RiMoonClearLine, RiSunLine } from '@remixicon/vue'
import type { FocusTarget } from '~/types/ui'

const { state } = useCurrentUser()
const session = computed(() => state.value)
const { dark, toggle } = useTheme()
const menuOpen = ref(false)
const menuButton = useTemplateRef<FocusTarget>('menuButton')
const mobileMenu = useTemplateRef<HTMLElement>('mobileMenu')
const route = useRoute()
function closeMenu(restoreFocus: boolean): void {
  if (!menuOpen.value) return
  menuOpen.value = false
  if (restoreFocus) void nextTick(() => menuButton.value?.focus())
}
function toggleMenu(): void {
  menuOpen.value = !menuOpen.value
  if (menuOpen.value) void nextTick(() => mobileMenu.value?.querySelector<HTMLElement>('a, button')?.focus())
}
function trapFocus(event: KeyboardEvent): void {
  const focusable = [...(mobileMenu.value?.querySelectorAll<HTMLElement>('a[href], button:not([disabled])') ?? [])]
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
watch(() => [route.fullPath, session.value.status, session.value.user?.id], () => closeMenu(false))
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
