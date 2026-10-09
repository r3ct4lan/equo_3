import { readFile, writeFile } from 'node:fs/promises'

// Nuxt DevTools 3 still imports the default export removed by simple-git 4.
// Keep the patched security release and use its documented named factory.
const target = new URL('../node_modules/@nuxt/devtools/dist/chunks/module-main.mjs', import.meta.url)
const original = 'import Git from \'simple-git\';'
const patched = 'import { simpleGit as Git } from \'simple-git\';'
const source = await readFile(target, 'utf8')

if (source.includes(patched)) {
  console.log('DevTools simple-git 4 compatibility patch is already applied.')
}
else {
  if (!source.includes(original)) {
    throw new Error('Cannot apply DevTools simple-git 4 compatibility patch: expected import is missing.')
  }
  await writeFile(target, source.replace(original, patched))
  console.log('Applied DevTools simple-git 4 compatibility patch.')
}
