export function safePath(rawUrl: string): string {
  try {
    const url = new URL(rawUrl)
    return `${url.origin}${url.pathname.replace(/[0-9a-f-]{36}\.[A-Za-z0-9_-]{43}/giu, '[redacted]')}`
  }
  catch {
    return '[invalid URL]'
  }
}

export function redact(message: string): string {
  return message
    .replace(/[0-9a-f-]{36}\.[A-Za-z0-9_-]{43}/giu, '[redacted invitation]')
    .replace(/https?:\/\/[^\s"'<>]+/giu, match => safePath(match))
    .replace(/([?&]token=)[^\s&"']+/giu, '$1[redacted]')
    .replace(/\brt\.[A-Za-z0-9_-]{20,}\b/gu, '[redacted refresh token]')
    .replace(/\brt2\.[0-9a-f-]{36}\.[A-Za-z0-9_-]{43}\b/gu, '[redacted refresh token]')
    .replace(/\beyJ[A-Za-z0-9_-]{10,}\.[A-Za-z0-9_-]{10,}\.[A-Za-z0-9_-]{10,}\b/gu, '[redacted jwt]')
    .replace(/\bv\d+\.[A-Za-z0-9_-]{20,}(?:\.[A-Za-z0-9_-]{20,})?\b/gu, '[redacted token]')
    .replace(/\b(?:sha256:)?[a-f0-9]{64}\b/giu, '[redacted hash]')
    .replace(/(Authorization:\s*Bearer\s+)[^\s,;]+/giu, '$1[redacted]')
    .replace(/(authorization[=:]\s*Bearer\s+)[^\s,;]+/giu, '$1[redacted]')
    .replace(/((?:Set-)?Cookie:\s*)[^\n\r]+/giu, '$1[redacted]')
    .replace(/((?:set-)?cookie[=:]\s*)[^\n\r]+/giu, '$1[redacted]')
    .replace(/(X-CSRF-Token:\s*)[^\s,;]+/giu, '$1[redacted]')
    .replace(/(x-csrf-token[=:]\s*)[^\s,;]+/giu, '$1[redacted]')
    .replace(/(\bpassword\s*[=:]\s*)[^\n\r]*/giu, '$1[redacted]')
    .replace(/(ciphertext[=:]\s*)[^\s,;]+/giu, '$1[redacted]')
    .replace(/((?:access|refresh|csrf)[_-]?token|password(?:[_-]?hash)?|token[_-]?hash|jwt|cookie|ciphertext|encrypted[_-]?payload|private[_-]?key|secret)\s*[=:]\s*(?:"[^"]*"|'[^']*'|[^\s,;]+)/giu, '$1=[redacted]')
    .replace(/\bSQLSTATE(?:\[[^\]]+\])?[^\r\n]*/giu, '[redacted database error]')
    .replace(/\b(?:SELECT|INSERT\s+INTO|UPDATE\s+\w+\s+SET|DELETE\s+FROM)\b[^\r\n]*/giu, '[redacted SQL]')
    .replace(/(?:^|\n)\s*(?:at\s+.+|#\d+\s+.+)(?=\n|$)/gmu, '\n[redacted stack frame]')
    .replace(/(?:[A-Z]:\\|\/(?:home|var|tmp|workspace)\/)[^\s"'<>]+/giu, '[redacted path]')
    .replace(/(\.value\s*=\s*['"])[^'"]+(['"])/giu, '$1[redacted]$2')
}
