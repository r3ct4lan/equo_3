# Документация Equo

| Поле | Значение |
|---|---|
| Название | Индекс проектной документации Equo |
| Назначение | Обеспечить единый вход в комплект проектных артефактов и правила их актуализации |
| Статус | Accepted |
| Версия | 11 |
| Дата актуальности | 2026-08-02 |
| Владелец | Maksim Smolkov |
| Источник | Project documentation; инвентаризация `equo-artifacts-final.zip`, результаты E1-01—E1-14 и решение OQ-018 |

В этом каталоге хранится канонический комплект проектных артефактов, систематизированный в E1-01, а также результаты E1-02—E1-14 и закрытия Block A. Используйте этот индекс для поиска терминов предметной области, бизнес-ограничений, модели данных, API-контрактов, архитектурных решений, границ MVP, первого вертикального среза, его критериев приёмки, реализаций backend/frontend, quality gate и истории принятых решений.

## Индекс артефактов

| Артефакт | Краткое описание | Статус | Когда обновлять |
|---|---|---|---|
| [Глоссарий предметной области](glossary/glossary.md) | Канонические термины предметной области и их значения | Финальная согласованная версия этапа проектирования | Появился, переименован, исключён термин или изменилось его значение |
| [Бизнес-правила](business-rules/business-rules.md) | Нормативные правила MVP со стабильными идентификаторами `BR-*` | Финальная согласованная версия этапа проектирования | Изменились поведение продукта, инварианты, права, жизненный цикл или границы MVP |
| [Модель сущностей](data-model/entities.md) | Сущности, поля, связи, ограничения целостности и транзакционные границы | Финальная согласованная версия этапа проектирования | Изменилась персистентная модель, жизненный цикл, связь, ограничение или транзакционная граница |
| [ER-диаграмма](data-model/er-diagram.md) | Mermaid-описание и подробные сведения о ключах, ограничениях и кардинальностях | Финальная согласованная версия этапа проектирования | Изменились сущность, поле, nullable-состояние, ключ, ограничение или кардинальность |
| [Реализованная начальная схема](data-model/implemented-initial-schema.md) | Фактические таблицы, Doctrine records, ограничения и отложенная часть ER-модели после E1-08 | Accepted | Изменились реализованные таблицы, mapping, миграции, ограничения или граница схемы первого среза |
| [ER-диаграмма — DOT](data-model/er-diagram.dot) | Редактируемый исходник Graphviz | Финальная согласованная версия этапа проектирования | Изменилась ER-диаграмма; SVG и PNG нужно пересоздать в том же изменении |
| [ER-диаграмма — SVG](data-model/er-diagram.svg) | Масштабируемая отображаемая версия | Финальная согласованная версия этапа проектирования | Изменился DOT-исходник |
| [ER-диаграмма — PNG](data-model/er-diagram.png) | Растровая отображаемая версия | Финальная согласованная версия этапа проектирования | Изменился DOT-исходник |
| [HTTP-контракты](api/http-contracts.md) | Маршруты, данные, ошибки, идемпотентность, конкурентность и пагинация публичного API v1 | Финальная согласованная версия этапа проектирования | Изменился публичный endpoint, данные, ошибка, заголовок, правило аутентификации или HTTP-поведение |
| [Реализация HTTP-слоя](api/http-implementation.md) | Фактические Symfony-механизмы JSON, DTO validation, serialization, request ID и standard errors после E1-09 | Accepted | Изменились HTTP subscribers, Serializer/Validator config, error classification или правила response DTO |
| [Модель доступа первого среза](security/access-model.md) | Публичные субъекты, capability-based activation policy, password hashing и принятые token/JWT profiles после E1-10 | Accepted | Изменились правила доступа, token lifecycle, password hashing, authentication profile или security-конфигурация |
| [Frontend-фундамент](frontend-foundation.md) | Фактическая Nuxt-структура, API client, error/session/form state и UI-правила после E1-11; принятый session profile ADR-018 | Accepted | Изменились frontend-каталоги, runtime API config, общие типы/состояния, маршрутизация или правила форм |
| [Quality gate и CI](quality-gate.md) | Единые local/CI команды, style/static analysis, test DB, audits, smoke и GitHub Actions после E1-12 | Accepted; first CI run pending | Изменились quality tools, Make/scripts, CI jobs, audit policy или test infrastructure |
| [Architecture Decision Records](adr/architecture-decisions.md) | Принятые архитектурные решения ADR-001 — ADR-018 | Accepted | Решение заменяется новым или выполнено условие его пересмотра; историю следует сохранять, добавляя новый ADR |
| [Сквозная проверка согласованности E1-02](consistency-review.md) | Матрица трассируемости, история `CONS-*` и итог закрытия Block A | Завершено | Изменился любой нормативный артефакт, обнаружено новое расхождение либо пересматривается заключение Block A |
| [Границы MVP](mvp-scope.md) | Цель, каталог `MVP-SC-*`, классификация, основной путь, зависимости и критерии запуска E1-04 | Accepted | Изменилась цель MVP, категория сценария, обязательный путь, критерий запуска или решение о границе |
| [Первый вертикальный срез](first-vertical-slice.md) | Выбор `MVP-SC-001`, сравнительная матрица, границы среза и входные данные для E1-06 | Accepted | Изменился первый срез, его границы, зависимости или обоснование выбора |
| [Критерии приёмки первого среза](first-vertical-slice-acceptance.md) | 61 проверяемый критерий E1-06, тестовые данные, трассируемость, автоматизация и smoke-набор | Accepted | Изменилось ожидаемое поведение первого среза, нормативный источник, тестовая граница или набор блокирующих проверок |
| [Backend первого вертикального среза](first-vertical-slice-backend-implementation.md) | Фактический поток регистрации и активации, правила, persistence, доставка email, трассировка и проверки после E1-13 | Implemented | Изменились endpoint, application/domain flow, persistence, доступ, outbox или тестовое покрытие первого среза |
| [Frontend первого вертикального среза](first-vertical-slice-frontend-implementation.md) | Фактические registration/activation routes, UI-состояния, idempotency attempt, error mapping и проверки после E1-14 | Implemented | Изменились страницы первого среза, UI-контракт, retry/idempotency, доступность или frontend-тесты |
| [Структура backend](backend-structure.md) | Фактические модули, namespace, service discovery и допустимые зависимости после E1-07 | Accepted | Изменились модульные границы, каталоги, namespace, правила зависимостей или регистрация сервисов |
| [Отчёт согласованности](reviews/consistency-report.md) | Исторический отчёт из исходного архива, заменённый проверкой E1-02 | Superseded | Только при исправлении метаданных или исторической ссылки; актуальные выводы ведутся в E1-02 |
| [Открытые вопросы](open-questions.md) | Единый журнал `OQ-*`; все 18 вопросов разрешены | Accepted | Обнаружен подтверждённый вопрос, принято или пересмотрено решение либо изменился связанный `CONS-*` |

## Инвентаризация источника

Все десять записей из `equo-artifacts-final.zip` сохранены:

| Запись в архиве | Расположение в репозитории |
|---|---|
| `equo-00-consistency-report.md` | [reviews/consistency-report.md](reviews/consistency-report.md) |
| `equo-01-glossary.md` | [glossary/glossary.md](glossary/glossary.md) |
| `equo-02-business-rules.md` | [business-rules/business-rules.md](business-rules/business-rules.md) |
| `equo-03-entities.md` | [data-model/entities.md](data-model/entities.md) |
| `equo-04-er-diagram.dot` | [data-model/er-diagram.dot](data-model/er-diagram.dot) |
| `equo-04-er-diagram.md` | [data-model/er-diagram.md](data-model/er-diagram.md) |
| `equo-04-er-diagram.png` | [data-model/er-diagram.png](data-model/er-diagram.png) |
| `equo-04-er-diagram.svg` | [data-model/er-diagram.svg](data-model/er-diagram.svg) |
| `equo-05-http-contracts.md` | [api/http-contracts.md](api/http-contracts.md) |
| `equo-06-adr.md` | [adr/architecture-decisions.md](adr/architecture-decisions.md) |

## Правила ведения

- Считайте бизнес-правила и принятые ADR нормативными. Не устраняйте смысловые расхождения молча за счёт редактирования другого артефакта.
- Сохраняйте стабильность идентификаторов требований `BR-*` и решений `ADR-*`.
- Обновляйте редактируемый DOT-исходник и обе отображаемые версии ER-диаграммы вместе.
- Фиксируйте нерешённые противоречия, отсутствующие метаданные, дубли и заменённые версии в [open-questions.md](open-questions.md).
