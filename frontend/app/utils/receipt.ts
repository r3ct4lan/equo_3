import type { EditableLine, ReceiptDraft, RecognizedLine } from '../types/receipt'
import { currencyDigits, MAX_AMOUNT_CENTS, moneyInput, parseMinorUnits } from './money.ts'

export function editableLine(row?: RecognizedLine): EditableLine {
  return {
    key: crypto.randomUUID(), name: row?.name ?? '', quantity: row?.quantity ?? '1',
    unitPrice: row?.unitPrice ?? '', amount: row?.amount ?? '', assignments: {}
  }
}
export function receiptAmount(text: string, currency: string): number | null {
  if (!currency) return null
  const normalized = text.trim().replace(',', '.').replace(/(\.\d*?)0+$/u, '$1').replace(/\.$/u, '')
  return normalized === '0' ? 0 : parseMinorUnits(normalized, currency)
}
export function receiptDifference(draft: ReceiptDraft, total: string, currency: string): number | null {
  const amount = receiptAmount(total, currency)
  if (!amount) return null
  let difference = amount
  for (const row of draft.lines) {
    const line = receiptAmount(row.amount, currency)
    if (line === null) return null
    difference -= line
  }
  return difference
}
export function extendedPrice(row: EditableLine, currency: string): string | null {
  const quantity = row.quantity.replace(',', '.')
  const price = row.unitPrice.replace(',', '.')
  if (!/^\d{1,9}(?:\.\d{1,6})?$/u.test(quantity) || !/^\d{1,10}(?:\.\d{1,6})?$/u.test(price)) return null
  const scale = (quantity.split('.')[1]?.length ?? 0) + (price.split('.')[1]?.length ?? 0)
  const product = BigInt(quantity.replace('.', '')) * BigInt(price.replace('.', ''))
  if (!currency) {
    const raw = product.toString().padStart(scale + 1, '0')
    return scale ? raw.slice(0, -scale) + '.' + raw.slice(-scale) : raw
  }
  const denominator = 10n ** BigInt(scale)
  const minor = (product * 10n ** BigInt(currencyDigits(currency)) + denominator / 2n) / denominator
  return minor <= BigInt(MAX_AMOUNT_CENTS) ? moneyInput(Number(minor), currency) : null
}
