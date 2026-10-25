import { readFile, readdir } from 'node:fs/promises'
import { fileURLToPath } from 'node:url'
import { resolve } from 'node:path'

export function auditStyles(source, tokens) {
  const errors = []
  const stripped = source.replace(/\/\*[\s\S]*?\*\//g, '')
  // One-pixel clipping is an accessibility technique, not a theme dimension.
  const visual = stripped.replace(/\.visually-hidden\s*\{[^}]+}/g, '')
  for (const match of visual.matchAll(/([\w-]+)\s*:\s*([^;{}]+)(?:;|(?=}))/g)) {
    const [, property, value] = match
    if (/#(?:[\da-f]{3,8})\b|\b(?:rgb|rgba|hsl|hsla)\s*\(/i.test(value)
      || /(?:\d|\.)\d*(?:rem|px|em|ms|s)\b/.test(value)
      || (/(?:color|background|fill|stroke)$/.test(property) && /\b(?:white|black|red|blue|green|gray|grey|yellow|orange|purple)\b/i.test(value))) {
      errors.push(`${property}: ${value.trim()} must use a theme token`)
    }
  }
  for (const [, name] of stripped.matchAll(/var\((--[\w-]+)/g)) {
    if (!tokens.has(name)) errors.push(`Undefined token ${name}`)
  }
  for (const [, condition] of stripped.matchAll(/@media\s*\(([^)]+)\)/g)) {
    if (/\d/.test(condition) && !/^(?:max-width|width <=):?\s*(?:36|52)rem$/.test(condition)) {
      errors.push(`Unregistered breakpoint ${condition}`)
    }
  }
  return errors
}

export function auditCatalog(components, catalog) {
  const visited = new Set()
  function visit(source) {
    for (const [, name] of source.matchAll(/<([A-Z]\w*)\b/g)) {
      if (visited.has(name) || !components.has(name)) continue
      visited.add(name)
      visit(components.get(name))
    }
  }
  visit(catalog)
  return [...components.keys()].filter(name => name.startsWith('Ui') && !visited.has(name))
}

async function check() {
  const root = fileURLToPath(new URL('../', import.meta.url))
  const theme = await readFile(resolve(root, 'app/assets/css/vars.css'), 'utf8')
  const tokens = new Set([...theme.matchAll(/(--[\w-]+)\s*:/g)].map(match => match[1]))
  const errors = []
  for (const file of await readdir(resolve(root, 'app/assets/css'))) {
    if (file === 'vars.css' || !file.endsWith('.css')) continue
    const source = await readFile(resolve(root, 'app/assets/css', file), 'utf8')
    errors.push(...auditStyles(source, tokens).map(error => `${file}: ${error}`))
  }
  const components = new Map()
  for (const file of await readdir(resolve(root, 'app/components'))) {
    if (file.endsWith('.vue')) components.set(file.slice(0, -4), await readFile(resolve(root, 'app/components', file), 'utf8'))
  }
  const catalog = await readFile(resolve(root, 'app/pages/ui-kit.vue'), 'utf8')
  errors.push(...auditCatalog(components, catalog).map(name => `${name} has no specimen in /ui-kit`))
  if (errors.length) throw new Error(errors.join('\n'))
  console.log(`UI system: ${tokens.size} theme tokens; every shared UI component is represented in /ui-kit.`)
}

if (process.argv[1] && resolve(process.argv[1]) === fileURLToPath(import.meta.url)) {
  await check()
}
