export function readTelegramInitData(hash: string): string | null {
  const values = new URLSearchParams(hash.slice(1)).getAll('tgWebAppData')
  if (values.length === 0) return null
  return values.length === 1 ? values[0]! : ''
}

const INVITATION_PREFIX = 'invite_'
const INVITATION_TOKEN = /^[0-9a-f-]{36}\.[A-Za-z0-9_-]{43}$/u

export function telegramInvitationUrl(token: string, botUsername: string): string | null {
  if (!INVITATION_TOKEN.test(token) || !/^[a-zA-Z0-9_]{5,32}$/u.test(botUsername)) return null
  const startParam = INVITATION_PREFIX + btoa(token).replaceAll('+', '-').replaceAll('/', '_').replace(/=+$/u, '')
  return `https://t.me/${botUsername}?startapp=${startParam}`
}

export function isTelegramInvitation(startParam: string): boolean {
  return startParam.startsWith(INVITATION_PREFIX)
}

export function readTelegramInvitation(startParam: string): string | null {
  if (!isTelegramInvitation(startParam)) return null
  const encoded = startParam.slice(INVITATION_PREFIX.length)
  if (!/^[A-Za-z0-9_-]{107}$/u.test(encoded)) return null

  try {
    const token = atob(encoded.replaceAll('-', '+').replaceAll('_', '/'))
    return INVITATION_TOKEN.test(token) ? token : null
  }
  catch {
    return null
  }
}
