<template>
  <div class="app-shell">
    <UiLink
      variant="skip"
      href="#main-content"
    >
      Skip to content
    </UiLink>

    <header class="app-header">
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
import { RiMoonClearLine, RiSunLine } from '@remixicon/vue'

const { state } = useCurrentUser()
const session = computed(() => state.value)
const { dark, toggle } = useTheme()
</script>
