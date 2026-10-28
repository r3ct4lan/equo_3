import type { Locator } from '@playwright/test'

export async function selectOption(input: Locator, value: string): Promise<void> {
  await input.focus()
  await input.fill('')
  await input.locator('..').locator('..').locator(`[data-value="${value}"]`).click()
}
