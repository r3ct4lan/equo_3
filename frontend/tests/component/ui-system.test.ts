import { mountSuspended } from '@nuxt/test-utils/runtime'
import { describe, expect, it, vi } from 'vitest'
import UiButton from '../../app/components/UiButton.vue'
import FormSubmitButton from '../../app/components/FormSubmitButton.vue'
import UiKit from '../../app/pages/ui-kit.vue'

describe('shared UI interactions', () => {
  it('uses a non-submitting default action and blocks repeat actions while pending', async () => {
    const click = vi.fn()
    const wrapper = await mountSuspended(UiButton, { attrs: { onClick: click }, slots: { default: 'Save' } })
    expect(wrapper.attributes('type')).toBe('button')
    expect(wrapper.attributes('data-slot')).toBe('button')
    expect(wrapper.attributes('data-variant')).toBe('default')
    await wrapper.trigger('click')
    expect(click).toHaveBeenCalledOnce()
    await wrapper.setProps({ pending: true, pendingLabel: 'Saving…' })
    expect(wrapper.attributes('disabled')).toBeDefined()
    expect(wrapper.attributes('aria-busy')).toBe('true')
    expect(wrapper.text()).toBe('Saving…')
    await wrapper.trigger('click')
    expect(click).toHaveBeenCalledOnce()
    wrapper.unmount()
  })

  it('removes a disabled navigation destination and blocks pointer and keyboard activation', async () => {
    const click = vi.fn()
    const wrapper = await mountSuspended(UiButton, { props: { to: '/ui-kit', disabled: true }, attrs: { onClick: click }, slots: { default: 'Continue' } })
    expect(wrapper.element.tagName).toBe('A')
    expect(wrapper.attributes('href')).toBeUndefined()
    expect(wrapper.attributes('aria-disabled')).toBe('true')
    expect(wrapper.attributes('tabindex')).toBe('-1')
    await wrapper.trigger('click')
    expect(click).not.toHaveBeenCalled()
    await wrapper.trigger('keydown', { key: 'Enter' })
    expect(click).not.toHaveBeenCalled()
    await wrapper.setProps({ disabled: false })
    expect(wrapper.attributes('href')).toBe('/ui-kit')
    wrapper.unmount()
  })

  it('keeps submit semantics and a typed danger variant through the form adapter', async () => {
    const wrapper = await mountSuspended(FormSubmitButton, { props: { variant: 'danger', pending: true }, slots: { default: 'Remove' } })
    expect(wrapper.element.tagName).toBe('BUTTON')
    expect(wrapper.attributes('type')).toBe('submit')
    expect(wrapper.classes()).toContain('button--danger')
    expect(wrapper.attributes('data-variant')).toBe('destructive')
    expect(wrapper.attributes('disabled')).toBeDefined()
    wrapper.unmount()
  })

  it('updates string, boolean and array models in the live catalog and respects disabled fieldsets', async () => {
    const wrapper = await mountSuspended(UiKit)
    expect(wrapper.findAll('h1')).toHaveLength(1)
    await wrapper.get('#kit-name').setValue('Sam')
    await wrapper.get('#kit-description').setValue('Dinner')
    await wrapper.get('#kit-direction').setValue('other')
    await wrapper.get('input[value="sam"]').setValue(true)
    await wrapper.get('form').trigger('submit')
    expect(wrapper.text()).toContain('Direction: other. Participants: 2.')
    expect(wrapper.get('#kit-name').element.value).toBe('Sam')
    expect(wrapper.get('#kit-description').element.value).toBe('Dinner')
    expect(wrapper.get('#kit-email').attributes('aria-describedby')).toBe('kit-email-help kit-email-error')
    await wrapper.findAll('button').find(button => button.text() === 'Disable example form')!.trigger('click')
    expect(wrapper.get('form fieldset').attributes('disabled')).toBeDefined()
    wrapper.unmount()
  })

  it('uses a native modal and restores focus after Escape closes the catalog confirmation', async () => {
    const wrapper = await mountSuspended(UiKit, { attachTo: document.body })
    const trigger = wrapper.findAll('button').find(button => button.text() === 'Open example dialog')!
    await trigger.trigger('click')
    expect((wrapper.get('dialog').element as HTMLDialogElement).open).toBe(true)
    expect(wrapper.get('dialog').attributes('aria-labelledby')).toBe('kit-dialog-title')
    await wrapper.get('dialog').trigger('cancel')
    expect((wrapper.get('dialog').element as HTMLDialogElement).open).toBe(false)
    expect(document.activeElement).toBe(trigger.element)
    wrapper.unmount()
  })
})
