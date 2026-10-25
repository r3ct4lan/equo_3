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
   сборка и визуальная проверка каталога, документация правил расширения.

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
