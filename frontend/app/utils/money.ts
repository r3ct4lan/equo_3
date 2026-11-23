export const MAX_AMOUNT_CENTS = 2147483647
export function limitFractionDigits(text: string, digits = 2): string {
  if (digits === 0) return text.split(/[.,]/u, 1)[0]!.replace(/\D/gu, '')
  let separatorSeen = false
  let fractionDigits = 0
  return [...text].filter((character) => {
    if (character === '.' || character === ',') {
      if (separatorSeen) return false
      separatorSeen = true
      return true
    }
    if (!/[0-9]/u.test(character)) return false
    return !separatorSeen || ++fractionDigits <= digits
  }).join('')
}
export function parseEuroCents(text: string): number | null {
  return parseMinorUnits(text, 'EUR')
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

// ISO 4217 minor units. The remaining supported currencies have two fractional digits.
const precision: Record<string, number> = {
  BHD: 3, BIF: 0, CLP: 0, DJF: 0, GNF: 0, IQD: 3, ISK: 0, JOD: 3, JPY: 0, KMF: 0, KRW: 0, KWD: 3, LYD: 3, OMR: 3, PYG: 0, RWF: 0, TND: 3, UGX: 0, VND: 0, VUV: 0, XAF: 0, XOF: 0, XPF: 0
}
export function currencyDigits(currency = 'EUR'): number {
  return precision[currency] ?? 2
}

export function parseMinorUnits(text: string, currency = 'EUR'): number | null {
  const match = /^(\d{1,10})(?:[.,](\d{1,4}))?$/u.exec(text.trim())
  const digits = currencyDigits(currency)
  if (!match || (match[2]?.length ?? 0) > digits) return null
  const units = BigInt(match[1]!) * 10n ** BigInt(digits) + BigInt((match[2] ?? '').padEnd(digits, '0'))
  return units > 0n && units <= BigInt(MAX_AMOUNT_CENTS) ? Number(units) : null
}

export function moneyInput(amount: number, currency = 'EUR'): string {
  const digits = currencyDigits(currency)
  const value = BigInt(amount)
  const scale = 10n ** BigInt(digits)
  return String(value / scale) + (digits ? '.' + String(value % scale).padStart(digits, '0') : '')
}

export function formatMoney(amount: number, currency = 'EUR', locale: 'ru' | 'en' = 'en'): string {
  if (currency === 'EUR') return formatEuro(amount, locale)
  if (!Number.isSafeInteger(amount)) throw new Error('Amount must be an exact integer.')
  const [whole, fraction] = moneyInput(Math.abs(amount), currency).split('.')
  const digits = currencyDigits(currency)
  const formatter = new Intl.NumberFormat(locale === 'ru' ? 'ru-RU' : 'en-IE', {
    style: 'currency', currency, currencyDisplay: 'code', minimumFractionDigits: digits, maximumFractionDigits: digits
  })
  return (amount < 0 ? '-' : '') + formatter.formatToParts(BigInt(whole!)).map(part => part.type === 'fraction' ? fraction : part.value).join('')
}

export function settlementPreview(paid: number, remaining: number, from: string, to: string, quote: string) {
  if (!/^(?:\d{1,32}(?:\.\d{1,18})?|\d{1,32}\/\d{1,32})$/u.test(quote)) return null
  const [base, divisor] = quote.split('/')
  const [whole, fraction = ''] = base!.split('.')
  const numerator = BigInt(whole! + fraction) * 10n ** BigInt(currencyDigits(to))
  const denominator = BigInt(divisor ?? 1) * 10n ** BigInt(fraction.length + currencyDigits(from))
  if (!numerator || !denominator) return null
  const threshold = (BigInt(remaining) * denominator + numerator - 1n) / numerator
  if (BigInt(paid) >= threshold) return { remaining: 0, excess: Number(BigInt(paid) - threshold) }
  return { remaining: remaining - Number(BigInt(paid) * numerator / denominator), excess: 0 }
}
