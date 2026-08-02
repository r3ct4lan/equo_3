# Frontend первого вертикального сценария

Статус: Implemented в рамках `E1-14`.

Сценарий: `MVP-SC-001 — регистрация, получение первого activation email и первичная активация аккаунта`.

## Матрица трассировки до реализации

| Критерий приёмки | Экран/route | UI-состояние | API operation | Ошибка/доступ | Тест |
|---|---|---|---|---|---|
| `AC-001—005` | `/register`, `/activate?token=…` | registration success → activation loading/success; без login/session | `POST /api/v1/auth/register`, `POST /api/v1/auth/activate` | Публичные операции; activation token — capability | frontend unit, browser smoke, backend regression |
| `AC-006—019` | `/register` | initial, client validation, field errors | register | Required/name/email/password validation; backend остаётся авторитетным | frontend unit, browser smoke, backend HTTP regression |
| `AC-020—030` | `/register` | business/rate/technical error, retry | register + `Idempotency-Key` | email conflict, idempotency conflict, rate limit, safe technical fallback | frontend unit, backend HTTP regression |
| `AC-033—045` | `/activate?token=…` | loading, semantic token/rate errors | activate | invalid/expired/used/invalidated token; rate limit | frontend unit, browser smoke, backend HTTP regression |
| `AC-055` | `/register` | initial/empty, loading | register | submit/input blocking during request | frontend unit, browser smoke |
| `AC-056` | `/register` | one pending attempt | register + stable UUID key | double submit blocked; unchanged retry reuses key; edited form gets new key | frontend unit, browser smoke |
| `AC-057` | `/register` | activation required | register | no implicit authentication | browser smoke, backend regression |
| `AC-058` | `/register` | validation/business/rate error | register | standard error envelope and field violations; no secret output | frontend unit, browser smoke |
| `AC-059` | `/activate?token=…` | loading/success | activate | token is removed from URL and never rendered or persisted | frontend unit, browser smoke |
| `AC-060` | `/activate?token=…` | token/rate error | activate | code-based semantic message; no resend flow | frontend unit, browser smoke |
| `AC-061` | оба route | recoverable technical error | both operations | safe message/request ID; registration retry keeps the attempt key | frontend unit, browser smoke, backend regression |

`401`, `403` и resource `404` не входят в контракт публичных register/activate endpoints согласно модели доступа. Они всё равно получают безопасное общее представление через единый API client. Initial data loading и list-empty state неприменимы: до submit данные не запрашиваются, а пустая форма является предусмотренным initial/empty state.

## Реализованный поток

```text
/register
  → useApiForm + client usability validation
  → UUID Idempotency-Key одной попытки
  → $api<RegisterResponse>(POST /v1/auth/register)
  → backend standard envelope или 201 response DTO
  → activation-required UI

/activate?token=…
  → token считывается в локальную память компонента
  → URL немедленно заменяется на /activate
  → $api<null>(POST /v1/auth/activate)
  → 204 success либо code-based token/rate/technical state
```

Обе операции публичны. Frontend не имитирует роли, login, current-user
bootstrap или backend authorization. Activation token является capability,
не рендерится, не помещается в Nuxt state, cookie, `localStorage` или
`sessionStorage` и очищается после terminal result либо unmount. Для
технического retry токен временно остаётся только в памяти живого компонента.

## Соответствие HTTP-контракту

| Элемент | Ожидалось | Реализовано | Проверка |
|---|---|---|---|
| Registration URL/method | `POST /api/v1/auth/register` | `$api` base `/api` + `/v1/auth/register`, `POST` | unit constant test, browser smoke, backend regression |
| Registration body | только `name`, `email`, `password` | typed `RegisterRequest`, явное формирование body | typecheck, code review, browser smoke |
| Idempotency | UUID header; один key на попытку | RFC 4122 UUID v4 через `crypto.getRandomValues`, stable retry, reset после edit/success, pending double-submit guard | unit format/lifecycle test, browser double-click/retry, DB: одна user/outbox запись |
| Registration success | `201`, user + `activationRequired=true` | typed `RegisterResponse`, activation-required state; password очищается | browser smoke, backend HTTP tests |
| Activation URL/method | `POST /api/v1/auth/activate` | `$api` base `/api` + `/v1/auth/activate`, `POST` | unit constant test, Mailpit browser smoke |
| Activation body | только raw `token` | typed `ActivateRequest`; token только в component memory | typecheck, browser storage/URL check |
| Activation success | `204`, без session | success state прямо сообщает об отдельном будущем sign-in | Mailpit browser smoke, backend HTTP tests |
| Validation | standard `422`, field violations | client usability validation + backend violations у полей | unit, browser blank submit, backend regression |
| Business errors | `409` stable codes | semantic email/idempotency/password states | unit, browser duplicate email |
| Token errors | `400/410` stable codes | invalid/expired/used/invalidated states без resend | unit, browser reused token |
| Rate limit | `429`, `Retry-After` | semantic state и точное число секунд из numeric header | unit |
| Technical error | safe envelope/request ID | safe message, support reference, explicit retry | unit, shared API-client tests |
| Unexpected access/resource errors | safe `401/403/404` | status-based non-retryable fallback; global safe 404 page | unit, browser 404 smoke |

## UI-состояния и доступность

- registration: initial/empty, client/backend validation, pending, business/rate/technical error, activation required;
- activation: preparing/loading, missing link, success, token/rate/technical error;
- labels связаны с input, field errors подключены через `aria-describedby`,
  invalid fields получают `aria-invalid`;
- error summary и terminal activation state получают focus;
- pending передаётся через disabled fieldset/button и `aria-busy`;
- success/loading/error используют live-region roles;
- skip link, семантические landmarks и keyboard focus styles сохранены;
- layout складывается в одну колонку до `52rem`, actions занимают полную ширину
  до `36rem`, touch targets имеют минимум `2.75rem`.

## Изменённые frontend-файлы

- `frontend/app/pages/register.vue` — registration route и state machine;
- `frontend/app/pages/activate.vue` — одноразовая активация и очистка URL;
- `frontend/app/utils/auth-flow.ts` — endpoints, validation, attempt keys и semantic errors;
- `frontend/app/types/api.ts` — точные request/response types первого среза;
- `frontend/app/utils/api-error.ts` — безопасное чтение numeric `Retry-After`;
- `frontend/app/components/FormErrorSummary.vue` — предметный заголовок summary;
- `frontend/app/layouts/default.vue`, `frontend/app/pages/index.vue` — навигация на реальный route;
- `frontend/app/assets/css/main.css` — form/auth/responsive styles;
- `frontend/tests/unit/auth-flow.test.mjs`, `frontend/tests/unit/api-error.test.mjs` — frontend regression.

Новые зависимости, backend-файлы и миграции не добавлялись.

## Проверки E1-14

| Команда/проверка | Exit code | Результат |
|---|---:|---|
| `docker compose exec -T frontend npm run test:unit` | 0 | 15 tests passed |
| `docker compose exec -T frontend npm run check:quality` | 0 | lock dry-run, ESLint и Nuxt typecheck passed |
| `docker compose exec -T frontend npm run build` | 0 | production build passed |
| `make test-backend` | 0 | empty isolated PostgreSQL, 93 tests / 722 assertions |
| `make check` | 0 | repository, backend, frontend, audits и smoke passed; source drift отсутствует |
| Browser: blank form | — | error summary focused; три field errors отображены |
| Browser: double click | — | один account и один durable email intent |
| Browser: registration success | — | activation-required state, без login/session |
| Browser: Mailpit exact link | — | письмо получено, точная ссылка открыла activation loading/success |
| Browser: token hygiene | — | URL `/activate`; DOM/storage/cookie не содержат token/session |
| Browser: reused link | — | semantic `Activation link was already used` |
| Browser: duplicate email | — | semantic `Email already registered` |
| Browser: console | — | итоговые registration/activation/Mailpit tabs без warnings/errors |

Для безопасного полного smoke к существующей dev-базе была применена только
уже tracked forward migration `Version20260731153000`; база и volumes не
очищались. Backend и worker были пересозданы с runtime-only
`FRONTEND_BASE_URL=http://localhost:8080`, потому что локальный `.env` использует
нестандартный `APP_PORT=8080`. Файлы окружения не изменялись.

## Известные ограничения и отложенная часть

- Login, resend activation, session bootstrap и protected routes остаются за
  пределами `MVP-SC-001`.
- `401/403/resource 404` не являются ответами двух публичных endpoints;
  проверены безопасные fallback, а не недостижимые feature states.
- Data loading и list-empty state неприменимы: экран регистрации не загружает
  данные, пустая форма является нормативным initial/empty state.
- Responsive breakpoints и отсутствие desktop overflow проверены; capability
  in-app browser не применила запрошенный mobile viewport и сохранила `1280px`,
  поэтому отдельная визуальная проверка на реальном mobile viewport остаётся
  ручной проверкой перед release.

Противоречий между сценариями, HTTP-контрактом, моделью доступа и ADR не
обнаружено; новых открытых вопросов не создано.
