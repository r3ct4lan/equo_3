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
    .replace(/([?&]token=)[^\s&]+/giu, '$1[redacted]')
    .replace(/\bv\d+\.[A-Za-z0-9_-]{20,}\b/gu, '[redacted capability]')
    .replace(/(password[=:]\s*)[^\s,;]+/giu, '$1[redacted]')
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
