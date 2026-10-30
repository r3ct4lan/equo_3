import { mountSuspended } from '@nuxt/test-utils/runtime'
import { nextTick } from 'vue'
import { beforeEach, describe, expect, it } from 'vitest'
import DefaultLayout from '../../app/layouts/default.vue'
import { ApiClientError } from '../../app/utils/api-error'
import { selectValue } from '../helpers/select'

describe('navigation session states', () => {
  beforeEach(() => {
    useCurrentUser().reset()
    useLocale().setLocale('en')
  })

  it('keeps the home brand without a menu while session is unknown', async () => {
    const wrapper = await mountSuspended(DefaultLayout)
    useCurrentUser().reset()
    await nextTick()

    expect(wrapper.get('header a[href="/"]').text()).toBe('Equo')
    expect(wrapper.find('nav').exists()).toBe(false)
    expect(wrapper.find('footer a[href="/ui-kit"]').exists()).toBe(false)
    wrapper.unmount()
  })

  it('does not show the guest menu or a UI kit footer link when anonymous', async () => {
    useCurrentUser().setAnonymous()
    const wrapper = await mountSuspended(DefaultLayout)

    expect(wrapper.find('nav').exists()).toBe(false)
    expect(wrapper.find('header a[href="/login"]').exists()).toBe(false)
    expect(wrapper.find('header a[href="/register"]').exists()).toBe(false)
    expect(wrapper.find('footer a[href="/ui-kit"]').exists()).toBe(false)
    wrapper.unmount()
  })

  it('switches the guest language and retains the account language after sign-out', async () => {
    useLocale().setLocale('en')
    useCurrentUser().setAnonymous()
    const wrapper = await mountSuspended(DefaultLayout)
    expect(wrapper.get('.app-header__language .multiselect__single').text()).toBe('EN')
    await wrapper.get('#guest-language').trigger('focus')
    expect(wrapper.text()).toContain('English')
    expect(wrapper.text()).toContain('Русский')
    await selectValue(wrapper, 'guest-language', 'ru')
    expect(wrapper.get('.app-header__language .multiselect__single').text()).toBe('RU')
    expect(wrapper.text()).toContain('Перейти к содержимому')
    useCurrentUser().setAuthenticated({ id: 'u1', name: 'Alex', email: 'alex@example.test', isActive: true, createdAt: '2026-08-08T12:00:00Z', locale: 'en' })
    await nextTick()
    expect(wrapper.get('nav a[href="/me"]').text()).toBe('My profile')
    expect(wrapper.find('#guest-language').exists()).toBe(false)
    useCurrentUser().setAnonymous()
    await nextTick()
    expect(useLocale().locale.value).toBe('en')
    wrapper.unmount()
  })

  it('preserves authenticated navigation and hides the menu on session error', async () => {
    useCurrentUser().setAuthenticated({
      id: 'u1',
      name: 'Alex',
      email: 'alex@example.test',
      isActive: true,
      createdAt: '2026-08-08T12:00:00Z'
    })
    const authenticated = await mountSuspended(DefaultLayout)
    expect(authenticated.get('nav a[href="/connects"]').text()).toBe('Connections')
    expect(authenticated.get('nav a[href="/debts"]').text()).toBe('Expenses')
    expect(authenticated.get('nav a[href="/me"]').text()).toBe('My profile')
    expect(authenticated.find('nav a[href="/"]').exists()).toBe(false)
    authenticated.unmount()

    useCurrentUser().setError(new ApiClientError({
      kind: 'network',
      code: 'NETWORK_ERROR',
      message: 'Unable to reach the service.'
    }))
    const error = await mountSuspended(DefaultLayout)
    expect(error.find('nav').exists()).toBe(false)
    error.unmount()
  })

  it('opens navigation with focus, closes on Escape and restores the menu button', async () => {
    useCurrentUser().setAuthenticated({ id: 'u1', name: 'Alex', email: 'alex@example.test', isActive: true, createdAt: '2026-08-08T12:00:00Z' })
    const wrapper = await mountSuspended(DefaultLayout, { attachTo: document.body })
    const menu = wrapper.get('.app-header__menu-toggle')
    await menu.trigger('click')
    await nextTick()
    expect(menu.attributes('aria-expanded')).toBe('true')
    expect(document.activeElement).toBe(wrapper.get('.mobile-menu__close').element)
    const theme = wrapper.get('.mobile-menu__theme')
    await wrapper.get('.mobile-menu').trigger('keydown', { key: 'Tab', shiftKey: true })
    expect(document.activeElement).toBe(theme.element)
    await wrapper.get('.mobile-menu').trigger('keydown', { key: 'Tab' })
    expect(document.activeElement).toBe(wrapper.get('.mobile-menu__close').element)
    await wrapper.get('header').trigger('keydown.esc')
    await nextTick()
    expect(menu.attributes('aria-expanded')).toBe('false')
    expect(document.activeElement).toBe(menu.element)
    await menu.trigger('click')
    await wrapper.get('.mobile-menu__links a').trigger('click')
    expect(menu.attributes('aria-expanded')).toBe('false')
    wrapper.unmount()
  })

  it('offers the mobile theme toggle to guests', async () => {
    useCurrentUser().setAnonymous()
    const wrapper = await mountSuspended(DefaultLayout)
    await wrapper.get('.app-header__menu-toggle').trigger('click')
    expect(wrapper.find('.mobile-menu__theme').exists()).toBe(true)
    expect(wrapper.find('.mobile-menu__links').exists()).toBe(false)
    wrapper.unmount()
  })

  it('keeps a guest menu open when bootstrap finishes without an identity change', async () => {
    const wrapper = await mountSuspended(DefaultLayout)
    useCurrentUser().reset()
    await nextTick()
    const trigger = wrapper.get('.app-header__menu-toggle')
    await trigger.trigger('click')
    useCurrentUser().setAnonymous()
    await nextTick()
    expect(trigger.attributes('aria-expanded')).toBe('true')
    expect(wrapper.find('.mobile-menu__theme').exists()).toBe(true)
    useCurrentUser().setAuthenticated({ id: 'u1', name: 'Alex', email: 'alex@example.test', isActive: true, createdAt: '2026-08-08T12:00:00Z' })
    await nextTick()
    expect(trigger.attributes('aria-expanded')).toBe('false')
    wrapper.unmount()
  })
})
