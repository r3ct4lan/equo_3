export interface RecognizedLine {
  name: string | null
  quantity: string | null
  unitPrice: string | null
  amount: string | null
  photo: number
  context: string | null
}
export interface Recognition {
  lines: RecognizedLine[]
  currency: string | null
  total: string | null
  discount: string | null
}
export interface ReceiptJob {
  id: string
  state: 'pending' | 'reading' | 'failed' | 'ready'
  draft: Recognition | null
  photos: number[]
}
export type Assignments = Record<string, string | null>
export interface EditableLine {
  key: string
  name: string
  quantity: string
  unitPrice: string
  amount: string
  assignments: Assignments
  review?: boolean
  mergedSeats?: Record<string, number>
}
export interface ReceiptDraft {
  uploadId: string
  lines: EditableLine[]
  discount: number
  remainder: Assignments
  remainderReview?: boolean
  remainderSeats?: Record<string, number>
}
export interface ReceiptLine {
  name: string
  quantity: string
  unitPrice: number | null
  amount: number
  assignments: Assignments
  mergedSeats?: Record<string, number>
}
export interface Receipt {
  uploadId: string
  lines: ReceiptLine[]
  discount: number
  remainder: Assignments
  remainderSeats?: Record<string, number>
}
