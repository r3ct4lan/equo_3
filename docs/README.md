# Документация Equo

| Поле | Значение |
|---|---|
| Название | Индекс проектной документации Equo |
| Назначение | Обеспечить единый вход в комплект проектных артефактов и правила их актуализации |
| Статус | Accepted |
| Версия | 45 |
| Дата актуальности | 2026-10-15 |
| Владелец | Maksim Smolkov |
| Источник | Нормативная документация и фактическая реализация MVP |

В этом каталоге хранится документация, необходимая для сопровождения и дальнейшей разработки MVP: термины предметной области, бизнес-правила, модель данных, API-контракты, архитектурные решения, границы и дорожная карта MVP, фактическая структура приложения, модель доступа, quality gate и журнал принятых решений. Временные материалы анализа завершённых этапов в репозитории не хранятся; запрошенные технические акты приёмки размещаются в `acceptance/`, а актуальный прогресс реализации фиксируется в дорожной карте.

## Индекс артефактов

Перед автономной разработкой читайте [продуктовый контекст](product-context.md)
и [правила работы](../AGENTS.md). Контекст передачи сверён 2026-10-09;
актуальные требования остаются в нормативных артефактах ниже.

| Артефакт | Краткое описание | Статус | Когда обновлять |
|---|---|---|---|
| [Глоссарий предметной области](glossary/glossary.md) | Канонические термины предметной области и их значения | Финальная согласованная версия этапа проектирования | Появился, переименован, исключён термин или изменилось его значение |
| [Бизнес-правила](business-rules/business-rules.md) | Нормативные правила MVP со стабильными идентификаторами `BR-*` | Финальная согласованная версия этапа проектирования | Изменились поведение продукта, инварианты, права, жизненный цикл или границы MVP |
| [Модель сущностей](data-model/entities.md) | Сущности, поля, связи, ограничения целостности и транзакционные границы | Финальная согласованная версия этапа проектирования | Изменилась персистентная модель, жизненный цикл, связь, ограничение или транзакционная граница |
| [ER-диаграмма](data-model/er-diagram.md) | Mermaid-описание и подробные сведения о ключах, ограничениях и кардинальностях | Финальная согласованная версия этапа проектирования | Изменились сущность, поле, nullable-состояние, ключ, ограничение или кардинальность |
| [Реализованная начальная схема](data-model/implemented-initial-schema.md) | Фактические таблицы, Doctrine records, ограничения и отложенная часть ER-модели | Accepted | Изменились реализованные таблицы, mapping, миграции, ограничения или граница текущей схемы |
| [ER-диаграмма — DOT](data-model/er-diagram.dot) | Редактируемый исходник Graphviz | Финальная согласованная версия этапа проектирования | Изменилась ER-диаграмма; SVG и PNG нужно пересоздать в том же изменении |
| [ER-диаграмма — SVG](data-model/er-diagram.svg) | Масштабируемая отображаемая версия | Финальная согласованная версия этапа проектирования | Изменился DOT-исходник |
| [ER-диаграмма — PNG](data-model/er-diagram.png) | Растровая отображаемая версия | Финальная согласованная версия этапа проектирования | Изменился DOT-исходник |
| [HTTP-контракты](api/http-contracts.md) | Маршруты, данные, ошибки, идемпотентность, конкурентность и пагинация публичного API v1 | Финальная согласованная версия этапа проектирования | Изменился публичный endpoint, данные, ошибка, заголовок, правило аутентификации или HTTP-поведение |
| [Реализация HTTP-слоя](api/http-implementation.md) | Фактические Symfony-механизмы JSON, DTO validation, serialization, request ID и standard errors после E1-09 | Accepted | Изменились HTTP subscribers, Serializer/Validator config, error classification или правила response DTO |
| [Модель доступа MVP](security/access-model.md) | Реализованные публичные субъекты, capability-based activation policy, password hashing, refresh rotation, Bearer `/me` и принятые token/JWT profiles | Accepted | Изменились правила доступа, token lifecycle, password hashing, authentication profile или security-конфигурация |
| [Frontend-фундамент](frontend-foundation.md) | Фактическая Nuxt-структура, API client, `$auth`, error/session/form state и UI-правила после frontend session lifecycle | Accepted | Изменились frontend-каталоги, runtime API config, общие типы/состояния, маршрутизация или правила форм |
| [Login и защищённая сессия](features/login-protected-session.md) | Contract audit, технический дизайн и progress `MVP-SC-002` для login, refresh, JWT, browser session lifecycle и `/me` | Implemented, verified and merged | Изменились нормативные auth contracts, выбранные зависимости, session lifecycle или план реализации `DR-E1-001—053` |
| [Выход и отзыв сессий](features/logout-session-revocation.md) | Реализация и прогресс `MVP-SC-003`: endpoints, local cleanup, concurrency и E2E | Merged; functional PASS; release audit blocked | Изменились contracts, lifecycle, OQ-022—024, этапы SC3-02—08 или устранён release blocker |
| [Приёмка выхода и отзыва сессий](acceptance/logout-session-revocation.md) | Свежая матрица доказательств SC-003, проверок и ограничений | Functional PASS; release BLOCKED | Повторены проверки, устранён audit blocker или принято решение владельца |
| [Восстановление забытого пароля](features/password-reset.md) | Контракт, этапы SC4-01—06 и фактическая реализация MVP-SC-004 | Merged; functional PASS; release audit blocked | Изменился reset lifecycle или результат проверки |
| [Приёмка восстановления пароля](acceptance/password-reset.md) | Доказательства SC-004, browser journeys, конкурентность и ограничения | Functional PASS; release BLOCKED | Повторены проверки или устранён blocker |
| [Безопасная смена пароля](features/password-change.md) | Contract audit, этапы SC5-01—06 и реализация MVP-SC-005 | Merged; functional PASS; release audit blocked | Изменились password change или результаты проверок |
| [Приёмка смены пароля](acceptance/password-change.md) | Доказательства SC-005, две browser sessions, concurrency и ограничения | Functional PASS; release BLOCKED | Повторены проверки или устранён blocker |
| [Приглашение и постоянный Connect](features/connect-invitations.md) | Контракты, этапы SC8-01—06 и реализация SC-008 | Merged; functional PASS; release audit blocked | Изменился invitation lifecycle или Connect scope |
| [Приёмка приглашений и Connect](acceptance/connect-invitations.md) | Два пользователя, idempotency, concurrency, privacy и итоговые проверки | Functional PASS; release BLOCKED | Повторены проверки или устранён blocker |
| [Общий расход, доли, баланс и история](features/create-debt.md) | SC-011, read models SC-009/012/015 и этапы SC11-01—06 | Merged; functional PASS; release audit blocked | Изменились финансовый scope или результат проверки |
| [Приёмка финансового этапа](acceptance/create-debt.md) | Atomicity, real DB races, ledger, ACL и browser путь двух сторон/third-user denial | Functional PASS; release BLOCKED | Повторены проверки или устранён blocker |
| [Ручной перевод](features/manual-transfer.md) | SC-016, этапы SC16-01—05, atomic MANUAL writer, form/retry и server refresh | Merged; functional PASS; release audit blocked | Изменился MANUAL scope или результат проверки |
| [Приёмка ручного перевода](acceptance/manual-transfer.md) | Both directions, overpayment, real races, основной browser путь и safe diagnostics | Functional PASS; release BLOCKED | Повторены проверки, устранён blocker или принято решение владельца |
| [Исправление долга](features/update-debt.md) | SC-013, этапы SC13-01—06, atomic recalculation, edit UI и If-Match | Merged; functional PASS; release audit blocked | Изменился update scope или результат проверки |
| [Приёмка исправления долга](acceptance/update-debt.md) | Stable identities, шесть rollback points, четыре races, stale editor и три browser accounts | Functional PASS; release BLOCKED | Повторены проверки, устранён blocker или принято решение владельца |
| [Retention и очистка](features/retention-operations.md) | SC-022 retention matrix, bounded cleanup command, daily schedule, safe summary and deployment alert thresholds | Retention/email slices implemented and verified; stage 7 in progress | Изменились retention requirements, cleanup behavior or operational evidence |
| [Приёмка retention](acceptance/retention-operations.md) | Backend tests, migration/schema verification, protected-route auth/ACL/privacy, inactive-user, idempotency, ETag/If-Match, transaction rollback, DB uniqueness, credential-redaction, ADR-015 dependency, deployment-service and environment/key-ring matrices, readiness/dependency/worker shutdown checks | Retention slice, DR-E7-021—030, DR-E7-042 and DR-E7-045—047 PASS; two npm advisories temporarily accepted; full SC-022 open | Повторены проверки или закрыты оставшиеся stage-7 requirements |
| [Операции email и retention](operations/email-delivery.md) | Запуск сервисов, health/readiness, graceful worker restart, безопасная диагностика/failure replay, key rotation, deployment alert thresholds and retention operations | Implemented; deployment values and production evidence remain open | Изменились deployment commands, retry/replay procedure or operations evidence |
| [Quality gate и CI](quality-gate.md) | Единые local/CI команды, style/static analysis, test DB, browser E2E, audits, smoke, Compose readiness/dependency checks и GitHub Actions | Accepted; два npm GHSA временно allowlisted до исправлений | Изменились quality tools, Make/scripts, CI jobs, audit policy или test infrastructure |
| [Приёмка повторного запроса активации](acceptance/activation-resend.md) | Сквозное доказательство replacement semantics, neutral 202, rate limit и security regressions для `MVP-SC-001` | Passed | Повторена приёмка или Product Owner принял итоговое решение |
| [Architecture Decision Records](adr/architecture-decisions.md) | Принятые ADR-001—ADR-019 | Accepted | Решение заменяется новым или выполнено условие его пересмотра; историю следует сохранять, добавляя новый ADR |
| [Границы MVP](mvp-scope.md) | Цель, каталог `MVP-SC-*`, классификация, основной путь, зависимости и критерии запуска E1-04 | Accepted | Изменилась цель MVP, категория сценария, обязательный путь, критерий запуска или решение о границе |
| [Дорожная карта разработки MVP](development-roadmap.md) | Семь последовательных этапов, стабильные task ID, checklists, Definition of Ready/Done, post-MVP backlog и traceability всех сценариев | Active | Завершена задача или этап, изменились зависимости/порядок, добавлена принятая feature либо изменились критерии реализации/приёмки |
| [Структура backend](backend-structure.md) | Фактические модули, namespace, service discovery и допустимые зависимости после E1-07 | Accepted | Изменились модульные границы, каталоги, namespace, правила зависимостей или регистрация сервисов |
| [Открытые вопросы](open-questions.md) | Единый журнал `OQ-*`; все 24 вопроса разрешены | Accepted | Обнаружен подтверждённый вопрос, принято или пересмотрено решение либо изменился связанный `CONS-*` |

## Правила ведения

- Считайте бизнес-правила и принятые ADR нормативными. Не устраняйте смысловые расхождения молча за счёт редактирования другого артефакта.
- Сохраняйте стабильность идентификаторов требований `BR-*` и решений `ADR-*`.
- Обновляйте редактируемый DOT-исходник и обе отображаемые версии ER-диаграммы вместе.
- Фиксируйте нерешённые противоречия, отсутствующие метаданные, дубли и заменённые версии в [open-questions.md](open-questions.md).
- Отмечайте checkbox в [дорожной карте](development-roadmap.md) только после реализации и проверки соответствующей задачи; нормативное поведение меняйте сначала в профильном артефакте.
