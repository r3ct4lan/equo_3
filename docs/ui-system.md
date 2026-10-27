# UI-система и аудит frontend

Дата: 2026-10-25. Основание: поручение владельца систематизировать frontend
перед работой над визуальным дизайном. Это отдельная задача `UI-001`,
продуктовое поведение финансовых и auth-сценариев сохраняется.

## Исходное состояние

- Nuxt 4 / Vue 3, strict TypeScript уже включён. Под «типизацией UI» понимаем
  общие компоненты с ограниченными типами вариантов и их использование
  на всех экранах, а не только наличие TypeScript.
- Общие `FormSubmitButton`, loading/error/empty и ошибки форм уже существуют.
  Остальные кнопки, ссылки-действия, карточки, поля и заголовки собраны
  непосредственно в страницах и предметных компонентах.
- `main.css` содержит цвета и часть размеров в `:root`, но интервалы,
  типографика, размеры controls, отдельные hover-цвета, active navigation,
  backdrop и toast shadow заданы литералами.
- HomeDashboard, ConnectBalance и payment dialog имеют локальные стили.
  Payment fieldset использует неописанный класс `payment-fields`.
- Уведомления об успехе повторяют markup marker/content; кнопки отправки
  в финансовых формах обходят `FormSubmitButton`.
- Страницы каталога компонентов и автоматического ограничения повторов нет.

## Проверяемые этапы

1. `UI-001-01` — аудит исходников и фиксация границ системы: выполнен.
2. `UI-001-02` — общие типизированные primitives, централизованные CSS tokens,
   перевод всех экранов и предметных компонентов, живая страница `/ui-kit`:
   выполнен. 118 существующих component tests и strict typecheck прошли
   в отдельном Docker-контейнере, смонтированном из этой рабочей копии.
3. `UI-001-03` — защита от повторов, проверки взаимодействий и регрессий,
   сборка и визуальная проверка каталога, документация правил расширения:
   выполнен; результаты и границы проверки приведены ниже.
4. `UI-001-04` — установить пресет владельца `a6OvgsF6` и подключить его
   к общим компонентам и каталогу: выполнен; подробности ниже.

Техническая проверка и визуальное одобрение владельца — разные результаты.
Новая визуальная концепция и продуктовая приёмка не подразумеваются аудитом.
Полный GitHub Actions gate выполняется по `quality-gate.md` на итоговом SHA
серии перед merge; локально — только затронутые frontend-проверки.

## Компоненты и каталог

`/ui-kit` доступен по ссылке в footer, без входа; примеры используют только
фиктивные данные и локальное состояние. Каталог показывает реальные общие
компоненты, а палитру и таблицу значений читает непосредственно из `vars.css`.

| Семейство | Общие компоненты | Использование |
|---|---|---|
| Действия | `UiButton`, `FormSubmitButton` | Primary / secondary / danger, обычная кнопка и route link, disabled / pending |
| Ссылки | `UiLink` | Текст, navigation, brand, skip link |
| Типографика | `UiText` | Body, h1–h3, lede, eyebrow, help, caption, amount; HTML-тег выбирается независимо от визуальной роли |
| Карточки | `UiCard`, `UiSummaryCard`, `UiExpenseRow` | Контейнеры форм/деталей/списков, суммы главной, последние расходы |
| Формы | `UiField`, `UiInput`, `UiSelect`, `UiTextarea`, `UiCheckbox` | Native controls, строковые/булевы/массивные модели, label, help, errors, disabled fieldset |
| Обратная связь | `UiNotice`, `UiSpinner`, `AppLoadingState`, `AppEmptyState`, `AppErrorState`, `FormErrorSummary`, `FormFieldError` | Loading, empty, info, success, error и ошибки форм |
| Детали и overlays | `UiDescriptionList`, `ConnectBalance`, `UiDialog`, `UiToast` | Атрибуты сущностей, три знака баланса, modal, уведомление приглашения |
| Тема | `UiTokenSwatch` | Образец CSS-переменной в каталоге |

Предметные формы и страницы компонуют эти элементы и владеют API/сценариями;
UI-kit не вызывает реальные financial/account actions и не дублирует каждую
предметную форму с собственной бизнес-логикой. Native `section/div`, `form`,
`fieldset/legend/label`, `ul/ol/li`, `strong/time/code`, `dt/dd`, table/details
сохраняются для семантики и структуры. Их базовое оформление также общее.
Новая графика и новые визуальные семейства должны попадать в каталог.

`UiButton` владеет внешним видом всех действий; `FormSubmitButton` — адаптер
с тем же компонентом и `type=submit`. Ссылки-действия рендерят NuxtLink,
обычные действия — native button с безопасным default `type=button`.
Pending блокирует повторное действие. Компоненты с программным фокусом
экспортируют `FocusTarget`, modal — `DialogTarget`; формы сохраняют связь
label/error и существующий порядок фокуса.

## Тема и правила расширения

Единый источник значений — `frontend/app/assets/css/vars.css`: палитра,
hover/active/danger/backdrop, typography, spacing, control sizes, radii,
shadows, opacity и motion. `main.css` содержит общие правила и композиции;
локальные стили прежних dashboard/balance/payment dialog перенесены туда.

Исключения: структурные breakpoint 36rem / 52rem остаются литералами в media
queries, где native CSS custom properties неприменимы; однопиксельное clipping
для visually-hidden, проценты и viewport dimensions описывают структуру.
Только образец цвета в каталоге вычисляет inline background через `var(...)`.

Для изменения дизайна сначала меняйте токены и общие компоненты, затем
оценивайте каталог и затронутые страницы. Новый вариант добавляется в типы
`app/types/ui.ts`, shared component/CSS и каталог. Page/domain component не
создаёт собственную реализацию кнопки, control, heading, card или notice.

## Результаты UI-001-01—03 (до установки пресета)

- Проверены все Vue-исходники приложения; собственные button/link actions,
  headings/body, controls, cards, notices и modal заменены общей системой.
  Каталог покрывает все 17 `Ui*` компонентов, включая составные примеры.
- Тема содержит 86 переменных. Токены цветов, шрифта, размеров и состояний
  применяются на всех экранах; literals в media queries и clipping перечислены
  как структурные исключения, без локальных `<style>` в компонентах.
- ESLint rule `equo-ui/components` запрещает native дубли primitives,
  присвоение принадлежащих им классов и inline/scoped стили. `lint:ui`
  проверяет CSS literals, неопределённые переменные и достижимость всех `Ui*`
  через реальные примеры каталога. Guards включены в обычный lint/CI и
  проверены положительными и отрицательными примерами.
- В Docker с зависимостями из lockfile прошли lint, strict typecheck,
  **74 unit / 123 component tests**, production build и dependency audit
  с действующей принятой политикой исключений. Lockfile обновлён вместе
  с точечным выравниванием Vue; install не затрагивал работающий Compose stack.
- Browser проверка каталога: desktop и ширина 320px, отсутствие горизонтального
  overflow, обновление полей/select/array checkbox, локальная отправка формы,
  modal, Escape и возврат фокуса. Проверена работа собранного `/ui-kit`
  (HTTP 200 и интерактивность после hydration).
- При cold production smoke обнаружен существующий конфликт root Vue 3.5.43
  и вложенного Vue 3.5.44 из Nuxt. На исходниках `main` (`8306ac3`) с тем же
  свежим lock-based install он воспроизводится без UI-изменений: Nitro создаёт
  некорректную ссылку на упакованный runtime. Root Vue выровнен до 3.5.44,
  вложенные дубли удалены lockfile resolution; сборка запускается и каталог
  работает. Принимается фактический startup, а не только exit code компилятора.

Полный GitHub Actions gate и регрессионный browser E2E ещё не выполнялись
для этого изменения. Перед merge необходим полный gate на итоговом SHA
по `quality-gate.md`; визуальное одобрение владельца остаётся отдельным шагом.
Новая тема/визуальная концепция этим аудитом не утверждается.

## UI-001-04 — пресет владельца

По новому поручению владельца выполнен
`npx shadcn-vue@latest init --preset a6OvgsF6 --template nuxt --pointer`
в существующем frontend. На момент установки CLI — **2.8.2**. Предварительно
подключены Tailwind 4.3.3 / Vite plugin и `shadcn-nuxt` 2.8.2 по
[Nuxt-инструкции shadcn-vue](https://www.shadcn-vue.com/docs/installation/nuxt).

Фактический пресет: `reka-luma`, база Zinc, teal primary, Roboto для текста,
Instrument Sans для заголовков, Remix Icon, `pointer: true`, light/dark.
`components.json` сохраняет результат генератора и пути registry.

### Интеграция

- Registry-исходники Button, Card и его subcomponents, Input, Textarea,
  NativeSelect/OptGroup/Option находятся в `app/components/ui/`.
  `shadcn-nuxt` использует префикс `Shadcn`, без конфликта с существующими `Ui*`.
- Существующие `UiButton`, `UiCard`, `UiInput`, `UiTextarea`, `UiSelect`
  используют registry-реализации. Primary/secondary/danger преобразуются
  в default/secondary/destructive. Сохраняются native submit semantics,
  aria/error связи, string models, disabled/pending и программный фокус.
  У Card добавлен `as`, чтобы формы и элементы списков сохраняли свои HTML-теги.
- Остальные shared components используют те же theme aliases; native checkbox
  сохраняет boolean/array models. Native dialog сохраняет Escape и возврат фокуса.
  Бизнес-формы, API client и session coordination не заменялись.
- Все значения темы и app aliases находятся в `vars.css` (**133 токена**).
  `tailwind.css` содержит imports и mapping семантических Tailwind utilities.
  Registry helpers сохранены в `assets/css/vendor/shadcn.css` с MIT license.
  Это управляемый исходник библиотеки; ограничения app CSS не переписывают
  библиотечные utilities. Шрифты поставляются локально через Fontsource,
  включая notices в `public/licenses/`, без Google Fonts requests.
- Каталог показывает все 17 app `Ui*` и 18 registry-компонентов, размеры и
  варианты Luma, и переключает light/dark локально для предварительного просмотра.
  Значения таблицы токенов следуют выбранной теме и CSS cascade без дублей.
  Это не сохранённая настройка темы пользователя.
- ESLint разрешает primitives в registry, но запрещает прямое использование
  registry в feature pages/domain components в обход `Ui*`; исключение — каталог.
  Catalog guard обходит registry imports/aliases и проверяет subcomponents.
- Устранена горизонтальная прокрутка на 320px при classic vertical scrollbar:
  `html` учитывает доступную ширину вместо безусловного минимума 20rem.
  Browser-проверка сравнивает `scrollWidth` с `clientWidth`, а не с `innerWidth`.

### Зависимости и проверки

CLI автоматически добавил себя в runtime dependencies. Он удалён после
генерации: для приложения нужны только registry sources и styles, а CLI
вносил новые не принятые advisory через инструменты преобразования исходников.
Сохранён только его MIT CSS asset; оставшийся optional Stylus peer обновлён
до совместимой версии без уязвимой цепочки. Audit allowlist не расширялся.
Ранее зафиксированные версии пакетов не менялись; новые зависимости закреплены
lockfile. Установка шла в отдельном Docker-контейнере с собственным volume
зависимостей, работающий Compose stack не переустанавливался.

Прошли lint/UI guards, strict typecheck, **77 unit / 123 component tests**,
lock consistency, dependency audit по принятой политике, production build
и standalone startup (`/ui-kit` — HTTP 200). Отдельно прошёл один изолированный
browser test `tests/e2e/ui-kit.spec.ts` против production-сборки: desktop/320px,
dark preview, form models и modal/Escape/focus. Полный локальный E2E не запускался.
В браузере дополнительно проверены локальные шрифты и отсутствие горизонтальной
прокрутки с 15px scrollbar: `clientWidth=305`, `scrollWidth=305` при viewport 320.

Полный GitHub Actions gate, merge и развёртывание не выполнялись.
Выбор пресета владельцем разрешает интеграцию; итоговая визуальная приёмка
всего приложения остаётся отдельным решением.
