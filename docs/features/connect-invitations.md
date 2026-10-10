# SC-008 — приглашение и постоянный Connect

Дата: 2026-10-10. Ветка: `codex/sc-008-connect-invitations`.
Статус: реализация. Нормативные источники: BR-CON-001—005, BR-INV-001—006,
BR-ACL-001/005/007, BR-SEC-005/007; ADR-004/008/014/015; HTTP 6, 11—12, 17;
модель Connect и ConnectInvitation. Зависимость SC-002 выполнена.

## Проверяемые этапы

| ID | Объём | Проверка | Статус |
|---|---|---|---|
| SC8-01 | Contract audit, декомпозиция | Документы и Git | PASS |
| SC8-02 | Чистая модель, новая миграция, mapping | Unit, миграции, schema parity | PASS |
| SC8-03 | Get-or-create, HMAC/key compatibility, атомарное accept, read models | Integration, реальные конкурентные процессы | PASS |
| SC8-04 | Пять endpoints, Bearer, privacy, пагинация, rate limits | HTTP, architecture gate | PASS |
| SC8-05 | Создание/copy/share, public preview, login/accept, список/карточка | Component, lint/typecheck | PASS |
| SC8-06 | Два пользователя и регрессии, документация | `make check-full`, browser E2E | Pending |

Этапы сохраняют ID задач DR-E2-001—045. Checkbox roadmap отмечаются после
проверки и включения в main. SC-009 покрывается только в части чтения Connect;
финансовая история и баланс реализуются этапом 3.

## Технические решения в рамках контракта

- Модули Invitations и Connects взаимодействуют только через Application/Api.
  IdentityAccess публикует узкий интерфейс актуального пользователя и блокировки;
  HTTP и Doctrine остаются адаптерами. Нет межмодульных ORM associations.
- Пара UUID каноническая, PostgreSQL unique pair и CHECK защищают от дублей и
  self-connect. Приглашение хранит SHA-256 полного подписанного token; HMAC-SHA256
  подписывает canonical invitation UUID, signature — Base64URL без padding.
- Отдельный signing key ring из окружения, минимум 256 бит на ключ. Старые ключи
  сохраняются до истечения всех подписанных ими ссылок (минимум 7 дней).
- Get-or-create блокирует автора; истёкшие приглашения сохраняются без изменения
  срока и без ложного usedAt. Единственность действующего приглашения гарантирует
  сериализованный writer; временной predicate нельзя выразить partial unique
  index с `now()`. Accept блокирует пользователей в порядке UUID, затем invitation;
  пара создаётся через conflict-safe get-or-create в той же транзакции.
- Полный hash сверяется после проверки HMAC во всех публичных операциях. Повтор
  accept тем же пользователем возвращает Connect; другой получает TOKEN_USED.
  Для использованного приглашения replay проверяется до expiry, чтобы сохранять
  нормативную идемпотентность. Preview used/expired возвращает 410; для inactive
  автора безопасный preview имеет canAccept=false.
- Connect list — `(createdAt DESC, id DESC)`, opaque cursor, limit 20/100.
  До финансового этапа balanceAmount=0; capability flags выражают активность
  сторон, UI не предлагает ещё отсутствующие финансовые операции.
- Invitation capability живёт только в памяти/адресе штатной ссылки. SSR payload,
  логи и diagnostics её не сохраняют; Referrer-Policy=no-referrer, Cache-Control
  no-store. Login continuation хранит token в памяти вкладки без query/storage.
- Известный dependency audit blocker не отменяется. Итоговый gate запускается
  полностью; поведенческие проверки и release readiness фиксируются отдельно.

SC8-02: 18 focused tests / 520 assertions PASS, новая миграция с нуля и schema parity PASS.
SC8-03: 24 focused tests / 104 assertions PASS, четыре real-process PostgreSQL
races (get-or-create, same/other acceptor, reciprocal invitations); backend quality PASS.

SC8-04: 40 focused tests / 284 assertions PASS (application, crypto, HTTP,
redaction). Nginx configuration syntax PASS. Capability upstream errors для
путей приглашений подавлены; безопасная диагностика сохраняется в application
logs через request ID. Остальные маршруты сохраняют штатный error log.

SC8-05: frontend lint/typecheck PASS; 53 component tests PASS. Проверены
copy, защищённый submit, login continuation только в памяти, terminal/retry
states, пагинация и отказ принимать ответы прежней ссылки/прежнего аккаунта.
