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
          ref="navigation"
          class="app-header__navigation"
          :data-open="menuOpen"
          aria-label="Primary navigation"
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
          v-if="session.status === 'authenticated'"
          ref="menuButton"
          variant="secondary"
          class="app-header__menu-toggle"
          :aria-expanded="menuOpen"
          aria-controls="primary-navigation"
          :aria-label="menuOpen ? 'Close navigation menu' : 'Open navigation menu'"
          @click="toggleMenu"
        >
          <RiCloseLine
            v-if="menuOpen"
            aria-hidden="true"
            focusable="false"
          />
          <RiMenuLine
            v-else
            aria-hidden="true"
            focusable="false"
          />
        </UiButton>
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
const navigation = useTemplateRef<HTMLElement>('navigation')
const route = useRoute()
function closeMenu(restoreFocus: boolean): void {
  if (!menuOpen.value) return
  menuOpen.value = false
  if (restoreFocus) void nextTick(() => menuButton.value?.focus())
}
function toggleMenu(): void {
  menuOpen.value = !menuOpen.value
  if (menuOpen.value) void nextTick(() => navigation.value?.querySelector<HTMLAnchorElement>('a')?.focus())
}
watch(() => [route.fullPath, session.value.status, session.value.user?.id], () => closeMenu(false))
</script>
