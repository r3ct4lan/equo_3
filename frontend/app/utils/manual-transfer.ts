import type { ConnectView } from '../types/connects'
import type { CreateManualTransferBody } from '../types/finance'
import { parseEuroCents } from './money.ts'

export function manualTransferCommand(connect: ConnectView, viewerId: string, direction: string, amountText: string): CreateManualTransferBody | null {
  const amount = parseEuroCents(amountText)
  if (!connect.canCreateTransfer || !connect.otherUser.isActive || !viewerId || viewerId === connect.otherUser.id || amount === null || !['you', 'other'].includes(direction)) return null
  return {
    senderId: direction === 'you' ? viewerId : connect.otherUser.id,
    receiverId: direction === 'you' ? connect.otherUser.id : viewerId,
    amount
  }
}
export function manualTransferPreview(balance: number, command: CreateManualTransferBody | null, viewerId: string): number | null {
  if (!command || !Number.isSafeInteger(balance)) return null
  const next = balance + (command.senderId === viewerId ? command.amount : -command.amount)
  return Number.isSafeInteger(next) ? next : null
}
