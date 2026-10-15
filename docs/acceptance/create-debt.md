# Техническая проверка общего расхода, баланса и истории

| Поле | Значение |
|---|---|
| Сценарии | MVP-SC-011; финансовые read models SC-009/012/015 |
| Дата | 2026-10-11 |
| Ветка | codex/sc-011-create-debt |
| Base | 4f62907; включает SC-008 и разрешение интеграции MVP |
| Функциональный результат | PASS |
| Release gate | BLOCKED: прежний npm dependency audit, исключений нет |
| Product Owner | Продуктовая приёмка и готовность MVP не подтверждены |

[Декомпозиция SC11-01—06](../features/create-debt.md) покрывает DR-E3-001—070,
BR-DEBT-001—014, BR-TRF-001—003/008—009, BR-ACL-001—007,
BR-IDEM-001—005, BR-CONC-001—003, HTTP 4—7/11/13/14 и ADR-002/003/005/009—012/015.

## Матрица доказательств

| Требование | Доказательство |
|---|---|
| Exact cents/storage range и ceil share без float/overflow | FinancialModelTest; frontend finance unit tests; strict money HTTP cases |
| Author=payer/author participant, payer-in-split и no self-transfer | FinancialModelTest, FinancialCreationTest и FinancialHttpTest |
| Debt/participant/Transfer round trip, CHECK/UNIQUE/FK/history protection | FinancialSchemaTest; все пять migrations из пустой БД и schema parity |
| Atomic Debt/participants/new Connect/shares/result | FinancialCreationTest; test-only INSERT triggers проверяют rollback каждой стадии |
| Новый Connect payer/participant только один | Creation tests и реальные разные writers на одной паре |
| Fresh eligibility self/Connect/co-participant и activity | Creation tests: unknown/inactive/неподходящий author, co-participant без пары; deactivation race |
| Same key/body replay, changed body conflict и logical expiry | Creation/HTTP tests; original 201/Location/ETag; TTL до physical cleanup |
| Real-process races | FinancialConcurrencyTest: same key, changed body, different keys, rollback первого writer и deactivation под lock; pg_blocking_pids barrier |
| Active debt list, полная карточка, role flags/ETag | FinancialHttpTest и component debt list/card |
| Excluded participant теряет Debt, сохраняет свою историю | HTTP тест с исключённой DP и soft-deleted долей; independent Transfer ACL |
| Deleted Debt скрыт из списка, frozen состав доступен по ссылке | HTTP/card tests с удалённым Debt и inactive payer |
| Ledger-only balance, deleted исключены, sign symmetric, wide aggregate | HTTP exact BIGINT case; 32 generated entries в трёх перестановках |
| History active/deleted обоих типов, keyset ties | FinancialHttpTest, schema test и component history/card |
| Safe inaccessible debtReference без title/isDeleted/composition | HTTP + component; посторонний пользователь в browser |
| Brief users без чужого email | HTTP/privacy tests, browser main/response predicates |
| Только server-derived eligible people | Все Connect/debt pages + current card participants; unit fixture с pagination/co-participant/inactive |
| UI validation, preview, duplicate guard и stable retry | Finance component tests; immutable key/body после network failure; response прежнего account discarded |
| Старые balances очищаются сразу при account switch | Component pending нового account; shared financial list/detail generation guards |
| A+B → расход → share/history/opposite balance | Browser финансовый путь с EUR 10.01 и включённым payer: две доли по 501 cents, одна операция |
| Lost response после commit не дублирует расход | Browser route.fetch получает реальный 201, ответ прерывается; explicit same-key/body retry получает тот же ID/Location/ETag |
| Третьей стороне недоступны Debt/Transfer/history | Browser UI и API safe 404, пустой debt list, отсутствие title/email |
| ADR-015/module boundaries | Architecture PHPUnit + PHPStan; cross-module Application/Api, UUID records |

## Итоговые проверки

| Проверка | Результат |
|---|---|
| Repository secrets/CI config | PASS |
| Backend style/PHPStan/container/YAML/routes/mapping | PASS |
| Все пять migrations на пустой tmpfs test-БД, schema parity | PASS |
| Полный PHPUnit | PASS: 548 tests / 7211 assertions |
| Composer audit --locked | PASS |
| Frontend lock/ESLint/typecheck | PASS |
| Frontend unit/component | PASS: 57 / 67 tests |
| Production Nuxt build | PASS |
| npm audit --audit-level=moderate | FAIL: 11 high, прежние braces/node-forge advisories |
| make check-full | BLOCKED на npm audit; встроенные smoke/E2E и финальный drift check не достигнуты |
| Отдельный финальный make test-e2e | PASS: 24/24, без source drift |
| make smoke и Nginx syntax | PASS |
| Новые dev migrations | PASS: latest Version20261011120000, pending 0 |
| git diff --check | PASS |

Первый финансовый browser snapshot прошёл 24/24. Финальный повтор выявил гонку
в фикстуре: вход третьим пользователем начинался при ещё открытой прежней сессии,
и login page автоматически открывала профиль. Фикстуры SC-008/011 теперь явно
завершают прежнюю сессию перед новым входом. Финальный полный browser suite
перезапущен и прошёл 24/24; automatic retries в Playwright не включались.

Полный backend и frontend gate прошёл до dependency audit, включая последние
UI fixes очистки прежнего account state и reload недоступных participants.
Изменение после gate касается только явной смены аккаунта в E2E fixture;
оно дополнительно проверено ESLint и полным browser suite.

## Audit и границы готовности

Audit threshold, manifests/lock и исключения не менялись. На дату проверки
npm registry возвращает braces 3.0.3 и node-forge 1.4.0; advisories затрагивают
эти версии и не указывают published patched version:
[braces GHSA-vfj7-8cjw-p6xm](https://github.com/advisories/GHSA-vfj7-8cjw-p6xm),
[node-forge GHSA-86w9-cpqp-85rv](https://github.com/advisories/GHSA-86w9-cpqp-85rv).
Предложение npm audit fix --force обновить framework вне pinned range
не является доказательством исправления/совместимости. Release blocker сохраняется.

68/70 задач этапа 3 функционально реализованы и проверены; DR-E3-066/070
остаются открытыми из-за общего gate. Checkbox roadmap обновляются только после
включения в main. Merge не означает audit exemption, продуктовое решение или
готовность всего MVP. Следующая функциональная область — SC-016/MANUAL writer.

Разрушающие tests работали только в отдельных tmpfs test/E2E окружениях.
Применённые migrations не переписывались. Локально добавлены недостающие
UserSession/Connect/financial tables проверенными additive migrations;
существующие app_user/outbox данные и volumes сохранены.
ER DOT/SVG/PNG не менялись: принятый нормативный data model не менялся.
Чужие .agents/ и skills-lock.json сохранены вне коммитов.

## Интеграция — 2026-10-11

Snapshot 5b1d981 fast-forward merged в main после функциональной проверки.
Проверенные application files при merge не менялись; roadmap отмечает 68/70
задач этапа 3. DR-E3-066/070 сохраняют общий audit blocker; продуктовая приёмка
и готовность MVP не подтверждены.

## Повторная проверка quality gate — 2026-10-15

На `main` snapshot `c390841` GitHub Actions run `38766369660` завершил полный
workflow успешно: backend/frontend quality и build, миграции и тесты,
dependency audit по принятой политике, full MVP browser E2E и application
smoke. Browser job проходит создание долга двумя пользователями и проверяет
доли, историю, баланс и privacy. CI запускает проектные проверки, описанные в
`docs/quality-gate.md`; локальный `make check` отдельно не запускался. Это
закрывает технические DR-E3-066/070. Product Owner acceptance и готовность MVP
остаются отдельным решением.
