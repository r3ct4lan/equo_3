import type { InjectionKey, Ref } from 'vue'
import type { AppLocale } from '~/utils/locale'

export const localeOverrideKey: InjectionKey<Readonly<Ref<AppLocale>>> = Symbol('localeOverride')
