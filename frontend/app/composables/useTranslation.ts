import { translate, translateError } from '~/utils/translations'

export function useTranslation() {
  const { locale } = useLocale()
  return { t: (text: string) => translate(locale.value, text), tError: (text: string) => translateError(locale.value, text) }
}
