export interface BriefUser { id: string, name: string, isActive: boolean }
export interface Pagination { nextCursor: string | null, hasMore: boolean }
export interface Page<T> { items: T[], pagination: Pagination }
interface CurrencyView {
  currency?: string
  viewerCurrency?: string
  equivalentAmount?: number | null
  rateCurrency?: string | null
  manualRate?: string | null
}
export interface DebtSummary extends CurrencyView {
  id: string
  title: string
  totalAmount: number
  payer: BriefUser
  participantCount: number
  shareAmount: number | null
  canEdit: boolean
  isDeleted: boolean
  version: number
  createdAt: string
}
export interface DebtView extends DebtSummary {
  description: string | null
  createdBy: BriefUser
  includePayerInSplit: boolean
  participants: { debtParticipantId: string, user: BriefUser, remainingAmount?: number | null, equivalentAmount?: number | null, isPayer: boolean, isAuthor: boolean, shareAmount: number, transferId: string | null, connectId: string | null }[]
  canDelete: boolean
}
export interface CreateDebtBody extends Record<string, unknown> {
  currency?: string
  manualRate?: string | null
  title: string
  description: string | null
  totalAmount: number
  payerId: string
  participantIds: string[]
  includePayerInSplit: boolean
}
export interface TransferView extends CurrencyView {
  creditedAmount?: number
  remainingAmount?: number | null
  excessAmount?: number | null
  shareCurrency?: string | null
  availableBeforePayment?: number | null
  shareRemainingAmount?: number | null
  settlementRate?: string | null
  id: string
  connectId: string
  sender: BriefUser
  receiver: BriefUser
  amount: number
  type: 'DEBT_SHARE' | 'MANUAL'
  createdBy: BriefUser | null
  debtReference: { id: string, isAccessible: boolean, title?: string, isDeleted?: boolean } | null
  relatedTransferId: string | null
  isDeleted: boolean
  canEdit: boolean
  canDelete: boolean
  version: number
  createdAt: string
}

export interface CreateManualTransferBody {
  currency?: string
  manualRate?: string | null
  senderId: string
  receiverId: string
  amount: number
  relatedTransferId?: string | null
}

export interface UpdateDebtBody extends Record<string, unknown> {
  currency?: string
  manualRate?: string | null
  title: string
  description: string | null
  totalAmount: number
  participantIds: string[]
  includePayerInSplit: boolean
}
