import { mountSuspended } from '@nuxt/test-utils/runtime'
import { describe, expect, it } from 'vitest'
import UiSelect from '../../app/components/UiSelect.vue'
import { selectValue } from '../helpers/select'

const options = [{ value: 'you', label: 'You → Alex' }, { value: 'other', label: 'Alex → You' }]

describe('shared vue-multiselect select', () => {
  it('selects with the keyboard and keeps the string model and accessible input', async () => {
    const wrapper = await mountSuspended(UiSelect, { props: { label: 'Direction', options, modelValue: 'you' }, attrs: { 'id': 'direction', 'required': true, 'aria-describedby': 'help' } })
    const input = wrapper.get('input')
    expect(input.attributes('role')).toBe('combobox')
    expect(input.attributes('aria-label')).toBe('Direction')
    expect(input.attributes('aria-describedby')).toBe('help')
    expect(input.attributes('required')).toBeUndefined()
    await input.trigger('focus')
    expect(input.attributes('aria-expanded')).toBe('true')
    await input.trigger('keydown', { key: 'ArrowDown', keyCode: 40 })
    await input.trigger('keydown', { key: 'Enter', keyCode: 13 })
    expect(wrapper.emitted('update:modelValue')).toEqual([['other']])
    expect(wrapper.emitted('change')).toEqual([['other']])
    expect(input.attributes('aria-expanded')).toBe('false')
    wrapper.unmount()
  })
  it('selects an empty-string option and searches without changing the committed value', async () => {
    const wrapper = await mountSuspended(UiSelect, { props: { label: 'Expense', options: [{ value: '', label: 'No related expense' }, { value: 'share', label: 'Dinner' }], modelValue: 'share' }, attrs: { id: 'share' } })
    await wrapper.get('input').trigger('focus')
    await wrapper.get('input').setValue('missing')
    expect(wrapper.text()).toContain('No options found')
    expect(wrapper.emitted('update:modelValue')).toBeUndefined()
    await selectValue(wrapper, 'share', '')
    expect(wrapper.emitted('update:modelValue')).toEqual([['']])
    wrapper.unmount()
  })
  it('prevents Escape from dismissing an enclosing dialog and blocks disabled changes', async () => {
    const wrapper = await mountSuspended(UiSelect, { props: { label: 'Direction', options, modelValue: 'you' } })
    await wrapper.get('input').trigger('focus')
    await wrapper.get('input').trigger('keydown', { key: 'Escape' })
    expect(wrapper.get('input').attributes('aria-expanded')).toBe('false')
    await wrapper.setProps({ disabled: true })
    expect(wrapper.get('input').attributes('disabled')).toBeDefined()
    await wrapper.get('.multiselect__tags').trigger('click')
    expect(wrapper.get('input').attributes('aria-expanded')).toBe('false')
    expect(wrapper.emitted('update:modelValue')).toBeUndefined()
    wrapper.unmount()
  })
})
