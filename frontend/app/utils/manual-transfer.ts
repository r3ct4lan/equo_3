import type { ConnectView } from '../types/connects'
import type { CreateManualTransferBody, TransferView } from '../types/finance'
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

export function manualTransferUpdateCommand(transfer: TransferView, viewerId: string, senderId: string, amountText: string): CreateManualTransferBody | null {
  const amount = parseEuroCents(amountText)
  const sides = [transfer.sender.id, transfer.receiver.id]
  if (transfer.type !== 'MANUAL' || transfer.isDeleted || !transfer.canEdit || transfer.createdBy?.id !== viewerId || !transfer.sender.isActive || !transfer.receiver.isActive || amount === null || !sides.includes(senderId)) return null
  const receiverId = sides.find(id => id !== senderId)
  return receiverId ? { senderId, receiverId, amount } : null
}

export function validTransferEtag(etag: string | null): boolean {
  return !!etag && /^"[1-9][0-9]{0,9}"$/u.test(etag) && Number(etag.slice(1, -1)) <= 2147483647
}
