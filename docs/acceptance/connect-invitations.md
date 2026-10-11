# Техническая проверка приглашения и постоянного Connect

| Поле | Значение |
|---|---|
| Сценарий | MVP-SC-008; чтение Connect из SC-009 |
| Дата | 2026-10-11 |
| Ветка | codex/sc-008-connect-invitations |
| Base | 5475565; включает SC-003—005 и разрешение на интеграцию MVP |
| Функциональный результат | PASS |
| Release gate | BLOCKED: npm dependency audit; исключений нет |
| Решение Product Owner | Продуктовая приёмка и готовность MVP не подтверждены |

Нормативные требования и стабильные этапы SC8-01—06:
[feature specification](../features/connect-invitations.md). Scope — DR-E2-001—045,
BR-CON-001—005, BR-INV-001—006, BR-ACL-001/005/007, BR-SEC-005/007;
ADR-004/008/014/015; HTTP 6, 11—12, 17; Connect и ConnectInvitation.

## Матрица доказательств

| Требование | Доказательство |
|---|---|
| Каноническая постоянная пара, не self, unique/FK RESTRICT | CanonicalPair/ConnectInvitation unit и ConnectSchemaTest; новая миграция и schema parity |
| Только одна доступная ссылка автора, неизменный срок повторной выдачи, новая после use/expiry | InvitationFlowTest и real-process InvitationConcurrencyTest; browser повтор create |
| HMAC/key compatibility и полный hash lookup | InvitationTokenCodecTest; InvitationFlowTest: старый ключ, неверные signature/hash и rollback |
| Safe public preview, inactive author | InvitationHttpTest и компонентная матрица; browser unknown preview |
| Own/inactive/expired/used-by-other запреты | InvitationFlowTest, InvitationHttpTest; UI component states |
| Атомарные pair/use и идемпотентный replay тому же пользователю | Flow rollback; четыре real-process races: create/create, accept same/other, reciprocal |
| 201 новая пара, 200 существующая/повтор | HTTP matrix; browser fetch принятия с 201, потерянный ответ, явный retry с 200 |
| Bearer/current subject, pair membership, чужой Connect скрыт через 404 | InvitationHttpTest; browser third-user denial |
| OtherUser без email, opaque cursor, limit bounds, inactive restrictions | HTTP list/detail/pagination tests; компонентные состояния |
| Rate limits IP и invitation, invalid capability IP-only | HTTP quota matrix; общий safe 429/Retry-After |
| Copy/share, continuation в памяти, поздние ответы прежнего аккаунта/ссылки | connect-invitations component suite; browser copy → anonymous preview → login → accept |
| Один refresh/retry budget, terminal errors без автоматических повторов | Переиспользованные $auth/$api, component и auth regression |
| Capability/hash/SQL/exception details исключены из диагностики | InvitationRedactionProcessorTest; Nginx safe path map; очищенные browser fixtures |
| Capability отсутствует в SSR/storage, no-store/no-referrer | Browser boolean secrecy predicates, SSR headers; memory-only continuation |
| Одна пара у обеих сторон, нулевой баланс до финансового этапа | Полный двухпользовательский browser journey; list/detail одинаковый ID |
| Границы модулей ADR-015 | Architecture PHPUnit и PHPStan; межмодульные Application/Api |

## Итоговые проверки

Запуск 2026-10-11, после восстановления выключенного Docker Desktop:

| Проверка | Результат |
|---|---|
| Repository secrets/CI config | PASS |
| Backend style, PHPStan 8, container/YAML/routes/mapping | PASS |
| Пустая test-БД: migrations и schema parity | PASS |
| PHPUnit | PASS: 461 tests / 4711 assertions |
| Composer audit --locked | PASS |
| Frontend lock, ESLint, typecheck | PASS |
| Frontend unit / component | PASS: 53 / 53 tests |
| Production Nuxt build | PASS |
| npm audit --audit-level=moderate | FAIL: 11 high; braces GHSA-vfj7-8cjw-p6xm и node-forge GHSA-86w9-cpqp-85rv |
| make check-full | BLOCKED на npm audit; встроенные browser/smoke и финальный drift check не достигнуты |
| Отдельный make test-e2e | PASS: 23/23; без source drift |
| Отдельный make smoke и Nginx syntax | PASS |
| git diff --check | PASS |

Backend suite превысил прежний Composer default timeout 300 секунд; test script
теперь передаёт 900 секунд только изолированному test container. Повторный suite
завершён без ошибок. Browser ожидание empty state исправлено по фактическому
компоненту strong; регрессии SC-001—005 и новый SC-008 проходят вместе.

Проверки выполнены в WSL/Linux и Docker Compose. Test PostgreSQL и отдельный HTTPS
E2E project используют tmpfs; dev-таблицы, применённые миграции и named volumes
не изменялись. E2E генерирует временные invitation/JWT/CSRF/TLS keys, удаляет
только свой project и секреты. Чужие .agents/ и skills-lock.json сохранены вне коммитов.

## Ограничения и дальнейшая работа

Audit threshold, lock и исключения не менялись. Dependency audit остаётся
видимым release blocker; функциональный PASS не даёт release exemption.
DR-E2-042/045 остаются открытыми. SC-009 покрыт только чтением списка/карточки
с нулевым балансом; финансовые операции/история, retention cleanup, staging,
продуктовая приёмка и финальный gate MVP остаются отдельными задачами.

Ветка функционально проверена; отметки roadmap обновляются после интеграции
в main. Merge не означает закрытие dependency audit или продуктовую приёмку.

## Интеграция — 2026-10-11

SC-008 fast-forward merged в main после проверки snapshot 8cef3ba.
Roadmap отмечает 43/45 задач, DR-E2-042/045 остаются открытыми. Проверенные
application files при merge не менялись. Product Owner acceptance, audit
exemption и готовность MVP не подтверждены.
