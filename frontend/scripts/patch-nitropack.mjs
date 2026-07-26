import { readFile, writeFile } from 'node:fs/promises'

const target = new URL(
  '../node_modules/nitropack/dist/presets/azure/utils.mjs',
  import.meta.url
)

const replacements = [
  [
    'import archiver from "archiver";',
    'import { ZipArchive } from "archiver";'
  ],
  [
    'const archive = archiver("zip", { zlib: { level: 9 } });',
    'const archive = new ZipArchive({ zlib: { level: 9 } });'
  ]
]

let source = await readFile(target, 'utf8')

if (replacements.every(([, patched]) => source.includes(patched))) {
  console.log('Nitro Archiver 8 compatibility patch is already applied.')
  process.exit(0)
}

for (const [original, patched] of replacements) {
  if (!source.includes(original)) {
    throw new Error(
      `Cannot apply Nitro Archiver 8 compatibility patch: missing "${original}".`
    )
  }

  source = source.replace(original, patched)
}

await writeFile(target, source)
console.log('Applied Nitro Archiver 8 compatibility patch.')
