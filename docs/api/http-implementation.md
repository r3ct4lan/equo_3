# Equo — реализация общего HTTP-слоя

| Поле | Значение |
|---|---|
| Назначение | Описать фактическую Symfony-реализацию общих HTTP-соглашений API v1 после E1-09 |
| Статус | Accepted |
| Версия | 1 |
| Дата актуальности | 2026-07-31 |
| Владелец | Maksim Smolkov |
| Источник | E1-09; HTTP-контракты v2; ADR-012; ADR-015; OQ-015 |

## 1. Граница реализации

Общий HTTP-слой реализует transport concerns, но не содержит предметных
endpoint, use case или бизнес-правил. Фактические production routes по-прежнему
ограничены `/api/health`. Fixture routes с префиксом `/api/v1/_test/http` и их
controller загружаются только при `APP_ENV=test`.

OpenAPI bundle не добавлялся: ADR не выбирает машинно-читаемый формат, а
нормативным источником остаются [HTTP-контракты](http-contracts.md).

## 2. Компоненты

| Компонент | Ответственность |
|---|---|
| `RequestIdSubscriber` | Принимает безопасный `X-Request-Id` или создаёт ULID; добавляет итоговый ID в каждый `/api/*` response |
| Symfony `MapRequestPayload` | Декодирует JSON и создаёт typed transport DTO до вызова controller |
| Symfony Validator | Проверяет attributes transport DTO и формирует violation list |
| `ValidationViolationNormalizer` | Преобразует violations в стабильные `field/code/message` и сортирует их |
| `ApiExceptionSubscriber` | Классифицирует transport exceptions, журналирует неожиданные ошибки и создаёт standard envelope |
| `ApiResponseSubscriber` | Сериализует возвращённый controller object/array без зависимости controller от Infrastructure |
| `ApiJsonResponder` | Применяет общий Serializer context и создаёт `application/json` response |

## 3. Поток запроса

```text
HTTP request
  → RequestIdSubscriber
  → routing/controller resolution
  → MapRequestPayload(JSON, strict extra fields)
  → transport request DTO
  → Symfony Validator
  → controller
  → application command/use case (в следующих задачах)
```

Future transport DTO располагается в `<Module>\Adapter\Http\Request`, потому что
его Validator attributes являются частью Symfony adapter. Controller преобразует
его в чистую application command/DTO. `Application` и `Domain` не импортируют
Symfony, `Request`, `JsonResponse` или Validator constraints.

Контроллер использует стандартный атрибут:

```php
#[MapRequestPayload(acceptFormat: 'json')]
RegisterHttpRequest $request
```

Serializer global context задаёт `allow_extra_attributes: false`.

## 4. Поток ответа

Controller может вернуть response DTO или array. `ApiResponseSubscriber`
сериализует результат централизованно:

```text
controller/application response DTO
  → ApiResponseSubscriber
  → Symfony Serializer
  → ApiJsonResponder
  → JSON response
  → RequestIdSubscriber добавляет X-Request-Id
```

Правила сериализации:

- имена PHP-свойств response DTO остаются camelCase;
- `DateTimeInterface` переводится в UTC и форматируется как
  `Y-m-d\TH:i:sZ`, например `2026-07-31T12:15:16Z`;
- UUID остаётся строкой;
- backed enum сериализуется строковым value;
- `null` не удаляется;
- пустая collection остаётся `[]`;
- вложенные response DTO сериализуются рекурсивно;
- circular reference не получает публичного fallback и превращается в
  безопасный `500`, поэтому случайный обход графа Doctrine не раскрывается.

Doctrine records не являются response DTO и не передаются из controller.
Успешный ответ без тела по-прежнему создаётся обычным Symfony `Response` со
статусом `204` и не проходит object serialization.

## 5. Поток ошибки

```text
exception
  → ApiExceptionSubscriber
  → classification по безопасному типу/status
  → logging для неожиданного 500 (exception + requestId)
  → error{code,message,details?,requestId}
  → ApiJsonResponder
  → X-Request-Id header
```

Subscriber применяется только к `/api/*`. Исходное сообщение exception никогда
не используется как публичный message. Stack trace, SQL, filesystem paths,
connection details и secrets остаются только внутри server-side logging.

## 6. Поддержанные технические ошибки

| Условие | HTTP | `error.code` |
|---|---:|---|
| Пустое обязательное тело или malformed JSON | 400 | `INVALID_JSON` |
| Неизвестное JSON-поле или иной bad request | 400 | `INVALID_REQUEST` |
| Нет аутентификации | 401 | `AUTHENTICATION_REQUIRED` |
| Операция запрещена | 403 | `FORBIDDEN` |
| Route/resource отсутствует | 404 | `RESOURCE_NOT_FOUND` |
| Метод не поддерживается route | 405 | `METHOD_NOT_ALLOWED` |
| `Content-Type` не поддерживается | 415 | `UNSUPPORTED_MEDIA_TYPE` |
| DTO/type/constraint validation | 422 | `VALIDATION_ERROR` |
| Нет `If-Match` | 428 | `PRECONDITION_REQUIRED` |
| Rate limit | 429 | `RATE_LIMIT_EXCEEDED` |
| Непредвиденная или неклассифицированная ошибка | 500 | `INTERNAL_SERVER_ERROR` |

Business-specific `409`/`410` и точные token/idempotency codes будут добавлены
в HTTP adapter соответствующего use case вместе с предметным endpoint. Общая
инфраструктура не угадывает бизнес-код по техническому exception message.

## 7. Validation violations

`details.violations` всегда является массивом объектов `field/code/message`.
Ошибки сортируются по этим трём полям, поэтому порядок детерминирован.

- DTO constraint задаёт публичный code через `payload: ['code' => '...']`;
- type mismatch получает `INVALID_TYPE`;
- constraint без отдельного публичного code получает безопасный
  `VALIDATION_ERROR`;
- для обязательных полей используется `REQUIRED`.

Внутреннее имя PHP-класса и UUID-код Symfony constraint в response не попадают.

## 8. Request ID

Входящий `X-Request-Id` сохраняется при соответствии
`[A-Za-z0-9._-]{1,64}`. Пустое, слишком длинное или небезопасное значение
заменяется ULID. Итоговое значение присутствует одновременно в response header и
в `error.requestId`.

## 9. Зависимости

Добавлены только официальные Symfony-компоненты `7.4.*`:

- `symfony/serializer` — object/array/enum/date JSON serialization;
- `symfony/validator` — DTO validation;
- `symfony/uid` — ULID request IDs;
- `symfony/property-access` — необходимый `ObjectNormalizer` для typed DTO;
  он транзитивно добавляет `property-info` и `type-info`.

NelmioApiDocBundle, API Platform и другие API framework/bundle не добавлялись.

## 10. Автоматические проверки

`HttpInfrastructureTest` использует test-only controller и проверяет successful
mapping, malformed/empty JSON, content type, missing fields, type mismatch,
несколько violations, unknown fields, UUID/date/enum/null/collection/nested
serialization, request ID, method/route errors и безопасный internal error.
`HealthControllerTest` дополнительно проверяет `X-Request-Id` существующего
healthcheck.

## 11. Нормативные источники

- [HTTP-контракты API v1](http-contracts.md);
- [ADR-012 и ADR-015](../adr/architecture-decisions.md);
- [OQ-015](../open-questions.md#oq-015);
- [первый вертикальный срез](../first-vertical-slice.md) и
  [критерии приёмки](../first-vertical-slice-acceptance.md).
