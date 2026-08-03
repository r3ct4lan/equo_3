# Frontend-фундамент Equo

| Поле | Значение |
|---|---|
| Назначение | Описать фактическую Nuxt 4 структуру, общие механизмы и реализованные экраны регистрации/активации |
| Статус | Implemented |
| Версия | 5 |
| Дата актуальности | 2026-08-03 |
| Владелец | Maksim Smolkov |
| Источник | HTTP-контракты v4; ADR-001/006/012/017/018; реализация HTTP-слоя; модель доступа; фактический frontend |

## 1. Граница реализации

Frontend содержит общий фундамент и публичные экраны регистрации и активации. Nuxt
`error.vue` отвечает за безопасное состояние `404` и непредвиденную ошибку.

Первый вертикальный срез публичен. Production JWT authenticator, refresh,
`GET /api/v1/me` и `UserSession` ещё отсутствуют в backend согласно
[модели доступа](security/access-model.md). Поэтому E1-11 не создаёт login page,
protected page или route middleware и не имитирует их тестовым endpoint.

## 2. Фактическая структура

```text
frontend/
├── app/
│   ├── app.vue
│   ├── error.vue
│   ├── assets/css/main.css
│   ├── components/
│   │   ├── AppEmptyState.vue
│   │   ├── AppErrorState.vue
│   │   ├── AppLoadingState.vue
│   │   ├── FormErrorSummary.vue
│   │   ├── FormFieldError.vue
│   │   └── FormSubmitButton.vue
│   ├── composables/
│   │   ├── useApiForm.ts
│   │   └── useCurrentUser.ts
│   ├── layouts/default.vue
│   ├── pages/{index,register,activate}.vue
│   ├── plugins/api.ts
│   ├── types/{api,session}.ts
│   ├── types/nuxt.d.ts
│   └── utils/{api-error,auth-flow}.ts
├── tests/unit/{api-error,auth-flow}.test.mjs
├── Dockerfile
├── nuxt.config.ts
└── package.json
```

Правила размещения:

- `pages` содержит только реальные URL, доступные пользователю;
- `layouts` задаёт application shell, но не предметную навигацию;
- `components` содержит небольшие transport-agnostic UI-состояния;
- `composables` координирует реактивное состояние и форму;
- `plugins/api.ts` является единственной HTTP-границей приложения;
- `types` повторяет только реально используемую часть публичного контракта;
- `utils` содержит чистую нормализацию, которую можно тестировать без Nuxt.

Первый небольшой feature не вводит отдельную иерархию каталогов: его страницы
находятся в `pages`, transport-типы — в `types/api.ts`, а чистая validation,
семантика ошибок и lifecycle idempotency attempt — в `utils/auth-flow.ts`.

## 3. Application shell и маршруты

`app.vue` использует стандартные `NuxtLayout` и `NuxtPage`. Default layout
содержит skip link, семантические `header/nav/main/footer`, адаптивный контейнер
и ссылки только на существующие Home и Register. Стартовая страница показывает нейтральное
состояние приложения и после hydration выполняет реальный `GET /api/health`
через `$api`. Проверка выполняется client-side, чтобы frontend healthcheck и
SSR shell не образовывали циклическую зависимость от Nginx/API при старте.

Неизвестный route обрабатывается `error.vue`: клиент не видит исходное exception
message или внутренний stack trace.

Route middleware отсутствует намеренно. Перед первым protected route необходимо:

1. реализовать backend login/refresh/current-user;
2. реализовать принятый ADR-018 session bootstrap;
3. дождаться завершения bootstrap до решения redirect;
4. считать frontend guard только UX-границей, не заменой backend authorization.

## 4. Runtime-конфигурация API

| Переменная | Видимость | Default | Назначение |
|---|---|---|---|
| `NUXT_PUBLIC_API_BASE` | browser и server | `/api` | Same-origin публичный base URL |
| `NUXT_API_INTERNAL_BASE` | только Nuxt server | пустой в Nuxt config; `http://nginx/api` в Compose | Доступ SSR к API внутри deployment network |

Browser base URL не содержит `localhost`. В Docker Compose Nuxt SSR обращается
к Nginx как к единой точке входа. Для другой production-топологии server-only
значение задаётся окружением. Ни одна переменная не содержит backend secret и
`apiInternalBase` не попадает в client runtime config.

## 5. Типизированный API client

`plugins/api.ts` предоставляет единственный `$api<T>(path, options)` поверх
официального `$fetch`/ofetch:

```text
component/composable
  → $api<T>
  → runtime base URL + Accept/JSON headers
  → optional explicit Bearer token + credentials include
  → Symfony API
  → typed data либо ApiClientError
```

Клиент:

- принимает generic response type;
- использует `/api` и не дублирует `/api/v1` в runtime config;
- передаёт `AbortSignal`, timeout, query и остальные стандартные fetch options;
- отключает неявные retry; retry и `Idempotency-Key` задаёт конкретная операция;
- добавляет `Content-Type: application/json` только запросу с body;
- принимает access token только явным параметром и нигде его не сохраняет;
- использует `credentials: include` для будущей согласованной refresh cookie;
- очищает состояние текущего пользователя при `401`, но не выполняет redirect;
- не показывает toast и не содержит предметных решений.

## 6. Ошибки API

`toApiClientError` признаёт только согласованный JSON envelope E1-09. Итоговый
тип сохраняет:

- `kind`: `api`, `http`, `network`, `timeout`, `aborted` или `unknown`;
- HTTP `status`, если он доступен;
- публичный `code`;
- безопасный `message`;
- `requestId`;
- `details.violations` и сгруппированные `fieldErrors`.

| Условие | Frontend-представление |
|---|---|
| `400`, `401`, `403`, `404`, `405`, `409`, `410`, `415`, `422`, `428`, `429`, `5xx` | Публичный envelope либо безопасное fallback-сообщение по status |
| Validation envelope | Нарушения группируются по `field`; публичные `code/message` сохраняются |
| HTML/proxy response | Тело игнорируется, используется безопасное fallback-сообщение |
| Network failure | `NETWORK_ERROR` без исходного hostname/exception |
| Timeout | `REQUEST_TIMEOUT` |
| AbortSignal | `REQUEST_ABORTED`, не считается пользовательской ошибкой формы |

Raw exception, HTML Nginx, stack trace и внутренние адреса не попадают в UI.
Автоматический тест проверяет envelope, field errors, HTML `500`, abort и
network failure.

## 7. Состояние текущего пользователя

`useCurrentUser` хранит в Nuxt `useState` только сериализуемую модель:

| Статус | Значение |
|---|---|
| `unknown` | Session bootstrap ещё не выполнялся |
| `authenticated` | Backend подтвердил `CurrentUser` |
| `anonymous` | Сессии нет либо API вернул `401` |
| `error` | Проверка сессии завершилась безопасной технической ошибкой |

Хранятся только публичные поля `id/name/email/isActive/createdAt`; password,
token и cookie недоступны состоянию. Composable предоставляет переходы
`setAuthenticated`, `setAnonymous`, `setError`, `reset`.

Сетевой `check()` намеренно отсутствует: backend endpoint и production auth ещё
не реализованы. Стратегия больше не является открытым вопросом: ADR-018 требует
client-only in-memory access token, browser bootstrap `refresh → /me`,
single-flight refresh и ожидание bootstrap перед protected redirect. Token не
должен попадать в `useState` или SSR payload; текущий composable уже соблюдает
эту границу, сохраняя только публичного пользователя и статус.

## 8. Формы и общие UI-состояния

`useApiForm` предоставляет:

- реактивные значения формы;
- optional клиентскую синтаксическую validation-функцию;
- блокировку повторного submit;
- сохранение введённых значений после ошибки;
- перенос backend violations в поля;
- общую `ApiClientError` формы;
- отдельное состояние canceled request;
- reset значений и ошибок.

Backend остаётся источником бизнес-правил. Реальный form component обязан
связать `label[for]` с input, передать `aria-invalid` и
`aria-describedby` на `FormFieldError`. `FormErrorSummary` получает focus после
ошибки, а `FormSubmitButton` передаёт pending через `disabled` и `aria-busy`.

Общие компоненты ограничены loading, error, empty и тремя form states. Глобальный
CSS задаёт читаемую типографику, spacing/container, focus, базовые form/error
стили и `prefers-reduced-motion`; branding и UI framework не вводились.

## 9. Первый вертикальный сценарий

`/register` принимает только `name`, `email`, `password`, выполняет лёгкую
клиентскую validation для удобства и отправляет точный request через `$api` в
`POST /api/v1/auth/register`. Backend остаётся авторитетным для email
normalization, password policy, уникальности и rate limits. UUID
`Idempotency-Key` создаётся при первой сетевой отправке, сохраняется для
неизменённого технического retry и заменяется после любого редактирования
формы. Pending блокирует поля и повторный submit.

Успех `201` заменяет форму на activation-required state, очищает password и не
объявляет login. Backend field violations связываются с input; известные
business/rate ошибки отображаются по стабильному `error.code`, а техническая
ошибка допускает повтор той же попытки и показывает только безопасный request
ID.

`/activate?token=…` считывает capability token только в памяти компонента,
сразу заменяет URL на `/activate` и вызывает `POST /api/v1/auth/activate`.
Success `204`, invalid/expired/used/invalidated/rate и технический retry имеют
отдельные состояния. Token не рендерится, не попадает в Nuxt state/storage и
очищается после terminal result или ухода со страницы. Resend и login не
имитируются, поскольку не входят в первый срез.

## 10. Проверки

```bash
docker compose exec frontend npm ci
docker compose exec frontend npm run lint
docker compose exec frontend npm run test:unit
docker compose exec frontend npm run typecheck
docker compose exec frontend npm run build
curl --fail http://localhost:${APP_PORT:-80}/
curl --fail http://localhost:${APP_PORT:-80}/api/health
```

`make check-frontend` запускает lock check, ESLint, typecheck, unit tests,
production build и npm audit. `make check` добавляет backend, repository и smoke
gate; полный состав описан в [quality gate E1-12](quality-gate.md).

E1-11 не добавлял npm-пакетов. E1-12 добавляет только dev-only ESLint и
официальный Nuxt flat config. Существующий direct `vue-router` выровнен
с версией `5.2.0`, которую использует Nuxt 4.5: прежний диапазон 4.x перекрывал
Nuxt Router и делал его Volar plugin недоступным для `vue-tsc`.

## 11. Сознательно отложено

- login, refresh, logout и current-user network bootstrap;
- реализация принятого ADR-018: in-memory token holder, session bootstrap,
  межвкладочная сериализация refresh и signed double-submit CSRF;
- protected route middleware;
- Pinia, form/validation library, UI framework и OpenAPI generator;
- окончательный branding, уведомления, аналитика и страницы будущих модулей.

## 12. Нормативные источники

- [HTTP-контракты](api/http-contracts.md);
- [реализация HTTP-слоя](api/http-implementation.md);
- [модель доступа](security/access-model.md);
- [ADR-001, ADR-006, ADR-012, ADR-017 и ADR-018](adr/architecture-decisions.md);
- [границы MVP](mvp-scope.md);
- [решённый OQ-018](open-questions.md#oq-018).
