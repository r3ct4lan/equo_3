import type { RouteLocationRaw } from 'vue-router'

export type ButtonVariant = 'primary' | 'secondary' | 'danger' | 'link'
export type LinkVariant = 'text' | 'nav' | 'brand' | 'skip'
export type TextVariant = 'body' | 'heading1' | 'heading2' | 'heading3' | 'lede' | 'eyebrow' | 'help' | 'caption' | 'amount'
export type TextTag = 'p' | 'span' | 'small' | 'strong' | 'h1' | 'h2' | 'h3'
export type CardTag = 'section' | 'div' | 'form' | 'li'
export type NoticeTone = 'info' | 'success' | 'error' | 'neutral'
export type InputType = 'text' | 'email' | 'password' | 'search'
export type LinkTo = RouteLocationRaw

export interface FocusTarget {
  focus: () => void
}

export interface DialogTarget {
  readonly open: boolean
  showModal: () => void
  close: () => void
  focusFirst: (selector: string) => void
}
