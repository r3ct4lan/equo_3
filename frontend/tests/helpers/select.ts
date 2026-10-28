import type { VueWrapper } from '@vue/test-utils'

export async function selectValue(wrapper: Pick<VueWrapper, 'get'>, id: string, value: string): Promise<void> {
  await wrapper.get(`#${id}`).trigger('focus')
  await wrapper.get(`#${id}`).setValue('')
  await wrapper.get(`#${id}`).trigger('focus')
  await wrapper.get(`[data-value="${value}"]`).trigger('click')
}
