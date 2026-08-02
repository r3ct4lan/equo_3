# Equo — критерии приёмки первого вертикального среза (E1-06)

| Поле | Значение |
|---|---|
| Название | Equo — критерии приёмки первого вертикального среза |
| Назначение | Зафиксировать однозначное и проверяемое поведение первичной регистрации и активации по первому email |
| Статус | Accepted |
| Версия | 4 |
| Дата актуальности | 2026-08-02 |
| Владелец | Maksim Smolkov |
| Исходный сценарий | [`MVP-SC-001`](mvp-scope.md#mvp-sc-001) |
| Первый срез | [Первичная регистрация с активацией по первому email](first-vertical-slice.md) |
| Источник | Канонический комплект Block A, E1-04 и E1-05 в Git-ревизии `d690e5a54b7c123137f850804ea3129fc20afe20` |

## 1. Назначение документа

Документ задаёт критерии приёмки выбранного в E1-05 среза:

```text
экран регистрации и активации
    → POST /api/v1/auth/register и POST /api/v1/auth/activate
    → правила User/UserActionToken/email outbox
    → PostgreSQL
```

Критерии описывают наблюдаемое поведение UI и API, бизнес-результат и проверяемое состояние данных. Они достаточны для технической подготовки backend, frontend и тестов, но не задают внутреннюю структуру классов, визуальный дизайн или реализацию тестов.

При конфликте этого документа с нормативными бизнес-правилами, моделью данных, HTTP-контрактами или принятым ADR действует нормативный источник; расхождение требует отдельного решения, а не молчаливого изменения критерия.

## 2. Использованные источники

### 2.1. Нормативные источники

| Источник | Версия и статус | Использование |
|---|---|---|
| [Бизнес-правила](business-rules/business-rules.md) | 2 от 2026-07-31, Accepted | Регистрация, активация, password policy, token lifecycle, идемпотентность, транзакции, email и rate limits |
| [Модель сущностей](data-model/entities.md) | 2 от 2026-07-31, Accepted | Поля и инварианты `User`, `UserActionToken`, `IdempotencyRecord`, `EmailDeliveryOutbox` |
| [ER-модель](data-model/er-diagram.md) | 1 от 2026-07-27, Accepted | PK/FK, `NOT NULL`, `CHECK`, `UNIQUE`, частичные индексы и кардинальности |
| [HTTP-контракты](api/http-contracts.md) | 4 от 2026-07-31, Accepted | Форматы запросов и ответов, ошибки, идемпотентность, rate limits и транзакционность |
| [ADR-001—ADR-019](adr/architecture-decisions.md) | 5 от 2026-08-02, Accepted | Модульный монолит, account lifecycle, action/access/refresh token profiles, защищённый idempotency fingerprint, границы БД, HTTP, outbox и операционный профиль |

### 2.2. Справочные и контрольные источники

- [Глоссарий](glossary/glossary.md) — канонические термины.
- [Границы MVP E1-04](mvp-scope.md) — `MVP-SC-001` как `Must have`, основной путь и зависимости.
- [Первый вертикальный срез E1-05](first-vertical-slice.md) — выбранный вариант, его границы и исключения.
- [Сквозная проверка E1-02](consistency-review.md) — завершение Block A и согласованность нормативного комплекта.
- [Журнал решений](open-questions.md) — 20 вопросов `Resolved`, активных вопросов нет.
- [Корневой README](../README.md) и [индекс документации](README.md) — стек и состав артефактов.

Исторический [отчёт согласованности](reviews/consistency-report.md) имеет статус `Superseded` и не является нормативным.

## 3. Контекст сценария

| Поле | Зафиксированное значение |
|---|---|
| Идентификатор | [`MVP-SC-001`](mvp-scope.md#mvp-sc-001) |
| Название среза | Первичная регистрация с активацией по первому email |
| Основная роль | Незарегистрированный посетитель, затем предъявитель activation token для созданного неактивного аккаунта |
| Пользовательская цель | Создать аккаунт и подтвердить email, чтобы аккаунт стал активным |
| Инициирующее действие | Отправка формы с `name`, `email`, `password` и новым `Idempotency-Key` |
| Наблюдаемый результат | UI подтверждает активацию; `User.isActive = true`, у применённого `UserActionToken` заполнен `usedAt`; сессия не создана |
| Используемые операции | [`POST /api/v1/auth/register`](api/http-contracts.md#81-регистрация), [`POST /api/v1/auth/activate`](api/http-contracts.md#83-активация-аккаунта) |
| Сущности | [`User`](data-model/entities.md#1-user), [`UserActionToken`](data-model/entities.md#10-useractiontoken), [`IdempotencyRecord`](data-model/entities.md#11-idempotencyrecord), [`EmailDeliveryOutbox`](data-model/entities.md#12-emaildeliveryoutbox) |
| Права | Оба endpoint публичны; регистрация ограничена validation/idempotency/rate limits, активация — владением действующим purpose-bound token |

Применяются [BR-USR-001—003](business-rules/business-rules.md#br-usr-001-регистрация), [BR-USR-009](business-rules/business-rules.md#br-usr-009-единая-политика-паролей), [BR-IDEM-001—005](business-rules/business-rules.md#br-idem-001-общий-механизм), [BR-TXN-001—002](business-rules/business-rules.md#br-txn-001-межтабличные-инварианты), [BR-SEC-003—007](business-rules/business-rules.md#br-sec-003-useractiontoken) и связанные [ADR-006](adr/architecture-decisions.md#adr-006-жизненный-цикл-аккаунта-и-сессий), [ADR-007](adr/architecture-decisions.md#adr-007-унифицированные-одноразовые-токены), [ADR-009](adr/architecture-decisions.md#adr-009-централизованная-идемпотентность), [ADR-011](adr/architecture-decisions.md#adr-011-разделение-ответственности-бд-и-доменного-слоя), [ADR-012](adr/architecture-decisions.md#adr-012-границы-и-http-соглашения-mvp), [ADR-013](adr/architecture-decisions.md#adr-013-надёжная-доставка-email-через-transactional-outbox-и-rabbitmq), [ADR-014](adr/architecture-decisions.md#adr-014-нормативный-операционный-профиль-безопасности-и-хранения-mvp).

Явно исключены login и `UserSession`, повторный запрос activation link, password reset, изменение email, admin-функции, финансовые сущности, production-provider-specific email API и полный retention cleanup. Полная карта исключений дана в [E1-05](first-vertical-slice.md#15-не-входит-в-первый-срез) и в разделе 16 ниже.

## 4. Предусловия и состояния данных

### 4.1. Предусловия

- Нормализованный email тестового пользователя отсутствует в `User`.
- Браузер не имеет access token и refresh cookie.
- Приложение, PostgreSQL, Redis, RabbitMQ, worker и настроенный SMTP transport доступны.
- В локальной демонстрации SMTP transport направлен в Mailpit.
- Сервер располагает ключами для хэширования action token и шифрования временного outbox payload; секреты находятся вне БД.
- Время тестовой среды управляемо для TTL, retry и sliding-window проверок либо заменено контролируемым источником времени на уровне теста.

### 4.2. Начальное и конечное состояния

| Момент | Проверяемое состояние |
|---|---|
| До регистрации | Нет `User` с нормализованным email; нет связанных token/outbox/idempotency записей |
| После `201` | Созданы `User.isActive = false`, один незавершённый `ACTIVATE_ACCOUNT`, один связанный `EmailDeliveryOutbox` с durable intent и завершённый результат `IdempotencyRecord`; сессии нет |
| После доставки | Outbox имеет `SENT` и `sentAt`, encrypted payload очищен; письмо содержит применимый открытый token, который в БД отсутствует в открытом виде |
| После `204` активации | `User.isActive = true`, у token заполнен `usedAt`, `invalidatedAt = null`; остальные данные пользователя не изменены; сессии нет |

## 5. Основной успешный путь

| Шаг | Роль и действие | Проверка и результат |
|---:|---|---|
| 1 | Посетитель открывает регистрацию | UI показывает пустые обязательные поля и доступное действие отправки |
| 2 | Посетитель вводит валидные `name`, `email`, `password` | Frontend выполняет только пользовательскую validation; backend остаётся авторитетным источником правил |
| 3 | UI отправляет регистрацию с новым `Idempotency-Key` | Backend проверяет JSON, обязательные поля, rate limits, password policy, нормализует email и проверяет уникальность |
| 4 | Backend фиксирует результат | В одной согласованной транзакции появляются `User`, `ACTIVATE_ACCOUNT`, `EmailDeliveryOutbox`, `IdempotencyRecord`; API возвращает `201` и `activationRequired = true` |
| 5 | UI отображает промежуточный результат | Пользователь видит, что аккаунт создан неактивным и требуется открыть email; login не выполняется |
| 6 | Relay и consumer доставляют письмо | Outbox публикуется через RabbitMQ, consumer повторно проверяет token и синхронно вызывает Mailer; в Mailpit видно первое письмо |
| 7 | Пользователь открывает activation link | UI извлекает token только для выполнения активации и вызывает `POST /auth/activate` |
| 8 | Backend применяет token | Проверяются hash, purpose, expiry, `usedAt`, `invalidatedAt`; атомарно меняются `User.isActive` и `UserActionToken.usedAt` |
| 9 | API и UI подтверждают результат | API возвращает `204`; UI показывает успешную активацию и предлагает отдельно войти |
| 10 | Результат повторно подтверждается | Повторное применение даёт `410 TOKEN_USED`; БД содержит активного пользователя и использованный token без `UserSession` |

Основной путь покрывают `AC-001—005`, а его критические инварианты — `AC-010`, `AC-020`, `AC-022—030`, `AC-038—040`, `AC-046—050`, `AC-056` и `AC-061`.

## 6. Критерии приёмки

Соглашения об уровнях автоматизации:

- `UT` — unit-тест бизнес-правила;
- `INT` — интеграционный тест приложения/БД/очереди;
- `API` — функциональный тест HTTP API;
- `FE` — компонентный тест frontend;
- `E2E` — сквозной тест UI → API → бизнес-логика → БД;
- `MAN` — ручная демонстрационная проверка.

### 6.1. Успешный пользовательский результат

#### AC-001. Полный путь регистрации и активации

- **Тип:** успешный E2E. **Приоритет:** `Critical`. **Предусловия:** раздел 4, валидный `TD-01`. **Автоматизация:** `E2E + INT + MAN`.

```gherkin
Дано нормализованный email отсутствует в User
И посетитель не аутентифицирован
Когда он регистрируется с валидными данными, открывает первое доставленное письмо и применяет activation token
Тогда UI последовательно сообщает о необходимости активации и об успешной активации
И User переходит из isActive=false в isActive=true
И применённый UserActionToken получает usedAt
И UserSession не создаётся
```

- **UI:** состояния registration loading → activation required → activation loading → activated.
- **API:** `POST /auth/register` → `201`; `POST /auth/activate` → `204`.
- **Данные:** ровно один согласованный набор `User + ACTIVATE_ACCOUNT + outbox + idempotency`; затем атомарный переход активности.
- **Основание:** [BR-USR-001—003](business-rules/business-rules.md#br-usr-001-регистрация), [BR-SEC-006](business-rules/business-rules.md#br-sec-006-надёжная-постановка-email), [registration/activation contracts](api/http-contracts.md#81-регистрация), [ADR-001](adr/architecture-decisions.md#adr-001-архитектура-mvp-как-модульный-монолит), [E1-05 demo](first-vertical-slice.md#16-предварительный-сценарий-демонстрации).

#### AC-002. Успешная регистрация публичного посетителя

- **Тип:** успешный API. **Приоритет:** `Critical`. **Предусловия:** `TD-01`, новый ключ. **Автоматизация:** `API + INT`.

```gherkin
Дано посетитель не имеет Authorization и refresh cookie
И email после trim и lowercase уникален
Когда он отправляет POST /api/v1/auth/register с валидными name, email, password и Idempotency-Key
Тогда API отвечает 201
И тело содержит user с UUID, введённым name, нормализованным email, isActive=false, createdAt в UTC
И activationRequired равно true
```

- **UI:** после loading показывает семантическое сообщение «проверьте email для активации».
- **API:** тело точно соответствует успешному контракту и не содержит `passwordHash`, token или session.
- **Данные:** создан неактивный `User`; связанные durable records зафиксированы.
- **Основание:** [BR-USR-001/003](business-rules/business-rules.md#br-usr-001-регистрация), [HTTP 8.1](api/http-contracts.md#81-регистрация), [ER User](data-model/er-diagram.md#7-локальные-check-ограничения), [ADR-012](adr/architecture-decisions.md#adr-012-границы-и-http-соглашения-mvp).

#### AC-003. Наблюдаемая доставка первого activation email

- **Тип:** успешная интеграция. **Приоритет:** `Critical`. **Предусловия:** успешный `AC-002`, доступные broker/worker/SMTP. **Автоматизация:** `INT + E2E + MAN`.

```gherkin
Дано регистрация зафиксировала действующий ACTIVATE_ACCOUNT и PENDING outbox
Когда relay и consumer успешно обрабатывают задание
Тогда первое activation email передано SMTP transport
И локальная демонстрация показывает письмо в Mailpit
И ссылка содержит открытый token, соответствующий хэшу текущего ACTIVATE_ACCOUNT
```

- **UI:** registration success не блокируется ожиданием SMTP.
- **API:** уже возвращённый `201` не меняется и не ожидает доставку.
- **Данные:** outbox достигает `SENT`, имеет `sentAt`, payload очищен; token остаётся незавершённым.
- **Основание:** [BR-SEC-006](business-rules/business-rules.md#br-sec-006-надёжная-постановка-email), [HTTP 18](api/http-contracts.md#18-транзакционность-команд), [ADR-013](adr/architecture-decisions.md#adr-013-надёжная-доставка-email-через-transactional-outbox-и-rabbitmq).

#### AC-004. Успешное применение activation token

- **Тип:** успешный API/переход состояния. **Приоритет:** `Critical`. **Предусловия:** текущий неистёкший `ACTIVATE_ACCOUNT`, неактивный `User`. **Автоматизация:** `API + INT`.

```gherkin
Дано token существует, имеет purpose ACTIVATE_ACCOUNT, не истёк, не использован и не аннулирован
И связанный User имеет isActive=false
Когда token отправлен в POST /api/v1/auth/activate
Тогда API отвечает 204 без тела
И User.isActive становится true
И UserActionToken.usedAt заполняется серверным временем
```

- **UI:** показывает успешную активацию и следующее отдельное действие «войти».
- **API:** `204 No Content`, без access/refresh token.
- **Данные:** переход пользователя и token фиксируется атомарно.
- **Основание:** [BR-USR-002](business-rules/business-rules.md#br-usr-002-первичная-активация), [BR-SEC-003](business-rules/business-rules.md#br-sec-003-useractiontoken), [HTTP 8.3](api/http-contracts.md#83-активация-аккаунта), [ADR-006/007](adr/architecture-decisions.md#adr-006-жизненный-цикл-аккаунта-и-сессий).

#### AC-005. Активация не создаёт аутентифицированную сессию

- **Тип:** безопасность/успешный результат. **Приоритет:** `High`. **Предусловия:** успешный `AC-004`. **Автоматизация:** `API + INT + FE`.

```gherkin
Дано после регистрации User остаётся неактивным и не имеет сессии
Когда activation token успешно применён и клиент получает ответ
Тогда ответ не содержит access token и не устанавливает refresh cookie
И в UserSession нет новой записи
И UI не считает пользователя вошедшим
```

- **UI:** предлагает login, но не открывает protected-состояние.
- **API:** только `204`, без auth headers/cookies.
- **Данные:** `UserSession` не создаётся и не изменяется.
- **Основание:** [BR-USR-002/003](business-rules/business-rules.md#br-usr-002-первичная-активация), [HTTP 8.1/8.3](api/http-contracts.md#81-регистрация), [ADR-006](adr/architecture-decisions.md#adr-006-жизненный-цикл-аккаунта-и-сессий).

### 6.2. Валидация входных данных

#### AC-006. Отсутствует name

- **Тип:** validation. **Приоритет:** `High`. **Предусловия:** валидные email/password/key, поля `name` нет. **Автоматизация:** `API + FE`.

```gherkin
Дано запрос регистрации не содержит name
Когда запрос отправлен
Тогда API отвечает 422 VALIDATION_ERROR
И details.violations указывает name
И никаких доменных записей не создаётся
```

- **UI:** связывает понятную ошибку с полем name; safe input можно исправить.
- **API:** стандартный error envelope с `requestId`.
- **Данные:** `User`, token, outbox и business result отсутствуют.
- **Основание:** [User required fields](data-model/entities.md#1-user), [error model](api/http-contracts.md#3-стандартная-модель-ошибки), [HTTP 8.1](api/http-contracts.md#81-регистрация).

#### AC-007. name пуст после проверки пробельности

- **Тип:** validation boundary. **Приоритет:** `High`. **Предусловия:** `name` содержит только whitespace. **Автоматизация:** `UT + API + INT`.

```gherkin
Дано name содержит только пробельные символы
Когда отправлена регистрация
Тогда API отвечает 422 VALIDATION_ERROR
И User не создаётся
И ограничение непустого name не обходится
```

- **UI:** показывает ошибку поля name.
- **API:** стандартная validation error.
- **Данные:** нет частичных записей; DB `btrim(name) <> ''` остаётся последней защитой.
- **Основание:** [User constraints](data-model/entities.md#1-user), [ER checks](data-model/er-diagram.md#7-локальные-check-ограничения), [BR-TXN-002](business-rules/business-rules.md#br-txn-002-ответственность-бд).

#### AC-008. Отсутствует email

- **Тип:** validation. **Приоритет:** `High`. **Предусловия:** валидные name/password/key, поля `email` нет. **Автоматизация:** `API + FE`.

```gherkin
Дано запрос регистрации не содержит email
Когда запрос отправлен
Тогда API отвечает 422 VALIDATION_ERROR с нарушением email
И регистрация не фиксирует данные
```

- **UI:** показывает ошибку поля email.
- **API:** error envelope с `requestId`.
- **Данные:** доменные записи отсутствуют.
- **Основание:** [User model](data-model/entities.md#1-user), [HTTP error model](api/http-contracts.md#3-стандартная-модель-ошибки), [HTTP 8.1](api/http-contracts.md#81-регистрация).

#### AC-009. Некорректный формат email

- **Тип:** validation. **Приоритет:** `High`. **Предусловия:** `TD-05`. **Автоматизация:** `UT + API + FE`.

```gherkin
Дано email имеет документированно некорректный формат
Когда отправлена регистрация
Тогда API отвечает 422 VALIDATION_ERROR
И details.violations содержит email и INVALID_EMAIL
И данные не изменяются
```

- **UI:** сообщает о некорректном формате без технических деталей.
- **API:** соответствует примеру error envelope.
- **Данные:** ничего не создано.
- **Основание:** [error example](api/http-contracts.md#3-стандартная-модель-ошибки), [HTTP 8.1](api/http-contracts.md#81-регистрация).

#### AC-010. Нормализация email

- **Тип:** business validation/success. **Приоритет:** `Critical`. **Предусловия:** email с внешними пробелами и смешанным регистром, нормализованное значение уникально. **Автоматизация:** `UT + API + INT`.

```gherkin
Дано введён email "  User.One+E1@Example.Test  "
Когда регистрация успешно выполнена
Тогда backend сохраняет и возвращает "user.one+e1@example.test"
И не выполняет provider-specific преобразований local-part
```

- **UI:** показывает нормализованный email из ответа, не вычисляя его как источник истины.
- **API:** `201`, `user.email` равен сохранённому нормализованному значению.
- **Данные:** unique применяется к trim+lowercase значению.
- **Основание:** [BR-USR-001](business-rules/business-rules.md#br-usr-001-регистрация), [User email](data-model/entities.md#1-user), [HTTP 8.1](api/http-contracts.md#81-регистрация).

#### AC-011. Отсутствует password

- **Тип:** validation. **Приоритет:** `High`. **Предусловия:** валидные name/email/key, поля `password` нет. **Автоматизация:** `API + FE`.

```gherkin
Дано запрос регистрации не содержит password
Когда запрос отправлен
Тогда API отвечает 422 VALIDATION_ERROR
И User не создаётся
```

- **UI:** показывает ошибку password и не выводит значение в сообщении/логах.
- **API:** стандартная validation error.
- **Данные:** `passwordHash` и связанные записи отсутствуют.
- **Основание:** [BR-USR-009](business-rules/business-rules.md#br-usr-009-единая-политика-паролей), [HTTP 8.1](api/http-contracts.md#81-регистрация).

#### AC-012. Минимальная длина password принимается

- **Тип:** validation boundary positive. **Приоритет:** `High`. **Предусловия:** password ровно 12 Unicode code points и содержит непробельный символ. **Автоматизация:** `UT + API`.

```gherkin
Дано password состоит ровно из 12 Unicode code points и не является пробельным
Когда отправлена валидная регистрация
Тогда password policy пройдена
И запрос не отклоняется из-за длины password
```

- **UI:** не блокирует документированно валидную границу.
- **API:** при остальных валидных данных отвечает `201`.
- **Данные:** сохраняется PasswordHasher hash, не открытый password.
- **Основание:** [BR-USR-009](business-rules/business-rules.md#br-usr-009-единая-политика-паролей), [ADR-006](adr/architecture-decisions.md#adr-006-жизненный-цикл-аккаунта-и-сессий).

#### AC-013. Максимальная длина password принимается

- **Тип:** validation boundary positive. **Приоритет:** `High`. **Предусловия:** password ровно 128 Unicode code points, не пробельный. **Автоматизация:** `UT + API`.

```gherkin
Дано password состоит ровно из 128 Unicode code points и содержит непробельный символ
Когда отправлена валидная регистрация
Тогда password policy пройдена
И запрос не отклоняется из-за длины password
```

- **UI:** допускает документированный максимум.
- **API:** при остальных валидных данных отвечает `201`.
- **Данные:** сохраняется только hash.
- **Основание:** [BR-USR-009](business-rules/business-rules.md#br-usr-009-единая-политика-паролей).

#### AC-014. Password короче минимума отклоняется

- **Тип:** validation boundary negative. **Приоритет:** `High`. **Предусловия:** password ровно 11 Unicode code points. **Автоматизация:** `UT + API + FE`.

```gherkin
Дано password состоит из 11 Unicode code points
Когда отправлена регистрация
Тогда API отвечает 422 PASSWORD_POLICY_VIOLATION
И данные не сохраняются
```

- **UI:** сообщает о несоответствии политике на смысловом уровне.
- **API:** `422`, стандартный envelope, без echo password.
- **Данные:** отсутствуют все записи операции.
- **Основание:** [BR-USR-009](business-rules/business-rules.md#br-usr-009-единая-политика-паролей), [error codes](api/http-contracts.md#32-основные-коды-ошибок).

#### AC-015. Password длиннее максимума отклоняется

- **Тип:** validation boundary negative. **Приоритет:** `High`. **Предусловия:** password ровно 129 Unicode code points. **Автоматизация:** `UT + API + FE`.

```gherkin
Дано password состоит из 129 Unicode code points
Когда отправлена регистрация
Тогда API отвечает 422 PASSWORD_POLICY_VIOLATION
И данные не сохраняются
```

- **UI:** показывает policy error.
- **API:** не возвращает password в details.
- **Данные:** нет частичного результата.
- **Основание:** [BR-USR-009](business-rules/business-rules.md#br-usr-009-единая-политика-паролей), [HTTP errors](api/http-contracts.md#3-стандартная-модель-ошибки).

#### AC-016. Пробельный password отклоняется

- **Тип:** validation boundary negative. **Приоритет:** `High`. **Предусловия:** 12 или более Unicode whitespace code points. **Автоматизация:** `UT + API`.

```gherkin
Дано password имеет допустимую длину, но состоит только из пробельных символов
Когда отправлена регистрация
Тогда API отвечает 422 PASSWORD_POLICY_VIOLATION
И данные не сохраняются
```

- **UI:** сообщает о нарушении политики без отображения password.
- **API:** стандартная ошибка.
- **Данные:** отсутствуют.
- **Основание:** [BR-USR-009](business-rules/business-rules.md#br-usr-009-единая-политика-паролей).

#### AC-017. Password не обрезается и не нормализуется

- **Тип:** security boundary positive. **Приоритет:** `High`. **Предусловия:** password допустимой длины с внешними пробелами и отличимым непробельным содержимым. **Автоматизация:** `UT + INT`.

```gherkin
Дано валидный password содержит значимые внешние пробелы или Unicode-последовательность
Когда регистрация создаёт passwordHash
Тогда backend передаёт PasswordHasher исходную последовательность code points без trim и normalization
И открытое значение нигде не сохраняется
```

- **UI:** не меняет отправляемое значение и не показывает его после отправки.
- **API:** не возвращает password/passwordHash.
- **Данные:** hash проверяется только исходным password, а не его обрезанным вариантом.
- **Основание:** [BR-USR-009](business-rules/business-rules.md#br-usr-009-единая-политика-паролей), [ADR-006](adr/architecture-decisions.md#adr-006-жизненный-цикл-аккаунта-и-сессий).

#### AC-018. Некорректный JSON

- **Тип:** syntax error. **Приоритет:** `High`. **Предусловия:** malformed JSON. **Автоматизация:** `API + FE`.

```gherkin
Дано тело запроса не является корректным JSON
Когда оно отправлено на register или activate
Тогда API отвечает 400 в стандартном error envelope
И доменные данные не изменяются
```

- **UI:** показывает общую исправимую ошибку запроса; технический текст parser не раскрывается.
- **API:** `400`, `requestId`, JSON error body.
- **Данные:** нет изменений.
- **Основание:** [HTTP 3.1](api/http-contracts.md#31-основные-http-статусы), [ADR-012](adr/architecture-decisions.md#adr-012-границы-и-http-соглашения-mvp).

#### AC-019. Отсутствует activation token

- **Тип:** validation. **Приоритет:** `High`. **Предусловия:** JSON не содержит обязательного `token` либо значение не пригодно как token. **Автоматизация:** `API + FE`.

```gherkin
Дано запрос активации не содержит применимого token
Когда запрос отправлен
Тогда API отклоняет его как validation error до доменного перехода
И User и UserActionToken не изменяются
```

- **UI:** показывает, что ссылка недействительна и не выполняет бесконечные повторы.
- **API:** `422 VALIDATION_ERROR` для отсутствующего обязательного поля; непустой неизвестный token покрывает `AC-033`.
- **Данные:** неизменны.
- **Основание:** [HTTP 8.3 request](api/http-contracts.md#83-активация-аккаунта), [error model](api/http-contracts.md#3-стандартная-модель-ошибки).

### 6.3. Уникальность email

#### AC-020. Регистрация существующего email запрещена

- **Тип:** business conflict. **Приоритет:** `Critical`. **Предусловия:** `User` с тем же нормализованным email существует. **Автоматизация:** `UT + API + INT + FE`.

```gherkin
Дано User уже хранит нормализованный email запроса
Когда регистрация отправлена с новым Idempotency-Key
Тогда API отвечает 409 EMAIL_ALREADY_EXISTS
И второй User, token и outbox не создаются
```

- **UI:** показывает бизнес-ошибку без технических сведений о constraint.
- **API:** `409 EMAIL_ALREADY_EXISTS`, standard envelope.
- **Данные:** существующий пользователь неизменен; частичного второго набора нет.
- **Основание:** [BR-USR-001](business-rules/business-rules.md#br-usr-001-регистрация), [HTTP 8.1](api/http-contracts.md#81-регистрация), [User unique](data-model/entities.md#1-user).

#### AC-021. Уникальность применяется после нормализации

- **Тип:** business conflict boundary. **Приоритет:** `High`. **Предусловия:** существует `user.one@example.test`, запрос содержит `"  User.One@Example.Test  "`. **Автоматизация:** `UT + API + INT`.

```gherkin
Дано сохранён email "user.one@example.test"
Когда посетитель регистрирует "  User.One@Example.Test  "
Тогда запрос считается конфликтующим с существующим email
И API отвечает 409 EMAIL_ALREADY_EXISTS
И дубликат не создаётся
```

- **UI:** показывает ту же бизнес-ошибку.
- **API:** не зависит от регистра или внешних пробелов.
- **Данные:** unique защищает нормализованное значение.
- **Основание:** [BR-USR-001](business-rules/business-rules.md#br-usr-001-регистрация), [ER unique](data-model/er-diagram.md#7-локальные-check-ограничения), [HTTP 8.1](api/http-contracts.md#81-регистрация).

### 6.4. Идемпотентность и конкурентная регистрация

#### AC-022. Обязательный Idempotency-Key

- **Тип:** idempotency validation. **Приоритет:** `Critical`. **Предусловия:** валидный body, заголовок отсутствует. **Автоматизация:** `API + FE`.

```gherkin
Дано валидный запрос регистрации не содержит Idempotency-Key
Когда запрос отправлен
Тогда API отвечает 400 IDEMPOTENCY_KEY_REQUIRED
И регистрация не создаёт бизнес-данные
```

- **UI:** каждая новая попытка формирует ключ до отправки; при ошибке показывает возможность безопасного повтора.
- **API:** `400` и документированное сообщение в standard envelope.
- **Данные:** ни `IdempotencyRecord`, ни доменные записи не зафиксированы.
- **Основание:** [BR-IDEM-001](business-rules/business-rules.md#br-idem-001-общий-механизм), [HTTP 4.1/4.2](api/http-contracts.md#4-идемпотентность), [ADR-009](adr/architecture-decisions.md#adr-009-централизованная-идемпотентность).

#### AC-023. Повтор с тем же ключом и тем же запросом

- **Тип:** idempotent replay. **Приоритет:** `Critical`. **Предусловия:** первый запрос с key завершился `201`, 24 часа не истекли. **Автоматизация:** `API + INT`.

```gherkin
Дано регистрация с данным ключом и нормализованным запросом завершилась 201
Когда тот же ключ и тот же нормализованный запрос отправлены повторно в пределах 24 часов
Тогда API возвращает первоначальные status 201 и body
И новый User, token, outbox или email intent не создаётся
```

- **UI:** повтор после неопределённого сетевого результата остаётся той же попыткой.
- **API:** возвращает байтово/семантически сохранённый исходный результат согласно контракту.
- **Данные:** один `IdempotencyRecord` и один бизнес-набор.
- **Основание:** [BR-IDEM-003/005](business-rules/business-rules.md#br-idem-003-повтор), [HTTP 4.2](api/http-contracts.md#42-поведение), [ADR-009](adr/architecture-decisions.md#adr-009-централизованная-идемпотентность).

#### AC-024. Повтор ключа с другим запросом

- **Тип:** idempotency conflict. **Приоритет:** `Critical`. **Предусловия:** key уже связан с завершённым normalized request. **Автоматизация:** `UT + API + INT`.

```gherkin
Дано Idempotency-Key уже использован для регистрации с одним нормализованным body
Когда тот же key отправлен с отличающимся нормализованным body
Тогда API отвечает 409 IDEMPOTENCY_KEY_REUSED
И первоначальный результат и все данные остаются неизменными
```

- **UI:** для изменённой формы создаёт новую попытку/ключ; не подменяет предыдущий результат.
- **API:** `409` standard envelope.
- **Данные:** второй бизнес-набор отсутствует.
- **Основание:** [BR-IDEM-003](business-rules/business-rules.md#br-idem-003-повтор), [HTTP 4.2](api/http-contracts.md#42-поведение), [IdempotencyRecord](data-model/entities.md#11-idempotencyrecord).

#### AC-025. Конкурентный повтор одного ключа

- **Тип:** idempotency concurrency. **Приоритет:** `Critical`. **Предусловия:** два одновременных одинаковых запроса с одним новым key. **Автоматизация:** `INT + API`.

```gherkin
Дано два одинаковых запроса регистрации используют один новый Idempotency-Key
Когда они выполняются конкурентно
Тогда одна транзакция резервирует запись, а другая ожидает или читает результат
И оба клиента получают один первоначальный status и body
И создаётся ровно один бизнес-набор
```

- **UI:** двойной транспортный повтор не виден как два аккаунта.
- **API:** оба ответа согласованы с исходным `201`.
- **Данные:** уникален `(public, register operation, key)`; дубликатов нет.
- **Основание:** [BR-IDEM-004](business-rules/business-rules.md#br-idem-004-конкурентный-повтор), [HTTP 4.2](api/http-contracts.md#42-поведение), [ER IdempotencyRecord](data-model/er-diagram.md#7-локальные-check-ограничения).

#### AC-026. Ровно 24-часовое окно идемпотентности

- **Тип:** idempotency boundary. **Приоритет:** `High`. **Предусловия:** управляемое время, существующая запись с `expiresAt = createdAt + 24h`. **Автоматизация:** `UT + INT + API`.

```gherkin
Дано IdempotencyRecord ещё не достиг expiresAt
Когда повторён тот же key и запрос
Тогда возвращается первоначальный результат
Но когда время достигло expiresAt и тот же key применяется снова
Тогда key логически считается новым независимо от физического наличия старой строки
```

- **UI:** не полагается на гарантированный replay после 24 часов.
- **API:** после expiry выполняет новую команду; для уже созданного email ожидается `409 EMAIL_ALREADY_EXISTS`, а не replay `201`.
- **Данные:** `expiresAt` точно равен `createdAt + 24h`; старая запись может очищаться позднее без продления гарантии.
- **Основание:** [BR-IDEM-005](business-rules/business-rules.md#br-idem-005-окно-гарантии), [BR-SEC-005](business-rules/business-rules.md#br-sec-005-сроки-хранения), [ADR-014](adr/architecture-decisions.md#adr-014-нормативный-операционный-профиль-безопасности-и-хранения-mvp).

#### AC-027. Конкурентная регистрация одного email разными ключами

- **Тип:** uniqueness concurrency. **Приоритет:** `Critical`. **Предусловия:** два одновременных валидных запроса с разными keys и одним normalized email. **Автоматизация:** `INT + API`.

```gherkin
Дано normalized email ещё отсутствует
И два запроса используют разные Idempotency-Key
Когда регистрации выполняются конкурентно
Тогда ровно один запрос получает 201
И второй получает 409 EMAIL_ALREADY_EXISTS
И существует ровно один User, один незавершённый ACTIVATE_ACCOUNT и один связанный outbox
```

- **UI:** каждый клиент получает однозначный success/conflict результат.
- **API:** constraint conflict отображается в нормативную бизнес-ошибку, без SQL details.
- **Данные:** DB unique является последней защитой; проигравшая транзакция не оставляет partial state.
- **Основание:** [BR-USR-001](business-rules/business-rules.md#br-usr-001-регистрация), [HTTP 8.0](api/http-contracts.md#80-общие-правила-выдачи-useractiontoken), [BR-TXN-002](business-rules/business-rules.md#br-txn-002-ответственность-бд).

### 6.5. Атомарность, целостность и секреты

#### AC-028. Откат регистрации не оставляет частичных данных

- **Тип:** transaction failure. **Приоритет:** `Critical`. **Предусловия:** контролируемая ошибка во время одной из записей transaction. **Автоматизация:** `INT + API`.

```gherkin
Дано регистрация прошла validation и начала согласованную транзакцию
Когда создание User, token, outbox, idempotency result или commit завершается ошибкой
Тогда API возвращает безопасный 500
И ни одна бизнес-запись операции не остаётся зафиксированной
```

- **UI:** показывает техническую ошибку и разрешает безопасный повтор с тем же key.
- **API:** standard envelope с `requestId`, без SQL/stack/secret.
- **Данные:** нет orphan User/token/outbox и нет ложного завершённого idempotency result.
- **Основание:** [BR-IDEM-004](business-rules/business-rules.md#br-idem-004-конкурентный-повтор), [BR-SEC-006](business-rules/business-rules.md#br-sec-006-надёжная-постановка-email), [HTTP 18](api/http-contracts.md#18-транзакционность-команд).

#### AC-029. Минимальный ER-фрагмент соблюдён

- **Тип:** data integrity. **Приоритет:** `Critical`. **Предусловия:** успешная регистрация. **Автоматизация:** `INT`.

```gherkin
Дано регистрация успешно зафиксирована
Когда проверяется минимальный ER-фрагмент
Тогда все обязательные поля четырёх сущностей заполнены
И FK связывают token с User, outbox с token, idempotency scope корректен
И UNIQUE, CHECK и partial unique инварианты выполняются
```

- **UI:** не отображает внутренние поля.
- **API:** возвращает только публичное представление `User`.
- **Данные:** соответствуют [модели](data-model/entities.md) и [ER](data-model/er-diagram.md), включая один unfinished token на `(userId,purpose)`.
- **Основание:** [entities](data-model/entities.md), [ER constraints](data-model/er-diagram.md#7-локальные-check-ограничения), [BR-TXN-002](business-rules/business-rules.md#br-txn-002-ответственность-бд), [ADR-011](adr/architecture-decisions.md#adr-011-разделение-ответственности-бд-и-доменного-слоя).

#### AC-030. Секреты хранятся и выдаются безопасно

- **Тип:** security/data. **Приоритет:** `Critical`. **Предусловия:** регистрация и доставка письма. **Автоматизация:** `UT + INT + API`.

```gherkin
Дано пользователь передал password и система создала activation token
Когда данные сохраняются, логируются и возвращаются
Тогда хранится только passwordHash и tokenHash
И raw token содержит 32 случайных байта и keyVersion по ADR-016
И tokenHash является versioned HMAC-SHA-256 digest полного raw token
И открытый token/ссылка временно находятся только в encrypted outbox payload
И password, raw token, hashes, ciphertext и ключ шифрования отсутствуют в API errors и логах
```

- **UI:** не сохраняет raw token дольше операции и не повторяет password.
- **API:** не возвращает закрытые поля.
- **Данные:** encryption key вне БД; payload очищается в нормативных конечных случаях.
- **Основание:** [BR-USR-009](business-rules/business-rules.md#br-usr-009-единая-политика-паролей), [BR-SEC-003/006](business-rules/business-rules.md#br-sec-003-useractiontoken), [ADR-007/013/016](adr/architecture-decisions.md#adr-007-унифицированные-одноразовые-токены).

#### AC-031. UUID и серверное UTC-время

- **Тип:** data format. **Приоритет:** `High`. **Предусловия:** успешная регистрация/активация. **Автоматизация:** `INT + API`.

```gherkin
Дано сервер создаёт записи среза
Когда идентификаторы и timestamps проверяются
Тогда публичные id имеют UUID-формат
И API даты представлены RFC 3339 UTC
И ответ содержит X-Request-Id
И createdAt задаётся сервером и не изменяется при активации
```

- **UI:** использует значения ответа, не формирует authoritative timestamps.
- **API:** camelCase/UUID/RFC3339 UTC и `X-Request-Id`.
- **Данные:** timestamps согласованы с lifecycle и `expiresAt > createdAt`.
- **Основание:** [HTTP 2.1](api/http-contracts.md#21-формат-данных), [entities](data-model/entities.md), [ADR-012](adr/architecture-decisions.md#adr-012-границы-и-http-соглашения-mvp).

#### AC-032. Операция не изменяет несвязанные данные

- **Тип:** data isolation. **Приоритет:** `High`. **Предусловия:** в БД есть посторонние users/records. **Автоматизация:** `INT`.

```gherkin
Дано БД содержит данные, не относящиеся к регистрируемому email и token
Когда регистрация и активация выполняются успешно или отклоняются
Тогда изменяются только записи текущей операции
И несвязанные пользователи, токены, outbox и idempotency records остаются неизменными
```

- **UI:** неприменимо; видит только текущий результат.
- **API:** не раскрывает чужие данные.
- **Данные:** изменения ограничены связями текущего aggregate/workflow.
- **Основание:** [BR-TXN-001](business-rules/business-rules.md#br-txn-001-межтабличные-инварианты), [ER relationships](data-model/er-diagram.md), [ADR-011](adr/architecture-decisions.md#adr-011-разделение-ответственности-бд-и-доменного-слоя).

### 6.6. Права, token lifecycle и переход состояния

#### AC-033. Неизвестный token не даёт доступа к аккаунту

- **Тип:** access/token error. **Приоритет:** `High`. **Предусловия:** непустой token, hash которого отсутствует. **Автоматизация:** `UT + API + FE`.

```gherkin
Дано предъявитель не аутентифицирован и token не соответствует сохранённому tokenHash
Когда он вызывает активацию
Тогда API отвечает 400 INVALID_TOKEN
И ни один User не изменяется
И ответ не раскрывает наличие email или аккаунта
```

- **UI:** показывает семантическое «ссылка недействительна».
- **API:** standard envelope, без token/hash/constraint.
- **Данные:** неизменны.
- **Основание:** [BR-SEC-003](business-rules/business-rules.md#br-sec-003-useractiontoken), [HTTP 8.3](api/http-contracts.md#83-активация-аккаунта), [E1-05 access](first-vertical-slice.md#13-права-доступа-и-безопасность).

#### AC-034. Истёкший token

- **Тип:** token boundary error. **Приоритет:** `High`. **Предусловия:** `expiresAt <= now`, token не used/invalidated. **Автоматизация:** `UT + API + INT + FE`.

```gherkin
Дано ACTIVATE_ACCOUNT достиг expiresAt
Когда token применяется
Тогда API отвечает 410 TOKEN_EXPIRED
И User остаётся неактивным
И usedAt не заполняется
```

- **UI:** сообщает, что ссылка истекла; resend action не добавляется в этот срез.
- **API:** `410 TOKEN_EXPIRED`.
- **Данные:** token может оставаться для retention, состояние не меняется.
- **Основание:** [BR-SEC-003/005](business-rules/business-rules.md#br-sec-003-useractiontoken), [HTTP 8.3](api/http-contracts.md#83-активация-аккаунта), [ADR-014](adr/architecture-decisions.md#adr-014-нормативный-операционный-профиль-безопасности-и-хранения-mvp).

#### AC-035. Уже использованный token

- **Тип:** repeated state transition. **Приоритет:** `High`. **Предусловия:** `usedAt` заполнен. **Автоматизация:** `UT + API + INT + FE`.

```gherkin
Дано ACTIVATE_ACCOUNT уже использован
Когда тот же token применяется повторно
Тогда API отвечает 410 TOKEN_USED
И usedAt и User не изменяются повторно
```

- **UI:** показывает, что ссылка уже использована, без повторного success.
- **API:** `410 TOKEN_USED`.
- **Данные:** повторный transition отсутствует.
- **Основание:** [BR-SEC-003](business-rules/business-rules.md#br-sec-003-useractiontoken), [HTTP 8.3](api/http-contracts.md#83-активация-аккаунта).

#### AC-036. Аннулированный token

- **Тип:** token state error. **Приоритет:** `High`. **Предусловия:** `invalidatedAt` заполнен, `usedAt = null`. **Автоматизация:** `UT + API + INT + FE`.

```gherkin
Дано ACTIVATE_ACCOUNT аннулирован
Когда его открытый token применяется
Тогда API отвечает 410 TOKEN_INVALIDATED
И User и token остаются неизменными
```

- **UI:** сообщает, что ссылка больше недействительна.
- **API:** `410 TOKEN_INVALIDATED`.
- **Данные:** `invalidatedAt` сохраняется, `usedAt` не появляется.
- **Основание:** [BR-SEC-004](business-rules/business-rules.md#br-sec-004-аннулирование), [HTTP 8.3](api/http-contracts.md#83-активация-аккаунта), [UserActionToken checks](data-model/entities.md#10-useractiontoken).

#### AC-037. Token неправильного purpose

- **Тип:** authorization/purpose error. **Приоритет:** `High`. **Предусловия:** валидный raw token соответствует записи другого purpose. **Автоматизация:** `UT + API + INT`.

```gherkin
Дано token существует, но его purpose не ACTIVATE_ACCOUNT
Когда token отправлен на /auth/activate
Тогда API отвечает 400 INVALID_TOKEN
И связанный User и token не изменяются
```

- **UI:** показывает общую недействительность ссылки, не раскрывая purpose.
- **API:** не позволяет использовать token между операциями.
- **Данные:** `usedAt` не заполняется.
- **Основание:** [BR-SEC-003](business-rules/business-rules.md#br-sec-003-useractiontoken), [ADR-007](adr/architecture-decisions.md#adr-007-унифицированные-одноразовые-токены), [HTTP 8.3](api/http-contracts.md#83-активация-аккаунта).

#### AC-038. Конкурентное применение одного token

- **Тип:** state transition concurrency. **Приоритет:** `Critical`. **Предусловия:** два конкурентных запроса с одним действующим token. **Автоматизация:** `INT + API`.

```gherkin
Дано User неактивен и ACTIVATE_ACCOUNT действителен
Когда два запроса активации выполняются конкурентно
Тогда ровно один отвечает 204 и фиксирует переход
И второй отвечает 410 TOKEN_USED после наблюдения завершённого token
И состояние не применяется дважды
```

- **UI:** один tab показывает success, другой — already used; оба не создают session.
- **API:** один `204`, один `410 TOKEN_USED`.
- **Данные:** один `usedAt`, один переход `false → true`.
- **Основание:** [BR-SEC-003](business-rules/business-rules.md#br-sec-003-useractiontoken), [HTTP 18](api/http-contracts.md#18-транзакционность-команд), [ADR-007/011](adr/architecture-decisions.md#adr-007-унифицированные-одноразовые-токены).

#### AC-039. Ошибка активации атомарна

- **Тип:** transaction failure. **Приоритет:** `Critical`. **Предусловия:** контролируемая ошибка между проверкой token и commit. **Автоматизация:** `INT + API`.

```gherkin
Дано token прошёл проверки и активационная транзакция начата
Когда обновление User, token или commit завершается ошибкой
Тогда API возвращает безопасный 500
И User.isActive и UserActionToken.usedAt остаются в исходном согласованном состоянии
```

- **UI:** показывает техническую ошибку с безопасным повтором.
- **API:** standard envelope с requestId.
- **Данные:** нет состояния «active user + unused token» либо «inactive user + used token».
- **Основание:** [BR-TXN-001](business-rules/business-rules.md#br-txn-001-межтабличные-инварианты), [HTTP 18](api/http-contracts.md#18-транзакционность-команд), [ADR-011](adr/architecture-decisions.md#adr-011-разделение-ответственности-бд-и-доменного-слоя).

#### AC-040. Точный итог успешной активации

- **Тип:** state transition/data. **Приоритет:** `Critical`. **Предусловия:** валидный token и неактивный User. **Автоматизация:** `UT + INT + API`.

```gherkin
Дано User.isActive=false и token является текущим ACTIVATE_ACCOUNT
Когда активация зафиксирована
Тогда изменяются только User.isActive на true и UserActionToken.usedAt на серверное время
И token.invalidatedAt остаётся null
И name, email, passwordHash и createdAt пользователя не изменяются
```

- **UI:** показывает activated.
- **API:** `204`, повторно проверить можно через `410 TOKEN_USED` и состояние БД/test read.
- **Данные:** точный допустимый переход, без session.
- **Основание:** [BR-USR-002](business-rules/business-rules.md#br-usr-002-первичная-активация), [UserActionToken](data-model/entities.md#10-useractiontoken), [HTTP 8.3](api/http-contracts.md#83-активация-аккаунта).

### 6.7. Rate limits

#### AC-041. Registration limit по IP

- **Тип:** rate-limit boundary. **Приоритет:** `High`. **Предусловия:** контролируемое окно и один IP. **Автоматизация:** `UT + API + INT`.

```gherkin
Дано IP выполнил 5 учитываемых регистрационных запросов за скользящий 1 час
Когда выполняется следующий запрос в том же окне
Тогда API отвечает 429 RATE_LIMIT_EXCEEDED
И Retry-After точно отражает оставшееся время блокировки
И бизнес-данные запроса не создаются
```

- **UI:** показывает временное ограничение и не запускает автоматический storm повторов.
- **API:** `429`, `Retry-After`, standard envelope.
- **Данные:** доменные данные неизменны; технический rate-limit state допустим.
- **Основание:** [BR-SEC-007](business-rules/business-rules.md#br-sec-007-rate-limits-mvp), [HTTP 17](api/http-contracts.md#17-rate-limits), [ADR-014](adr/architecture-decisions.md#adr-014-нормативный-операционный-профиль-безопасности-и-хранения-mvp).

#### AC-042. Registration limit по нормализованному email

- **Тип:** rate-limit boundary. **Приоритет:** `High`. **Предусловия:** 3 учитываемых запроса одного normalized email за 24 часа, варианты регистра/пробелов. **Автоматизация:** `UT + API + INT`.

```gherkin
Дано normalized email исчерпал 3 запроса за скользящие 24 часа
Когда отправляется следующий вариант того же email
Тогда API отвечает 429 RATE_LIMIT_EXCEEDED с точным Retry-After
И проверка не зависит от регистра и внешних пробелов
```

- **UI:** показывает временную ошибку.
- **API:** не раскрывает, существует ли аккаунт.
- **Данные:** новая регистрация не создаётся.
- **Основание:** [BR-SEC-007](business-rules/business-rules.md#br-sec-007-rate-limits-mvp), [HTTP 17](api/http-contracts.md#17-rate-limits), [ADR-014](adr/architecture-decisions.md#adr-014-нормативный-операционный-профиль-безопасности-и-хранения-mvp).

#### AC-043. Apply-token limit по IP

- **Тип:** rate-limit boundary. **Приоритет:** `High`. **Предусловия:** IP исчерпал 10 попыток применения token за 15 минут. **Автоматизация:** `UT + API + INT`.

```gherkin
Дано IP выполнил 10 учитываемых apply-token запросов за скользящие 15 минут
Когда выполняется одиннадцатый
Тогда API отвечает 429 RATE_LIMIT_EXCEEDED с точным Retry-After
И token/User не меняются
```

- **UI:** сообщает о временном ограничении.
- **API:** `429`, standard envelope.
- **Данные:** доменные данные неизменны.
- **Основание:** [HTTP 17](api/http-contracts.md#17-rate-limits), [BR-SEC-007](business-rules/business-rules.md#br-sec-007-rate-limits-mvp), [ADR-014](adr/architecture-decisions.md#adr-014-нормативный-операционный-профиль-безопасности-и-хранения-mvp).

#### AC-044. Apply-token limit по token hash

- **Тип:** rate-limit boundary. **Приоритет:** `High`. **Предусловия:** один token hash исчерпал 5 попыток за 15 минут. **Автоматизация:** `UT + API + INT`.

```gherkin
Дано hash предъявляемого token исчерпал 5 попыток за скользящие 15 минут
Когда выполняется шестая попытка
Тогда API отвечает 429 RATE_LIMIT_EXCEEDED с точным Retry-After
И token/User не меняются
```

- **UI:** показывает временное ограничение без raw token в сообщении.
- **API:** не возвращает tokenHash.
- **Данные:** доменный переход отсутствует.
- **Основание:** [HTTP 17](api/http-contracts.md#17-rate-limits), [BR-SEC-007](business-rules/business-rules.md#br-sec-007-rate-limits-mvp), [ADR-014](adr/architecture-decisions.md#adr-014-нормативный-операционный-профиль-безопасности-и-хранения-mvp).

#### AC-045. Все ключи rate limit обязательны

- **Тип:** rate-limit composition/privacy. **Приоритет:** `High`. **Предусловия:** для операции один ключ имеет quota, другой исчерпан. **Автоматизация:** `UT + API`.

```gherkin
Дано операция имеет несколько rate-limit ключей
И хотя бы по одному ключу quota исчерпана
Когда запрос выполняется
Тогда он отклоняется 429 независимо от quota других ключей
И поведение email-limit не раскрывает существование аккаунта
```

- **UI:** одинаково отображает rate-limit error.
- **API:** точный `Retry-After` для применимого ограничения.
- **Данные:** доменные изменения отсутствуют.
- **Основание:** [HTTP 17](api/http-contracts.md#17-rate-limits), [BR-SEC-007](business-rules/business-rules.md#br-sec-007-rate-limits-mvp).

### 6.8. Outbox, RabbitMQ и email

#### AC-046. Durable intent создаётся атомарно

- **Тип:** outbox transaction. **Приоритет:** `Critical`. **Предусловия:** валидная новая регистрация. **Автоматизация:** `INT + API`.

```gherkin
Дано регистрация готова к commit
Когда транзакция успешно фиксируется
Тогда User, первый ACTIVATE_ACCOUNT и связанный EmailDeliveryOutbox появляются атомарно
И outbox имеет PENDING и encrypted payload
И успешный HTTP-ответ возможен без ожидания SMTP
```

- **UI:** получает activation-required после durable commit, не после SMTP.
- **API:** `201` подтверждает business change и durable intent.
- **Данные:** один outbox на token; нет User/token без outbox.
- **Основание:** [BR-SEC-006](business-rules/business-rules.md#br-sec-006-надёжная-постановка-email), [HTTP 8.0/18](api/http-contracts.md#80-общие-правила-выдачи-useractiontoken), [ADR-013](adr/architecture-decisions.md#adr-013-надёжная-доставка-email-через-transactional-outbox-и-rabbitmq).

#### AC-047. PUBLISHED только после publisher confirm

- **Тип:** outbox publish. **Приоритет:** `Critical`. **Предусловия:** `PENDING` outbox, доступный RabbitMQ. **Автоматизация:** `INT`.

```gherkin
Дано relay выбрал PENDING delivery
Когда RabbitMQ подтверждает durable persistent publish
Тогда outbox переходит в PUBLISHED
И сообщение содержит только deliveryId и несекретные routing metadata
Но до publisher confirm статус PUBLISHED не фиксируется
```

- **UI:** не зависит от промежуточного статуса.
- **API:** первоначальный `201` не меняется.
- **Данные:** publishAttempts/timestamps меняются согласованно; raw token и ciphertext не попадают в message.
- **Основание:** [BR-SEC-006](business-rules/business-rules.md#br-sec-006-надёжная-постановка-email), [ADR-013](adr/architecture-decisions.md#adr-013-надёжная-доставка-email-через-transactional-outbox-и-rabbitmq), [EmailDeliveryOutbox](data-model/entities.md#12-emaildeliveryoutbox).

#### AC-048. Ошибка публикации повторяется по нормативному профилю

- **Тип:** relay retry. **Приоритет:** `High`. **Предусловия:** broker не подтверждает publish, token ещё действителен. **Автоматизация:** `UT + INT`.

```gherkin
Дано outbox PENDING и token действителен
Когда publish завершается временной ошибкой
Тогда durable intent не теряется и PUBLISHED не устанавливается
И relay повторяет через 30 секунд с множителем 2 и максимумом 30 минут
И повторы продолжаются только пока token действителен
```

- **UI:** уже показывает activation required; не получает ложный success доставки.
- **API:** не изменяется задним числом.
- **Данные:** `publishAttempts` и безопасный `lastError` обновляются; payload остаётся encrypted до исхода.
- **Основание:** [BR-SEC-006](business-rules/business-rules.md#br-sec-006-надёжная-постановка-email), [HTTP 18](api/http-contracts.md#18-транзакционность-команд), [ADR-014](adr/architecture-decisions.md#adr-014-нормативный-операционный-профиль-безопасности-и-хранения-mvp).

#### AC-049. Неактуальный token не отправляется

- **Тип:** consumer security. **Приоритет:** `Critical`. **Предусловия:** delivery получена consumer, token expired/used/invalidated либо перестал быть текущим. **Автоматизация:** `UT + INT`.

```gherkin
Дано consumer получил deliveryId
И связанный token к моменту обработки не является текущим действующим token
Когда consumer повторно проверяет token
Тогда SMTP не вызывается
И outbox завершается FAILED
И encrypted payload очищается
И сообщение подтверждается без бесконечного retry
```

- **UI:** пользователь не получает новую ложную ссылку; отдельный resend UI отсутствует.
- **API:** неприменимо к уже завершённому registration response.
- **Данные:** `failedAt` заполнен, payload очищен, token/User не меняются.
- **Основание:** [BR-SEC-006](business-rules/business-rules.md#br-sec-006-надёжная-постановка-email), [ADR-013](adr/architecture-decisions.md#adr-013-надёжная-доставка-email-через-transactional-outbox-и-rabbitmq), [BR-SEC-005](business-rules/business-rules.md#br-sec-005-сроки-хранения).

#### AC-050. Успешный SMTP handoff завершает outbox

- **Тип:** email delivery success. **Приоритет:** `Critical`. **Предусловия:** актуальный token, расшифровываемый payload, успешный синхронный Mailer call. **Автоматизация:** `INT + E2E + MAN`.

```gherkin
Дано consumer проверил актуальный ACTIVATE_ACCOUNT
Когда синхронный Mailer transport успешно принял письмо
Тогда EmailDeliveryOutbox получает SENT и sentAt
И encrypted payload очищается
И письмо можно наблюдать в Mailpit в локальной демонстрации
```

- **UI:** пользователь может перейти по доставленной ссылке.
- **API:** activation link приводит к нормативному `POST /auth/activate`.
- **Данные:** `SENT` и `FAILED` взаимоисключающи; payload отсутствует после final state.
- **Основание:** [BR-SEC-005/006](business-rules/business-rules.md#br-sec-005-сроки-хранения), [EmailDeliveryOutbox](data-model/entities.md#12-emaildeliveryoutbox), [ADR-013](adr/architecture-decisions.md#adr-013-надёжная-доставка-email-через-transactional-outbox-и-rabbitmq).

#### AC-051. SMTP retry и окончательный отказ

- **Тип:** consumer retry/failure. **Приоритет:** `High`. **Предусловия:** первая SMTP-попытка и последующие попытки завершаются ошибкой. **Автоматизация:** `UT + INT`.

```gherkin
Дано SMTP не принимает activation email
Когда consumer обрабатывает временные ошибки
Тогда после первой попытки выполняется не более пяти повторов через 1m, 5m, 15m, 1h и 6h
И после исчерпания задание попадает в failure transport
И outbox получает FAILED, failedAt и очищенный payload
```

- **UI:** не обещает фактическое получение; остаётся activation-required.
- **API:** `201` не отзывается, потому что durable intent был зафиксирован.
- **Данные:** безопасный `lastError` не содержит secret/ciphertext; final states согласованы.
- **Основание:** [BR-SEC-006](business-rules/business-rules.md#br-sec-006-надёжная-постановка-email), [HTTP 18](api/http-contracts.md#18-транзакционность-команд), [ADR-013/014](adr/architecture-decisions.md#adr-013-надёжная-доставка-email-через-transactional-outbox-и-rabbitmq).

#### AC-052. Повторная доставка завершённого задания безопасна

- **Тип:** consumer idempotency. **Приоритет:** `High`. **Предусловия:** duplicate message с deliveryId для `SENT` или `FAILED`. **Автоматизация:** `UT + INT`.

```gherkin
Дано outbox уже находится в конечном SENT или FAILED
Когда consumer повторно получает тот же deliveryId
Тогда завершённая работа пропускается
И SMTP не вызывается снова
И final timestamps и доменные данные не изменяются
```

- **UI:** не получает дополнительного состояния.
- **API:** неприменимо.
- **Данные:** completed job остаётся неизменной.
- **Основание:** [ADR-013](adr/architecture-decisions.md#adr-013-надёжная-доставка-email-через-transactional-outbox-и-rabbitmq), [BR-SEC-006](business-rules/business-rules.md#br-sec-006-надёжная-постановка-email).

#### AC-053. Допустимая редкая SMTP-доставка at-least-once

- **Тип:** documented delivery boundary. **Приоритет:** `Medium`. **Предусловия:** SMTP принял письмо, worker завершился до фиксации `SENT`. **Автоматизация:** `INT + MAN`.

```gherkin
Дано SMTP уже принял письмо
И consumer аварийно завершился до фиксации SENT
Когда сообщение обрабатывается повторно
Тогда допускается редкое повторное письмо
Но durable intent не теряется, User/token не дублируются и обе ссылки соответствуют одному текущему token
```

- **UI:** одна из одинаково действительных ссылок активирует аккаунт; следующая попытка получает `TOKEN_USED`.
- **API:** token остаётся одноразовым независимо от количества писем.
- **Данные:** бизнес-сущности не дублируются.
- **Основание:** [BR-SEC-006](business-rules/business-rules.md#br-sec-006-надёжная-постановка-email), [HTTP 18](api/http-contracts.md#18-транзакционность-команд), [ADR-013](adr/architecture-decisions.md#adr-013-надёжная-доставка-email-через-transactional-outbox-и-rabbitmq).

#### AC-054. В consumer нет второго асинхронного email-контура

- **Тип:** accepted architecture behavior. **Приоритет:** `High`. **Предусловия:** consumer обрабатывает delivery. **Автоматизация:** `INT`.

```gherkin
Дано Messenger consumer получил SendUserActionEmail
Когда он отправляет письмо
Тогда он вызывает выделенный синхронный Mailer transport
И не создаёт вложенное асинхронное SendEmailMessage
И SENT означает завершение именно SMTP-вызова этого consumer
```

- **UI:** наблюдаемый `SENT` согласован с SMTP handoff.
- **API:** не затрагивается.
- **Данные:** не возникает ложного `SENT` до фактического Mailer call.
- **Основание:** [HTTP 18](api/http-contracts.md#18-транзакционность-команд), [ADR-013](adr/architecture-decisions.md#adr-013-надёжная-доставка-email-через-transactional-outbox-и-rabbitmq).

### 6.9. Состояния UI и обработка ошибок

#### AC-055. Исходное и loading-состояния регистрации

- **Тип:** frontend state. **Приоритет:** `High`. **Предусловия:** открыт экран регистрации. **Автоматизация:** `FE + E2E`.

```gherkin
Дано посетитель открыл экран регистрации
Когда форма ещё не отправлена
Тогда видны обязательные name, email, password и доступное действие регистрации
Но когда запрос выполняется
Тогда UI показывает loading и предотвращает создание второй независимой попытки
```

- **UI:** пустая форма является empty state; визуальный дизайн не фиксируется.
- **API:** одна пользовательская попытка имеет один key.
- **Данные:** до отправки нет изменений.
- **Основание:** [E1-05 UI boundaries](first-vertical-slice.md#91-пользовательский-интерфейс), [HTTP 8.1](api/http-contracts.md#81-регистрация).

#### AC-056. Двойная отправка формы не создаёт дубликат

- **Тип:** frontend/idempotency concurrency. **Приоритет:** `Critical`. **Предусловия:** валидная форма, быстрый double click/retry. **Автоматизация:** `FE + E2E + API`.

```gherkin
Дано пользователь начал одну регистрацию
Когда форма отправляется повторно двойным кликом или сетевым retry до известного результата
Тогда UI использует Idempotency-Key той же попытки
И пользователь получает один исходный результат
И создаётся один аккаунт и одно первое письмо intent
```

- **UI:** submit блокируется или повтор связан с тем же key; после изменения формы начинается новая попытка с новым key.
- **API:** поведение `AC-023/025`.
- **Данные:** один набор records.
- **Основание:** [BR-IDEM-003/004](business-rules/business-rules.md#br-idem-003-повтор), [HTTP 4](api/http-contracts.md#4-идемпотентность), [E1-05 frontend](first-vertical-slice.md#96-технические-зависимости-внутри-среза).

#### AC-057. UI регистрации показывает успешный промежуточный результат

- **Тип:** frontend success. **Приоритет:** `High`. **Предусловия:** API вернул `201`. **Автоматизация:** `FE + E2E`.

```gherkin
Дано API регистрации вернул 201 и activationRequired=true
Когда UI обрабатывает ответ
Тогда он сообщает, что аккаунт создан неактивным и требуется открыть email
И не сообщает об успешном login
И не ожидает SMTP синхронно
```

- **UI:** понятное activation-required state.
- **API:** публичное тело используется как источник результата.
- **Данные:** уже зафиксирован durable intent.
- **Основание:** [HTTP 8.1](api/http-contracts.md#81-регистрация), [BR-USR-001/002](business-rules/business-rules.md#br-usr-001-регистрация), [E1-05 result](first-vertical-slice.md#73-результат).

#### AC-058. UI различает validation и business errors регистрации

- **Тип:** frontend error. **Приоритет:** `High`. **Предусловия:** API возвращает документированную `422`, `409` или `429`. **Автоматизация:** `FE + API`.

```gherkin
Дано регистрация отклонена документированной validation, email, idempotency или rate-limit ошибкой
Когда UI получает standard error envelope
Тогда он показывает понятное действие или сообщение соответствующей категории
И связывает field violations с полями, если они есть
И не показывает stack trace, hash, raw token, ciphertext или password
```

- **UI:** safe поля могут быть сохранены для исправления; password не выводится и не логируется.
- **API:** code/requestId/details используются без зависимости от произвольного текста message.
- **Данные:** согласно конкретному error критерию неизменны.
- **Основание:** [HTTP 3](api/http-contracts.md#3-стандартная-модель-ошибки), [HTTP 8.1](api/http-contracts.md#81-регистрация), [BR-USR-009](business-rules/business-rules.md#br-usr-009-единая-политика-паролей).

#### AC-059. UI активации показывает loading и success

- **Тип:** frontend state/success. **Приоритет:** `High`. **Предусловия:** activation page получила token из ссылки. **Автоматизация:** `FE + E2E`.

```gherkin
Дано пользователь открыл activation link с token
Когда UI выполняет POST /auth/activate
Тогда он показывает activation loading
И при 204 показывает, что аккаунт активирован
И предлагает отдельно войти
```

- **UI:** token не отображается и не сохраняется дольше операции.
- **API:** один вызов `POST /auth/activate`, `204`.
- **Данные:** состояние соответствует `AC-040`.
- **Основание:** [E1-05 UI](first-vertical-slice.md#91-пользовательский-интерфейс), [HTTP 8.3](api/http-contracts.md#83-активация-аккаунта), [BR-USR-002](business-rules/business-rules.md#br-usr-002-первичная-активация).

#### AC-060. UI активации обрабатывает token errors

- **Тип:** frontend error. **Приоритет:** `High`. **Предусловия:** API возвращает `INVALID_TOKEN`, `TOKEN_EXPIRED`, `TOKEN_USED`, `TOKEN_INVALIDATED` или `429`. **Автоматизация:** `FE + API + E2E`.

```gherkin
Дано активация не выполнена из-за состояния token или rate limit
Когда UI получает документированную ошибку
Тогда он показывает соответствующее семантическое состояние
И не повторяет запрос бесконечно
И не предлагает исключённый resend flow как уже реализованный
```

- **UI:** различает invalid/expired/used/invalidated/temporary limit на смысловом уровне; доступные действия не выходят за срез.
- **API:** коды и статусы соответствуют `AC-033—037`, `AC-043—045`.
- **Данные:** неизменны.
- **Основание:** [HTTP 8.3](api/http-contracts.md#83-активация-аккаунта), [HTTP 17](api/http-contracts.md#17-rate-limits), [E1-05 exclusions](first-vertical-slice.md#15-не-входит-в-первый-срез).

#### AC-061. Техническая ошибка безопасна и допускает корректный повтор

- **Тип:** technical error/security. **Приоритет:** `Critical`. **Предусловия:** непредвиденная backend/DB/network ошибка либо неопределённый клиенту результат регистрации. **Автоматизация:** `API + FE + INT + E2E`.

```gherkin
Дано запрос завершился непредвиденной технической ошибкой
Когда API формирует ответ и UI его обрабатывает
Тогда API возвращает безопасный 500 в standard envelope с requestId
И не раскрывает stack trace, SQL, constraints, password, token, hash или ciphertext
И UI показывает исправимую общую ошибку
И retry неизвестного результата регистрации использует тот же Idempotency-Key
```

- **UI:** не объявляет успех без подтверждённого ответа; повтор активации допускается и разрешается token lifecycle.
- **API:** `500`, JSON envelope, `X-Request-Id`; атомарность определяется `AC-028/039`.
- **Данные:** либо полный committed результат, воспроизводимый по key, либо отсутствие частичных данных.
- **Основание:** [HTTP 2.4/3](api/http-contracts.md#24-request-id), [BR-IDEM-003/004](business-rules/business-rules.md#br-idem-003-повтор), [BR-SEC-006](business-rules/business-rules.md#br-sec-006-надёжная-постановка-email), [ADR-012/013](adr/architecture-decisions.md#adr-012-границы-и-http-соглашения-mvp).

## 7. Негативные и граничные сценарии

Каталог критериев покрывает следующие обязательные категории:

| Категория | Критерии | Проверяемая граница |
|---|---|---|
| Отсутствующие/некорректные поля | `AC-006—009`, `AC-011`, `AC-018—019` | Required fields, email format, malformed JSON, обязательный token |
| Password policy | `AC-012—017` | Ровно 12/128 code points, 11/129, whitespace-only, отсутствие trim/normalization |
| Нормализация и уникальность email | `AC-010`, `AC-020—021`, `AC-027` | Trim/lowercase, normalized conflict, конкурентный unique |
| Идемпотентность | `AC-022—026`, `AC-056` | Missing/reused key, same/different/concurrent retry, ровно 24 часа, double submit |
| Атомарность | `AC-028—029`, `AC-039`, `AC-046` | Нет partial registration/activation/outbox state |
| Доступ и lifecycle token | `AC-033—040` | Invalid/expired/used/invalidated/wrong-purpose, concurrent use |
| Rate limits | `AC-041—045` | Все нормативные ключи, окна, границы и `Retry-After` |
| Delivery failures | `AC-047—054` | Publisher confirm, relay/SMTP retry, stale token, failure transport, at-least-once |
| UI и безопасные ошибки | `AC-055—061` | Loading/success/validation/business/access/technical/retry states |

Граничные значения, которых нет в нормативной документации (например,
максимальная длина `name` или `email`), намеренно не добавлены. Неизвестные
JSON-поля отклоняются с `400 INVALID_REQUEST` по принятому OQ-015 и
[HTTP 2.6](api/http-contracts.md#26-граница-json-запроса).

## 8. Права доступа

1. `POST /auth/register` разрешён неаутентифицированному посетителю. Отсутствие `Authorization` здесь не является `401`; защита обеспечивается validation, `Idempotency-Key`, уникальностью и rate limits (`AC-002`, `AC-020—027`, `AC-041—042`).
2. `POST /auth/activate` также публичен, но действующий purpose-bound одноразовый token является capability для изменения ровно связанного с ним пользователя (`AC-004`, `AC-033—040`, `AC-043—045`).
3. Наличие произвольного access token само по себе не даёт права активировать чужой аккаунт. Без правильного activation token переход невозможен.
4. Отдельные `401` и `403` критерии неприменимы к двум публичным endpoint выбранного среза; добавление их означало бы изменение HTTP-контрактов.
5. Ошибки не возвращают чужой email, сведения о существовании аккаунта сверх документированного `EMAIL_ALREADY_EXISTS`, hashes, raw secrets или внутреннюю диагностику (`AC-030`, `AC-033`, `AC-045`, `AC-058`, `AC-061`).
6. После активации пользователь остаётся неаутентифицированным до отдельного `MVP-SC-002` (`AC-005`).

Основание: [общая authentication model](api/http-contracts.md#23-аутентификация), [registration/activation](api/http-contracts.md#8-регистрация-и-аутентификация), [BR-USR-002/003](business-rules/business-rules.md#br-usr-002-первичная-активация), [BR-SEC-003/007](business-rules/business-rules.md#br-sec-003-useractiontoken), [E1-05 access boundaries](first-vertical-slice.md#13-права-доступа-и-безопасность).

## 9. Ожидаемые изменения данных

| Сущность | Успешная регистрация | Успешная активация | При отказе |
|---|---|---|---|
| `User` | Новая запись; обязательные поля; normalized unique email; PasswordHasher hash; `isActive=false`; immutable `createdAt` | Только `isActive: false → true` | Не создаётся либо остаётся в исходном состоянии |
| `UserActionToken` | Один текущий `ACTIVATE_ACCOUNT`; `payload=null`; `expiresAt=createdAt+24h`; `usedAt=null`; `invalidatedAt=null`; хранится только `tokenHash` | У того же token заполняется `usedAt`; `invalidatedAt` остаётся `null` | Lifecycle поля не меняются |
| `EmailDeliveryOutbox` | Одна запись для token; `PENDING`; encrypted payload; delivery metadata | Не меняется активацией; доставка отдельно приводит к `SENT` или `FAILED` и очистке payload | Транзакционный отказ регистрации не оставляет запись; delivery failure следует `AC-048—052` |
| `IdempotencyRecord` | `scope=public`, registration operation/key/requestHash, исходные `201` status/body, `expiresAt=createdAt+24h` | Не участвует | При rollback нет ложного завершённого результата; conflict/replay не создаёт бизнес-дубли |
| `UserSession` | Не создаётся | Не создаётся | Не меняется |

Обязательные связи, ограничения и retention берутся из [модели сущностей](data-model/entities.md), [ER-модели](data-model/er-diagram.md#7-локальные-check-ограничения), [BR-TXN-001/002](business-rules/business-rules.md#br-txn-001-межтабличные-инварианты) и [BR-SEC-005/006](business-rules/business-rules.md#br-sec-005-сроки-хранения).

## 10. Состояния интерфейса

| Экран | Состояние | Наблюдаемое поведение | Критерии |
|---|---|---|---|
| Регистрация | Initial/empty | Пустые обязательные `name`, `email`, `password`; действие доступно | `AC-055` |
| Регистрация | Loading | Отправка видима; одна попытка использует один key; double submit не создаёт новую операцию | `AC-055—056` |
| Регистрация | Success | Сообщение о необходимости открыть email; нет login/session | `AC-002`, `AC-005`, `AC-057` |
| Регистрация | Validation | Field violations привязаны к полям; можно исправить | `AC-006—019`, `AC-058` |
| Регистрация | Business/rate | Понятные email/idempotency/rate-limit состояния; при наличии учитывается `Retry-After` | `AC-020—027`, `AC-041—045`, `AC-058` |
| Активация | Loading | Выполняется один вызов с token; raw token не отображается | `AC-059` |
| Активация | Success | Аккаунт активирован; предлагается отдельный login | `AC-004—005`, `AC-059` |
| Активация | Token/access error | Invalid/expired/used/invalidated/rate-limit отображаются семантически, без утечки token | `AC-033—037`, `AC-043—045`, `AC-060` |
| Любой | Technical error | Исправимая общая ошибка; requestId пригоден для поддержки; секреты/stack не видны | `AC-018`, `AC-061` |

Отдельный empty-state списка неприменим: пустая registration form является исходным состоянием. Текст и внешний вид сообщений не фиксируются; фиксируется их смысл и доступное действие.

## 11. Ожидаемое поведение API

| Операция/условие | HTTP | Код/тело | Данные |
|---|---:|---|---|
| Валидная регистрация | `201` | `user{id,name,email,isActive:false,createdAt}`, `activationRequired:true` | Полный атомарный набор регистрации |
| Missing `Idempotency-Key` | `400` | `IDEMPOTENCY_KEY_REQUIRED` | Нет business result |
| Malformed JSON | `400` | Standard error envelope | Нет изменений |
| Validation/password violation | `422` | `VALIDATION_ERROR` либо `PASSWORD_POLICY_VIOLATION`, violations где применимо | Нет изменений |
| Существующий email | `409` | `EMAIL_ALREADY_EXISTS` | Нет второго набора |
| Key с другим body | `409` | `IDEMPOTENCY_KEY_REUSED` | Исходный набор неизменен |
| Registration rate limit | `429` | `RATE_LIMIT_EXCEEDED`, точный `Retry-After` | Нет business result |
| Валидная активация | `204` | Тело отсутствует | `User.isActive=true`, token `usedAt` |
| Неизвестный/wrong-purpose token | `400` | `INVALID_TOKEN` | Нет изменений |
| Missing token | `422` | `VALIDATION_ERROR` | Нет изменений |
| Expired/used/invalidated token | `410` | `TOKEN_EXPIRED` / `TOKEN_USED` / `TOKEN_INVALIDATED` | Нет изменений |
| Apply-token rate limit | `429` | `RATE_LIMIT_EXCEEDED`, точный `Retry-After` | Нет изменений |
| Непредвиденная ошибка | `500` | Standard safe envelope с `requestId` | Полный commit или полный rollback |

Для всех ответов действуют JSON/camelCase/UUID/RFC3339 UTC и `X-Request-Id` из
[общих соглашений](api/http-contracts.md#2-общие-соглашения). Непустые
неизвестные JSON-поля отклоняются с `400 INVALID_REQUEST` согласно принятому
OQ-015 и [HTTP 2.6](api/http-contracts.md#26-граница-json-запроса).

## 12. Тестовые данные

Все адреса используют зарезервированный домен `.test`; значения нейтральны и не являются реальными персональными или секретными данными.

| ID | Вход/fixture | Назначение | Ожидаемый результат |
|---|---|---|---|
| `TD-01` | `name="Alex Doe"`, `email="  User.One+E1@Example.Test  "`, `password="A2345678901!"`, key `11111111-1111-4111-8111-111111111111` | Валидный путь; password ровно 12 code points | `201`, email `user.one+e1@example.test`, затем письмо и `204` |
| `TD-02` | Body без `name` | Required field | `422 VALIDATION_ERROR`, violation `name`, no data |
| `TD-03` | `name="   "` | Непустой name/DB boundary | `422`, no data |
| `TD-04` | Body без `email` | Required field | `422`, violation `email`, no data |
| `TD-05` | `email="not-an-email"` | Документированный invalid format | `422`, `INVALID_EMAIL`, no data |
| `TD-06` | Body без `password` | Required field | `422`, no data |
| `TD-07` | `"a" × 12` | Минимум password | При остальных валидных данных принимается |
| `TD-08` | `"a" × 128` | Максимум password | Принимается |
| `TD-09` | `"a" × 11` | Ниже минимума | `422 PASSWORD_POLICY_VIOLATION` |
| `TD-10` | `"a" × 129` | Выше максимума | `422 PASSWORD_POLICY_VIOLATION` |
| `TD-11` | `" " × 12` | Только whitespace | `422 PASSWORD_POLICY_VIOLATION` |
| `TD-12` | 12+ code points с внешними пробелами и `A` внутри | Нет trim/normalization password | Принимается; только исходное значение проверяет hash |
| `TD-13` | Existing `user.one@example.test`; input `" User.One@Example.Test "` | Normalized conflict | `409 EMAIL_ALREADY_EXISTS`, no duplicate |
| `TD-14` | Валидный body без key | Missing header | `400 IDEMPOTENCY_KEY_REQUIRED` |
| `TD-15` | Один key + одинаковый body; затем один key + изменённый `name` | Idempotent replay/reuse | Исходный `201`; затем `409 IDEMPOTENCY_KEY_REUSED` |
| `TD-16` | Raw token, hash которого отсутствует | Нет capability/«чужие данные» | `400 INVALID_TOKEN`, no changes |
| `TD-17` | Fixtures: valid, `expiresAt=now`, used, invalidated, wrong-purpose token | Lifecycle boundaries | `204`, `410 EXPIRED/USED/INVALIDATED`, `400 INVALID_TOKEN` |
| `TD-18` | Malformed JSON `{"name":` | Syntax | `400`, safe envelope, no data |
| `TD-19` | Два concurrent request с same key/body | Concurrent replay | Два одинаковых исходных результата, один набор |
| `TD-20` | Два concurrent request с distinct keys/same normalized email | Unique race | Один `201`, один `409`, один набор |

Для token-тестов fixture использует отдельный тестовый `keyVersion` и HMAC key,
которые не применяются вне `test`; проверяются формат, lookup текущим ключом,
отказ неизвестной версии и совместимость retention key. Для rate-limit и retry
тестов fixture задаёт контролируемые IP, normalized email, token hash, clock и
число предыдущих попыток; нормативные значения берутся из [HTTP 17](api/http-contracts.md#17-rate-limits), [ADR-014](adr/architecture-decisions.md#adr-014-нормативный-операционный-профиль-безопасности-и-хранения-mvp) и [ADR-016](adr/architecture-decisions.md#adr-016-криптографический-профиль-useractiontoken).

## 13. Матрица трассируемости

Сокращения сущностей: `U` — [`User`](data-model/entities.md#1-user), `T` — [`UserActionToken`](data-model/entities.md#10-useractiontoken), `I` — [`IdempotencyRecord`](data-model/entities.md#11-idempotencyrecord), `O` — [`EmailDeliveryOutbox`](data-model/entities.md#12-emaildeliveryoutbox).

| AC | Пользовательский шаг | Бизнес-правило | Сущность ER | HTTP-контракт | ADR | Тест |
|---|---|---|---|---|---|---|
| AC-001 | Полный путь | [USR-001—003, SEC-006](business-rules/business-rules.md#br-usr-001-регистрация) | U,T,I,O | [8.1, 8.3, 18](api/http-contracts.md#81-регистрация) | [001,006,007,013](adr/architecture-decisions.md#adr-001-архитектура-mvp-как-модульный-монолит) | E2E, INT, MAN |
| AC-002 | Регистрация | [USR-001/003](business-rules/business-rules.md#br-usr-001-регистрация) | U,T,I,O | [8.1](api/http-contracts.md#81-регистрация) | [012](adr/architecture-decisions.md#adr-012-границы-и-http-соглашения-mvp) | API, INT |
| AC-003 | Получить email | [SEC-006](business-rules/business-rules.md#br-sec-006-надёжная-постановка-email) | T,O | [18](api/http-contracts.md#18-транзакционность-команд) | [013](adr/architecture-decisions.md#adr-013-надёжная-доставка-email-через-transactional-outbox-и-rabbitmq) | INT, E2E, MAN |
| AC-004 | Активировать | [USR-002, SEC-003](business-rules/business-rules.md#br-usr-002-первичная-активация) | U,T | [8.3](api/http-contracts.md#83-активация-аккаунта) | [006,007,016](adr/architecture-decisions.md#adr-006-жизненный-цикл-аккаунта-и-сессий) | API, INT |
| AC-005 | Остаться signed-out | [USR-002/003](business-rules/business-rules.md#br-usr-002-первичная-активация) | U | [8.1/8.3](api/http-contracts.md#81-регистрация) | [006](adr/architecture-decisions.md#adr-006-жизненный-цикл-аккаунта-и-сессий) | API, INT, FE |
| AC-006 | Ввести name | [TXN-002](business-rules/business-rules.md#br-txn-002-ответственность-бд) | U | [3,8.1](api/http-contracts.md#3-стандартная-модель-ошибки) | [011,012](adr/architecture-decisions.md#adr-011-разделение-ответственности-бд-и-доменного-слоя) | API, FE |
| AC-007 | Ввести name | [TXN-002](business-rules/business-rules.md#br-txn-002-ответственность-бд) | U | [3,8.1](api/http-contracts.md#3-стандартная-модель-ошибки) | [011](adr/architecture-decisions.md#adr-011-разделение-ответственности-бд-и-доменного-слоя) | UT, API, INT |
| AC-008 | Ввести email | [USR-001](business-rules/business-rules.md#br-usr-001-регистрация) | U | [3,8.1](api/http-contracts.md#3-стандартная-модель-ошибки) | [012](adr/architecture-decisions.md#adr-012-границы-и-http-соглашения-mvp) | API, FE |
| AC-009 | Ввести email | [USR-001](business-rules/business-rules.md#br-usr-001-регистрация) | U | [3,8.1](api/http-contracts.md#3-стандартная-модель-ошибки) | [012](adr/architecture-decisions.md#adr-012-границы-и-http-соглашения-mvp) | UT, API, FE |
| AC-010 | Ввести email | [USR-001](business-rules/business-rules.md#br-usr-001-регистрация) | U | [8.1](api/http-contracts.md#81-регистрация) | [006,011](adr/architecture-decisions.md#adr-006-жизненный-цикл-аккаунта-и-сессий) | UT, API, INT |
| AC-011 | Ввести password | [USR-009](business-rules/business-rules.md#br-usr-009-единая-политика-паролей) | U | [3,8.1](api/http-contracts.md#3-стандартная-модель-ошибки) | [006](adr/architecture-decisions.md#adr-006-жизненный-цикл-аккаунта-и-сессий) | API, FE |
| AC-012 | Ввести password | [USR-009](business-rules/business-rules.md#br-usr-009-единая-политика-паролей) | U | [8.1](api/http-contracts.md#81-регистрация) | [006](adr/architecture-decisions.md#adr-006-жизненный-цикл-аккаунта-и-сессий) | UT, API |
| AC-013 | Ввести password | [USR-009](business-rules/business-rules.md#br-usr-009-единая-политика-паролей) | U | [8.1](api/http-contracts.md#81-регистрация) | [006](adr/architecture-decisions.md#adr-006-жизненный-цикл-аккаунта-и-сессий) | UT, API |
| AC-014 | Ввести password | [USR-009](business-rules/business-rules.md#br-usr-009-единая-политика-паролей) | U | [3.2,8.1](api/http-contracts.md#32-основные-коды-ошибок) | [006](adr/architecture-decisions.md#adr-006-жизненный-цикл-аккаунта-и-сессий) | UT, API, FE |
| AC-015 | Ввести password | [USR-009](business-rules/business-rules.md#br-usr-009-единая-политика-паролей) | U | [3.2,8.1](api/http-contracts.md#32-основные-коды-ошибок) | [006](adr/architecture-decisions.md#adr-006-жизненный-цикл-аккаунта-и-сессий) | UT, API, FE |
| AC-016 | Ввести password | [USR-009](business-rules/business-rules.md#br-usr-009-единая-политика-паролей) | U | [3.2,8.1](api/http-contracts.md#32-основные-коды-ошибок) | [006](adr/architecture-decisions.md#adr-006-жизненный-цикл-аккаунта-и-сессий) | UT, API |
| AC-017 | Ввести password | [USR-009](business-rules/business-rules.md#br-usr-009-единая-политика-паролей) | U | [8.1](api/http-contracts.md#81-регистрация) | [006](adr/architecture-decisions.md#adr-006-жизненный-цикл-аккаунта-и-сессий) | UT, INT |
| AC-018 | Отправить форму | [TXN-001](business-rules/business-rules.md#br-txn-001-межтабличные-инварианты) | — | [3.1](api/http-contracts.md#31-основные-http-статусы) | [012](adr/architecture-decisions.md#adr-012-границы-и-http-соглашения-mvp) | API, FE |
| AC-019 | Открыть ссылку | [SEC-003](business-rules/business-rules.md#br-sec-003-useractiontoken) | T | [3,8.3](api/http-contracts.md#83-активация-аккаунта) | [007,012](adr/architecture-decisions.md#adr-007-унифицированные-одноразовые-токены) | API, FE |
| AC-020 | Зарегистрироваться | [USR-001](business-rules/business-rules.md#br-usr-001-регистрация) | U,T,O | [8.1](api/http-contracts.md#81-регистрация) | [011,012](adr/architecture-decisions.md#adr-011-разделение-ответственности-бд-и-доменного-слоя) | UT, API, INT, FE |
| AC-021 | Зарегистрироваться | [USR-001](business-rules/business-rules.md#br-usr-001-регистрация) | U | [8.1](api/http-contracts.md#81-регистрация) | [011](adr/architecture-decisions.md#adr-011-разделение-ответственности-бд-и-доменного-слоя) | UT, API, INT |
| AC-022 | Отправить регистрацию | [IDEM-001/002](business-rules/business-rules.md#br-idem-001-общий-механизм) | I | [4](api/http-contracts.md#4-идемпотентность) | [009](adr/architecture-decisions.md#adr-009-централизованная-идемпотентность) | API, FE |
| AC-023 | Повторить регистрацию | [IDEM-003/005](business-rules/business-rules.md#br-idem-003-повтор) | U,T,I,O | [4.2](api/http-contracts.md#42-поведение) | [009](adr/architecture-decisions.md#adr-009-централизованная-идемпотентность) | API, INT |
| AC-024 | Изменить повтор | [IDEM-003](business-rules/business-rules.md#br-idem-003-повтор) | I | [4.2](api/http-contracts.md#42-поведение) | [009](adr/architecture-decisions.md#adr-009-централизованная-идемпотентность) | UT, API, INT |
| AC-025 | Повторить одновременно | [IDEM-004](business-rules/business-rules.md#br-idem-004-конкурентный-повтор) | U,T,I,O | [4.2](api/http-contracts.md#42-поведение) | [009](adr/architecture-decisions.md#adr-009-централизованная-идемпотентность) | INT, API |
| AC-026 | Повторить на TTL | [IDEM-005, SEC-005](business-rules/business-rules.md#br-idem-005-окно-гарантии) | I | [4.2](api/http-contracts.md#42-поведение) | [009,014](adr/architecture-decisions.md#adr-009-централизованная-идемпотентность) | UT, INT, API |
| AC-027 | Зарегистрировать одновременно | [USR-001, TXN-002](business-rules/business-rules.md#br-usr-001-регистрация) | U,T,I,O | [8.0/8.1](api/http-contracts.md#80-общие-правила-выдачи-useractiontoken) | [011,012](adr/architecture-decisions.md#adr-011-разделение-ответственности-бд-и-доменного-слоя) | INT, API |
| AC-028 | Получить technical error | [IDEM-004, SEC-006](business-rules/business-rules.md#br-idem-004-конкурентный-повтор) | U,T,I,O | [18](api/http-contracts.md#18-транзакционность-команд) | [009,011,013](adr/architecture-decisions.md#adr-009-централизованная-идемпотентность) | INT, API |
| AC-029 | Проверить результат | [TXN-001/002](business-rules/business-rules.md#br-txn-001-межтабличные-инварианты) | U,T,I,O | [8,18](api/http-contracts.md#8-регистрация-и-аутентификация) | [011](adr/architecture-decisions.md#adr-011-разделение-ответственности-бд-и-доменного-слоя) | INT |
| AC-030 | Передать секреты | [USR-009, SEC-003/006](business-rules/business-rules.md#br-usr-009-единая-политика-паролей) | U,T,O | [3,8](api/http-contracts.md#3-стандартная-модель-ошибки) | [007,013](adr/architecture-decisions.md#adr-007-унифицированные-одноразовые-токены) | UT, INT, API |
| AC-031 | Получить ответ | [SEC-003](business-rules/business-rules.md#br-sec-003-useractiontoken) | U,T,I,O | [2.1/2.4](api/http-contracts.md#21-формат-данных) | [012](adr/architecture-decisions.md#adr-012-границы-и-http-соглашения-mvp) | INT, API |
| AC-032 | Выполнить сценарий | [TXN-001](business-rules/business-rules.md#br-txn-001-межтабличные-инварианты) | U,T,I,O | [18](api/http-contracts.md#18-транзакционность-команд) | [011](adr/architecture-decisions.md#adr-011-разделение-ответственности-бд-и-доменного-слоя) | INT |
| AC-033 | Применить неизвестную ссылку | [SEC-003](business-rules/business-rules.md#br-sec-003-useractiontoken) | U,T | [8.3](api/http-contracts.md#83-активация-аккаунта) | [007,012](adr/architecture-decisions.md#adr-007-унифицированные-одноразовые-токены) | UT, API, FE |
| AC-034 | Применить истёкшую ссылку | [SEC-003/005](business-rules/business-rules.md#br-sec-003-useractiontoken) | U,T | [8.3](api/http-contracts.md#83-активация-аккаунта) | [007,014](adr/architecture-decisions.md#adr-007-унифицированные-одноразовые-токены) | UT, API, INT, FE |
| AC-035 | Применить ссылку повторно | [SEC-003](business-rules/business-rules.md#br-sec-003-useractiontoken) | U,T | [8.3](api/http-contracts.md#83-активация-аккаунта) | [007](adr/architecture-decisions.md#adr-007-унифицированные-одноразовые-токены) | UT, API, INT, FE |
| AC-036 | Применить отозванную ссылку | [SEC-004](business-rules/business-rules.md#br-sec-004-аннулирование) | U,T | [8.3](api/http-contracts.md#83-активация-аккаунта) | [007](adr/architecture-decisions.md#adr-007-унифицированные-одноразовые-токены) | UT, API, INT, FE |
| AC-037 | Применить чужой purpose | [SEC-003](business-rules/business-rules.md#br-sec-003-useractiontoken) | U,T | [8.3](api/http-contracts.md#83-активация-аккаунта) | [007](adr/architecture-decisions.md#adr-007-унифицированные-одноразовые-токены) | UT, API, INT |
| AC-038 | Активировать одновременно | [SEC-003, TXN-001](business-rules/business-rules.md#br-sec-003-useractiontoken) | U,T | [8.3,18](api/http-contracts.md#83-активация-аккаунта) | [007,011](adr/architecture-decisions.md#adr-007-унифицированные-одноразовые-токены) | INT, API |
| AC-039 | Получить technical error | [TXN-001](business-rules/business-rules.md#br-txn-001-межтабличные-инварианты) | U,T | [18](api/http-contracts.md#18-транзакционность-команд) | [011](adr/architecture-decisions.md#adr-011-разделение-ответственности-бд-и-доменного-слоя) | INT, API |
| AC-040 | Подтвердить активацию | [USR-002, SEC-003](business-rules/business-rules.md#br-usr-002-первичная-активация) | U,T | [8.3](api/http-contracts.md#83-активация-аккаунта) | [006,007](adr/architecture-decisions.md#adr-006-жизненный-цикл-аккаунта-и-сессий) | UT, INT, API |
| AC-041 | Регистрироваться с IP | [SEC-007](business-rules/business-rules.md#br-sec-007-rate-limits-mvp) | — | [17](api/http-contracts.md#17-rate-limits) | [014](adr/architecture-decisions.md#adr-014-нормативный-операционный-профиль-безопасности-и-хранения-mvp) | UT, API, INT |
| AC-042 | Регистрировать email | [SEC-007](business-rules/business-rules.md#br-sec-007-rate-limits-mvp) | — | [17](api/http-contracts.md#17-rate-limits) | [014](adr/architecture-decisions.md#adr-014-нормативный-операционный-профиль-безопасности-и-хранения-mvp) | UT, API, INT |
| AC-043 | Применять token с IP | [SEC-007](business-rules/business-rules.md#br-sec-007-rate-limits-mvp) | T | [17](api/http-contracts.md#17-rate-limits) | [014](adr/architecture-decisions.md#adr-014-нормативный-операционный-профиль-безопасности-и-хранения-mvp) | UT, API, INT |
| AC-044 | Применять один token | [SEC-007](business-rules/business-rules.md#br-sec-007-rate-limits-mvp) | T | [17](api/http-contracts.md#17-rate-limits) | [014](adr/architecture-decisions.md#adr-014-нормативный-операционный-профиль-безопасности-и-хранения-mvp) | UT, API, INT |
| AC-045 | Повторить sensitive action | [SEC-007](business-rules/business-rules.md#br-sec-007-rate-limits-mvp) | — | [17](api/http-contracts.md#17-rate-limits) | [014](adr/architecture-decisions.md#adr-014-нормативный-операционный-профиль-безопасности-и-хранения-mvp) | UT, API |
| AC-046 | Завершить регистрацию | [SEC-006](business-rules/business-rules.md#br-sec-006-надёжная-постановка-email) | U,T,O | [8.0,18](api/http-contracts.md#80-общие-правила-выдачи-useractiontoken) | [013](adr/architecture-decisions.md#adr-013-надёжная-доставка-email-через-transactional-outbox-и-rabbitmq) | INT, API |
| AC-047 | Ожидать письмо | [SEC-006](business-rules/business-rules.md#br-sec-006-надёжная-постановка-email) | O | [18](api/http-contracts.md#18-транзакционность-команд) | [013](adr/architecture-decisions.md#adr-013-надёжная-доставка-email-через-transactional-outbox-и-rabbitmq) | INT |
| AC-048 | Ожидать письмо | [SEC-006](business-rules/business-rules.md#br-sec-006-надёжная-постановка-email) | T,O | [18](api/http-contracts.md#18-транзакционность-команд) | [013,014](adr/architecture-decisions.md#adr-013-надёжная-доставка-email-через-transactional-outbox-и-rabbitmq) | UT, INT |
| AC-049 | Ожидать письмо | [SEC-005/006](business-rules/business-rules.md#br-sec-005-сроки-хранения) | T,O | [18](api/http-contracts.md#18-транзакционность-команд) | [013,014](adr/architecture-decisions.md#adr-013-надёжная-доставка-email-через-transactional-outbox-и-rabbitmq) | UT, INT |
| AC-050 | Получить письмо | [SEC-005/006](business-rules/business-rules.md#br-sec-005-сроки-хранения) | T,O | [18](api/http-contracts.md#18-транзакционность-команд) | [013](adr/architecture-decisions.md#adr-013-надёжная-доставка-email-через-transactional-outbox-и-rabbitmq) | INT, E2E, MAN |
| AC-051 | Ожидать письмо при SMTP error | [SEC-006](business-rules/business-rules.md#br-sec-006-надёжная-постановка-email) | O | [18](api/http-contracts.md#18-транзакционность-команд) | [013,014](adr/architecture-decisions.md#adr-013-надёжная-доставка-email-через-transactional-outbox-и-rabbitmq) | UT, INT |
| AC-052 | Получить duplicate delivery | [SEC-006](business-rules/business-rules.md#br-sec-006-надёжная-постановка-email) | O | [18](api/http-contracts.md#18-транзакционность-команд) | [013](adr/architecture-decisions.md#adr-013-надёжная-доставка-email-через-transactional-outbox-и-rabbitmq) | UT, INT |
| AC-053 | Получить редкий duplicate email | [SEC-006](business-rules/business-rules.md#br-sec-006-надёжная-постановка-email) | U,T,O | [18](api/http-contracts.md#18-транзакционность-команд) | [013](adr/architecture-decisions.md#adr-013-надёжная-доставка-email-через-transactional-outbox-и-rabbitmq) | INT, MAN |
| AC-054 | Получить письмо | [SEC-006](business-rules/business-rules.md#br-sec-006-надёжная-постановка-email) | O | [18](api/http-contracts.md#18-транзакционность-команд) | [013](adr/architecture-decisions.md#adr-013-надёжная-доставка-email-через-transactional-outbox-и-rabbitmq) | INT |
| AC-055 | Открыть регистрацию | [USR-001](business-rules/business-rules.md#br-usr-001-регистрация) | — | [8.1](api/http-contracts.md#81-регистрация) | [012](adr/architecture-decisions.md#adr-012-границы-и-http-соглашения-mvp) | FE, E2E |
| AC-056 | Нажать submit повторно | [IDEM-003/004](business-rules/business-rules.md#br-idem-003-повтор) | U,T,I,O | [4](api/http-contracts.md#4-идемпотентность) | [009](adr/architecture-decisions.md#adr-009-централизованная-идемпотентность) | FE, E2E, API |
| AC-057 | Увидеть registration result | [USR-001/002](business-rules/business-rules.md#br-usr-001-регистрация) | U,T,O | [8.1](api/http-contracts.md#81-регистрация) | [006,013](adr/architecture-decisions.md#adr-006-жизненный-цикл-аккаунта-и-сессий) | FE, E2E |
| AC-058 | Исправить registration error | [USR-009, IDEM, SEC-007](business-rules/business-rules.md#br-usr-009-единая-политика-паролей) | U,I | [3,8.1,17](api/http-contracts.md#3-стандартная-модель-ошибки) | [009,012,014](adr/architecture-decisions.md#adr-009-централизованная-идемпотентность) | FE, API |
| AC-059 | Увидеть activation result | [USR-002](business-rules/business-rules.md#br-usr-002-первичная-активация) | U,T | [8.3](api/http-contracts.md#83-активация-аккаунта) | [006,007](adr/architecture-decisions.md#adr-006-жизненный-цикл-аккаунта-и-сессий) | FE, E2E |
| AC-060 | Увидеть token error | [SEC-003/004/007](business-rules/business-rules.md#br-sec-003-useractiontoken) | U,T | [8.3,17](api/http-contracts.md#83-активация-аккаунта) | [007,014](adr/architecture-decisions.md#adr-007-унифицированные-одноразовые-токены) | FE, API, E2E |
| AC-061 | Восстановиться после tech error | [IDEM-003/004, SEC-006](business-rules/business-rules.md#br-idem-003-повтор) | U,T,I,O | [2.4,3,18](api/http-contracts.md#24-request-id) | [009,012,013](adr/architecture-decisions.md#adr-009-централизованная-идемпотентность) | API, FE, INT, E2E |

Полное покрытие применимых правил:

| Бизнес-правило | Критерии |
|---|---|
| [BR-USR-001](business-rules/business-rules.md#br-usr-001-регистрация) | `AC-001—002`, `AC-010`, `AC-020—021`, `AC-027`, `AC-046`, `AC-055`, `AC-057` |
| [BR-USR-002](business-rules/business-rules.md#br-usr-002-первичная-активация) | `AC-001`, `AC-004—005`, `AC-040`, `AC-057`, `AC-059` |
| [BR-USR-003](business-rules/business-rules.md#br-usr-003-неактивный-аккаунт) | `AC-001—002`, `AC-005` |
| [BR-USR-009](business-rules/business-rules.md#br-usr-009-единая-политика-паролей) | `AC-011—017`, `AC-030`, `AC-058` |
| [BR-IDEM-001](business-rules/business-rules.md#br-idem-001-общий-механизм) | `AC-022—025`, `AC-056`, `AC-061` |
| [BR-IDEM-002](business-rules/business-rules.md#br-idem-002-область-ключа) | `AC-022`, `AC-025`, `AC-029` |
| [BR-IDEM-003](business-rules/business-rules.md#br-idem-003-повтор) | `AC-023—024`, `AC-056`, `AC-061` |
| [BR-IDEM-004](business-rules/business-rules.md#br-idem-004-конкурентный-повтор) | `AC-025`, `AC-028`, `AC-061` |
| [BR-IDEM-005](business-rules/business-rules.md#br-idem-005-окно-гарантии) | `AC-023`, `AC-026` |
| [BR-TXN-001](business-rules/business-rules.md#br-txn-001-межтабличные-инварианты) | `AC-028—029`, `AC-032`, `AC-038—040`, `AC-046` |
| [BR-TXN-002](business-rules/business-rules.md#br-txn-002-ответственность-бд) | `AC-007`, `AC-020`, `AC-027`, `AC-029` |
| [BR-SEC-003](business-rules/business-rules.md#br-sec-003-useractiontoken) | `AC-004`, `AC-030`, `AC-033—040` |
| [BR-SEC-004](business-rules/business-rules.md#br-sec-004-аннулирование) | `AC-029`, `AC-036`, `AC-049` |
| [BR-SEC-005](business-rules/business-rules.md#br-sec-005-сроки-хранения) | `AC-026`, `AC-034`, `AC-049—050` |
| [BR-SEC-006](business-rules/business-rules.md#br-sec-006-надёжная-постановка-email) | `AC-001`, `AC-003`, `AC-028`, `AC-030`, `AC-046—054`, `AC-061` |
| [BR-SEC-007](business-rules/business-rules.md#br-sec-007-rate-limits-mvp) | `AC-041—045`, `AC-058`, `AC-060` |

Проверка покрытия:

- каждое применимое правило `BR-USR-001—003`, `BR-USR-009`, `BR-IDEM-001—005`, `BR-TXN-001—002`, `BR-SEC-003—007` связано минимум с одним AC;
- обе HTTP-операции и все их документированные successful/error categories покрыты;
- все четыре сущности минимального ER-фрагмента покрыты;
- критериев без ссылки на согласованный источник нет.

## 14. Рекомендуемые уровни автоматизации

| Уровень | Обязательное покрытие |
|---|---|
| Unit | Password/email rules, idempotency request equivalence/TTL, token lifecycle, rate-limit boundaries, retry schedule |
| Интеграционный | PostgreSQL constraints/transactions/concurrency, outbox lifecycle, RabbitMQ publisher confirm, consumer/Mailer behavior |
| Functional API | Все `201/204/400/409/410/422/429/500` случаи двух endpoint, error envelope и headers |
| Frontend component | Initial/loading/success/field/business/token/technical states, double submit и безопасный retry |
| E2E | Полный `AC-001`, double submit, один representative negative token path и safe technical recovery |
| Ручная демонстрация | Письмо в Mailpit, переход по реальной ссылке и наблюдение итогового UI/БД state |

Каждый `Critical` критерий должен иметь хотя бы один автоматический уровень. `MAN` дополняет, но не заменяет автоматическую проверку.

### Сводка критериев

| Тип | Количество |
|---|---:|
| Успешный пользовательский результат | 5 |
| Validation | 14 |
| Уникальность email | 2 |
| Идемпотентность и concurrency регистрации | 6 |
| Атомарность, целостность и секреты | 5 |
| Права/token lifecycle/state transition | 8 |
| Rate limits | 5 |
| Outbox/RabbitMQ/email | 9 |
| UI/error handling | 7 |
| **Всего** | **61** |

| Приоритет | Количество |
|---|---:|
| `Critical` | 23 |
| `High` | 37 |
| `Medium` | 1 |
| `Low` | 0 |
| **Всего** | **61** |

## 15. Минимальный smoke-набор

После каждого развёртывания минимальный smoke подтверждает:

| Smoke | AC | Проверка |
|---|---|---|
| `SMOKE-01` | `AC-001` | Уникальный посетитель проходит UI-регистрацию, получает первое письмо через configured SMTP/Mailpit и активирует аккаунт без сессии |
| `SMOKE-02` | `AC-020` | Повторная регистрация normalized existing email даёт `409 EMAIL_ALREADY_EXISTS` без второго набора |
| `SMOKE-03` | `AC-023` | Повтор same key/same body возвращает исходный `201/body` и не создаёт дублей |
| `SMOKE-04` | `AC-033` | Неизвестный token даёт `400 INVALID_TOKEN`, не меняя пользователей |
| `SMOKE-05` | `AC-056` | Double submit одной UI-попытки приводит к одному аккаунту и одному durable email intent |

`SMOKE-01` является основной демонстрацией. Остальные проверки защищают наиболее рискованные границы unique/idempotency/token access/UI retry. Time-dependent rate limits, retry schedules и fault injection остаются обязательными regression tests, но не включены в каждый deployment smoke.

## 16. Явно исключённое поведение

| Не входит в критерии среза | Где рассматривается |
|---|---|
| Повторный запрос activation link и реактивация после деактивации | Остальная часть `MVP-SC-001` и `MVP-SC-007` |
| Login, JWT, refresh cookie/rotation и `UserSession` | `MVP-SC-002` |
| Logout | `MVP-SC-003` |
| Password reset | `MVP-SC-004` |
| Password change | `MVP-SC-005` |
| Изменение имени/email и `CHANGE_EMAIL` flow | `MVP-SC-006` |
| Invitation, `Connect`, долги, transfer, balance/history | `MVP-SC-008—018` |
| Push/SMS/in-app/financial notifications | `MVP-SC-029`, `Out of scope` |
| Admin UI и ручная активация оператором | `MVP-SC-024`, `Out of scope` |
| Production email provider API и гарантия получения конечным mailbox | Будущая эксплуатационная конфигурация; SMTP abstraction сохраняется |
| Full monitoring/replay UI и физический retention cleanup | `MVP-SC-022`; для среза обязательны безопасные логи, failure transport и нормативные состояния |
| Детальный visual design, component architecture, class/library choices | Последующие design/implementation задачи |
| Неописанные max lengths для name/email | Требуют нормативного решения; не предполагаются E1-06 |

Фоновые email-процессы не исключены, потому что являются обязательной частью первого пользовательского результата по BR-SEC-006/ADR-013.

## 17. Решения, полученные от пользователя

Дополнительные вопросы в E1-06 не потребовались. Продуктовые решения пользователя не добавлялись: критерии выведены из принятого E1-05 и нормативных документов Block A. Журнал [open-questions.md](open-questions.md) не изменялся.

## 18. Риски и допущения

- Управляемое время необходимо для точной и быстрой автоматизации TTL, sliding window и retry; конкретный clock/test harness является обратимой технической деталью.
- Интеграционные проверки broker/SMTP должны уметь наблюдать publisher confirms и sync Mailer outcome; конкретный instrumentation не задаётся.
- `at-least-once` нормативно допускает редкое повторное письмо в узком crash-window (`AC-053`), но не повторные аккаунты, токены или переходы.
- Mailpit доказывает локальный SMTP handoff, а не доставку production mailbox.
- Неизвестные JSON-поля отклоняются согласно OQ-015/HTTP 2.6; пределы длины
  `name`/`email` не заданы и критериями не выдумываются.
- Отдельный resend flow исключён, поэтому UI expired/invalid token не должен притворяться, что может выпустить новую ссылку.
- Долгосрочная физическая очистка остаётся требованием готовности всего MVP, но не является частью приёмки первого среза.

## 19. Итоговый чек-лист приёмки

- [ ] `AC-001` проходит полностью: регистрация → первое письмо → активация.
- [ ] Неаутентифицированный посетитель может зарегистрироваться, но после активации session не создаётся.
- [ ] Email нормализуется, unique соблюдается, password policy проверяется backend.
- [ ] Некорректные `name/email/password/token` отклоняются документированными статусами.
- [ ] Registration idempotency выдерживает same/different/concurrent retry и ровно 24-часовую границу.
- [ ] Invalid/expired/used/invalidated/wrong-purpose token не меняет данные.
- [ ] Конкурентные registration/activation операции не создают дубликат или partial state.
- [ ] `User`, token, outbox и idempotency result сохраняются с нормативными связями и ограничениями.
- [ ] Секреты не хранятся открыто и не раскрываются в API/UI/logs.
- [ ] Outbox, publisher confirm, relay/SMTP retries, failure transport и payload cleanup работают по BR-SEC-006/ADR-013/014.
- [ ] Все registration/apply-token rate limits и точный `Retry-After` проверены.
- [ ] API соответствует контрактам и возвращает standard error envelope с request ID.
- [ ] UI отображает initial/loading/success/validation/business/token/technical states и безопасный retry.
- [ ] Каждый `Critical` AC автоматизирован; `SMOKE-01—05` выполняются после развёртывания.
- [ ] Исключённые login/resend/admin/financial возможности не добавлены.

Первый вертикальный срез считается принятым только при выполнении всех `Critical` и `High` критериев. `AC-053` (`Medium`) фиксирует допустимую границу delivery semantics и не требует устранения нормативно разрешённого редкого duplicate email.
