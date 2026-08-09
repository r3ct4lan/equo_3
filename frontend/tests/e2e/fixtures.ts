import { test as base } from '@playwright/test'

function safePath(rawUrl: string): string {
  try {
    const url = new URL(rawUrl)
    return `${url.origin}${url.pathname}`
  }
  catch {
    return '[invalid URL]'
  }
}

function redact(message: string): string {
  return message
    .replace(/https?:\/\/[^\s"'<>]+/giu, match => safePath(match))
    .replace(/([?&]token=)[^\s&"']+/giu, '$1[redacted]')
    .replace(/\brt\.[A-Za-z0-9_-]{20,}\b/gu, '[redacted refresh token]')
    .replace(/\beyJ[A-Za-z0-9_-]{10,}\.[A-Za-z0-9_-]{10,}\.[A-Za-z0-9_-]{10,}\b/gu, '[redacted jwt]')
    .replace(/\bv\d+\.[A-Za-z0-9_-]{20,}(?:\.[A-Za-z0-9_-]{20,})?\b/gu, '[redacted token]')
    .replace(/(Authorization:\s*Bearer\s+)[^\s,;]+/giu, '$1[redacted]')
    .replace(/(authorization[=:]\s*Bearer\s+)[^\s,;]+/giu, '$1[redacted]')
    .replace(/((?:Set-)?Cookie:\s*)[^\n\r]+/giu, '$1[redacted]')
    .replace(/((?:set-)?cookie[=:]\s*)[^\n\r]+/giu, '$1[redacted]')
    .replace(/(X-CSRF-Token:\s*)[^\s,;]+/giu, '$1[redacted]')
    .replace(/(x-csrf-token[=:]\s*)[^\s,;]+/giu, '$1[redacted]')
    .replace(/(password(?:\s*[:=]|\s*=\s*)\s*)[^\s,;]+/giu, '$1[redacted]')
    .replace(/(\.value\s*=\s*['"])[^'"]+(['"])/giu, '$1[redacted]$2')
}

export const test = base.extend({
  page: async ({ page }, use, testInfo) => {
    const diagnostics: string[] = []

    page.on('console', (message) => {
      if (message.type() === 'warning' || message.type() === 'error') {
        diagnostics.push(`[console:${message.type()}] ${redact(message.text())}`)
      }
    })
    page.on('pageerror', (error) => {
      diagnostics.push(`[pageerror] ${redact(error.message)}`)
    })
    page.on('requestfailed', (request) => {
      diagnostics.push(
        `[requestfailed] ${request.method()} ${safePath(request.url())} ${redact(request.failure()?.errorText ?? '')}`
      )
    })

    await use(page)

    if (testInfo.status !== testInfo.expectedStatus && diagnostics.length > 0) {
      await testInfo.attach('browser-diagnostics', {
        body: Buffer.from(diagnostics.join('\n'), 'utf8'),
        contentType: 'text/plain'
      })
    }
  }
})

export { expect } from '@playwright/test'
