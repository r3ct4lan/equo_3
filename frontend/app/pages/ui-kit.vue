<script setup lang="ts">
import type { DialogTarget, FocusTarget } from '~/types/ui'
import themeSource from '~/assets/css/vars.css?raw'

useHead({ title: 'UI kit · Equo', meta: [{ name: 'robots', content: 'noindex, nofollow' }] })

const sections = [
  ['tokens', 'Theme tokens'], ['typography', 'Typography'], ['buttons', 'Buttons & links'],
  ['forms', 'Form controls'], ['cards', 'Cards & financial data'], ['states', 'Feedback & states'],
  ['overlays', 'Dialog & toast']
] as const
const tokens = [...themeSource.matchAll(/(--[\w-]+):\s*([^;]+);/g)].map(match => ({
  name: match[1]!, value: match[2]!
}))
const colors = tokens.filter(token => token.name.startsWith('--color-'))
const variants = ['primary', 'secondary', 'danger'] as const
const draft = reactive({ name: '', email: '', password: '', direction: 'you', description: '', included: true, people: ['alex'] })
const formDisabled = ref(false)
const submitted = ref(false)
const showToast = ref(false)
const dialog = useTemplateRef<DialogTarget>('dialog')
const trigger = useTemplateRef<FocusTarget>('trigger')

async function closeDialog(): Promise<void> {
  dialog.value?.close()
  await nextTick()
  trigger.value?.focus()
}
</script>

<template>
  <div class="stack stack--large">
    <header class="hero">
      <UiText variant="eyebrow">
        Equo / Design foundations
      </UiText>
      <UiText variant="heading1">
        UI kit
      </UiText>
      <UiText variant="lede">
        The shared building blocks used across Equo. Explore the current theme, components, and interaction states in one place.
      </UiText>
      <UiText variant="help">
        Examples use fictional data. Actions on this page only change the examples.
      </UiText>
    </header>

    <div class="ui-kit-layout">
      <nav
        class="ui-kit-nav"
        aria-label="UI kit sections"
      >
        <UiLink
          v-for="[id, label] in sections"
          :key="id"
          :href="'#ui-' + id"
        >
          {{ label }}
        </UiLink>
      </nav>

      <div class="stack stack--large ui-kit-content">
        <section
          id="ui-tokens"
          class="stack"
          aria-labelledby="tokens-heading"
        >
          <UiText
            id="tokens-heading"
            variant="heading2"
          >
            Theme tokens
          </UiText>
          <UiText>Colors, spacing, typography, radii, shadows, and control sizes come from a single theme file.</UiText>
          <div class="ui-kit-palette">
            <UiTokenSwatch
              v-for="token in colors"
              :key="token.name"
              :name="token.name"
              :value="token.value"
            />
          </div>
          <details class="ui-kit-token-details">
            <summary>All {{ tokens.length }} theme tokens and their current values</summary>
            <div class="ui-kit-table-scroll">
              <table class="ui-kit-token-table">
                <caption class="visually-hidden">
                  Current theme values
                </caption>
                <thead>
                  <tr>
                    <th scope="col">
                      Token
                    </th><th scope="col">
                      Value
                    </th>
                  </tr>
                </thead>
                <tbody>
                  <tr
                    v-for="token in tokens"
                    :key="token.name"
                  >
                    <th scope="row">
                      <code>{{ token.name }}</code>
                    </th><td><code>{{ token.value }}</code></td>
                  </tr>
                </tbody>
              </table>
            </div>
          </details>
        </section>

        <section
          id="ui-typography"
          class="stack"
          aria-labelledby="typography-heading"
        >
          <UiText
            id="typography-heading"
            variant="heading2"
          >
            Typography
          </UiText>
          <UiCard class="stack">
            <UiText variant="eyebrow">
              Eyebrow / section label
            </UiText>
            <UiText
              variant="heading1"
              as="p"
            >
              Page heading
            </UiText>
            <UiText
              variant="heading2"
              as="p"
            >
              Section heading
            </UiText>
            <UiText
              variant="heading3"
              as="p"
            >
              Confirmation heading
            </UiText>
            <UiText variant="lede">
              A short introduction to shared expenses.
            </UiText>
            <UiText>
              Body text with <strong>emphasis</strong> and a <UiLink href="#ui-buttons">
                text link
              </UiLink>.
            </UiText>
            <UiText variant="help">
              Help text explains a field or a next step.
            </UiText>
            <UiText variant="caption">
              Caption / supporting detail
            </UiText>
            <UiText variant="amount">
              €1,234.56
            </UiText>
            <UiText class="support-reference">
              Support reference: <code>demo-request</code>
            </UiText>
          </UiCard>
        </section>

        <section
          id="ui-buttons"
          class="stack"
          aria-labelledby="buttons-heading"
        >
          <UiText
            id="buttons-heading"
            variant="heading2"
          >
            Buttons &amp; links
          </UiText>
          <UiCard class="stack">
            <div
              v-for="variant in variants"
              :key="variant"
              class="form-actions"
            >
              <UiButton :variant="variant">
                {{ variant }}
              </UiButton>
              <UiButton
                :variant="variant"
                disabled
              >
                {{ variant }} disabled
              </UiButton>
              <UiButton
                :variant="variant"
                pending
                pending-label="Saving…"
              >
                {{ variant }}
              </UiButton>
              <UiButton
                :variant="variant"
                to="#ui-buttons"
              >
                {{ variant }} link
              </UiButton>
            </div>
            <div class="form-actions">
              <UiButton
                to="#ui-buttons"
                disabled
              >
                Disabled navigation
              </UiButton>
              <UiLink href="#ui-forms">
                Text link
              </UiLink>
              <UiLink
                to="/ui-kit"
                variant="nav"
              >
                Current navigation
              </UiLink>
              <UiLink
                href="#ui-typography"
                variant="brand"
              >
                Equo
              </UiLink>
            </div>
            <UiText variant="help">
              Use Tab to inspect focus. The skip link appears at the top of the application when focused.
            </UiText>
          </UiCard>
        </section>

        <section
          id="ui-forms"
          class="stack"
          aria-labelledby="forms-heading"
        >
          <UiText
            id="forms-heading"
            variant="heading2"
          >
            Form controls
          </UiText>
          <UiCard
            as="form"
            class="stack"
            novalidate
            @submit.prevent="submitted = true"
          >
            <fieldset
              class="stack"
              :disabled="formDisabled"
            >
              <legend>Example form</legend>
              <UiField>
                <label for="kit-name">Name</label>
                <UiInput
                  id="kit-name"
                  v-model="draft.name"
                  autocomplete="off"
                  placeholder="Alex"
                />
              </UiField>
              <UiField>
                <label for="kit-email">Email with an error</label>
                <UiInput
                  id="kit-email"
                  v-model="draft.email"
                  type="email"
                  aria-invalid="true"
                  aria-describedby="kit-email-help kit-email-error"
                />
                <UiText
                  id="kit-email-help"
                  variant="help"
                >
                  Field help stays visible alongside errors.
                </UiText>
                <FormFieldError
                  id="kit-email-error"
                  :messages="['Enter a valid email address.']"
                />
              </UiField>
              <UiField>
                <label for="kit-password">Password</label>
                <UiInput
                  id="kit-password"
                  v-model="draft.password"
                  type="password"
                  autocomplete="new-password"
                />
              </UiField>
              <UiField>
                <label for="kit-direction">Direction</label>
                <UiSelect
                  id="kit-direction"
                  v-model="draft.direction"
                >
                  <option value="you">
                    You → Alex
                  </option><option value="other">
                    Alex → You
                  </option>
                </UiSelect>
              </UiField>
              <UiField>
                <label for="kit-description">Description</label>
                <UiTextarea
                  id="kit-description"
                  v-model="draft.description"
                  rows="3"
                />
              </UiField>
              <label class="checkbox-label"><UiCheckbox v-model="draft.included" />Include the payer in the split</label>
              <fieldset class="stack">
                <legend>Participants</legend>
                <label class="checkbox-label"><UiCheckbox
                  v-model="draft.people"
                  value="alex"
                />Alex</label>
                <label class="checkbox-label"><UiCheckbox
                  v-model="draft.people"
                  value="sam"
                />Sam</label>
              </fieldset>
              <FormSubmitButton>Preview submission</FormSubmitButton>
            </fieldset>
            <UiButton
              variant="secondary"
              @click="formDisabled = !formDisabled"
            >
              {{ formDisabled ? 'Enable example form' : 'Disable example form' }}
            </UiButton>
            <UiNotice
              v-if="submitted"
              tone="success"
            >
              <div><strong>Example submitted</strong><UiText>No data was sent. Direction: {{ draft.direction }}. Participants: {{ draft.people.length }}.</UiText></div>
            </UiNotice>
          </UiCard>
        </section>

        <section
          id="ui-cards"
          class="stack"
          aria-labelledby="cards-heading"
        >
          <UiText
            id="cards-heading"
            variant="heading2"
          >
            Cards &amp; financial data
          </UiText>
          <div class="dashboard-balances">
            <UiSummaryCard
              title-id="kit-owed"
              title="You owe"
              :amount="12550"
              to="#ui-cards"
              link-label="Across all your connections"
            />
            <UiSummaryCard
              title-id="kit-receivable"
              title="You are owed"
              :amount="8300"
              to="#ui-cards"
              link-label="Across all your connections"
            />
          </div>
          <UiCard class="stack">
            <UiText variant="heading3">
              Standard card &amp; detail list
            </UiText>
            <UiDescriptionList>
              <div><dt>Paid by</dt><dd>Alex</dd></div>
              <div><dt>Split</dt><dd>2 people · €12.50 each</dd></div>
            </UiDescriptionList>
            <ConnectBalance :amount="2500" />
            <ConnectBalance :amount="-1250" />
            <ConnectBalance :amount="0" />
          </UiCard>
          <ol
            class="dashboard-expenses stack"
            aria-label="Example expenses"
          >
            <UiExpenseRow
              to="#ui-cards"
              title="Dinner together"
              :amount="2500"
              payer-name="Alex"
              created-at="2026-10-25T12:00:00Z"
            />
          </ol>
          <UiCard
            as="li"
            class="stack ui-kit-list-specimen"
          >
            <UiLink href="#ui-cards">
              Payment · €12.50
            </UiLink>
            <UiText>Alex → Sam</UiText>
            <UiText>Removed · does not affect the balance</UiText>
            <time datetime="2026-10-25T12:00:00Z">25 Oct 2026</time>
          </UiCard>
        </section>

        <section
          id="ui-states"
          class="stack"
          aria-labelledby="states-heading"
        >
          <UiText
            id="states-heading"
            variant="heading2"
          >
            Feedback &amp; states
          </UiText>
          <AppLoadingState message="Loading your overview…" />
          <AppEmptyState
            title="No expenses yet"
            message="Add an expense to share its cost with your connections."
          />
          <AppErrorState
            title="Expenses could not be loaded"
            message="Try again to load your expenses."
            retry-label="Try again"
            @retry="showToast = true"
          />
          <UiNotice tone="success">
            <div><strong>Changes saved</strong><UiText>Your details are up to date.</UiText></div>
          </UiNotice>
          <UiNotice><div><strong>Connection already exists</strong><UiText>You are already connected with Alex.</UiText></div></UiNotice>
          <FormErrorSummary
            title="Check the form"
            message="Correct the highlighted fields and submit again."
          />
        </section>

        <section
          id="ui-overlays"
          class="stack"
          aria-labelledby="overlays-heading"
        >
          <UiText
            id="overlays-heading"
            variant="heading2"
          >
            Dialog &amp; toast
          </UiText>
          <UiCard class="stack">
            <UiText>Confirmations use the shared buttons and headings. Dialogs use native modal focus containment and Escape handling.</UiText>
            <div class="form-actions">
              <UiButton
                ref="trigger"
                @click="dialog?.showModal()"
              >
                Open example dialog
              </UiButton>
              <UiButton
                variant="secondary"
                @click="showToast = true"
              >
                Show example toast
              </UiButton>
            </div>
          </UiCard>
          <UiDialog
            ref="dialog"
            aria-labelledby="kit-dialog-title"
            @cancel.prevent="closeDialog"
          >
            <template #actions>
              <UiButton
                variant="secondary"
                @click="closeDialog"
              >
                Close
              </UiButton>
            </template>
            <UiCard class="stack">
              <UiText
                id="kit-dialog-title"
                variant="heading3"
              >
                Remove example expense?
              </UiText>
              <UiText>This is a preview of a confirmation. Your expenses are unaffected.</UiText>
              <div class="form-actions">
                <UiButton
                  variant="danger"
                  @click="closeDialog"
                >
                  Confirm removal
                </UiButton>
                <UiButton
                  variant="secondary"
                  @click="closeDialog"
                >
                  Cancel
                </UiButton>
              </div>
            </UiCard>
          </UiDialog>
          <UiToast v-if="showToast">
            <UiText>Example notification. No request was sent.</UiText>
            <UiButton
              variant="secondary"
              @click="showToast = false"
            >
              Dismiss
            </UiButton>
          </UiToast>
        </section>
      </div>
    </div>
  </div>
</template>
