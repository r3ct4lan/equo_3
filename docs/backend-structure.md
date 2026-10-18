# Equo — фактическая структура backend

| Поле | Значение |
|---|---|
| Назначение | Зафиксировать выражение принятых ADR в структуре Symfony backend |
| Статус | Accepted |
| Версия | 16 |
| Дата актуальности | 2026-10-12 |
| Владелец | Maksim Smolkov |
| Источник | E1-07—E1-12, backend login stage, ADR-001, ADR-006, ADR-007, ADR-012—018 и фактическая реализация auth среза |

## 1. Принцип организации

Backend остаётся одним Symfony-приложением и одним deployment. Первый сегмент
пространства имён после `App\` обозначает логический модуль. Все модули используют
одну PostgreSQL, но не обращаются к внутренним моделям друг друга.

Бизнес-модули строятся по ports-and-adapters и создают только необходимые части
трёх слоёв:

```text
App\<Module>\Domain
App\<Module>\Application
App\<Module>\Adapter
```

`App\Infrastructure` — отдельный технический модуль, а не четвёртый слой каждого
бизнес-модуля и не Shared Kernel.

В `App\IdentityAccess\` реализованы регистрация и активация, их Domain и
Application use cases, persistence, прикладная activation access policy,
password hashing, session security primitives, server-side session persistence
и backend login/refresh/`/me`, logout/logout-all, password reset endpoints.
Модули `Connects` и `Invitations` реализованы в SC-008. `Debts` и `Transfers`
реализованы в SC-011: создание расхода, ledger, финансовые read models и ACL.

## 2. Модули текущего этапа

| Модуль | Ответственность | Корневое пространство имён | Состояние |
|---|---|---|---|
| Identity and Access | Пользователь, жизненный цикл аккаунта, action token, регистрация, активация, login, refresh-сессия и current user | `App\IdentityAccess\` | Реализованы Domain/Application auth среза, login/refresh/`/me` endpoints, Bearer authenticator/principal, security primitives, session persistence, HTTP/security/persistence adapters и Doctrine records |
| Connects | Постоянная каноническая пара, get-or-create, чтение и ACL | `App\Connects\` | Domain/Application/Adapter и отдельный Doctrine record |
| Invitations | Подписанная ссылка, preview, атомарное принятие | `App\Invitations\` | Domain/Application/Adapter и отдельный Doctrine record |
| Debts | Агрегат расхода, равные доли, создание и чтение с ACL | `App\Debts\` | Domain/Application/Adapter, Debt и DebtParticipant records |
| Transfers | Источник баланса, автоматические доли, история и independent ACL | `App\Transfers\` | Domain/Application/Adapter и Transfer record |
| Infrastructure | Идемпотентность, email delivery/outbox и общие технические входы | `App\Infrastructure\` | Реализованы HTTP infrastructure, Bearer JSON entry point, идемпотентность, зашифрованный outbox, relay, Messenger consumer, Mailer integration и schema listener |

`IdempotencyRecord` по ADR-009 и `EmailDeliveryOutbox` по ADR-013 принадлежат
`Infrastructure`. Они являются техническими Doctrine records и не требуют
дублирующей доменной модели.

## 3. Фактическое дерево

```text
backend/
├── config/
│   ├── packages/
│   │   ├── doctrine.yaml
│   │   ├── messenger.yaml
│   │   └── validator.yaml
│   ├── routes/test/http_fixture.yaml
│   └── services_test.yaml
├── migrations/
│   ├── Version20260731153000.php
│   └── Version20260808223000.php
├── src/
│   ├── IdentityAccess/
│   │   ├── Domain/
│   │   │   ├── Access/{UserActionToken,UserActionTokenPurpose,UserSession}.php
│   │   │   └── User/{EmailAddress,PasswordPolicy,User}.php
│   │   ├── Application/
│   │   │   ├── {Activate,CurrentUser,Login,Refresh,Register}/
│   │   │   ├── Api/
│   │   │   ├── Authorization/
│   │   │   └── Port/
│   │   └── Adapter/
│   │       ├── Http/{ActivateController,AuthCookieFactory,LoginController,MeController,RefreshController,RefreshRequestGuard,RegisterController}.php
│   │       ├── Persistence/Doctrine/
│   │       │   ├── DoctrineIdentityRepository.php
│   │       │   ├── DoctrineUserSessionRepository.php
│   │       │   └── Record/{UserActionTokenRecord,UserRecord,UserSessionRecord}.php
│   │       ├── Security/
│   │       └── System/
│   ├── Infrastructure/
│   │   ├── EmailDelivery/
│   │   │   ├── Command/RelayEmailOutboxCommand.php
│   │   │   ├── Messaging/
│   │   │   │   ├── FinalDeliveryFailureSubscriber.php
│   │   │   │   ├── SendUserActionEmail.php
│   │   │   │   ├── SendUserActionEmailHandler.php
│   │   │   │   └── UserActionEmailRetryStrategy.php
│   │   │   ├── Outbox/OutboxRelay.php
│   │   │   ├── Persistence/Doctrine/
│   │   │   │   ├── DoctrineEmailOutbox.php
│   │   │   │   └── Record/{EmailDeliveryOutboxRecord,EmailDeliveryStatus}.php
│   │   │   └── Security/PayloadCipher.php
│   │   ├── Idempotency/Persistence/Doctrine/
│   │   │   ├── DoctrineIdempotency.php
│   │   │   └── Record/IdempotencyRecord.php
│   │   ├── Persistence/Doctrine/
│   │   │   └── InitialSchemaForeignKeyListener.php
│   │   └── Http/
│   │       ├── ApiExceptionSubscriber.php
│   │       ├── BearerAuthenticationEntryPoint.php
│   │       ├── ApiJsonResponder.php
│   │       ├── ApiResponseSubscriber.php
│   │       ├── HealthController.php
│   │       ├── JsonContentTypeSubscriber.php
│   │       ├── RequestIdSubscriber.php
│   │       └── ValidationViolationNormalizer.php
│   └── Kernel.php
└── tests/
    ├── Architecture/
    │   └── ModuleDependencyTest.php
    ├── Fixture/Http/
    │   └── test-only DTO, response and controller
    ├── Infrastructure/Http/
    │   ├── HealthControllerTest.php
    │   └── HttpInfrastructureTest.php
    ├── Integration/
    │   ├── EmailDelivery/EmailDeliveryFlowTest.php
    │   ├── IdentityAccess/
    │   └── Persistence/InitialSchemaTest.php
    ├── IdentityAccess/
    │   ├── Application/Authorization/ActivationAccessPolicyTest.php
    │   └── Adapter/Security/SymfonyPasswordHasherTest.php
    └── bootstrap.php
```

Глобальные Symfony-scaffold каталоги `src/Controller`, `src/Entity` и
`src/Repository` отсутствуют: новый код размещается внутри модуля-владельца.

## 4. Каталоги и пространства имён

Composer задаёт `App\ => src/`, а тесты — `App\Tests\ => tests/`. Отдельные
PSR-4 mappings для модулей не нужны.

| Путь | Пространство имён | Назначение |
|---|---|---|
| `src/<Module>/Domain/` | `App\<Module>\Domain\` | Чистая предметная модель |
| `src/<Module>/Application/` | `App\<Module>\Application\` | Use cases, API и порты |
| `src/<Module>/Adapter/` | `App\<Module>\Adapter\` | HTTP, persistence и messaging adapters |
| `src/Infrastructure/` | `App\Infrastructure\` | Общие технические механизмы |
| `tests/<Module>/` | `App\Tests\<Module>\` | Тесты модуля |

Внутри `Application` используются:

- `Application\Api` — опубликованные межмодульные операции и DTO;
- `Application\Port` — исходящие интерфейсы, реализуемые adapters или
  `Infrastructure`.

Типичные adapters располагаются в `Adapter\Http`,
`Adapter\Persistence\Doctrine` и `Adapter\Messaging`. Создавать все каталоги в
каждом модуле заранее не требуется.

## 5. Ответственность слоёв

### Domain

- сущности, value objects, доменные сервисы и инварианты;
- зависит только от PHP и собственного Domain;
- не использует Symfony, Doctrine или модели соседних модулей;
- не содержит Doctrine attributes.

### Application

- команды, handlers, use cases, DTO и координация транзакций;
- зависит от собственного Domain и Application;
- публикует узкий `Application\Api`;
- объявляет внешние зависимости в `Application\Port`;
- не использует Symfony и Doctrine.

### Adapter

- преобразует HTTP, persistence и messaging contracts в application-вызовы;
- зависит от собственного Application и Domain;
- может использовать Symfony, Doctrine, Messenger и Mailer;
- для бизнес-агрегатов содержит отдельные Doctrine records и mapper;
- не создаёт универсальные base entities, repositories или mapper-framework.

### Infrastructure

- реализует общие технические механизмы и `Application\Port` бизнес-модулей;
- может вызывать опубликованный `Application\Api`;
- не импортирует чужие Domain, Adapter или внутренние Application-классы;
- не становится хранилищем общих предметных типов.

ADR-011 продолжает определять границу ответственности данных: PostgreSQL
обеспечивает локальные PK/FK/NOT NULL/CHECK/UNIQUE, а доменный код внутри
транзакции — межтабличные и зависящие от состояния правила.

## 6. Допустимые направления зависимостей

```text
Domain
  ↑
Application
  ↑
Adapter

другой бизнес-модуль ──→ <Module>\Application\Api
Infrastructure       ──→ <Module>\Application\Api|Port
```

Правила:

1. `Domain` не зависит от Application или Adapter.
2. `Application` не зависит от Adapter или Infrastructure.
3. `Adapter` зависит только от слоёв своего модуля и чужого опубликованного
   `Application\Api`.
4. Бизнес-модуль использует другой бизнес-модуль только через
   `Application\Api`.
5. `Infrastructure` может реализовать `Application\Port` и использовать
   `Application\Api`, но не внутренние модели бизнес-модуля.
6. Связывание портов и реализаций выполняется конфигурацией Symfony container.
7. Прямой SQL не используется для обхода доменных инвариантов.

Эти правила проверяет `tests/Architecture/ModuleDependencyTest.php`. Тест
контролирует соответствие namespace пути и квалифицированные зависимости.
Deptrac остаётся возможным следующим шагом, когда граф станет сложнее собственной
проверки.

## 7. Symfony service discovery и Doctrine

`config/services.yaml` включает autowire/autoconfigure для `src/`, но исключает:

- `Kernel.php`;
- все `*/Domain/`;
- бизнес-records в `*/Adapter/Persistence/Doctrine/Record/`;
- технические records в
  `Infrastructure/*/Persistence/Doctrine/Record/`.

Application services, adapters и технические services регистрируются
автоматически. Domain service с зависимостями регистрируется явно и остаётся
независимым от container.

`PasswordHashingPort` явно связан с `SymfonyPasswordHasher`. Фабрика Symfony
PasswordHasher получает нормативный алгоритм `auto`; открытый пароль и
конкретный framework type не пересекают Application boundary.

Общие request ID, exception и response subscribers зарегистрированы
автоматически как services `App\Infrastructure\Http`. Бизнес-controller их не
импортирует: стандартный Symfony `MapRequestPayload` обрабатывает transport DTO,
а `kernel.view` сериализует возвращённый response DTO/array. Test fixture
controller и routes подключаются только через `services_test.yaml` и
`routes/test/`.

Doctrine auto-mapping отключён. В `doctrine.yaml` явно зарегистрированы только
три фактически существующих persistence namespace: IdentityAccess records,
Infrastructure Idempotency records и Infrastructure EmailDelivery records.
Глобальный `App\Entity` не используется. Межмодульные FK остаются скалярными в
ORM и отражаются в SchemaTool через технический schema listener.

## 8. Первый вертикальный срез

Пример размещения компонентов регистрации и активации:

| Компонент | Расположение |
|---|---|
| `User`, `UserActionToken` и их прикладные инварианты | `IdentityAccess\Domain` |
| Register/activate/login/refresh/current-user use cases | `IdentityAccess\Application` |
| Порт постановки action email | `IdentityAccess\Application\Port` |
| API проверки актуальности token для доставки | `IdentityAccess\Application\Api` |
| Контроллеры `/auth/register`, `/auth/activate`, `/auth/login`, `/auth/refresh`, `/me`, transport request DTO, refresh guard и auth cookie factory | `IdentityAccess\Adapter\Http` |
| Bearer authenticator и principal | `IdentityAccess\Adapter\Security` |
| Bearer authentication entry point | `Infrastructure\Http` |
| Чистые application commands/response DTO | `IdentityAccess\Application` |
| Реализованные Doctrine records пользователя/token/session | `IdentityAccess\Adapter\Persistence\Doctrine\Record` |
| Реализованная activation object policy | `IdentityAccess\Application\Authorization` |
| Purpose action token | `IdentityAccess\Domain\Access` |
| Password hashing port и Symfony adapter | `IdentityAccess\Application\Port` и `IdentityAccess\Adapter\Security` |
| `IdempotencyRecord` и координация безопасных повторов регистрации | `Infrastructure\Idempotency` |
| `EmailDeliveryOutbox`, шифрование payload, relay, Messenger consumer, retry/failure handling и Mailer integration | `Infrastructure\EmailDelivery` |

Infrastructure хранит ссылку outbox на action token как UUID, а не как
межмодульную ORM-association. Создание outbox через application port участвует в
той же PostgreSQL-транзакции, что User и token; публикация в RabbitMQ начинается
после commit.

Фактический поток доставки первого вертикального среза:

1. `DoctrineEmailOutbox` сохраняет durable intent с зашифрованным payload в
   транзакции регистрации.
2. `RelayEmailOutboxCommand` вызывает `OutboxRelay`, который блокирует готовую
   запись, повторно проверяет актуальность action token и публикует только
   `deliveryId` в RabbitMQ через Symfony Messenger.
3. `SendUserActionEmailHandler` блокирует outbox, ещё раз проверяет token,
   расшифровывает payload и синхронно передаёт письмо `TransportInterface`.
4. Успешная отправка переводит запись в `SENT` и очищает payload. Ошибка SMTP
   повторяется по нормативному расписанию; после исчерпания попыток
   `FinalDeliveryFailureSubscriber` переводит запись в `FAILED` и очищает секрет.
5. Compose worker последовательно запускает relay и короткоживущий
   `messenger:consume async`; publish confirms и failure transport задаются в
   `config/packages/messenger.yaml`.

`EmailDeliveryFlowTest` проверяет успешную публикацию и доставку, повтор relay
после отказа публикации, SMTP retry, истёкший token, terminal failure, очистку
payload и допустимый повтор при неопределённом результате SMTP.

Фактические таблицы, поля и ограничения описаны в
[реализованной начальной схеме](data-model/implemented-initial-schema.md).
Общие JSON/error/request ID правила описаны в
[реализации HTTP-слоя](api/http-implementation.md).

## 9. Правила добавления нового кода

1. Сначала выберите модуль-владелец и слой по направлению зависимости.
2. Не возвращайте глобальные `Controller`, `Entity` и `Repository`.
3. Не создавайте пустые слои или универсальные абстракции.
4. Domain-код не импортирует framework или persistence types.
5. Межмодульный контракт размещайте в `Application\Api`, исходящий порт — в
   `Application\Port`.
6. Doctrine record и mapper создавайте только для реально сохраняемого
   бизнес-агрегата.
7. При добавлении record одновременно настройте явный Doctrine mapping и
   проверьте исключение из service discovery.
8. Любое исключение из dependency matrix требует нового ADR.
9. Symfony validation attributes размещайте на transport DTO в `Adapter\Http`;
   Application command/DTO остаётся framework-independent.
10. Controller возвращает response DTO/array, но никогда не Doctrine record.

## 10. Применённые решения

- [ADR-001 — модульный монолит](adr/architecture-decisions.md#adr-001-архитектура-mvp-как-модульный-монолит);
- [ADR-006 — жизненный цикл аккаунта](adr/architecture-decisions.md#adr-006-жизненный-цикл-аккаунта-и-сессий);
- [ADR-007 — одноразовые токены](adr/architecture-decisions.md#adr-007-унифицированные-одноразовые-токены);
- [ADR-009 — централизованная идемпотентность](adr/architecture-decisions.md#adr-009-централизованная-идемпотентность);
- [ADR-011 — ответственность БД и доменного слоя](adr/architecture-decisions.md#adr-011-разделение-ответственности-бд-и-доменного-слоя);
- [ADR-012 — HTTP-соглашения](adr/architecture-decisions.md#adr-012-границы-и-http-соглашения-mvp);
- [ADR-013 — transactional outbox и RabbitMQ](adr/architecture-decisions.md#adr-013-надёжная-доставка-email-через-transactional-outbox-и-rabbitmq);
- [ADR-014 — операционный профиль](adr/architecture-decisions.md#adr-014-нормативный-операционный-профиль-безопасности-и-хранения-mvp);
- [ADR-015 — слои и зависимости модулей](adr/architecture-decisions.md#adr-015-слои-backend-и-направления-зависимостей-модулей);
- [ADR-016 — криптографический профиль UserActionToken](adr/architecture-decisions.md#adr-016-криптографический-профиль-useractiontoken);
- [ADR-017 — профиль JWT access token](adr/architecture-decisions.md#adr-017-профиль-jwt-access-token).

Текущая граница реализации соответствует [границам MVP](mvp-scope.md),
[бизнес-правилам](business-rules/business-rules.md) и
[HTTP-контрактам](api/http-contracts.md).

## Реализованное восстановление пароля (SC-004)

`IdentityAccess\Application\PasswordReset` владеет RequestPasswordReset и
ResetPassword, framework-independent commands и orchestration. Общая Domain
PasswordPolicy, HMAC codec, hasher, transaction и revoke core переиспользованы.
Новые узкие ports PasswordResetRepositoryPort, PasswordResetRateLimitPort и
PasswordResetOutboxPort описывают операции фичи без расширения HTTP-зависимостей.
DoctrinePasswordResetRepository реализует owner/token locks, replacement и hash
update; UserRecord предоставляет явный setter hash. DoctrineIdentityRepository
обновляет cached credential после lock и соблюдает User → token для activation.

DTO/controllers находятся в `IdentityAccess\Adapter\Http`, Symfony limiter — в
Adapter. Существующий DoctrineEmailOutbox реализует reset port; relay/consumer
по-прежнему передают только deliveryId. Consumer сравнивает purpose/template из
межмодульного `Application\Api\TokenDeliveryState`, не импортирует Domain другого
модуля, проверяет current deliverability и очищает ciphertext при terminal state.
Migration не нужна; изменение не вводит новые модули или универсальный mapper.
[Декомпозиция](https://github.com/r3ct4lan/equo_3/blob/dc0b1234f00b3fcbd3526a8080b01098b8468947/docs/features/password-reset.md), [проверка](https://github.com/r3ct4lan/equo_3/blob/dc0b1234f00b3fcbd3526a8080b01098b8468947/docs/acceptance/password-reset.md).
## Реализованная смена пароля (SC-005)

IdentityAccess\Application\PasswordChange владеет ChangePassword и command.
Узкий PasswordChangeRepositoryPort предоставляет fresh credential под User lock
и hash update, DoctrinePasswordChangeRepository реализует его с HINT_REFRESH.
Используются общие PasswordPolicy/hasher, TransactionPort, ClockPort и session
revoke core. HTTP DTO/controller и Bearer support остаются в Adapter; Domain
не импортирует framework, Application не зависит от HTTP. Схема не менялась.
[Этапы](https://github.com/r3ct4lan/equo_3/blob/dc0b1234f00b3fcbd3526a8080b01098b8468947/docs/features/password-change.md), [проверка](https://github.com/r3ct4lan/equo_3/blob/dc0b1234f00b3fcbd3526a8080b01098b8468947/docs/acceptance/password-change.md).

## Границы SC-008

`Connects/Domain/{CanonicalPair,Connect}` и `Invitations/Domain/ConnectInvitation`
не зависят от Symfony/Doctrine. Application использует собственные ports;
`ConnectAccess` и `IdentityAccess/Application/Api/AccountAccess` — узкие
межмодульные интерфейсы. AccountSummary содержит id/name/isActive без email.

Doctrine repositories, records, HTTP controllers и InvitationTokenCodec/RateLimit
находятся в Adapter соответствующего модуля. Infrastructure/Persistence/Doctrine/ConnectRuntime
реализует transaction/clock/UUID ports обоих модулей на общей БД. Infrastructure
регистрирует глобальный InvitationRedactionProcessor; он скрывает capability,
token hashes, SQL/parameters и сообщения exceptions, сохраняя request ID.
Application не зависит от HTTP; controllers получают subject через Symfony Security.

## Финансовые модули SC-011

Debts/Domain содержит Money, Debt и DebtParticipant; Transfers/Domain —
Transfer с локальными правилами DEBT_SHARE/MANUAL. Domain не импортирует
Symfony/Doctrine. Application commands и queries не зависят от HTTP.

CreateDebt использует собственные repository/runtime/idempotency ports,
AccountAccess и ConnectAccess, AutomaticShares. Порядок транзакции:
central idempotency reservation → sorted UUID User locks → fresh eligibility
и activity → Debt/participants → get-or-create пары → автоматические доли →
сохранение исходного ответа. ConnectRuntime реализует новые runtime ports;
DebtIdempotency adapter переиспользует central DoctrineIdempotency.

DebtQueries/DebtViews владеют Debt ACL и safe references; TransferQueries —
independent Connect membership и history. BalanceService суммирует ledger
через собственный repository. Межмодульные зависимости идут только через
Application/Api: ConnectAccess, AutomaticShares, BalanceQuery, DebtAccess,
AccountAccess. ConnectService получает BalanceQuery без cycle в DI.

Doctrine records используют UUID links без межмодульных ORM associations.
Новая Version20261011120000 добавляет три financial tables, constraints и индексы;
schema listener отражает их FK при сравнении mapping. KeysetCursor реализует
узкие ports двух query modules. Controllers/Validator DTO живут в Adapter/Http.

[План](https://github.com/r3ct4lan/equo_3/blob/dc0b1234f00b3fcbd3526a8080b01098b8468947/docs/features/create-debt.md), [доказательства](https://github.com/r3ct4lan/equo_3/blob/dc0b1234f00b3fcbd3526a8080b01098b8468947/docs/acceptance/create-debt.md).

## MANUAL writer SC-016

CreateManualTransfer принадлежит Transfers/Application, использует собственные
repository/runtime/idempotency ports и AccountAccess/ConnectAccess через
Application/Api. Точный pair проверяется внутри транзакции после lookup ключа;
активность сторон — свежими sorted UUID User locks. Domain factory создаёт
MANUAL с null debt/participant/related и проверяет локальные инварианты.
Infrastructure DebtIdempotency реализует также TransferIdempotencyPort,
переиспользуя общий DoctrineIdempotency, без второй таблицы или движка.
TransferQueries формирует исходный безопасный representation для сохранённого
201. Схема, ledger query и Connect balance API переиспользуются без новой
миграции. PHP-FPM access log исключает URI, safe API routes остаются в Nginx.

## Исправление долга SC-013

Debt::revise и DebtUpdate принадлежат Domain; UpdateDebt координирует одну
транзакцию через DebtRuntimePort. DebtRepositoryPort.find(forUpdate=true)
блокирует root до чтения состава; свежие sorted UUID User locks сериализуют
проверку активности с деактивацией. AutomaticShares.recalculate — межмодульный
Application/Api port; Transfers владеет обновлением только DEBT_SHARE.

Read repository получает root и полный participant history одним SQL snapshot.
Writer сохраняет прежние IDs/createdAt, обновляет только изменённые participant
flags и создаёт только новые записи. Transfer.recalculateShare увеличивает
version лишь при фактическом изменении суммы/флага. MANUAL не затрагивается.
Domain не зависит от Doctrine/Symfony, Application — от HTTP.
[Доказательства](https://github.com/r3ct4lan/equo_3/blob/dc0b1234f00b3fcbd3526a8080b01098b8468947/docs/acceptance/update-debt.md).
