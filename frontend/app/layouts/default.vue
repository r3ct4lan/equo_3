<template>
  <div class="app-shell">
    <UiLink
      variant="skip"
      href="#main-content"
    >
      {{ t('Skip to content') }}
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
          :aria-label="t('Open navigation menu')"
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
          :aria-label="t('Equo home')"
        >
          Equo
        </UiLink>

        <nav
          v-if="session.status === 'authenticated'"
          id="primary-navigation"
          class="app-header__navigation"
          :aria-label="t('Primary navigation')"
        >
          <UiLink
            variant="nav"
            to="/connects"
          >
            {{ t('Connections') }}
          </UiLink>
          <UiLink
            variant="nav"
            to="/debts"
          >
            {{ t('Expenses') }}
          </UiLink>
          <UiLink
            variant="nav"
            to="/transfers"
          >
            {{ t('Transfers') }}
          </UiLink>
          <UiLink
            variant="nav"
            to="/me"
          >
            {{ t('My profile') }}
          </UiLink>
        </nav>
        <UiSelect
          v-if="session.status === 'anonymous'"
          id="guest-language"
          class="app-header__language"
          :label="locale === 'ru' ? 'Язык' : 'Language'"
          :options="languageOptions"
          :model-value="locale"
          @update:model-value="changeLanguage"
        />
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
          {{ t('Equo keeps shared expenses understandable.') }}
        </UiText>
      </div>
    </footer>
  </div>
</template>

<script setup lang="ts">
import { RiMenuLine } from '@remixicon/vue'
import type { FocusTarget } from '~/types/ui'

const { state } = useCurrentUser()
const { locale, setLocale } = useLocale()
const languageOptions = [{ label: 'English', value: 'en' }, { label: 'Русский', value: 'ru' }]
function changeLanguage(value: string): void {
  if (value === 'en' || value === 'ru') setLocale(value)
}
useHead(() => ({
  htmlAttrs: { lang: locale.value },
  titleTemplate: (title?: string) => {
    if (!title) return 'Equo'
    const suffix = ' · Equo'
    return title.endsWith(suffix) ? t(title.slice(0, -suffix.length)) + suffix : title
  }
}))
const session = computed(() => state.value)
const menuOpen = ref(false)
const menuButton = useTemplateRef<FocusTarget>('menuButton')
const mobileMenu = useTemplateRef<{ focusFirst: () => void }>('mobileMenu')
const mobileLinks = computed(() => session.value.status === 'authenticated'
  ? [
      { label: t('Connections'), to: '/connects' }, { label: t('Expenses'), to: '/debts' },
      { label: t('Transfers'), to: '/transfers' }, { label: t('My profile'), to: '/me' }
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

const { t } = useTranslation()
</script>
