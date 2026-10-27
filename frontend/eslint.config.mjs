import { createConfigForNuxt } from '@nuxt/eslint-config'
import uiComponentRule from './scripts/ui-component-rule.mjs'

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
}).append({
  files: ['app/**/*.vue'],
  plugins: { 'equo-ui': { rules: { components: uiComponentRule } } },
  rules: { 'equo-ui/components': 'error' }
})
