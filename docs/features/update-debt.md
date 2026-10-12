# SC-013 — Исправление долга и полный перерасчёт
Дата: 2026-10-12. Ветка: codex/sc-013-update-debt от main 4282715.
Статус: functional PASS; интеграция в main pending; release BLOCKED.

## Требования и техническое решение
Основания: MVP-SC-013, DR-E5-001—037, BR-DEBT-001/006—013,
BR-CONC-001—003, BR-ACL-002—007, ADR-003/005/010, HTTP 13.5.
PUT описывает полный желаемый title/description/amount/participantIds/
includePayerInSplit. Payer и author неизменяемы; payerId/version в body
не принимаются. If-Match обязателен, строгий quoted positive integer.
Missing → 428; malformed → 400; stale → 409 с expectedVersion/actualVersion.
Недоступный Debt скрывается 404; читающий неавтор получает 403.
Deleted/frozen проверяются до записи; свежие User locks исключают гонку
с деактивацией. Authentication policy проверяется до command policy.

Транзакция: lock Debt root → ACL → sorted UUID locks author/payer/current/
desired participants → domain state/version/composition/eligibility checks →
полный desired aggregate → participant soft exclude/restore/create →
Connect get-or-create → полный пересчёт автоматических Transfer → root version.
Текущего inactive обычного участника можно оставить/исключить; нового или
восстанавливаемого — только active и доступного автору. Non-payer author
обязательно остаётся текущим. Исторические participant/Transfer IDs и createdAt
сохраняются при restore; manual Transfer не меняется.

No-op с актуальным If-Match возвращает 200 и прежний ETag без записи,
без изменения Debt/Transfer versions. Нормализация title/UUID/состава совпадает
с create; фактическое изменение агрегата увеличивает root version один раз,
Transfer version — только при изменении amount/deleted. Stale no-op отвергается.
Схема уже поддерживает мягкое удаление, новых migrations не нужно.

UI хранит ETag карточки, показывает неизменяемого payer, текущие/исключаемые/
добавляемые или возвращаемые selections; inactive current допускается.
API не добавляет исторических участников в current representation.
Повторное добавление доступного человека восстанавливает прежнюю запись.
Conflict/uncertain result не вызывает silent PUT retry; предлагается explicit
server reload/review. После успеха Debt/баланс/история перечитываются с сервера.
Session/path/unmount guards отбрасывают поздние ответы.

## Этапы
| ID | Содержание | Статус |
|---|---|---|
| SC13-01 | Contract audit, no-op и декомпозиция | PASS |
| SC13-02 | Domain revision/composition/versions и unit tests | PASS: 20 unit/architecture tests, 678 assertions; backend quality |
| SC13-03 | Atomic writer/repositories/shares, rollback и DB races | PASS: full backend 626 tests / 7724 assertions, schema parity, backend quality |
| SC13-04 | PUT/If-Match/ACL/errors, HTTP tests | PASS: 26 tests / 293 assertions; backend quality |
| SC13-05 | Edit UI, conflict/reload/server refresh, component tests | PASS: 62 unit, 87 component; frontend quality/build |
| SC13-06 | Полный gate, browser, документация и интеграция | Functional PASS: 652 backend / 8020 assertions, 62 unit, 87 component, 26 browser; release BLOCKED npm audit; merge pending |

Удаление Debt, смена payer, прямое изменение DEBT_SHARE и audit log версий
не входят. Технические доказательства отделяются от продуктовой приёмки и
полного release gate. Прежний npm audit (11 high) не обходится.

SC13-03: шесть rollback точек и четыре real-process races (stale writer,
first rollback, payer deactivation, retained inactive current participant) PASS.
Read repository получает root/current/history participant rows одним SQL snapshot;
writer блокирует root перед чтением состава. Новых миграций не потребовалось.

SC13-05: ETag берётся из onResponse существующего API client, без fallback
на body.version. Карточка и edit — sibling pages [id]/index.vue и edit.vue.
Balance/history перечитываются существующими server queries при открытии;
успешное сохранение возвращает к карточке с новым GET. Исторический состав
не добавлен в DTO: UI явно различает current/remove и add-or-restore.

SC13-06: [итоговая техническая проверка](../acceptance/update-debt.md).
35/37 задач этапа 5 реализованы и проверены. DR-E5-035/037 остаются открытыми:
npm audit возвращает 11 high; полный release gate не PASS. Product Owner
acceptance и готовность MVP не подтверждены. Следующая обязательная область —
эксплуатационный этап 7 и финальная проверка SC-019—022.
