# Frontend-фундамент Equo

| Поле | Значение |
|---|---|
| Назначение | Описать фактическую Nuxt 4 структуру, общие механизмы и реализованные auth-экраны |
| Статус | Implemented |
| Версия | 7 |
| Дата актуальности | 2026-09-27 |
| Владелец | Maksim Smolkov |
| Источник | HTTP-контракты v4; ADR-001/006/012/017/018; реализация HTTP-слоя; модель доступа; фактический frontend |

## 1. Граница реализации

Frontend содержит общий фундамент, публичные экраны регистрации и активации,
login, browser session lifecycle и минимальную защищённую `/me`-страницу. Nuxt
`error.vue` отвечает за безопасное состояние `404` и непредвиденную ошибку.

Auth frontend следует ADR-018: access token хранится только в client-side
closure текущей вкладки, bootstrap выполняет `refresh -> /me`, а protected
route middleware является UX-границей поверх обязательной backend authorization.
Logout, logout-all и reset/change password остаются вне этого слоя.

## 2. Фактическая структура

```text
frontend/
├── app/
│   ├── app.vue
│   ├── error.vue
│   ├── assets/css/main.css
│   ├── components/
│   │   ├── ActivationRequestForm.vue
│   │   ├── AppEmptyState.vue
│   │   ├── AppErrorState.vue
│   │   ├── AppLoadingState.vue
│   │   ├── FormErrorSummary.vue
│   │   ├── FormFieldError.vue
│   │   └── FormSubmitButton.vue
│   ├── composables/
│   │   ├── useActivationRequest.ts
│   │   ├── useApiForm.ts
│   │   └── useCurrentUser.ts
│   ├── layouts/default.vue
│   ├── middleware/auth.ts
│   ├── pages/{index,register,activate,login,me}.vue
│   ├── plugins/{api,auth.client}.ts
│   ├── types/{api,session}.ts
│   ├── types/nuxt.d.ts
│   └── utils/{api-error,auth-flow,auth-session}.ts
├── tests/unit/{api-error,auth-flow,auth-session}.test.mjs
├── Dockerfile
├── nuxt.config.ts
└── package.json
```

Правила размещения:

- `pages` содержит только реальные URL, доступные пользователю;
- `layouts` задаёт application shell, но не предметную навигацию;
- `components` содержит небольшие transport-agnostic UI-состояния;
- `composables` координирует реактивное состояние и форму;
- `plugins/api.ts` является единственной HTTP-transport границей приложения;
- `plugins/auth.client.ts` владеет browser session lifecycle и предоставляет
  `$auth`;
- `types` повторяет только реально используемую часть публичного контракта;
- `utils` содержит чистую нормализацию, которую можно тестировать без Nuxt.

Первый auth-срез не вводит отдельную иерархию каталогов: страницы находятся в
`pages`, transport-типы - в `types/api.ts`, чистая validation, семантика ошибок
и lifecycle idempotency attempt регистрации - в `utils/auth-flow.ts`, а
session lifecycle - в `utils/auth-session.ts`.

## 3. Application shell и маршруты

`app.vue` использует стандартные `NuxtLayout` и `NuxtPage`. Default layout
содержит skip link, семантические `header/nav/main/footer`, адаптивный контейнер
и ссылки Home, Login/Register либо My profile в зависимости от состояния
сессии. Стартовая страница показывает нейтральное состояние приложения и после
hydration выполняет реальный `GET /api/health`
через `$api`. Проверка выполняется client-side, чтобы frontend healthcheck и
SSR shell не образовывали циклическую зависимость от Nginx/API при старте.

Неизвестный route обрабатывается `error.vue`: клиент не видит исходное exception
message или внутренний stack trace.

`middleware/auth.ts` работает только в browser runtime: он ждёт `$auth.bootstrap`
и перенаправляет только подтверждённо anonymous пользователя на `/login` с
безопасным local `redirect`. Ошибка bootstrap остаётся в error state, чтобы UI
мог предложить retry и не выдавал ложный anonymous redirect. Backend остаётся
единственным источником authorization для защищённых данных.

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
- использует `credentials: include` для согласованной refresh cookie;
- не меняет состояние текущего пользователя на generic `401`; auth transitions
  принадлежат `$auth`;
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

Сетевой lifecycle реализован в `$auth`, а `useCurrentUser` остаётся только
публичным сериализуемым состоянием. `$auth` хранит access token в private
closure, выполняет browser bootstrap `refresh -> /me`, сериализует refresh
через Web Locks и предоставляет `protectedRequest` с одним refresh и одним
retry после access-token `401`. Token не попадает в `useState`, SSR payload,
storage, URL или cross-tab messages.

Refresh требует Web Locks API: если браузер не может скоординировать refresh,
операция завершается безопасной ошибкой без отправки refresh request.
`BroadcastChannel` используется только для token-free события `session-ended`.

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

## 9. Реализованные auth-сценарии

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
очищается после terminal result или ухода со страницы.

Повторный запрос activation link встроен переиспользуемым
`ActivationRequestForm` в activation-required state после регистрации и в
существующий публичный `/activate` для missing/invalid/expired/replaced и
технических состояний. Login содержит ссылку возврата на `/activate`; отдельный
route и второй layout не создаются. `useActivationRequest` координирует
`useApiForm`, единственный `$api`, pending/cancel lifecycle и очистку текущего
пароля после `202` либо ухода со страницы.

Форма отправляет только `email/password` в
`POST /api/v1/auth/activation-requests`. Success всегда имеет нейтральную
семантику: UI подтверждает принятие запроса, но не существование аккаунта,
верность пароля, состояние аккаунта или фактическую отправку письма. Email
остаётся только в локальном состоянии компонента, password после успеха
очищается; session state, URL и browser storage не изменяются. `422` связывает
violations с полями, `429` безопасно отображает `Retry-After` без таймера и
автоповтора, а network/`5xx` предлагает только ручной retry. Отменённый при
уходе запрос не представляется как сетевая ошибка.

`/login` принимает email и password, выполняет только лёгкую клиентскую
validation обязательности и email syntax, затем вызывает `$auth.login`, который
отправляет `POST /api/v1/auth/login`, сохраняет access token в private closure и
переводит current user в authenticated state. Пароль очищается после успешного
входа. Redirect принимается только как безопасный local path; внешние URL,
protocol-relative paths и backslash paths заменяются на `/me`.

`/me` защищён `middleware/auth.ts` и загружает профиль через
`$auth.protectedRequest<CurrentUser>('/v1/me')`. Страница показывает только
публичные поля пользователя и retryable error state; token, session id, refresh
cookie и CSRF values не рендерятся.

## 10. Проверки

```bash
docker compose exec frontend npm ci
docker compose exec frontend npm run lint
docker compose exec frontend npm run test:unit
docker compose exec frontend npm test
docker compose exec frontend npm run test:component -- --run tests/component/activation-request-form.test.ts
docker compose exec frontend npm run typecheck
docker compose exec frontend npm run build
docker compose exec frontend npm audit --audit-level=moderate
curl --fail http://localhost:${APP_PORT:-80}/
curl --fail http://localhost:${APP_PORT:-80}/api/health
```

`make check-frontend` запускает lock check, ESLint, typecheck, unit/component
tests, production build и npm audit. `make check` запускает repository,
backend/frontend quality gate и smoke без browser E2E. Полный auth/session
browser E2E вынесен в `make check-full`; полный состав описан в
[quality gate E1-12](quality-gate.md).

E1-11 не добавлял npm-пакетов. E1-12 добавляет только dev-only ESLint и
официальный Nuxt flat config. Существующий direct `vue-router` выровнен
с версией `5.2.0`, которую использует Nuxt 4.5: прежний диапазон 4.x перекрывал
Nuxt Router и делал его Volar plugin недоступным для `vue-tsc`.

## 11. Сознательно отложено

- logout, logout-all, reset/change password и изменение профиля;
- Pinia, form/validation library, UI framework и OpenAPI generator;
- окончательный branding, уведомления, аналитика и страницы будущих модулей.

## 12. Нормативные источники

- [HTTP-контракты](api/http-contracts.md);
- [реализация HTTP-слоя](api/http-implementation.md);
- [модель доступа](security/access-model.md);
- [ADR-001, ADR-006, ADR-012, ADR-017 и ADR-018](adr/architecture-decisions.md);
- [границы MVP](mvp-scope.md);
- [решённый OQ-018](open-questions.md#oq-018).
