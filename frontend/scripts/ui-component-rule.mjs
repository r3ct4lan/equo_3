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
      localStyle: 'Keep styles in assets/css and use vars.css tokens.',
      registry: 'Use the typed Ui* facade in application pages and domain components; registry sources are shown directly only in /ui-kit.'
    }
  },
  create(context) {
    const file = basename(context.filename)
    const facade = /\/app\/components\/Ui[^/]+\.vue$/.test(context.filename.replaceAll('\\', '/'))
    // Registry sources own native primitives; application pages still use the typed facades.
    if (context.filename.replaceAll('\\', '/').includes('/app/components/ui/')) return {}
    const services = context.sourceCode.parserServices
    const owned = owners => (Array.isArray(owners) ? owners : [owners]).includes(file)
    if (!services.defineTemplateBodyVisitor) return {}
    return services.defineTemplateBodyVisitor({
      VElement(node) {
        const name = node.rawName
        if (name.startsWith('Shadcn') && file !== 'ui-kit.vue' && !facade) {
          context.report({ node, messageId: 'registry' })
        }
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
      ImportDeclaration(node) {
        if (/components\/ui(?:\/|$)/.test(String(node.source.value)) && file !== 'ui-kit.vue' && !facade) {
          context.report({ node, messageId: 'registry' })
        }
      },
      Program(node) {
        const fragment = services.getDocumentFragment?.()
        if (fragment?.children.some(child => child.type === 'VElement' && child.name === 'style')) {
          context.report({ node, messageId: 'localStyle' })
        }
      }
    })
  }
}
