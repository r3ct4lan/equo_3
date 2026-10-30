import { expect, test } from './fixtures'
import { selectOption } from './select'

test('UI-001: installed preset remains usable on narrow screens and in dark mode', async ({ page }) => {
  await page.goto('/ui-kit')
  await page.waitForFunction(() => {
    const root = document.querySelector('#__nuxt') as HTMLElement & {
      __vue_app__?: { config?: { globalProperties?: { $nuxt?: { isHydrating?: boolean } } } }
    } | null
    return root?.__vue_app__?.config?.globalProperties?.$nuxt?.isHydrating === false
  })
  await expect(page.getByRole('heading', { name: 'UI kit', exact: true })).toBeVisible()
  await page.evaluate(async () => {
    await document.fonts.ready
  })

  for (const viewport of [{ width: 1280, height: 900 }, { width: 320, height: 780 }]) {
    await page.setViewportSize(viewport)
    // innerWidth includes a classic scrollbar; clientWidth is the available layout width.
    await expect.poll(() => page.evaluate(() => document.documentElement.scrollWidth <= document.documentElement.clientWidth)).toBe(true)
  }

  await page.locator('.hero').getByRole('button', { name: 'Switch to dark theme', exact: true }).click()
  await expect(page.locator('html')).toHaveClass(/dark/)
  await page.getByLabel('Name', { exact: true }).fill('Demo')
  await selectOption(page.getByRole('combobox', { name: 'Direction', exact: true }), 'other')
  const picker = page.locator('.people-picker').first()
  await picker.getByRole('combobox').focus()
  await picker.getByRole('option', { name: 'Sam', exact: true }).click()
  await picker.getByRole('combobox').press('Escape')
  await page.getByRole('button', { name: 'Preview submission', exact: true }).click()
  await expect(page.getByText('No data was sent.', { exact: false })).toContainText('Direction: other. Participants: 2.')

  const trigger = page.getByRole('button', { name: 'Open example dialog', exact: true })
  await trigger.click()
  await expect(page.getByRole('dialog')).toBeVisible()
  await page.keyboard.press('Escape')
  await expect(page.getByRole('dialog')).not.toBeVisible()
  await expect(trigger).toBeFocused()
  await expect.poll(() => page.evaluate(() => document.documentElement.scrollWidth <= document.documentElement.clientWidth)).toBe(true)
})

test('UI-005: participant selection, keyboard search and themes use the shared multiselect', async ({ page }, testInfo) => {
  await page.goto('/ui-kit')
  await expect(page.getByRole('combobox', { name: 'Share with', exact: true })).toHaveAttribute('aria-expanded', 'false')
  const share = page.locator('.people-picker').first()
  const input = share.getByRole('combobox')
  await input.focus()
  await expect(share.getByRole('option', { name: /^Alex alex@example.test/ })).toHaveAttribute('aria-selected', 'true')
  await input.press('Tab')
  await expect(share.getByRole('button', { name: 'Load more', exact: true })).toBeFocused()
  await page.keyboard.press('Enter')
  await expect(share.getByRole('option')).toHaveCount(7)
  await share.getByRole('option', { name: 'Sam', exact: true }).click()
  await expect(share.getByRole('button', { name: 'Remove Sam', exact: true })).toBeVisible()
  await expect(input).toHaveAttribute('aria-expanded', 'true')
  await expect(share.getByRole('checkbox')).toHaveCount(0)
  await input.fill('alex@example')
  await expect(share.getByRole('option')).toHaveCount(1)
  await input.press('Escape')
  await expect(input).toHaveAttribute('aria-expanded', 'false')
  await selectOption(page.getByRole('combobox', { name: 'Who paid?', exact: true }), 'kit-sam')
  await expect(page.locator('.people-picker').nth(1).locator('.multiselect__single')).toHaveText('Sam')
  await share.getByRole('button', { name: 'Remove Sam', exact: true }).click()
  await expect(page.locator('.people-picker').nth(1).locator('.multiselect__single')).toHaveCount(0)
  for (const width of [1280, 320]) {
    await page.setViewportSize({ width, height: 900 })
    await input.focus()
    if (await input.inputValue()) await input.fill('')
    await expect(share.getByRole('option', { name: 'Sam', exact: true })).toBeVisible()
    await expect.poll(() => page.evaluate(() => document.documentElement.scrollWidth <= document.documentElement.clientWidth)).toBe(true)
    await page.screenshot({ path: testInfo.outputPath(`multiselect-${width}.png`) })
    await input.press('Escape')
    await page.locator('.hero').getByRole('button', { name: /Switch to .* theme/ }).click()
  }
  await expect(share.getByRole('button', { name: 'Remove Alex', exact: true })).toBeVisible()
  await page.getByRole('button', { name: 'Disable example form', exact: true }).click()
  await expect(input).toBeDisabled()
  await expect(page.locator('select')).toHaveCount(0)
})
