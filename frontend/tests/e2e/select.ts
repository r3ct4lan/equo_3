import type { Locator } from '@playwright/test'

export async function selectOption(input: Locator, value: string): Promise<void> {
  await input.focus()
  if (await input.inputValue()) await input.fill('')
  await input.locator('..').locator('..').locator(`[data-value="${value}"]`).click()
}

export async function setPerson(picker: Locator, value: string, selected: boolean): Promise<void> {
  const input = picker.getByRole('combobox')
  await input.focus()
  if (await input.inputValue()) await input.fill('')
  const option = picker.locator(`[data-value="${value}"]`)
  await option.waitFor({ state: 'visible' })
  const isSelected = await option.locator('..').locator('..').getAttribute('aria-selected') === 'true'
  if (isSelected !== selected) await option.click()
}
