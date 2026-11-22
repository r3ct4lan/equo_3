import { enableAutoUnmount, flushPromises } from '@vue/test-utils'
import { mockNuxtImport, mountSuspended } from '@nuxt/test-utils/runtime'
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import MergePage from '../../app/pages/account/merge.vue'
import { ApiClientError } from '../../app/utils/api-error'

enableAutoUnmount(afterEach)

const { auth } = vi.hoisted(() => ({ auth: { protectedRequest: vi.fn(), bootstrap: vi.fn() } }))
mockNuxtImport('useNuxtApp', original => () => new Proxy(original(), {
  get(target, property, receiver) {
    return property === '$auth' ? auth : Reflect.get(target, property, receiver)
  }
}))

const preview = {
  version: 'opaque-preview-version',
  mode: 'replace_telegram',
  email: 'primary@example.test',
  discardedEmail: 'joined@example.test',
  replacesTelegram: true,
  discardsTelegram: false,
  disappearingTransfers: [{ id: 'transfer', createdAt: '2026-10-01T08:30:00Z', amount: 1450 }],
  disappearingExpenses: [{ id: 'expense', createdAt: '2026-10-03T08:30:00Z', amount: 6000, title: 'Shared meal' }]
}

describe('account merge preview', () => {
  beforeEach(() => {
    auth.bootstrap.mockReset().mockResolvedValue(undefined)
    auth.protectedRequest.mockReset()
    useCurrentUser().setAuthenticated({ id: 'u1', name: 'User', email: 'primary@example.test', isActive: true, createdAt: '' })
  })

  it('shows the email kept for sign-in and disappearing operation amounts and dates', async () => {
    auth.protectedRequest.mockResolvedValueOnce(preview)
    const wrapper = await mountSuspended(MergePage, { route: '/account/merge' })
    await flushPromises()

    expect(wrapper.text()).toContain('primary@example.test')
    expect(wrapper.text()).toContain('joined@example.test')
    expect(wrapper.text()).toContain('Shared meal')
    expect(wrapper.text()).toMatch(/14[.,]50/)
    expect(wrapper.text()).toMatch(/60[.,]00/)
    expect(wrapper.findAll('time').map(value => value.attributes('datetime'))).toEqual(['2026-10-01T08:30:00Z', '2026-10-03T08:30:00Z'])
    expect(wrapper.text()).not.toContain('opaque-preview-version')
  })

  it('asks for a separate confirmation when affected operations have changed', async () => {
    const changed = {
      ...preview,
      version: 'updated-preview-version',
      disappearingTransfers: [{ ...preview.disappearingTransfers[0]!, amount: 1550 }]
    }
    auth.protectedRequest.mockResolvedValueOnce(preview)
      .mockResolvedValueOnce({ status: 'preview_changed', preview: changed })
      .mockResolvedValueOnce({ status: 'merged' })
    const wrapper = await mountSuspended(MergePage, { route: '/account/merge' })
    await flushPromises()
    await wrapper.findAll('button').find(value => value.text() === 'Confirm merge')!.trigger('click')
    await flushPromises()

    expect(wrapper.text()).toContain('The operations changed. Review the updated preview before confirming.')
    expect(wrapper.text()).toMatch(/15[.,]50/)
    expect(auth.protectedRequest).toHaveBeenCalledTimes(2)
    await wrapper.findAll('button').find(value => value.text() === 'Confirm merge')!.trigger('click')
    await flushPromises()
    expect(auth.protectedRequest).toHaveBeenLastCalledWith('/v1/account/credentials/merge/confirm', {
      method: 'POST', body: { version: 'updated-preview-version' }
    })
  })

  it('cancels the pending proof from the preview', async () => {
    auth.protectedRequest.mockResolvedValueOnce(preview).mockResolvedValueOnce(undefined)
    const wrapper = await mountSuspended(MergePage, { route: '/account/merge' })
    await flushPromises()
    const button = wrapper.findAll('button').find(value => value.text() === 'Cancel merge')!
    await button.trigger('click')
    await flushPromises()

    expect(auth.protectedRequest).toHaveBeenLastCalledWith('/v1/account/credentials/cancel', { method: 'POST' })
    expect(wrapper.text()).not.toContain('Shared meal')
  })

  it('shows no account or financial details when the confirmation is unavailable', async () => {
    auth.protectedRequest.mockRejectedValueOnce(new ApiClientError({ kind: 'api', status: 400, code: 'INVALID_TOKEN', message: 'Private error details' }))
    const wrapper = await mountSuspended(MergePage, { route: '/account/merge' })
    await flushPromises()

    expect(wrapper.get('[role="alert"]').text()).toContain('Confirm access to both accounts again')
    expect(wrapper.text()).not.toContain('Private error details')
    expect(wrapper.text()).not.toContain('Shared meal')
  })
})
