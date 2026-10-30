import { resolveLocale, type AppLocale } from '~/utils/locale'

export function useLocale() {
  const headers = useRequestHeaders(['accept-language'])
  const cookie = useCookie<AppLocale | null>('equo-locale', { default: () => null, maxAge: 31536000, sameSite: 'lax' })
  const currentUser = useCurrentUser()
  const locale = useState<AppLocale>('locale', () => {
    if (cookie.value === 'en' || cookie.value === 'ru') return cookie.value
    const language = import.meta.server ? headers['accept-language']?.split(',')[0]?.split(';')[0] : navigator.language
    return resolveLocale(language)
  })

  function setLocale(value: AppLocale): void {
    locale.value = value
    cookie.value = value
  }

  watch(() => currentUser.state.value.user?.locale, (value) => {
    if (value === 'en' || value === 'ru') setLocale(value)
  }, { immediate: true })

  return { locale: readonly(locale), setLocale }
}
