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

export function formatDateTime(date: Date, locale: AppLocale): string {
  const time = new Intl.DateTimeFormat(locale === 'ru' ? 'ru-RU' : 'en-IE', {
    hour: '2-digit', minute: '2-digit', hour12: false, timeZone: 'UTC'
  }).format(date)
  return `${formatDate(date, locale)}, ${time}`
}

export function formatPeople(count: number, locale: AppLocale): string {
  if (locale === 'en') return `${count} ${count === 1 ? 'person' : 'people'}`
  const n = count % 100
  const word = n >= 11 && n <= 14 ? 'человек' : n % 10 === 1 ? 'человек' : n % 10 >= 2 && n % 10 <= 4 ? 'человека' : 'человек'
  return `${count} ${word}`
}
