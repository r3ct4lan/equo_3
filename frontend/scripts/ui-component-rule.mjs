import { basename } from 'node:path'

const elements = {
  button: ['UiButton.vue'],
  a: ['UiLink.vue'],
  NuxtLink: ['UiButton.vue', 'UiLink.vue'],
  input: ['UiInput.vue', 'UiCheckbox.vue'],
  select: ['UiSelect.vue'],
  textarea: ['UiTextarea.vue'],
  dialog: ['UiDialog.vue'],
  h1: ['UiText.vue'], h2: ['UiText.vue'], h3: ['UiText.vue'], p: ['UiText.vue'], small: ['UiText.vue']
}
const classes = {
  'button': 'UiButton.vue', 'panel': 'UiCard.vue', 'form-field': 'UiField.vue',
  'ui-control': ['UiInput.vue', 'UiSelect.vue', 'UiTextarea.vue'],
  'state-message': 'UiNotice.vue', 'status-card__marker': 'UiNotice.vue',
  'spinner': 'UiSpinner.vue', 'profile-list': 'UiDescriptionList.vue',
  'nav-link': 'UiLink.vue', 'brand': 'UiLink.vue', 'skip-link': 'UiLink.vue',
  'invitation-toast': 'UiToast.vue', 'ui-dialog': 'UiDialog.vue'
}

export default {
  meta: {
    type: 'problem',
    schema: [],
    messages: {
      primitive: 'Use the shared UI component instead of <{{name}}> (docs/ui-system.md).',
      ownedClass: 'The {{name}} class belongs to {{owner}}. Use that component and its typed props.',
      inlineStyle: 'Put visual values in vars.css and shared CSS; inline styles are reserved for theme swatches.',
      localStyle: 'Keep styles in assets/css and use vars.css tokens.'
    }
  },
  create(context) {
    const file = basename(context.filename)
    const services = context.sourceCode.parserServices
    const owned = owners => (Array.isArray(owners) ? owners : [owners]).includes(file)
    if (!services.defineTemplateBodyVisitor) return {}
    return services.defineTemplateBodyVisitor({
      VElement(node) {
        const name = node.rawName
        if (elements[name] && !owned(elements[name])) {
          context.report({ node, messageId: 'primitive', data: { name } })
        }
      },
      VAttribute(node) {
        const name = node.directive ? node.key.argument?.name : node.key.name
        if (name === 'style' && file !== 'UiTokenSwatch.vue') {
          context.report({ node, messageId: 'inlineStyle' })
        }
        if (!node.directive && name === 'class' && node.value) {
          for (const name of node.value.value.split(/\s+/)) {
            const owner = classes[name] ?? (name.startsWith('button--') ? 'UiButton.vue' : undefined)
            if (owner && !owned(owner)) {
              context.report({ node, messageId: 'ownedClass', data: { name, owner: String(owner) } })
            }
          }
        }
      }
    }, {
      Program(node) {
        const fragment = services.getDocumentFragment?.()
        if (fragment?.children.some(child => child.type === 'VElement' && child.name === 'style')) {
          context.report({ node, messageId: 'localStyle' })
        }
      }
    })
  }
}
