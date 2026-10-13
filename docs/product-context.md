# Продуктовый контекст и автономная разработка

Актуализировано: 2026-10-13. Источник передачи: `Equo-handoff-2026-10-09.zip`
(HANDOFF, MVP, DECISIONS и 13 файлов сценариев), сопоставленный с документацией,
кодом, тестами и Git репозитория. Пакет восстанавливает переписку и не заменяет
принятые нормативные документы из [индекса](README.md).

SHA-256 пакета:
`01cd330c03853233471c8d11993179e0660884b87cdbfcfd5715ef0bb4cfdbaa`.
Исходная сверенная main: `ee7df538a6dc104f837483ee3222cef76b56d8a8`;
remote main на момент сверки совпадает с ней.

Equo — учёт совместных расходов и взаиморасчётов. Основной путь: регистрация и
активация → login/session → приглашение и Connect → групповой долг с равными
долями → баланс и история → исправление долга или ручной Transfer. Transfer —
источник истины баланса, Debt — корень агрегата; Connect постоянный и
неориентированный. Финансовые инварианты, денежные единицы, округление, доступ,
история и retention определены в business rules, entities, HTTP и ADR, а не
восстанавливаются по сокращённым описаниям пакета.

## Результат сверки передачи

- Git на исходной `main` содержит все шесть сообщённых коммитов
  `a51d9bc`, `63889dd`, `c9d9898`, `fae69f8`, `5b91cf9`, `ee7df53` в указанном
  порядке; SC-001 и login/session представлены кодом, тестами и документацией.
  Их регрессия проверяется свежим gate вместе с текущей фичей.
- SC3-01 отражает закрытые OQ-022—024. SC3-02 содержит строгий rt2 codec,
  hash-only storage, stable session locator, rotation и current revoke core.
  HTTP logout/logout-all, bulk revoke и frontend actions на момент сверки
  отсутствуют. Начальная схема уже содержит нужные поля и индексы.
- Пакет описывает SC3-03 как HTTP current logout. Принятая декомпозиция в
  `features/logout-session-revocation.md` определяет SC3-03 как bulk revoke,
  SC3-04 как оба HTTP endpoints, SC3-05 как lifecycle/UI, SC3-06 как branch
  tests, SC3-07 как concurrency, SC3-08 как E2E/приёмку. Сохраняется нумерация
  репозитория; требования пакета покрываются этими этапами без потери объёма.
- Отмеченные в пакете пробелы полного glossary, 64 BR, entities, access,
  ADR-001—019, индивидуальных SC-019—022 и roadmap разрешаются существующими
  документами репозитория. Приоритеты будущих сценариев берутся из
  `mvp-scope.md`, а не из неполного реестра пакета.
- Оставшиеся обязательные пользовательские сценарии после SC-003:
  SC-004/005, SC-008/009, SC-011/012/013, SC-015/016. Сквозные требования
  SC-019—022 реализуются вместе с фичами и закрываются финальной проверкой MVP.
  Это ориентир; точные зависимости и статусы находятся в roadmap.
- Внешний SMTP в staging, эксплуатационный выпуск и продуктовая приёмка
  владельцем не подтверждаются локальным техническим PASS.

## Рабочий договор

Владелец разрешил самостоятельную декомпозицию, выбор решений в рамках
требований, проверку каждого этапа, обновление документации и отдельный коммит
на завершённый и проверенный этап. С 2026-10-10 до завершения реализации всех
фич MVP по [границам MVP](mvp-scope.md) и [roadmap](development-roadmap.md)
разрешены push веток фич, слияние реализованных и проверенных веток в `main`
и push обновлённой `main` в `origin` проекта
`git@github.com:r3ct4lan/equo_3.git`. Отдельное подтверждение на каждый этап, коммит,
push или merge не требуется. Обязательные проверки, фиксация блокеров,
продуктовая приёмка и критерии готовности MVP сохраняются. Существенное
противоречие продуктовых требований или самостоятельно неустранимый блокер
сообщаются владельцу. Правила закреплены в [AGENTS.md](../AGENTS.md).

Текущий прогресс SC-003 ведётся в [feature specification](features/logout-session-revocation.md)
и [roadmap](development-roadmap.md). Реализация началась с фактически
незавершённого SC3-03; SC3-01/02 повторно не реализованы. SC3-03—07 и browser
scope SC3-08 проверены: 322 backend, 40 unit, 27 component и 16 E2E PASS.
Ветка: `codex/sc-003-logout-session-revocation`.
Полный release gate блокируют два upstream dependency advisories без
исправленных релизов, поэтому SC3-08 ещё не закрыт. Для выпуска требуется устранить
этот audit blocker либо получить явное решение владельца о временном исключении.
Повторно реализовывать logout не требуется. [Акт проверки](acceptance/logout-session-revocation.md)
содержит доказательства и ограничения; отчёты чата не являются PASS.

Владелец принял функциональный результат SC-003 2026-10-09 и поручил следующую
фичу списка: SC-004, восстановление забытого пароля. Release audit blocker
остаётся отдельным ограничением и не считается разрешением на audit exemption.
SC-004 не требует полного закрытия SC3-08; переиспользует проверенный revoke core.
Ветка `codex/sc-004-password-reset` начинается от `6d2617e`, поскольку
на момент старта SC-004 SC-003 ещё не была merged в main. Декомпозиция —
[password-reset.md](features/password-reset.md).

SC-004 реализована и функционально проверена по SC4-01—06: запрос, email,
одноразовый reset, отзыв сессий, UI и обязательный browser E2E. Итог —
[акт SC-004](acceptance/password-reset.md). Ветка остаётся отдельной и включает
SC-003; merge в main выполнен 2026-10-10, продуктовая приёмка SC-004 не подтверждена. Полный release
gate остаётся blocked dependency audit без исключений. SC-005 (смена известного пароля) реализована и функционально проверена
в отдельной ветке codex/sc-005-password-change от 4cae6c8.
Checkbox roadmap отражают merge, а feature/acceptance — проверку веток.

SC-005: [этапы SC5-01—06](features/password-change.md),
[акт проверки](acceptance/password-change.md). 413 backend, 53 unit, 41 component
и 21 browser E2E PASS. Все refresh-сессии отзываются атомарно, UI требует новый
login; неверный current password сохраняет сессию без refresh/retry. SC-003—005
merged в main 2026-10-10 по поручению пользователя; npm audit (11 high, два advisory) остаётся release blocker.
Продуктовая приёмка SC-005 и готовность MVP этим результатом не подтверждены.

Merge выполнен последовательно fast-forward: SC-003 (6d2617e), SC-004 (4cae6c8),
SC-005 (b24fb4f), без изменения проверенного application snapshot. Проверенные
задачи roadmap включены в main; DR-E6-049/053 остаются открытыми из-за dependency
audit. SC-008 реализована, проверена и включена в main 2026-10-11
(snapshot 8cef3ba): повторяемая подписанная ссылка, public preview, атомарное
принятие, список/карточка постоянного Connect. [Акт проверки](acceptance/connect-invitations.md):
461 backend / 4711 assertions, 53 unit, 53 component, 23 browser E2E PASS.
Полный gate остаётся blocked npm audit; DR-E2-042/045 и продуктовая приёмка
не закрыты. Следующая функциональная область — финансовый этап 3: SC-011
и связанные SC-009/012/015, с зависимостями и декомпозицией в roadmap.

## Финансовый этап SC-011 — 2026-10-11

В codex/sc-011-create-debt от 4f62907 реализованы SC-011 и необходимые
финансовые read models SC-009/012/015: общий расход, равные доли, ledger balance,
история и independent ACL. Проверены 548 backend / 7211 assertions,
57 unit, 67 component и 24 browser E2E; build, smoke и Composer audit PASS.
[Акт](acceptance/create-debt.md). Release gate blocked прежними npm advisories;
продуктовая приёмка не подтверждена. Snapshot 5b1d981 fast-forward merged
в main; roadmap отмечает 68/70 задач этапа 3, DR-E3-066/070 остаются открытыми. Следующая функциональная область — этап 4, SC-016
(MANUAL writer); общий audit blocker не закрыт.

## Ручной перевод SC-016 — 2026-10-11

SC16-01—05 функционально реализованы и проверены в
codex/sc-016-manual-transfer от 8c36eec: атомарный MANUAL, оба направления,
переплата, safe retry, server balance/history refresh. 588 backend / 7537
assertions, 59 unit, 76 component, 25 browser E2E, build и smoke PASS.
[Акт](acceptance/manual-transfer.md). 26/28 задач этапа 4 выполнены;
DR-E4-026/028 и release blocked прежними 11 high npm advisories, без waiver.
Продуктовая приёмка не подтверждена. Следующая функциональная область —
этап 5, SC-013; audit blocker не закрыт.

Application snapshot SC-016 b2a6307 fast-forward merged и pushed в main
2026-10-11. Проверенные application files при интеграции не менялись;
main содержит 26/28 задач этапа 4. Release blocker и решение Product Owner
не закрыты этой интеграцией.

## Исправление долга SC-013 — 2026-10-12

SC13-01—06 функционально реализованы и проверены в codex/sc-013-update-debt
от 4282715: полный update по настоящему ETag, soft exclude/restore со стабильной
identity, atomic recalculation всех долей, explicit conflict/reload и неизменный
MANUAL. 652 backend / 8020 assertions, 62 unit, 87 component, 26 browser E2E,
build, smoke и Composer audit PASS. [Акт](acceptance/update-debt.md).
35/37 задач этапа 5 выполнены; merged в main (snapshot 8699d82). DR-E5-035/037 и release gate
blocked прежними 11 high npm advisories, без исключений.

Следующая обязательная область — этап 7 (SC-019—022, hardening/эксплуатация и
финальная техническая приёмка), затем решение Product Owner. Реализованные
пользовательские Must-сценарии не означают готовность MVP к запуску.
SC-014/017 — Should и не становятся следующей обязательной фичей.

## Состояние после интеграции SC-022 — 2026-10-13

SC-022 retention и email operations slices включены в `main`. Владелец временно
принял два npm advisory без patched versions; точечная GHSA allowlist сохраняет
строгое блокирование всех остальных `moderate/high/critical` findings. Обновить
пакеты и удалить исключения нужно после публикации исправлений (`DR-E7-060`),
периодические проверки releases не требуются. Реализована конфигурация
deployment email alert thresholds без общих числовых SLA (`DR-E7-013`).

`DR-E7-021` завершён: защищённые read/write маршруты и ACL зафиксированы в
acceptance-матрице, проверки включают отказ в анонимном создании долга без
записи и приватность списков для постороннего пользователя. Следующая задача
security audit — `DR-E7-022`, затем `DR-E7-023—030`. Полный gate
(`DR-E7-042`), deployment review, staging SMTP и Product Owner release decision
остаются открытыми. Локальный полный gate остановился до тестов из-за
отсутствующего Docker CLI в WSL; CI quality gate выполняется для PR.
