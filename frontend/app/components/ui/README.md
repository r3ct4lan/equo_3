# Registry primitives

Generated with `shadcn-vue@2.8.2` using the owner's preset `a6OvgsF6`:
`reka-luma`, Zinc, teal primary, Roboto / Instrument Sans, Remix Icon, pointer.
See `frontend/components.json` for the CLI configuration.

The Nuxt module registers these components with the `Shadcn` prefix. Feature
pages and domain components use the typed root `Ui*` facades; `/ui-kit` also
shows registry variants and subcomponents directly. This keeps buttons,
form controls, disabled states and focus behavior consistent across Equo.

`Card.vue` has a local `as` extension so the shared facade can render native
`form`, `section`, `div` and `li` elements without extra wrappers. Preserve this
extension when regenerating the Card family. Input and textarea
facades preserve string models and expose a focus method.

Selects use the shared UiMultiselect facade over vue-multiselect 3.x; the native-select family is removed.

The base Tailwind helpers from the CLI package are copied to
`assets/css/vendor/shadcn.css` with the upstream MIT license. The CLI is used
through `npx`, rather than installed in the application dependencies. Fonts
are served from Fontsource packages; distributed notices are in
`public/licenses/`.

Review generator changes before accepting them: keep theme values in
`assets/css/vars.css`, shared Tailwind mappings/imports in `tailwind.css`,
and add specimens to `/ui-kit`. A preset update may add token blocks or font
imports to `tailwind.css`; reconcile them with the existing central theme
and locally served fonts. Do not keep a second set of live theme values.
