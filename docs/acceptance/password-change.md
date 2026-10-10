# Техническая проверка смены известного пароля

| Поле | Значение |
|---|---|
| Сценарий | MVP-SC-005 |
| Дата | 2026-10-10 |
| Ветка | codex/sc-005-password-change |
| Base | 4cae6c8; включает SC-004 и SC-003, ещё не merged в main |
| Функциональный результат | PASS |
| Release gate | BLOCKED: dependency audit; исключений нет |
| Решение Product Owner | Продуктовая приёмка SC-005 и merge не выполнены |

Нормативные требования: [SC5-01—06](../features/password-change.md),
BR-USR-007/009, HTTP 9.0/9.3/16—18, User/password transaction boundaries,
ADR-006/014/015/017/018, принятые OQ-005/009/010/014/018.

## Матрица доказательств

| Требование | Доказательство |
|---|---|
| Strict Bearer и authenticated subject, active User recheck | PasswordChangeHttpTest: missing/invalid/expired JWT, unknown/inactive; application denials, fresh hydration |
| Новая policy 12—128 Unicode code points, non-space, без trim; текущий пароль буквально | ChangePasswordTest, HTTP validation matrix, frontend validation/component; persisted Unicode password |
| User FOR UPDATE → fresh current credential → replace hash → revoke sessions | DoctrinePasswordChangeRepository, ChangePassword; реальные process/DB serial-order tests |
| Атомарность и rollback после flush | PasswordChangePersistenceTest: исходный hash и все session rows сохранены после controlled exception |
| Отозваны все unfinished sessions владельца, включая текущую; чужие/expired/previously revoked сохранены | Persistence matrix; browser две независимые сессии, обе старые refresh credentials дают 401 |
| Два change со старым current password дают один успех | PasswordChangeConcurrencyTest: второй процесс наблюдаемо ждёт User lock, затем INVALID_CREDENTIALS |
| Login/refresh до change отзываются; после change отказывают | Оба порядка в concurrency suite, свежие hash/session checks, реальные DB locks |
| Change/reset/logout-all/current logout совместимы | Оба serial order для каждой пары; reset capability сохраняется согласно нормативному scope |
| Успех — empty 204 с точными cookies paths/flags, отказ не очищает cookies и не пишет DB | HTTP success/error assertions; общая AuthCookieFactory |
| JWT продолжает действовать до exp | HTTP /me после change; browser JWT другой сессии даёт 200 при refresh 401 |
| Только exact 204 завершает local auth и соседние вкладки | Auth-session unit suite и browser BroadcastChannel/reload; token-free session-ended |
| Неверный current password не вызывает refresh/retry или logout | Unit + browser: один POST, ноль refresh, форма/авторизация остаются |
| Bearer denial — один refresh/retry без nested Web Lock | Unit tests: successful retry, repeated auth denial, credential denial после refresh |
| Pending и duplicate guard, autocomplete/accessibility, отсутствие формы без auth | Component и browser validation/duplicate submit; account page middleware |
| Current/new password очищаются после server result и unmount | Component server-result tests, cleanup hook формы; browser empty-value predicates после credential/network failure |
| Пароли/credentials не попадают в URL/storage/public response/API logs | HTTP privacy regression, private component state; browser expectNoPersistentSecrets и safe diagnostics |
| Старый пароль отвергается, новым можно войти | Browser полный journey; literal Unicode password сохранён без normalization |

## Итоговые проверки

Запуск 2026-10-10:

| Проверка | Результат |
|---|---|
| Repository secret patterns и CI config | PASS |
| Backend style, PHPStan level 8, DI/YAML/routes/mapping | PASS |
| Пустая test-БД: migrations, schema sync, PHPUnit | PASS: 413 tests / 4340 assertions; без PHPUnit notices |
| Composer audit --locked | PASS |
| Frontend lock, ESLint, typecheck | PASS |
| Frontend unit / component | PASS: 53 / 41 tests, 10 component files |
| Production Nuxt build | PASS |
| npm audit --audit-level=moderate | FAIL: 11 high; два корневых advisory |
| make check-full | BLOCKED на npm audit; встроенные browser/smoke и финальный drift check не запускались |
| Отдельный make test-e2e | PASS: 21/21, 2.6 минуты; source drift отсутствует |
| Отдельный make smoke | PASS: frontend и API через Nginx на localhost:8080 |
| Точечный PasswordChange suite перед полным gate | PASS: 25 tests / 249 assertions (включает существующий hydration regression) |
| Точечный concurrency suite | PASS: 11 tests / 165 assertions, pg_blocking_pids barrier |
| git diff --check и итоговый status | PASS; чужие AGENTS.md, .agents/ и skills-lock.json сохранены вне feature commits |

Полный gate остановился на прежних advisory
[braces GHSA-vfj7-8cjw-p6xm](https://github.com/advisories/GHSA-vfj7-8cjw-p6xm)
и [node-forge GHSA-86w9-cpqp-85rv](https://github.com/advisories/GHSA-86w9-cpqp-85rv).
Audit threshold, dependency lock и исключения не изменялись. Функциональный PASS
не означает release exemption.

Проверки выполнялись в WSL/Linux через Docker Compose. Test PostgreSQL и отдельный
HTTPS E2E project используют tmpfs; dev-данные и named volumes не изменялись.
Схема не менялась; новые миграции не нужны. Browser suite выполняет реальные API
commands и одновременно сохраняет регрессии SC-001—004.

## Ограничения приёмки

- Release требует устранить dependency audit blocker либо получить явное решение
  владельца об исключении с обоснованием и сроком пересмотра.
- Ветка основана на ещё не merged SC-004/003; merge должен учитывать зависимость.
  Checkbox roadmap остаются открытыми до merge.
- Продуктовая приёмка владельцем, staging и выпуск MVP не подтверждены этим актом.
  Финансовые сценарии и этап 7 остаются незавершёнными.

## Обновление интеграции — 2026-10-10

SC-003/004/005 последовательно merged в main по поручению пользователя,
без конфликтов; snapshot b24fb4f совпадает с проверенной SC-005. Описания
отсутствия merge выше относятся к дате исходного акта и заменены этой записью.
Общая регрессия SC-005: 413 backend / 4340 assertions, 53 unit, 41 component,
21 browser E2E и smoke PASS. Dependency audit остаётся BLOCKED; выпуск,
исключение audit и продуктовая приёмка SC-004/005 этим merge не подтверждены.
