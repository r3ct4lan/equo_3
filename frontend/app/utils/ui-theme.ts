/** Read the same light/dark token declarations that the browser applies. */
export function uiThemeTokens(source: string, dark = false): { name: string, value: string }[] {
  const tokens = new Map<string, string>()
  for (const [, selector, declarations] of source.matchAll(/(:root|\.dark)\s*\{([^}]+)\}/g)) {
    if (selector === '.dark' && !dark) continue
    for (const [, name, value] of declarations!.matchAll(/(--[\w-]+):\s*([^;]+);/g)) {
      tokens.set(name!, value!.trim())
    }
  }
  return [...tokens].map(([name, value]) => ({ name, value }))
}
