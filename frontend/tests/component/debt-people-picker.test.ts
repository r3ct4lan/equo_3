import { flushPromises } from '@vue/test-utils'
import { mockNuxtImport, mountSuspended } from '@nuxt/test-utils/runtime'
import { beforeEach, describe, expect, it, vi } from 'vitest'
import DebtPeoplePicker from '../../app/components/DebtPeoplePicker.vue'

const { auth } = vi.hoisted(() => ({ auth: { protectedRequest: vi.fn(), bootstrap: vi.fn() } }))
mockNuxtImport('useNuxtApp', original => () => new Proxy(original(), {
  get(target, property, receiver) {
    if (property === '$auth') return auth
    return Reflect.get(target, property, receiver)
  }
}))
const person = (id: string) => ({ id, name: `Person ${id}`, isActive: true, email: `${id}@example.test` })
const page = (items: ReturnType<typeof person>[], nextCursor: string | null = null) => ({ items, pagination: { nextCursor, hasMore: !!nextCursor } })

describe('participant picker', () => {
  beforeEach(() => {
    auth.protectedRequest.mockReset()
    useCurrentUser().setAuthenticated({ id: 'self', name: 'Self', email: 'self@example.test', isActive: true, createdAt: '2026-01-01T00:00:00Z' })
  })
  it('loads pages, keeps selections and exposes labels and keyboard closing', async () => {
    auth.protectedRequest.mockImplementation(async (_path, options) => options?.query?.cursor ? page([person('6')]) : page([person('1'), person('2'), person('3'), person('4'), person('5')], 'next'))
    const wrapper = await mountSuspended(DebtPeoplePicker, { props: { 'label': 'Share with', 'modelValue': [], 'onUpdate:modelValue': value => wrapper.setProps({ modelValue: value }) } })
    useCurrentUser().setAuthenticated({ id: 'self', name: 'Self', email: 'self@example.test', isActive: true, createdAt: '2026-01-01T00:00:00Z' })
    await flushPromises()
    const toggle = wrapper.get('.people-picker__toggle')
    expect(toggle.attributes('aria-expanded')).toBe('false')
    await toggle.trigger('click')
    await flushPromises()
    expect(toggle.attributes('aria-expanded')).toBe('true')
    expect(wrapper.findAll('.people-picker__option')).toHaveLength(5)
    expect(wrapper.text()).toContain('1@example.test')
    await wrapper.get('input[value="1"]').setValue(true)
    await wrapper.findAll('button').find(button => button.text() === 'Load more')!.trigger('click')
    await flushPromises()
    expect(wrapper.findAll('.people-picker__option')).toHaveLength(6)
    expect(wrapper.get('input[value="1"]').element.checked).toBe(true)
    await wrapper.trigger('keydown.esc')
    expect(toggle.attributes('aria-expanded')).toBe('false')
    expect(wrapper.text()).toContain('Person 1')
    wrapper.unmount()
  })
  it('aborts stale searches and ignores their late results', async () => {
    let resolve: (value: unknown) => void = () => undefined
    auth.protectedRequest.mockImplementationOnce(() => new Promise((done) => {
      resolve = done
    })).mockResolvedValue(page([person('new')]))
    const wrapper = await mountSuspended(DebtPeoplePicker, { props: { label: 'Share with', modelValue: [] } })
    await wrapper.get('.people-picker__toggle').trigger('click')
    const signal = auth.protectedRequest.mock.calls[0]![1].signal as AbortSignal
    await wrapper.get('input[type="text"]').setValue('new')
    expect(signal.aborted).toBe(true)
    await new Promise(done => setTimeout(done, 300))
    await flushPromises()
    resolve(page([person('old')]))
    await flushPromises()
    expect(wrapper.text()).toContain('Person new')
    expect(wrapper.text()).not.toContain('Person old')
    wrapper.unmount()
  })
})
