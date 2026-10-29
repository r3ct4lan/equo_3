import { defineComponent, h, ref } from 'vue'
import { enableAutoUnmount, flushPromises } from '@vue/test-utils'
import { mockNuxtImport, mountSuspended } from '@nuxt/test-utils/runtime'
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { useFinancialList } from '../../app/composables/useFinancialList'
import { useFinancialDetail } from '../../app/composables/useFinancialDetail'

enableAutoUnmount(afterEach)

const { auth } = vi.hoisted(() => ({ auth: { protectedRequest: vi.fn() } }))
mockNuxtImport('useNuxtApp', original => () => new Proxy(original(), {
  get(target, property, receiver) {
    return property === '$auth' ? auth : Reflect.get(target, property, receiver)
  }
}))

type Item = { id: string }
const page = (id: string, nextCursor: string | null = null) => ({
  items: [{ id }], pagination: { nextCursor, hasMore: nextCursor !== null }
})

function deferred<T>() {
  let resolve!: (value: T) => void
  let reject!: (reason: unknown) => void
  const promise = new Promise<T>((done, fail) => {
    resolve = done
    reject = fail
  })
  return { promise, resolve, reject }
}

function signedIn(id = 'viewer') {
  useCurrentUser().setAuthenticated({ id, name: 'Alex', email: 'self@example.test', isActive: true, createdAt: '' })
}

async function mountList() {
  const path = ref('/v1/transfers')
  const query = ref({ direction: 'incoming' })
  let data!: ReturnType<typeof useFinancialList<Item>>
  const wrapper = await mountSuspended(defineComponent({
    setup() {
      data = useFinancialList<Item>(() => path.value, () => query.value)
      return () => h('div')
    }
  }))
  await flushPromises()
  return { wrapper, data, path, query }
}

async function mountDetail() {
  const path = ref('/v1/debts/first')
  let data!: ReturnType<typeof useFinancialDetail<Item>>
  const wrapper = await mountSuspended(defineComponent({
    setup() {
      data = useFinancialDetail<Item>(() => path.value)
      return () => h('div')
    }
  }))
  await flushPromises()
  return { wrapper, data, path }
}

beforeEach(() => {
  auth.protectedRequest.mockReset()
  useCurrentUser().setAnonymous()
})

describe('financial lists', () => {
  it('waits for authentication and ignores duplicate loads while a request is pending', async () => {
    const request = deferred<ReturnType<typeof page>>()
    auth.protectedRequest.mockReturnValue(request.promise)
    const { data } = await mountList()
    await data.load()
    expect(auth.protectedRequest).not.toHaveBeenCalled()
    signedIn()
    await flushPromises()
    expect(data.pending.value).toBe(true)
    expect(data.loaded.value).toBe(false)
    await data.load()
    expect(auth.protectedRequest).toHaveBeenCalledExactlyOnceWith('/v1/transfers', { query: { direction: 'incoming' } })
    request.resolve(page('first', 'next'))
    await flushPromises()
    expect(data.items.value).toEqual([{ id: 'first' }])
    expect(data.cursor.value).toBe('next')
    expect(data.loaded.value).toBe(true)
    expect(data.pending.value).toBe(false)
  })

  it('preserves the page and cursor on failure, then appends only on an explicit retry', async () => {
    auth.protectedRequest.mockResolvedValueOnce(page('first', 'next'))
      .mockRejectedValueOnce(new Error('private transport detail'))
      .mockResolvedValueOnce(page('second'))
      .mockResolvedValueOnce(page('replacement'))
    const { data } = await mountList()
    signedIn()
    await flushPromises()
    await data.load(true)
    expect(data.items.value).toEqual([{ id: 'first' }])
    expect(data.cursor.value).toBe('next')
    expect(data.error.value?.kind).toBe('network')
    expect(auth.protectedRequest).toHaveBeenCalledTimes(2)
    await data.load(true)
    expect(auth.protectedRequest).toHaveBeenLastCalledWith('/v1/transfers', { query: { direction: 'incoming', cursor: 'next' } })
    expect(data.items.value).toEqual([{ id: 'first' }, { id: 'second' }])
    expect(data.cursor.value).toBeNull()
    expect(data.error.value).toBeNull()
    await data.load()
    expect(data.items.value).toEqual([{ id: 'replacement' }])
    expect(auth.protectedRequest).toHaveBeenLastCalledWith('/v1/transfers', { query: { direction: 'incoming' } })
  })

  it.each(['path', 'query', 'account'] as const)('discards an old page after changing %s, including its cursor', async (change) => {
    const old = deferred<ReturnType<typeof page>>()
    const current = deferred<ReturnType<typeof page>>()
    auth.protectedRequest.mockResolvedValueOnce(page('first', 'old-cursor'))
      .mockReturnValueOnce(old.promise).mockReturnValueOnce(current.promise)
    const { data, path, query } = await mountList()
    signedIn()
    await flushPromises()
    const loadingMore = data.load(true)
    if (change === 'path') path.value = '/v1/connects/another/transfers'
    if (change === 'query') query.value = { direction: 'outgoing' }
    if (change === 'account') signedIn('other-viewer')
    await flushPromises()
    expect(data.items.value).toEqual([])
    expect(data.cursor.value).toBeNull()
    expect(data.loaded.value).toBe(false)
    old.resolve(page('private-old-page', 'private-old-cursor'))
    await loadingMore
    expect(data.pending.value).toBe(true)
    expect(data.items.value).toEqual([])
    expect(auth.protectedRequest).toHaveBeenLastCalledWith(path.value, { query: query.value })
    current.resolve(page('current'))
    await flushPromises()
    expect(data.items.value).toEqual([{ id: 'current' }])
    expect(data.cursor.value).toBeNull()
  })

  it.each(['logout', 'unmount'] as const)('clears all data on %s and ignores a late rejection', async (change) => {
    const old = deferred<ReturnType<typeof page>>()
    auth.protectedRequest.mockResolvedValueOnce(page('private', 'next')).mockReturnValueOnce(old.promise)
    const { wrapper, data } = await mountList()
    signedIn()
    await flushPromises()
    const loadingMore = data.load(true)
    if (change === 'logout') useCurrentUser().setAnonymous()
    else wrapper.unmount()
    await flushPromises()
    old.reject(new Error('old-account error'))
    await loadingMore
    expect(data.items.value).toEqual([])
    expect(data.cursor.value).toBeNull()
    expect(data.error.value).toBeNull()
    expect(data.loaded.value).toBe(false)
    expect(data.pending.value).toBe(false)
    expect(auth.protectedRequest).toHaveBeenCalledTimes(2)
  })
})

describe('financial details', () => {
  it('waits for authentication, captures the ETag and clears the previous version before reload', async () => {
    const next = deferred<Item>()
    auth.protectedRequest.mockImplementationOnce(async (_path, options) => {
      options.onResponse({ response: new Response(null, { headers: { ETag: '"v1"' } }) })
      return { id: 'first' }
    }).mockReturnValueOnce(next.promise)
    const { data } = await mountDetail()
    await data.load()
    expect(auth.protectedRequest).not.toHaveBeenCalled()
    signedIn()
    await flushPromises()
    expect(data.item.value).toEqual({ id: 'first' })
    expect(data.etag.value).toBe('"v1"')
    const loading = data.load()
    expect(data.item.value).toBeNull()
    expect(data.etag.value).toBeNull()
    expect(data.pending.value).toBe(true)
    await data.load()
    expect(auth.protectedRequest).toHaveBeenCalledTimes(2)
    next.resolve({ id: 'second' })
    await loading
    expect(data.item.value).toEqual({ id: 'second' })
    expect(data.etag.value).toBeNull()
    expect(data.pending.value).toBe(false)
  })

  it('does not retain an ETag from an unsuccessful response and retries only explicitly', async () => {
    auth.protectedRequest.mockImplementationOnce(async (_path, options) => {
      options.onResponse({ response: new Response(null, { status: 503, headers: { ETag: '"error"' } }) })
      throw new Error('private transport detail')
    }).mockResolvedValueOnce({ id: 'retried' })
    const { data } = await mountDetail()
    signedIn()
    await flushPromises()
    expect(data.error.value?.kind).toBe('network')
    expect(data.item.value).toBeNull()
    expect(data.etag.value).toBeNull()
    expect(data.pending.value).toBe(false)
    expect(auth.protectedRequest).toHaveBeenCalledTimes(1)
    await data.load()
    expect(data.item.value).toEqual({ id: 'retried' })
    expect(data.error.value).toBeNull()
  })

  it.each(['path', 'account'] as const)('discards an old detail and ETag after changing %s', async (change) => {
    const old = deferred<Item>()
    const current = deferred<Item>()
    auth.protectedRequest.mockReturnValueOnce(old.promise).mockReturnValueOnce(current.promise)
    const { data, path } = await mountDetail()
    signedIn()
    await flushPromises()
    const oldOptions = auth.protectedRequest.mock.calls[0]![1]
    if (change === 'path') path.value = '/v1/debts/second'
    else signedIn('other-viewer')
    await flushPromises()
    oldOptions.onResponse({ response: new Response(null, { headers: { ETag: '"old"' } }) })
    old.resolve({ id: 'private-old-detail' })
    await flushPromises()
    expect(data.pending.value).toBe(true)
    expect(data.item.value).toBeNull()
    expect(data.etag.value).toBeNull()
    const currentOptions = auth.protectedRequest.mock.calls[1]![1]
    currentOptions.onResponse({ response: new Response(null, { headers: { ETag: '"current"' } }) })
    current.resolve({ id: 'current' })
    await flushPromises()
    expect(data.item.value).toEqual({ id: 'current' })
    expect(data.etag.value).toBe('"current"')
    expect(data.pending.value).toBe(false)
  })

  it.each(['logout', 'unmount'] as const)('clears the detail on %s and ignores a late failure', async (change) => {
    const old = deferred<Item>()
    auth.protectedRequest.mockResolvedValueOnce({ id: 'private' }).mockReturnValueOnce(old.promise)
    const { wrapper, data } = await mountDetail()
    signedIn()
    await flushPromises()
    const loading = data.load()
    if (change === 'logout') useCurrentUser().setAnonymous()
    else wrapper.unmount()
    await flushPromises()
    old.reject(new Error('old-account error'))
    await loading
    expect(data.item.value).toBeNull()
    expect(data.etag.value).toBeNull()
    expect(data.error.value).toBeNull()
    expect(data.pending.value).toBe(false)
    expect(auth.protectedRequest).toHaveBeenCalledTimes(2)
  })
})
