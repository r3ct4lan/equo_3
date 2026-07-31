# Equo — фактическая структура backend

| Поле | Значение |
|---|---|
| Назначение | Зафиксировать выражение принятых ADR в структуре Symfony backend |
| Статус | Accepted |
| Версия | 2 |
| Дата актуальности | 2026-07-31 |
| Владелец | Maksim Smolkov |
| Источник | E1-07, ADR-001 и ADR-015 |

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

E1-07 физически создаёт только технический модуль, в котором уже есть
содержательный код. Пространство имён `App\IdentityAccess\` подготовлено для
первого вертикального среза, но пустые каталоги и marker-классы не создаются.
Модули `Connects`, `Debts`, `Transfers` и `Invitations` появятся только вместе с
первым реальным компонентом соответствующего сценария.

## 2. Модули текущего этапа

| Модуль | Ответственность | Корневое пространство имён | Состояние |
|---|---|---|---|
| Identity and Access | Пользователь, жизненный цикл аккаунта, action token, регистрация и активация | `App\IdentityAccess\` | Граница зафиксирована; предметных классов ещё нет |
| Infrastructure | Идемпотентность, email delivery/outbox и общие технические входы | `App\Infrastructure\` | Физически создан health endpoint |

`IdempotencyRecord` по ADR-009 и `EmailDeliveryOutbox` по ADR-013 принадлежат
`Infrastructure`. Они являются техническими Doctrine records и не требуют
дублирующей доменной модели.

## 3. Фактическое дерево

```text
backend/
├── config/
│   ├── packages/
│   │   ├── doctrine.yaml
│   │   └── messenger.yaml
│   └── services.yaml
├── src/
│   ├── Infrastructure/
│   │   └── Http/
│   │       └── HealthController.php
│   └── Kernel.php
└── tests/
    ├── Architecture/
    │   └── ModuleDependencyTest.php
    ├── Infrastructure/
    │   └── Http/
    │       └── HealthControllerTest.php
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

Doctrine auto-mapping отключён. При появлении первого record добавляется явный
mapping его конкретного namespace; глобальный `App\Entity` не используется.

## 8. Первый вертикальный срез

Пример размещения компонентов регистрации и активации:

| Компонент | Расположение |
|---|---|
| `User`, `UserActionToken` и их инварианты | `IdentityAccess\Domain` |
| Register/activate use cases | `IdentityAccess\Application` |
| Порт постановки action email | `IdentityAccess\Application\Port` |
| API проверки актуальности token для доставки | `IdentityAccess\Application\Api` |
| Контроллеры `/auth/register` и `/auth/activate` | `IdentityAccess\Adapter\Http` |
| Doctrine records, repositories и mapper пользователя/token | `IdentityAccess\Adapter\Persistence\Doctrine` |
| `IdempotencyRecord` и координация повторов | `Infrastructure\Idempotency` |
| `EmailDeliveryOutbox`, relay, consumer и Mailer integration | `Infrastructure\EmailDelivery` |

Infrastructure хранит ссылку outbox на action token как UUID, а не как
межмодульную ORM-association. Создание outbox через application port участвует в
той же PostgreSQL-транзакции, что User и token; публикация в RabbitMQ начинается
после commit.

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

## 10. Применённые решения

- [ADR-001 — модульный монолит](adr/architecture-decisions.md#adr-001-архитектура-mvp-как-модульный-монолит);
- [ADR-006 — жизненный цикл аккаунта](adr/architecture-decisions.md#adr-006-жизненный-цикл-аккаунта-и-сессий);
- [ADR-007 — одноразовые токены](adr/architecture-decisions.md#adr-007-унифицированные-одноразовые-токены);
- [ADR-009 — централизованная идемпотентность](adr/architecture-decisions.md#adr-009-централизованная-идемпотентность);
- [ADR-011 — ответственность БД и доменного слоя](adr/architecture-decisions.md#adr-011-разделение-ответственности-бд-и-доменного-слоя);
- [ADR-012 — HTTP-соглашения](adr/architecture-decisions.md#adr-012-границы-и-http-соглашения-mvp);
- [ADR-013 — transactional outbox и RabbitMQ](adr/architecture-decisions.md#adr-013-надёжная-доставка-email-через-transactional-outbox-и-rabbitmq);
- [ADR-014 — операционный профиль](adr/architecture-decisions.md#adr-014-нормативный-операционный-профиль-безопасности-и-хранения-mvp);
- [ADR-015 — слои и зависимости модулей](adr/architecture-decisions.md#adr-015-слои-backend-и-направления-зависимостей-модулей).

Граница реализации взята из [описания первого вертикального среза](first-vertical-slice.md)
и его [критериев приёмки](first-vertical-slice-acceptance.md).
