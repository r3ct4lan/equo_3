# Продуктовый контекст и автономная разработка

Актуализировано: 2026-10-14. Источник передачи: `Equo-handoff-2026-10-09.zip`
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

## Состояние после DR-E7-022 — 2026-10-14

SC-022 retention и email operations slices включены в `main`. Владелец временно
принял два npm advisory без patched versions; точечная GHSA allowlist сохраняет
строгое блокирование всех остальных `moderate/high/critical` findings. Обновить
пакеты и удалить исключения нужно после публикации исправлений (`DR-E7-060`),
периодические проверки releases не требуются. Реализована конфигурация
deployment email alert thresholds без общих числовых SLA (`DR-E7-013`).

`DR-E7-021` и `DR-E7-022` завершены: защищённые операции и единообразие 404 для
скрытых/отсутствующих Connect, Debt и Transfer зафиксированы в acceptance.
Следующая задача security audit — `DR-E7-023`, затем `DR-E7-024—030`. Полный
gate (`DR-E7-042`), deployment review, staging SMTP и Product Owner release
decision остаются открытыми. CI quality gate #31 прошёл для DR-E7-022;
локальный `make check-full` остановился на проверке CI config, поскольку в WSL
отсутствует Docker CLI.

## Состояние после DR-E7-023 — 2026-10-14

Финансовые read-модели проверены на отсутствие email других пользователей во
всех вложенных ответах Connect, Debt и Transfer, включая MANUAL `createdBy` и
полное представление Debt при создании. CI quality gate #34 прошёл: 670 backend
тестов / 8 231 assertions и 26 browser E2E. Следующая задача security audit —
`DR-E7-024`, проверка ограничений inactive user для всех commands. Полный SC-022,
deployment review и решение Product Owner остаются открытыми.

## Состояние после DR-E7-024 — 2026-10-14

Проверены inactive-user restrictions для всех реализованных protected writers:
неактивный текущий аккаунт получает `401`; inactive payer/participant блокирует
создание Debt без частичных финансовых записей; invitation acceptance, MANUAL
creation и Debt update сохраняют свои отдельные роли и ограничения. CI quality
gate #38 прошёл: 673 backend теста / 8 285 assertions и 26 browser E2E. Следующая
задача audit — `DR-E7-025`, матрица `Idempotency-Key` по scope, operation и body.
Полный SC-022, deployment review и Product Owner release decision остаются
открытыми.

## Состояние после DR-E7-025 — 2026-10-14

`Idempotency-Key` проверен для трёх реализованных команд, где он обязателен:
registration, Debt creation и MANUAL creation. Добавлено доказательство
независимых user scope и operation; повторы возвращают сохранённый результат,
изменённый запрос в рамках прежней пары конфликтует. CI quality gate #42
прошёл: 674 backend теста / 8 299 assertions и 26 browser E2E. Следующий пункт —
`DR-E7-026`, аудит `ETag/If-Match` для реализованных versioned writers. Полный
SC-022, deployment review и Product Owner release decision остаются открытыми.

## Состояние после DR-E7-026 — 2026-10-14

Проверен versioned writer `PUT /debts/{debtId}`: успешная и no-op версии,
stale conflict, empty/missing/malformed `If-Match` и отсутствие изменений при
отказе. Для реализованных Debt/MANUAL read/write responses сверены version ETag.
Отложенный `SH-014` Debt delete и отсутствующие Transfer update/delete не
включались. CI quality gate #43 прошёл: 674 backend теста / 8 299 assertions и
26 browser E2E. Следующая задача — `DR-E7-027`, rollback многотабличных writers.
Полный SC-022, deployment review и решение Product Owner открыты.

## Состояние после DR-E7-027 — 2026-10-14

Проверены rollback и отсутствие частичных записей у реализованных
многотабличных writers: identity/outbox/session, Debt create/update, MANUAL
Transfer и принятие приглашения. Контролируемые сбои подтверждают, что ранние
записи отменяются вместе с поздней ошибкой. CI quality gate #48 прошёл: 675
backend тестов / 8 314 assertions и 26 browser E2E. Следующая задача —
`DR-E7-028`, проверка уникальных DB constraints. Полный SC-022, deployment
review и решение Product Owner открыты.

## Состояние после DR-E7-028 — 2026-10-14

Подтверждены уникальные ограничения PostgreSQL и соответствие Doctrine mapping:
canonical Connect pair, один participant на user/debt (в том числе soft-deleted),
одна DEBT_SHARE на participant (в том числе soft-deleted), уникальные hashes
action/invitation tokens, один незавершённый action token на user/purpose и
уникальность idempotency tuple `(scope, operation, key)` с разрешёнными другими
scope/operation. Добавлены недостающие integration tests. CI quality gate #51
прошёл: 679 backend тестов / 8 318 assertions и 26 browser E2E. Следующая задача —
`DR-E7-029`, audit redaction. Полный SC-022, deployment review и решение Product
Owner открыты.

## Состояние после DR-E7-029 — 2026-10-15

Проверена редактировка чувствительных данных в serializer assertions, логах,
публичных failure responses и browser diagnostics. Проверки подтверждают
отсутствие password, action token, JWT, refresh cookie, CSRF, ciphertext,
SQLSTATE и локальных путей в ошибочных ответах и диагностике. Успешный auth
response остаётся в пределах принятого HTTP-контракта. Playwright screenshots,
traces и videos отключены, CI публикует только ограниченные redacted browser
artifacts. CI quality gate #55 прошёл: 680 backend тестов / 8 341 assertions и
26 browser E2E. Следующая задача — `DR-E7-030`, ADR-015 dependency/architecture
audit для новых модулей. Полный SC-022, deployment review и решение Product
Owner открыты.

## Состояние после DR-E7-030 — 2026-10-15

Завершён dependency/architecture audit по ADR-015 для Connects, Invitations,
Debts и Transfers. Межмодульные вызовы идут только через опубликованные
Application\Api; нарушений границ Domain/Adapter/Persistence не найдено.
ModuleDependencyTest теперь явно закрепляет наличие каждого audited module и
всех трёх слоёв в области проверки. CI quality gate #58 прошёл: 681 backend
тест / 8 365 assertions и 26 browser E2E. Следующая незавершённая задача —
DR-E7-042, повторить make check-full по текущему основному пути. Полный
SC-022, deployment review и решение Product Owner остаются открытыми.

## Состояние после DR-E7-042 — 2026-10-15

Текущий основной путь проверен полным gate в CI #59: 681 backend тест /
8 365 assertions, frontend quality и build, dependency audit, smoke и 26
изолированных browser E2E прошли. Локальный make check-full дошёл до
check-ci-config.sh и остановился из-за отсутствия Docker CLI в WSL; CI выполнил
полный набор успешно. Следующая незавершённая задача — DR-E7-045, проверить
обязательные deployment services. Полный SC-022, deployment review и решение
Product Owner остаются открытыми.

## Состояние после DR-E7-045 — 2026-10-15

Подтверждены семь обязательных Compose services: nginx, frontend, backend,
PostgreSQL, Redis, RabbitMQ и worker; README/runbook описывают тот же набор.
Добавлен отдельный CI guard, который проверяет resolved service list. CI #65
прошёл: 681 backend test / 8 335 assertions и 26 browser E2E, а Repository
quality подтвердил новый service guard. Локальный Docker Compose запуск
недоступен в WSL без Docker CLI. Следующий пункт — DR-E7-046, разделить
development defaults и production-required secrets/key rings. Проверки
production deployment, health/readiness и graceful worker restart остаются
открытыми.

## Состояние после DR-E7-046 — 2026-10-15

Development values оставлены только в явно помеченном local-only .env.example.
Compose требует явной передачи APP_SECRET, DB/broker credentials, runtime URLs,
SMTP DSN и всех криптографических key rings; в Compose удалены inline
credential/key defaults. Операционный runbook перечисляет production-required
переменные, а приложение валидирует key formats при создании codec services.
CI #68 прошёл: 681 backend test / 8 335 assertions и 26 browser E2E. Локальная
Compose validation недоступна без Docker CLI в WSL; production deployment ещё
не упражнялся. Следующая задача — DR-E7-047, проверить healthchecks, startup
dependencies и graceful worker restart.

## Состояние после DR-E7-047 — 2026-10-15

Основной Compose stack теперь проверяет PHP-FPM ping, API `/api/health`, frontend
и инфраструктурные сервисы отдельными healthchecks; зависимости backend, Nginx и
worker запускаются после healthy dependencies. Worker пересылает SIGTERM
активному relay/consumer, Messenger consumer перезапускается каждые пять секунд,
Compose ждёт остановку до 90 секунд. Compose guard включён в
Repository quality. CI #71 выявил задержку outbox relay при 60-секундном
consumer; интервал восстановлен до пяти секунд и закреплён guard-проверкой.
CI #72 прошёл полностью: 681 backend test / 8 335 assertions, frontend build,
smoke и 26 browser E2E — [workflow](https://github.com/r3ct4lan/equo_3/actions/runs/38659025957).
Следующая
незавершённая задача roadmap — DR-E7-052, финальная traceability-проверка Must
have; production deployment и Product Owner acceptance остаются открытыми.

## Состояние после DR-E7-052 — 2026-10-15

Traceability matrix в roadmap сверена с каталогом из 16 `Must have`: каждый
сценарий теперь имеет технический статус `[x]` и ссылку на focused acceptance
или текущий полный CI. Проверка основана на main snapshot `1fee70c` и workflow
[#73](https://github.com/r3ct4lan/equo_3/actions/runs/38659998867). Этот checkbox
фиксирует техническую проверку сценария; отдельное решение Product Owner и
производственная готовность не подразумеваются. Следующая задача — DR-E7-053:
доказать все 13 критериев готовности из `mvp-scope.md`.

## Состояние после DR-E7-053 — 2026-10-15

Для всех 13 критериев раздела 16 `mvp-scope.md` собрана сводная матрица тестов
и документированных операционных проверок в
[акте MVP readiness](acceptance/mvp-readiness.md). Полный Quality gate #18 на
PR #18 прошёл: 681 backend test / 8 335 assertions и 26 browser E2E. Матрица
ограничивает operational доказательство изолированным development Compose;
production deployment, external SMTP, выбор backup/RPO/RTO и решение Product
Owner остаются отдельными открытыми вопросами. DR-E7-053 закрывает наличие
доказательств, но не launch blockers: следующая задача — DR-E7-054, проверить,
остались ли открытые блокеры раздела 16.
