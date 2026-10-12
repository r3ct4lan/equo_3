import type { DebtView, UpdateDebtBody } from '../types/finance'
import { parseEuroCents } from './money.ts'

export interface DebtEditValues {
  title: string
  description: string
  amount: string
  participantIds: string[]
  includePayerInSplit: boolean
}
export function euroInput(cents: number): string {
  const value = BigInt(cents)
  return String(value / 100n) + '.' + String(value % 100n).padStart(2, '0')
}
export function updateDebtCommand(debt: DebtView, values: DebtEditValues, viewerId: string): UpdateDebtBody | null {
  const totalAmount = parseEuroCents(values.amount)
  const ids = [...new Set(values.participantIds)].sort()
  if (!debt.canEdit || debt.isDeleted || viewerId !== debt.createdBy.id || !values.title.trim() || totalAmount === null || !ids.length || ids.includes(debt.payer.id) || (viewerId !== debt.payer.id && !ids.includes(viewerId))) return null
  return { title: values.title.trim(), description: values.description || null, totalAmount, participantIds: ids, includePayerInSplit: values.includePayerInSplit }
}
export function validDebtEtag(etag: string | null): boolean {
  return !!etag && /^"[1-9][0-9]{0,9}"$/u.test(etag) && Number(etag.slice(1, -1)) <= 2147483647
}
