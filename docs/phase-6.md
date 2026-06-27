# Fase 6 — Cierre financiero: Refunds y WinnerPayout

Fase 6 implementó el ciclo de vida financiero completo: reembolso de órdenes pagadas
(Fase 6.2) y registro manual del pago al ganador con comprobante (Fase 6.3).
Cerrada con **1096 tests / 5107 assertions — todos verdes.**

---

## 1. Resumen final de Fase 6

| Subfase | Qué se implementó |
|---|---|
| 6.2 | `RefundOrderAction`, tabla `refunds`, endpoints POST/GET, idempotencia state-based |
| 6.3 | `ProcessWinnerPayoutAction`, tablas `winner_payouts` y `winner_payout_documents`, endpoints POST/GET, storage privado, comprobante adjunto |
| 6.4 | Cierre: tests de arquitectura cross-cutting, documentación final coherente |

**Fuera de Fase 6 (explícitamente):** gateway externo, notificaciones al jugador,
edición/anulación de refund o payout, pagos parciales, múltiples comprobantes,
frontend, nuevos endpoints distintos a los 4 financieros.

---

## 2. Mapa financiero final

### 2.1 Entidades y su rol

```
Game
  prize_cents   : integer   — snapshot del premio fijado al crear el juego
  currency      : char(3)

Order (1 por jugador por juego)
  subtotal_cents : bigint
  total_cents    : bigint   — fuente autoritativa del monto cobrado
  currency       : char(3)
  status         : OrderStatus  {Pending, PaymentSubmitted, Paid, Refunded, Rejected, Expired, Cancelled}
  paid_at        : timestampTz nullable

Payment (1 por Order, UNIQUE)
  amount_cents   : bigint
  currency       : char(3)
  method         : PaymentMethod  {Manual}
  status         : PaymentStatus  {Pending, Submitted, Approved, Rejected, Refunded}

PurchaseAllocation (append-only, puente Commerce→RNB)
  order_item_id  : uuid FK
  game_entry_id  : uuid FK
  payment_id     : uuid FK

GameEntry (módulo RNB)
  status : EntryStatus  {Confirmed, Cancelled, Refunded, Winner}

GameWinner (append-only, 1 por partida)
  game_id, game_entry_id, game_draw_id, game_number_id, user_id, winning_hits, won_at

Refund (append-only, 1 por Order)           ← NUEVO Fase 6.2
  order_id, payment_id, amount_cents, currency, reason
  idempotency_key_hash, request_fingerprint (server-side, no expuestos en API)
  processed_by_user_id, processed_at

WinnerPayout (append-only, 1 por GameWinner)  ← NUEVO Fase 6.3
  game_winner_id, game_id, user_id
  amount_cents, currency, method, external_reference, notes
  idempotency_key_hash, request_fingerprint (server-side, no expuestos en API)
  processed_by_user_id, processed_at

WinnerPayoutDocument (append-only, 1 por WinnerPayout)  ← NUEVO Fase 6.3
  payout_id, disk, path, original_filename, mime_type, size_bytes, sha256
  (disk, path, sha256 — internos, no expuestos en API)
```

### 2.2 Ciclo de vida financiero completo

```
COMPRA:
  Order(Pending) → Order(PaymentSubmitted) →
    [admin aprueba] → Order(Paid) + Payment(Approved)
    [admin rechaza] → Order(Rejected) + Payment(Rejected)
    [expiración]    → Order(Expired)
    [jugador cancela] → Order(Cancelled)

JUEGO:
  GameEntry(Confirmed) → [draw con hit] → GameEntry(Winner)
  GameWinner INSERT (append-only)

REFUND:
  Order(Paid) + Payment(Approved)
    → [RefundOrderAction] →
      Order(Refunded) + Payment(Refunded)
      GameEntry(Confirmed) → GameEntry(Refunded)
      GameNumber(Sold) → GameNumber(Available)  [si juego en SalesOpen/SalesClosed/Cancelled]
      Refund INSERT (append-only)
      GameEvent INSERT  type=order_refunded

PAYOUT:
  GameWinner existe + Game(Completed)
    → [ProcessWinnerPayoutAction] →
      WinnerPayout INSERT (append-only)
      WinnerPayoutDocument INSERT (append-only)
      GameEvent INSERT  type=payout_paid
```

---

## 3. Decisiones de producto cerradas

Todas las decisiones de diseño de Fase 6.1 fueron resueltas durante 6.2/6.3:

| # | Decisión | Resolución aplicada |
|---|---|---|
| D1 | ¿Refund si GameEntry está en `Winner`? | **Prohibido.** `WinnerEntryNotRefundable` si entry.status=Winner o si existe GameWinner con ese game_entry_id. |
| D2 | ¿El Refund libera GameNumber? | **Sí, solo en estados refundables** (`SalesOpen`, `SalesClosed`, `Cancelled`). En otros → `OrderNotRefundable`. |
| D3 | ¿Refund en juego `Completed` (sin ganador en ese entry)? | **Permitido** si el entry no está en Winner y no hay GameWinner que lo referencie. |
| D4 | ¿Transición de GameEntry en Refund? | **Solo si está `Confirmed`**; exception si está en cualquier otro estado terminal. |
| D5 | ¿Campo `refunded_by` en `payments`? | **No.** El actor se registra solo en `game_events` como `actor_user_id`. |
| D6 | ¿Comprobante de payout? | **Sí, implementado en Fase 6.3** como tabla `winner_payout_documents`. |
| D7 | ¿Method siempre `manual`? | **Sí, solo `manual`** en Fase 6. CHECK constraint en DB. |
| D8 | ¿Tabla `refunds` separada? | **Sí**, con `UNIQUE(order_id)` y `UNIQUE(idempotency_key_hash)`. |
| D9 | ¿Usar `idempotency_keys` table para Refund? | **No.** Idempotencia state-based en la tabla `refunds` directamente. |
| D10 | ¿Notificar al jugador? | **Evento emitido** (`OrderRefunded` post-commit). Listener real fuera de Fase 6. |

---

## 4. Fuente de verdad monetaria

| Operación | Fuente autoritativa | Snapshot |
|---|---|---|
| Refund | `order.total_cents`, `order.currency` | Copiado a `refunds.amount_cents` en INSERT |
| WinnerPayout | `game.prize_cents`, `game.currency` | Copiado a `winner_payouts.amount_cents` en INSERT |

**Invariante de consistencia verificada en action:**

```
payment.amount_cents == order.total_cents   (guard en RefundOrderAction antes de refundar)
order.currency == payment.currency          (guard en RefundOrderAction)
winner_payouts.amount_cents == game.prize_cents  (snapshot en INSERT, no recalculado)
```

---

## 5. Refunds administrativos

### 5.1 Alcance

Reembolso total de un `Order(Paid)` iniciado por un administrador. Un solo refund por
orden (`UNIQUE(order_id)`). No hay refunds parciales en Fase 6.

### 5.2 Migración

`2026_06_19_020001_create_refunds_table` — tabla `refunds`:

```sql
id                   UUID  PK  (HasUuids — UUID v7)
order_id             UUID  NOT NULL  FK orders  UNIQUE
payment_id           UUID  NOT NULL  FK payments
amount_cents         BIGINT  NOT NULL  CHECK (> 0)
currency             CHAR(3)  NOT NULL  CHECK (~'^[A-Z]{3}$')
reason               TEXT  NOT NULL  CHECK (trim() <> '')
idempotency_key_hash CHAR(64)  NOT NULL  UNIQUE  CHECK (~'^[0-9a-f]{64}$')
request_fingerprint  CHAR(64)  NOT NULL  CHECK (~'^[0-9a-f]{64}$')
processed_by_user_id INT  NOT NULL  FK users
processed_at         TIMESTAMPTZ  NOT NULL
created_at           TIMESTAMPTZ  NOT NULL
-- No updated_at: append-only
```

### 5.3 Modelo

`App\Modules\Commerce\Domain\Models\Refund` — `HasUuids`, `UPDATED_AT = null`,
booted hooks lanzan `ImmutableModelException` en cualquier intento de update o delete.

### 5.4 Invariantes de negocio

- `Order.status` debe ser `Paid`; `Payment.status` debe ser `Approved`.
- `payment.amount_cents == order.total_cents` y `payment.currency == order.currency`.
- `game.status` debe estar en `{SalesOpen, SalesClosed, Cancelled}`.
- Ningún `GameEntry` de la orden puede estar en `Winner`; ningún `GameWinner` puede referenciar sus entries.
- `PurchaseAllocation` es append-only — no se toca durante el refund.
- `GameNumber` transiciona `Sold → Available` — **solo en `RefundOrderAction`**.

---

## 6. WinnerPayout manual

### 6.1 Alcance

Registro manual del pago al ganador por un administrador. Un solo payout por ganador
(`UNIQUE(game_winner_id)`). Incluye comprobante adjunto (PDF) en disco privado.

### 6.2 Migraciones

`2026_06_27_100000_create_winner_payouts_table` — tabla `winner_payouts`:

```sql
id                   UUID  PK  (HasUuids)
game_winner_id       UUID  NOT NULL  FK game_winners  UNIQUE
game_id              UUID  NOT NULL  FK games
user_id              INT   NOT NULL  FK users        (snapshot de game_winner.user_id)
amount_cents         BIGINT  NOT NULL  CHECK (> 0)
currency             CHAR(3)  NOT NULL  CHECK (~'^[A-Z]{3}$')
method               VARCHAR(24)  NOT NULL  CHECK (= 'manual')
external_reference   TEXT  NOT NULL  CHECK (trim() <> '')
notes                TEXT  nullable
idempotency_key_hash CHAR(64)  NOT NULL  UNIQUE  CHECK (~'^[0-9a-f]{64}$')
request_fingerprint  CHAR(64)  NOT NULL  CHECK (~'^[0-9a-f]{64}$')
processed_by_user_id INT  NOT NULL  FK users
processed_at         TIMESTAMPTZ  NOT NULL
created_at           TIMESTAMPTZ  NOT NULL
-- No updated_at: append-only
```

`2026_06_27_100001_create_winner_payout_documents_table` — tabla `winner_payout_documents`:

```sql
id                UUID  PK  (HasUuids)
payout_id         UUID  NOT NULL  FK winner_payouts
disk              TEXT  NOT NULL  CHECK (trim() <> '')   (no expuesto en API)
path              TEXT  NOT NULL  CHECK (trim() <> '')   (no expuesto en API)
original_filename TEXT  NOT NULL  CHECK (trim() <> '')
mime_type         TEXT  NOT NULL  CHECK (trim() <> '')
size_bytes        BIGINT  NOT NULL  CHECK (> 0)
sha256            CHAR(64)  NOT NULL  CHECK (~'^[0-9a-f]{64}$')  (no expuesto en API)
uploaded_by       INT   NOT NULL  FK users
created_at        TIMESTAMPTZ  NOT NULL
-- No updated_at: append-only
```

### 6.3 Modelos

- `WinnerPayout` — `HasUuids`, `UPDATED_AT = null`, booted hooks lanzan `ImmutableModelException`.
- `WinnerPayoutDocument` — mismas garantías.

### 6.4 Invariantes de negocio

- `game.status` debe ser `Completed`.
- `GameWinner` debe existir para ese `game_id`.
- Solo puede existir un `WinnerPayout` por `GameWinner` — `UNIQUE(game_winner_id)`.
- `amount_cents` y `currency` snapshoteados de `game.prize_cents` / `game.currency` al INSERT.
- El comprobante (PDF) se sube antes de la transacción; se elimina en compensación si la action falla.

---

## 7. Idempotencia

Ambos flujos usan **idempotencia state-based** — sin `IdempotentCommandExecutor`, sin captura
de `UniqueConstraintViolationException`.

### 7.1 Header y hash

- Header `Idempotency-Key` obligatorio en endpoints POST (middleware `EnsureIdempotencyKeyHeader`, HTTP 400 si falta).
- `idempotency_key_hash = sha256(trim(header))` — calculado en el Controller.

### 7.2 Fingerprint

**Refund** (calculado en Controller):

```
sha256("operation=refund\n"
      ."order_id={order_id}\n"
      ."actor_user_id={actor_user_id}\n"
      ."reason={mb_strtolower(trim(reason))}")
```

**WinnerPayout** (calculado en Action, DESPUÉS de obtener `game_winner_id` bajo lock):

```
sha256("winner_payout|{game_id}|{game_winner_id}|{actor_user_id}"
      ."|{strtolower(trim(external_ref))}|{strtolower(trim(notes))}|{doc_sha256}")
```

### 7.3 Ramas de idempotencia

| Situación | Respuesta |
|---|---|
| Primera llamada | HTTP 200, `was_already_{refunded\|processed}: false` |
| Misma key + mismo fingerprint | HTTP 200, `was_already_{refunded\|processed}: true` |
| Misma key + diferente fingerprint | HTTP 409, `error: idempotency_key_mismatch` |
| Key diferente, refund/payout ya existe | HTTP 200, resultado existente, `true` |

---

## 8. Locks y concurrencia

### 8.1 RefundOrderAction — lock order canónico

```
1. Game        FOR UPDATE  — previene cambios de lifecycle durante el refund
2. Order       FOR UPDATE  — raíz del refund
3. Refund      FOR UPDATE  — early-return idempotency (antes de tocar Payment/Entries)
4. Payment     FOR UPDATE  — transición Approved → Refunded
5. GameEntries FOR UPDATE  (sorted by id)
6. GameNumbers FOR UPDATE  (sorted by id)
```

Sin deadlock con `ApprovePaymentAction`: ambos toman `Game → Order` como raíz.
El early-return en paso 3 garantiza que los reintentos no fallen por registros en estado terminal.

### 8.2 ProcessWinnerPayoutAction — lock order canónico

```
1. Game        FOR UPDATE  — previene conflicto con acciones de lifecycle del motor
2. GameWinner  FOR UPDATE  — unicidad: previene dos payouts simultáneos
3. WinnerPayout FOR UPDATE — early-return idempotency
4. INSERT WinnerPayout
5. INSERT WinnerPayoutDocument
6. INSERT GameEvent (type=payout_paid)
```

Sin deadlock con `ApprovePaymentAction` (conjuntos de tablas distintos).

### 8.3 Tests de concurrencia

Ambos flujos tienen 4 escenarios cubiertos con procesos PHP reales (via `Symfony\Component\Process\Process`):

| Escenario | Descripción |
|---|---|
| (a) Same key, simultaneous | Solo 1 registro creado; ambos procesos retornan ok |
| (b) Diff key, simultaneous | Solo 1 registro creado; el segundo retorna existente |
| (c) Same key, diff fingerprint | Primero ok; segundo → IdempotencyKeyMismatch |
| (d) Action vs StartGameAction | Ambos compiten por Game FOR UPDATE; no hay deadlock |

---

## 9. Auditoría y eventos

### 9.1 Eventos en `game_events` (audit log append-only)

| `type` (GameEventType) | Valor DB | Emitido por | Payload |
|---|---|---|---|
| `GameEventType::OrderRefunded` | `order_refunded` | `RefundOrderAction` (dentro de transacción) | `refund_id`, `order_id`, `payment_id`, `buyer_user_id`, `actor_user_id`, `refunded_cents`, `currency`, `refund_reason` |
| `GameEventType::PayoutPaid` | `payout_paid` | `ProcessWinnerPayoutAction` (dentro de transacción) | `payout_id`, `game_winner_id`, `game_id`, `user_id`, `actor_user_id`, `amount_cents`, `currency`, `method`, `external_reference` |

### 9.2 Eventos de dominio post-commit

| Clase | Cuándo | Campos |
|---|---|---|
| `OrderRefunded` | Después de `DB::transaction()`, en try/catch con `report($e)` | `refundId`, `orderId`, `paymentId`, `gameId`, `buyerUserId`, `actorUserId`, `refundedCents`, `currency`, `reason`, `gameEntryIds`, `gameNumberIds`, `numbers`, `processedAt` |
| `WinnerPayoutRegistered` | Después de `DB::transaction()`, en try/catch con `report($e)` | `payoutId`, `gameWinnerId`, `gameId`, `winnerUserId`, `actorUserId`, `amountCents`, `currency`, `method`, `externalReference`, `processedAt` |

### 9.3 Campos que NUNCA van en `game_events`

`idempotency_key_hash`, `request_fingerprint`, `document_disk`, `document_path`,
`document_sha256`, credenciales bancarias, tokens de gateway, binarios.

---

## 10. Storage privado y cleanup

- **Disco:** `winner_payouts` — `driver=local`, `root=storage/app/private/winner-payouts`, `serve=false`.
- **Flujo:** el archivo se almacena en disco **antes** de abrir la transacción.
- **Compensación:** si la Action lanza una excepción, el Controller elimina el archivo del disco en un `try/catch`.
- **Replay idempotente:** si la Action retorna `wasAlreadyProcessed=true`, el Controller también elimina el archivo recién subido (el documento real ya está en DB desde la primera llamada).
- **Único archivo vivo:** después de cualquier respuesta, existe exactamente 1 archivo por payout exitoso.

---

## 11. Endpoints finales

| Método | URI | Middleware | Nombre de ruta |
|---|---|---|---|
| `POST` | `/api/v1/admin/orders/{order}/refund` | `auth:sanctum, admin, idempotent` | `admin.orders.refund.store` |
| `GET`  | `/api/v1/admin/orders/{order}/refund` | `auth:sanctum, admin` | `admin.orders.refund.show` |
| `POST` | `/api/v1/admin/games/{game}/winner/payout` | `auth:sanctum, admin, idempotent` | `admin.games.winner.payout.store` |
| `GET`  | `/api/v1/admin/games/{game}/winner/payout` | `auth:sanctum, admin` | `admin.games.winner.payout.show` |

- Sin autenticación → HTTP 401.
- Player autenticado → HTTP 403 (middleware `admin`).
- POST sin `Idempotency-Key` → HTTP 400 (middleware `idempotent`).
- GET sin `Idempotency-Key` → sin restricción (GET no muta estado).

---

## 12. Contratos HTTP y privacidad

### 12.1 RefundResource — campos expuestos

```json
{
  "data": {
    "id": "uuid",
    "order_id": "uuid",
    "payment_id": "uuid",
    "game_id": "uuid",
    "amount_cents": 1000,
    "currency": "PEN",
    "reason": "...",
    "processed_by_user_id": 42,
    "processed_at": "ISO8601",
    "created_at": "ISO8601",
    "entries": { "ids": ["uuid"], "count": 1 },
    "numbers": [7],
    "game_number_ids": ["uuid"],
    "was_already_refunded": false
  }
}
```

**NO expuesto:** `idempotency_key_hash`, `request_fingerprint`, `buyer_user_id`.

### 12.2 WinnerPayoutResource — campos expuestos

```json
{
  "data": {
    "id": "uuid",
    "game_id": "uuid",
    "game_winner_id": "uuid",
    "user_id": 7,
    "amount_cents": 50000,
    "currency": "PEN",
    "method": "manual",
    "external_reference": "OP-TEST-001",
    "notes": null,
    "processed_by_user_id": 42,
    "processed_at": "ISO8601",
    "created_at": "ISO8601",
    "document": {
      "id": "uuid",
      "original_filename": "comprobante.pdf",
      "mime_type": "application/pdf",
      "size_bytes": 102400,
      "created_at": "ISO8601"
    },
    "was_already_processed": false
  }
}
```

**NO expuesto:** `idempotency_key_hash`, `request_fingerprint`,
`document.disk`, `document.path`, `document.sha256`.

---

## 13. Constraints e inmutabilidad

### 13.1 Tabla `refunds`

| Constraint | Tipo |
|---|---|
| `UNIQUE(order_id)` | 1 refund por Order |
| `UNIQUE(idempotency_key_hash)` | 1 key hash global |
| `CHECK(amount_cents > 0)` | monto positivo |
| `CHECK(currency ~ '^[A-Z]{3}$')` | código ISO 4217 |
| `CHECK(idempotency_key_hash ~ '^[0-9a-f]{64}$')` | hex SHA-256 |
| `CHECK(request_fingerprint ~ '^[0-9a-f]{64}$')` | hex SHA-256 |
| `CHECK(trim(reason) <> '')` | razón no vacía |
| `ImmutableModelException` en update/delete | modelo append-only |

### 13.2 Tabla `winner_payouts`

| Constraint | Tipo |
|---|---|
| `UNIQUE(game_winner_id)` | 1 payout por ganador |
| `UNIQUE(idempotency_key_hash)` | 1 key hash global |
| `CHECK(amount_cents > 0)` | monto positivo |
| `CHECK(currency ~ '^[A-Z]{3}$')` | código ISO 4217 |
| `CHECK(method = 'manual')` | solo método manual |
| `CHECK(trim(external_reference) <> '')` | referencia no vacía |
| `CHECK(idempotency_key_hash ~ '^[0-9a-f]{64}$')` | hex SHA-256 |
| `CHECK(request_fingerprint ~ '^[0-9a-f]{64}$')` | hex SHA-256 |
| `ImmutableModelException` en update/delete | modelo append-only |

### 13.3 Tabla `winner_payout_documents`

| Constraint | Tipo |
|---|---|
| `CHECK(size_bytes > 0)` | archivo no vacío |
| `CHECK(sha256 ~ '^[0-9a-f]{64}$')` | hex SHA-256 |
| `CHECK(trim(disk) <> '')`, `CHECK(trim(path) <> '')` | campos no vacíos |
| `CHECK(trim(original_filename) <> '')` | nombre no vacío |
| `CHECK(trim(mime_type) <> '')` | MIME no vacío |
| `ImmutableModelException` en update/delete | modelo append-only |

---

## 14. Arquitectura

### 14.1 Invariantes cross-cutting

| Invariante | Verificado por |
|---|---|
| Controllers write no abren `DB::transaction` — la Action es dueña del límite transaccional | `Phase64ClosureArchitectureTest` |
| Controllers read no abren `DB::transaction` | `Phase64ClosureArchitectureTest` |
| Admin Resources son transformadores puros de DTO — sin queries Eloquent en `toArray()` | `Phase64ClosureArchitectureTest` |
| `RefundResource` no expone `idempotency_key_hash` ni `request_fingerprint` | `Phase64ClosureArchitectureTest` |
| `WinnerPayoutResource` no expone `disk`, `path`, `sha256`, ni hashes de idempotencia | `Phase64ClosureArchitectureTest` |
| Sin `Storage::fake()` en código productivo (`app/`) | `Phase64ClosureArchitectureTest` |
| `RefundOrderAction::executeWithinTransaction` verifica `DB::transactionLevel()` | `Phase64ClosureArchitectureTest` |
| Ambas Actions financieras son dueñas de `DB::transaction` | `Phase64ClosureArchitectureTest` |
| `GameNumberStatus::Sold → Available` solo en `RefundOrderAction` | `Phase62RefundArchitectureTest` |
| `RefundOrderAction` solo es referenciada desde el controller admin | `Phase62RefundArchitectureTest` |
| `PurchaseAllocation` no es modificada en el flujo de refund | `Phase62RefundArchitectureTest` |
| Sin `IdempotentCommandExecutor` en flujos financieros | `Phase62RefundArchitectureTest`, `Phase63PayoutArchitectureTest` |
| Sin `UniqueConstraintViolationException` en flujos financieros | `Phase62RefundArchitectureTest`, `Phase63PayoutArchitectureTest` |
| Módulo RNB no importa clases de Commerce | `Phase63PayoutArchitectureTest` |

### 14.2 Dependencias de módulo

```
Commerce ──imports──► RepeatNumberBingo  (dirección permitida)
RepeatNumberBingo ──NO importa──► Commerce  (invariante arquitectural)

PurchaseAllocation: vive en Commerce, referencia game_entry_id por UUID FK.
WinnerPayout: vive en Commerce, referencia game_winners.id → dirección permitida.
```

---

## 15. Tests y verificación final

### 15.1 Suites de Fase 6

| Suite | Archivo | Tests |
|---|---|---|
| Feature | `tests/Feature/Commerce/RefundOrderTest.php` | 25 |
| Feature | `tests/Feature/Commerce/WinnerPayoutTest.php` | 30 |
| Integration / Constraints | `tests/Integration/Commerce/RefundConstraintsTest.php` | 10 |
| Integration / Constraints | `tests/Integration/Commerce/WinnerPayoutConstraintsTest.php` | 21 |
| Integration / Concurrency | `tests/Integration/Commerce/RefundOrderConcurrencyTest.php` | 4 |
| Integration / Concurrency | `tests/Integration/Commerce/WinnerPayoutConcurrencyTest.php` | 4 |
| Integration / TxGuard | `tests/Integration/Commerce/RefundOrderActionTransactionGuardTest.php` | — |
| Architecture | `tests/Integration/Architecture/Phase62RefundArchitectureTest.php` | 6 |
| Architecture | `tests/Integration/Architecture/Phase63PayoutArchitectureTest.php` | 8 |
| Architecture | `tests/Integration/Architecture/Phase64ClosureArchitectureTest.php` | 8 |

### 15.2 Resultado de suite completa

**1096 tests / 5107 assertions — todos verdes al cierre de Fase 6.4.**

```bash
php artisan test --compact   # → 1096 passed (5107 assertions)
vendor/bin/pint --dirty --format agent  # → passed
git diff --check             # → limpio
```

---

## 16. Límites explícitos de Fase 6

Lo siguiente **no** está implementado en Fase 6 y requiere aprobación explícita antes de iniciar:

- Gateway de pago externo (Stripe, PayU, etc.)
- Notificaciones reales al jugador (email, push) — el evento se emite, el listener no existe
- Edición o anulación de un Refund existente
- Edición o anulación de un WinnerPayout existente
- Refunds parciales o múltiples refunds por orden
- Múltiples comprobantes por payout
- Frontend para operaciones financieras
- Nuevos endpoints financieros distintos a los 4 documentados en §11

---

## 17. Pendientes fuera de Fase 6

| Tema | Descripción |
|---|---|
| Notificaciones | Listener para `OrderRefunded` que notifique al comprador |
| Listener de payout | Listener para `WinnerPayoutRegistered` que notifique al ganador |
| Refunds en Completed | Si el producto decide ampliar `ALLOWED_GAME_STATUSES` para Completed |
| Gateway externo | Integración de pasarela real para WinnerPayout automatizado |

---

## Apéndice — Auditoría inicial (Fase 6.1)

Esta sección preserva el reporte de auditoría previo a la implementación, para referencia histórica.
El estado implementado está en las secciones principales del documento.

**Estado al cierre de Fase 6.1 (antes de implementar 6.2/6.3):**

- 977 tests / 4715 assertions al cierre de 6.1.
- 49 rutas registradas; ninguna de refund ni payout existía.
- `RefundOrderAction`, `ProcessWinnerPayoutAction`, tablas `refunds` y `winner_payouts` no existían.
- `OrderStatus::Refunded`, `PaymentStatus::Refunded`, `EntryStatus::Refunded` ya existían con transiciones definidas.
- Fuente monetaria analizada: `order.total_cents` para Refund, `game.prize_cents` para Payout.
- 10 decisiones de diseño levantadas (D1–D10), todas resueltas en Fases 6.2/6.3.
- Dependencias de módulo confirmadas: `Commerce → RNB` permitido; `RNB → Commerce` prohibido.
