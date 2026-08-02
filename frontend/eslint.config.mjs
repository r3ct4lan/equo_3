import { createConfigForNuxt } from '@nuxt/eslint-config'

export default createConfigForNuxt({
  features: {
    stylistic: {
      commaDangle: 'never',
      indent: 2,
      quotes: 'single',
      semi: false
    }
  }
}).prepend({
  ignores: [
    '.nuxt/**',
    '.output/**',
    'coverage/**',
    'node_modules/**'
  ]
})
