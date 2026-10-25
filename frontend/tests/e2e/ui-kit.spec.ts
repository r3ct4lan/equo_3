import { expect, test } from './fixtures'

test('UI-001: installed preset remains usable on narrow screens and in dark mode', async ({ page }) => {
  await page.goto('/ui-kit')
  await expect(page.getByRole('heading', { name: 'UI kit', exact: true })).toBeVisible()
  await page.evaluate(async () => {
    await document.fonts.ready
  })

  for (const viewport of [{ width: 1280, height: 900 }, { width: 320, height: 780 }]) {
    await page.setViewportSize(viewport)
    // innerWidth includes a classic scrollbar; clientWidth is the available layout width.
    await expect.poll(() => page.evaluate(() => document.documentElement.scrollWidth <= document.documentElement.clientWidth)).toBe(true)
  }

  await page.getByRole('button', { name: 'Preview dark theme', exact: true }).click()
  await expect(page.locator('html')).toHaveClass(/dark/)
  await page.getByLabel('Name', { exact: true }).fill('Demo')
  await page.getByLabel('Direction', { exact: true }).selectOption('other')
  await page.getByRole('checkbox', { name: 'Sam', exact: true }).check()
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
