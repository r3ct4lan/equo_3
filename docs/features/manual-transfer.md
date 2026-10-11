# SC-016 — Ручной перевод
Дата: 2026-10-11. Статус: merged в main; functional PASS; release audit blocked. Ветка: codex/sc-016-manual-transfer.

## Требования и границы
Основания: MVP-SC-016, DR-E4-001—028, BR-TRF-001/002/004/005,
BR-IDEM-001—005, BR-ACL-001, ADR-002/009 и HTTP 14.4.
Автор — активная сторона постоянного Connect; sender/receiver — ровно две
его стороны, оба активны. Любое направление, положительные целые евроценты
1..2147483647; баланс сумму не ограничивает. MANUAL создаётся с автором,
version=1, debtId/debtParticipantId/relatedTransferId=null; Debt и DEBT_SHARE
не меняются. Баланс вычисляется только сервером из действующих Transfer.

Применяется принятая граница mvp-scope §15: до SC-018 создание без
relatedTransferId. Полный пример HTTP 14.4 содержит отложенное поле:
SC-016 принимает только senderId/receiverId/amount; неизвестные поля дают 400.
Это реализация принятого инкремента, без изменения BR-TRF-007.
Edit/delete, связи с долями и автоматическое погашение не входят.

## Технические решения
Application использует ConnectAccess (ACL и точная пара), AccountAccess
(свежие активные стороны с блокировкой), TransferRepositoryPort и общий
инфраструктурный механизм idempotency через собственный порт.
В одной транзакции: резервирование user-scoped ключа, отсортированные UUID
user locks, создание MANUAL, сохранение исходного 201 representation.
Connect ID входит в canonical request; replay возвращает исходный body,
Location и ETag в окне 24 часов. Ошибка откатывает запись и reservation.
Чужой/отсутствующий Connect скрывается одинаковым 404 до записи.

UI явно показывает sender→receiver и предварительный баланс, допускает
переплату. После подтверждения перечитывает Connect и историю с сервера.
Неопределённый network/5xx результат сохраняет immutable body/key для
явного retry; double submit и поздний ответ другой сессии исключаются.

## Проверяемые этапы
| ID | Содержание | Статус |
|---|---|---|
| SC16-01 | Contract audit, границы и декомпозиция | PASS |
| SC16-02 | Domain, atomic writer, persistence/idempotency/races | PASS: 24 tests / 113 assertions; 4 architecture tests / 614 assertions; backend quality |
| SC16-03 | POST, validation, ACL и safe HTTP errors | PASS: 16 tests / 201 assertions; backend quality |
| SC16-04 | Форма, preview, retry и server refresh; component tests | PASS: 59 unit, 76 component; frontend quality/build |
| SC16-05 | Полный gate, browser/core path, акт и интеграция | Functional PASS; release BLOCKED; merged в main b2a6307 |

Каждый этап фиксируется отдельным проверенным коммитом. Функциональные
доказательства отделяются от полного release gate и решения Product Owner.
Прежний npm audit blocker не считается исключением и проверяется повторно.

## Итоговая проверка
588 backend / 7537 assertions, 59 unit, 76 component и 25 browser E2E PASS.
Build, repository/backend/frontend quality, Composer audit, migrations/schema
parity, Nginx syntax и smoke PASS. E2E не изменил исходники.
Новый writer проверяет pair внутри транзакции после idempotency lookup;
изменённый запрос с прежним ключом, включая неверную пару, получает 409.
PHP-FPM access format содержит только IP/date/method/status; E2E дополнительно
проверяет отсутствие capability paths в service logs.
После свежего PHP patch runtime исправлены только четыре замечания стиля;
18 SQL statements и параметры существующей миграции идентичны до/после.
make check-full дважды blocked прежними npm advisories (11 high), без waiver.
[Матрица и ограничения](../acceptance/manual-transfer.md).

После style-only исправлений: 76 focused backend tests / 1350 assertions,
повторная migration/schema parity и fresh PHP 8.4.26 backend quality PASS.

Проверенный application snapshot b2a6307 fast-forward merged и pushed в main
2026-10-11. Merge не менял application files и не закрывает release blocker.
