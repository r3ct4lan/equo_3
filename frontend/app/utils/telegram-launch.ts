export function readTelegramInitData(hash: string): string | null {
  const values = new URLSearchParams(hash.slice(1)).getAll('tgWebAppData')
  if (values.length === 0) return null
  return values.length === 1 ? values[0]! : ''
}
