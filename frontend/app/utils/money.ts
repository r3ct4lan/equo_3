export const MAX_AMOUNT_CENTS = 2147483647
export function limitFractionDigits(text: string): string {
  let separatorSeen = false
  let fractionDigits = 0
  return [...text].filter((character) => {
    if (character === '.' || character === ',') {
      if (separatorSeen) return false
      separatorSeen = true
      return true
    }
    if (!/[0-9]/u.test(character)) return false
    return !separatorSeen || ++fractionDigits <= 2
  }).join('')
}
export function parseEuroCents(text: string): number | null {
  const match = /^(\d{1,8})(?:[.,](\d{1,2}))?$/u.exec(text.trim())
  if (!match) return null
  const cents = BigInt(match[1]!) * 100n + BigInt((match[2] ?? '').padEnd(2, '0'))
  return cents > 0n && cents <= BigInt(MAX_AMOUNT_CENTS) ? Number(cents) : null
}
export function formatEuro(cents: number, locale: 'en' | 'ru' = 'en'): string {
  if (!Number.isSafeInteger(cents)) throw new Error('Amount must be an exact integer.')
  const value = BigInt(cents)
  const absolute = value < 0n ? -value : value
  const whole = (absolute / 100n).toLocaleString(locale === 'ru' ? 'ru-RU' : 'en-IE')
  const fraction = String(absolute % 100n).padStart(2, '0')
  return locale === 'ru'
    ? `${value < 0n ? '-' : ''}${whole},${fraction}\u00a0€`
    : `${value < 0n ? '-€' : '€'}${whole}.${fraction}`
}
export function splitShare(cents: number, participantCount: number): number {
  if (!Number.isSafeInteger(cents) || cents <= 0 || !Number.isInteger(participantCount) || participantCount < 1) return 0
  return Number((BigInt(cents) + BigInt(participantCount) - 1n) / BigInt(participantCount))
}
