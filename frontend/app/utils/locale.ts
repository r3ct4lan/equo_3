export type AppLocale = 'en' | 'ru'

export function resolveLocale(language: string | null | undefined): AppLocale {
  return language?.trim().toLowerCase().split(/[-_]/u)[0] === 'ru' ? 'ru' : 'en'
}

export function formatDate(date: Date, locale: AppLocale): string {
  return new Intl.DateTimeFormat(locale === 'ru' ? 'ru-RU' : 'en-IE', {
    day: '2-digit',
    month: locale === 'ru' ? '2-digit' : 'short',
    year: 'numeric',
    timeZone: 'UTC'
  }).format(date)
}
