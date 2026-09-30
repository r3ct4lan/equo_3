# Техническая приёмка повторного запроса активации

| Поле | Значение |
|---|---|
| Сценарий | `MVP-SC-001`: registration → activation resend → activation → login → protected `/me` |
| Дата проверки | 2026-09-30 |
| Ветка | `main` |
| Базовый SHA реализации | `c9d9898c7ca6ded8012155bfb9f39c98d924b5e5` |
| Среда | WSL2 Ubuntu; Docker Desktop 24.0.5; Compose 2.20.2; PHP 8.4.24; Node.js 22.23.2; npm 11.6.2; Chromium Playwright |
| Вердикт технической приёмки | **PASS** |
| Release gate | **GO** — `make check-full` завершён успешно |
| Решение Product Owner | **Pending / не предоставлено** |

## 1. Основание и границы

Проверка выполнена против нормативных требований `BR-USR-001—003`,
`BR-SEC-003—007`, HTTP-контрактов активации, ADR-007/013/014/016/018/019 и
критериев `MVP-SC-001`. Противоречий между нормативными артефактами и
проверяемым поведением не обнаружено.

Изменялись только browser E2E, test-only fixture/configuration и документация
проверки. Product code, публичные HTTP-контракты, бизнес-правила, ADR и миграции
не изменялись. Dev-база, dev-volumes и реальные данные не использовались и не
удалялись.

Основной набор: [`first-vertical-slice.spec.ts`](../../frontend/tests/e2e/first-vertical-slice.spec.ts).
CI job: [`Auth session browser E2E`](../../.github/workflows/quality.yml).
Локальная точка входа: `make test-e2e`; она уже входит в `make check-full` и
вызывается отдельным обязательным CI job.

## 2. Критерии, доказательство и результат

| Критерий | Автоматизированное доказательство | Результат |
|---|---|---|
| Регистрация создаёт неактивного пользователя и первое письмо | `E2E-10`: HTTP 201, Mailpit, aggregate state `active=false`, tokens `1/unfinished=1`, outbox `1` | PASS |
| Повторный запрос через реальный UI нейтрален | `E2E-10`: HTTP 202 и `{status: activation_email_scheduled}`; UI не раскрывает существование пользователя, корректность пароля или факт отправки | PASS |
| Старая capability заменяется новой | `E2E-10`: после resend tokens `2`, invalidated `1`, unfinished `1`, outbox `2`; второе письмо имеет другой token | PASS |
| Старая ссылка больше не действует | `E2E-10`: HTTP 410 `TOKEN_INVALIDATED`, состояние БД не меняется | PASS |
| Неактивный пользователь не может войти | `E2E-10`: login до новой активации возвращает HTTP 403 и не создаёт сессию | PASS |
| Новая ссылка активирует ровно один раз | `E2E-10`: HTTP 204; итог `active=true`, invalidated `1`, used `1`, unfinished `0` | PASS |
| После активации доступны login, `/me` и reload | `E2E-10`: login, защищённый `/me`, reload и повторный `/me` успешны | PASS |
| Unknown email не раскрывается и не пишет данные | `E2E-11`: тот же HTTP 202/UI; aggregate state до/после идентичен и равен нулевому | PASS |
| Уже активный пользователь не раскрывается и не пишет данные | `E2E-11`: ответ и UI побайтно совпадают с unknown; token/outbox state до/после неизменен | PASS |
| Rate limit виден UI, содержит `Retry-After`, не ретраится и не пишет данные | `E2E-12`: четвёртый resend возвращает 429 `RATE_LIMIT_EXCEEDED`; UI показывает задержку; browser request count остаётся `4`; aggregate state после 429 не меняется | PASS |
| Capability/session secrets не сохраняются и не попадают в диагностику | `E2E-10—12` и общие fixtures: URL очищается, storage/JS-readable cookies/console проверяются; trace/video отключены, diagnostics redacted, Nginx пишет `$uri` без query | PASS |

Test-only endpoint отдаёт только агрегаты (`exists`, `active`, количества token
states и outbox). Он не возвращает ID, hash, payload или raw capability и
регистрируется только в `APP_ENV=e2e`; обычный `APP_ENV=test` route list его не
содержит.

## 3. Проверки и фактические результаты

| Команда | Exit | Результат |
|---|---:|---|
| `docker compose exec -T -e APP_ENV=test backend composer check` | 0 | Composer manifest/autoload, PHP-CS-Fixer (164 файла), PHPStan, Symfony container/YAML/routes и Doctrine mapping прошли |
| Focused PHPUnit для activation request, transaction/concurrency и email delivery/security | 0 | 32 tests, 382 assertions |
| `docker compose exec -T frontend npm test` | 0 | 28 unit + 24 component = 52 tests |
| `docker compose exec -T frontend npm run check:quality` | 0 | lock dry-run, ESLint и Nuxt typecheck прошли |
| `docker compose exec -T frontend npm run build` | 0 | production client/server build прошёл |
| `make test-e2e` | 0 | 12/12 Playwright tests, 1.2 min; default CI-equivalent project name; source drift отсутствует |
| `make test-backend` | 0 | 269 tests, 2721 assertions |
| `make audit-backend` | 0 | Composer advisories не найдены |
| `make audit-frontend` | 0 | 0 vulnerabilities |
| `make smoke` | 0 | frontend и `/api/health` через Nginx прошли |
| `./scripts/check-secrets.sh && ./scripts/check-ci-config.sh` | 0 | tracked secret patterns не найдены; workflow YAML валиден |
| `make check` | 0 | Полный repository/backend/frontend/audit/smoke gate прошёл без source drift |
| `make check-full` | 0 | Полный gate и 12/12 browser E2E прошли одной командой |

Во время подготовки E2E были устранены две test-infrastructure причины
нестабильного старта: RabbitMQ healthcheck запускается от пользователя
`rabbitmq`, а Debian packages для Playwright загружаются через HTTPS. Финальный
`make check-full` прошёл одной командой. Предшествующие инфраструктурные
остановки происходили до выполнения browser tests и не затрагивали dev data.

## 4. Устранённые блокеры и ограничение

1. `RefreshSessionPersistenceTest` использует фиксированный `ClockPort`, а
   concurrency-сценарий создаёт active session относительно текущего UTC. Тесты
   больше не истекают из-за календарной даты; product clock и TTL не менялись.
2. Обновлены `vitest/@vitest/*` до 4.1.11, `devalue` до 5.9.4, `svgo` до 4.1.0,
   `undici` до 8.11.2 и уязвимые копии `brace-expansion` до 2.1.7/5.0.12.
   `npm audit --audit-level=moderate` сообщает 0 vulnerabilities.
3. Проверена локальная контейнерная среда, а не внешний SMTP и не production.
   Mail delivery подтверждена через реальный SMTP-путь в изолированный Mailpit;
   проверка внешнего SMTP отложена до staging.

## 5. Итог

Поведение повторного запроса активации, нейтральность ответов, replacement
семантика, rate limit и security regressions технически приняты. Общие refresh
test fixtures и dependency advisories исправлены; повторный `make check-full`
успешен. Вердикт технической приёмки — `PASS`.

Окончательное решение Product Owner: **Pending / не предоставлено**.
