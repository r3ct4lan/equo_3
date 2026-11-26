import type { Assignments, EditableLine, Receipt, ReceiptDraft, ReceiptLine, RecognizedLine } from '../types/receipt'
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

export function editReceipt(saved: Receipt, currency: string): ReceiptDraft {
  return {
    uploadId: saved.uploadId, discount: saved.discount, remainder: { ...saved.remainder }, remainderSeats: saved.remainderSeats,
    lines: saved.lines.map(row => ({
      ...editableLine(), name: row.name, quantity: row.quantity, unitPrice: row.unitPrice === null ? '' : moneyInput(row.unitPrice, currency),
      amount: moneyInput(row.amount, currency), assignments: { ...row.assignments }, mergedSeats: row.mergedSeats
    }))
  }
}
function units(text: string): bigint | null {
  const match = /^(\d{1,9})(?:[.,](\d{1,6}))?$/u.exec(text)
  if (!match) return null
  const value = BigInt(match[1]! + (match[2] ?? '').padEnd(6, '0'))
  return value > 0n ? value : null
}
function gcd(a: bigint, b: bigint): bigint {
  while (b) [a, b] = [b, a % b]
  return a
}
export function prepareReceipt(draft: ReceiptDraft, totalText: string, currency: string, people: string[]): { receipt: Receipt, shares: Record<string, number> } | null {
  const total = receiptAmount(totalText, currency)
  const gap = receiptDifference(draft, totalText, currency)
  if (!total || gap === null || draft.discount !== Math.max(0, -gap) || draft.lines.length > 300) return null
  const sums = new Map<string, [bigint, bigint]>()
  function allocate(amount: number, quantity: string, assignments: Assignments, seats?: Record<string, number>): boolean {
    const entries = Object.entries(assignments)
    const allUnits = units(quantity)
    if (!entries.length || !allUnits) return false
    const equal = entries[0]![1] === null
    const denominator = equal ? entries.reduce((n, [id]) => n + BigInt(seats?.[id] ?? 1), 0n) : allUnits
    let used = 0n
    for (const [id, value] of entries) {
      if (!people.includes(id) || equal !== (value === null)) return false
      const weight = value === null ? BigInt(seats?.[id] ?? 1) : units(value)
      if (weight === null) return false
      used += weight
      const [a, b] = sums.get(id) ?? [0n, 1n]
      const numerator = a * denominator + BigInt(amount) * weight * b
      const divisor = b * denominator
      const common = gcd(numerator, divisor)
      sums.set(id, [numerator / common, divisor / common])
    }
    return used === denominator
  }
  const lines: ReceiptLine[] = []
  for (const row of draft.lines) {
    const amount = receiptAmount(row.amount, currency)
    const unitPrice = receiptAmount(row.unitPrice, currency)
    if (row.review || amount === null || (row.unitPrice && unitPrice === null) || !allocate(amount, row.quantity, row.assignments, row.mergedSeats)) return null
    const assignments = Object.fromEntries(Object.entries(row.assignments).map(([id, value]) => [id, value?.replace(',', '.') ?? null]))
    lines.push({ name: row.name, quantity: row.quantity.replace(',', '.'), amount, unitPrice, assignments, ...(row.mergedSeats ? { mergedSeats: row.mergedSeats } : {}) })
  }
  if (gap > 0 && (draft.remainderReview || !allocate(gap, '1', draft.remainder, draft.remainderSeats))) return null
  const shares = Object.fromEntries([...sums].map(([id, [a, b]]) => {
    const divisor = b * BigInt(total + draft.discount)
    return [id, Number((a * BigInt(total) + divisor - 1n) / divisor)]
  }))
  return { shares, receipt: { uploadId: draft.uploadId, lines, discount: draft.discount, remainder: gap > 0 ? { ...draft.remainder } : {}, ...(gap > 0 && draft.remainderSeats ? { remainderSeats: draft.remainderSeats } : {}) } }
}
