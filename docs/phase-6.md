# Fase 6 — Cierre financiero: Refunds y WinnerPayout

## Alcance de la Fase 6.1

Este documento es una **auditoría y contrato** del modelo financiero real de Commerce,
sin implementar código productivo. Fases 6.2+ implementarán Refunds y WinnerPayout
tras aprobación explícita.

---

## 1. Mapa financiero real

### 1.1 Entidades existentes y su rol financiero

```
Game
  prize_cents : integer   ← snapshot del premio al crear el juego
  currency    : char(3)

Order (1 por jugador por juego)
  subtotal_cents : bigint   ← suma de unit_price_cents de items
  total_cents    : bigint   ← monto a cobrar (puede incluir fees futuros)
  currency       : char(3)
  status         : OrderStatus
  paid_at        : timestampTz nullable
  cancelled_at   : timestampTz nullable
  expired_at     : timestampTz nullable

  → HasOne Payment
  → HasMany OrderItem
  → HasMany NumberReservation (eliminadas al aprobar)

OrderItem (1 por número de juego reservado)
  unit_price_cents : integer
  game_number_id   : uuid FK

Payment (1 por Order, UNIQUE)
  amount_cents     : bigint
  currency         : char(3)
  method           : PaymentMethod   (solo 'manual' hoy)
  status           : PaymentStatus
  submitted_at     : timestampTz nullable
  reviewed_by      : int nullable FK users
  reviewed_at      : timestampTz nullable
  rejection_reason : text nullable

  → HasMany PaymentDocument  (evidencias subidas por el jugador)

PurchaseAllocation (append-only, puente Commerce→RNB)
  order_item_id  : uuid FK
  game_entry_id  : uuid FK
  payment_id     : uuid FK

GameEntry (en módulo RNB, fuente de verdad de participación)
  status         : EntryStatus   {Confirmed, Cancelled, Refunded, Winner}

GameWinner (append-only, 1 por partida)
  game_id        : uuid FK
  game_entry_id  : uuid FK
  game_draw_id   : uuid FK
  game_number_id : uuid FK
  user_id        : int FK
  winning_hits   : integer
  won_at         : timestampTz
```

### 1.2 Ciclo de vida financiero completo (estado actual)

```
COMPRA:
  Order(Pending) → [reserva números] → Order(PaymentSubmitted) →
    → [admin aprueba]  → Order(Paid)      + Payment(Approved)
    → [admin rechaza]  → Order(Rejected)  + Payment(Rejected)
    → [expiración]     → Order(Expired)   + NumberReservations borradas
    → [jugador cancela]→ Order(Cancelled)

JUEGO:
  GameEntry(Confirmed) → GameEntry(Winner) [si gana]
  GameWinner INSERT (append-only)

REFUND (no implementado):
  Order(Paid) → Order(Refunded) + Payment(Refunded)
  GameEntry(Confirmed|Winner) → GameEntry(Refunded)   [según decisión de producto]

PAYOUT (no implementado):
  GameWinner existe → WinnerPayout INSERT (tabla nueva propuesta)
```

### 1.3 Qué NO existe actualmente

| Concepto | Estado |
|---|---|
| Acción `RefundOrderAction` | No existe |
| Acción `ProcessWinnerPayoutAction` | No existe |
| Tabla `winner_payouts` | No existe |
| Campo `external_reference` en `payments` | No existe |
| Campo `idempotency_key` en `payments` | No existe |
| Campo de payout en `game_winners` | No existe |

---

## 2. Fuente de verdad monetaria

### 2.1 Monto a devolver en un Refund

La fuente autoritativa es **`order.total_cents`** con **`order.currency`**.

- `order.total_cents` captura exactamente lo que se le cobró al jugador.
- `payment.amount_cents` debe ser igual a `order.total_cents` al momento de la
  aprobación. La igualdad no está forzada por constraint hoy — es una invariante
  de negocio que debe verificarse en `RefundOrderAction`.
- Si en el futuro se permiten pagos parciales o fees, `payment.amount_cents`
  podría diferir; el diseño de Refund debe declarar explícitamente cuál de los
  dos valores es autoritativo. **Por ahora: `order.total_cents`.**

### 2.2 Monto del premio (WinnerPayout)

La fuente autoritativa es **`game.prize_cents`** con **`game.currency`**.

- El snapshot fue fijado al crear el juego y no puede cambiarse una vez que el
  juego supera el estado `Draft` (el modelo bloquea cambios de configuración en
  `Running`, `Paused`, `Resolving`, `Completed`).
- `GameWinner` no almacena el monto del premio — lo toma de su relación `game`.
  El registro de `WinnerPayout` debe snapshotear `prize_cents` y `currency` al
  momento de su creación para preservar el valor histórico.

### 2.3 Invariante de consistencia monetaria

```
payment.amount_cents == order.total_cents  (debe verificarse antes de refundar)
game.prize_cents == winner_payouts.amount_cents  (snapshot en el INSERT)
order.currency == payment.currency  (constraint de negocio, no en DB hoy)
game.currency == winner_payouts.currency  (snapshot en el INSERT)
```

---

## 3. Invariantes necesarias

### 3.1 Refund

| # | Invariante | Fuente |
|---|---|---|
| R1 | Solo se puede refundar un `Order` en estado `Paid`. | `OrderStatus.allowedNextStates()` |
| R2 | `Order` y `Payment` deben transicionar atómicamente a `Refunded`. | Transacción única |
| R3 | `GameEntry` asociada debe transicionar a `Refunded` si está `Confirmed`. | DECISIÓN D4 |
| R4 | El `GameNumber` debe volver a `Available` tras el refund. | Implícito del ciclo de vida |
| R5 | Un `GameEntry` en estado `Winner` **no puede** transicionar a `Refunded`. | `EntryStatus` es terminal para `Winner` |
| R6 | El Refund debe ser idempotente — un segundo intento con los mismos parámetros devuelve el resultado ya existente sin mutar estado. | Idempotencia basada en estado |
| R7 | El monto a registrar en el Refund es `order.total_cents` — no se recalcula. | §2.1 |
| R8 | `game_events` debe registrar el evento `OrderRefunded` con `actor_user_id`, `order_id`, `payment_id`, `refund_reason`. | Auditoría append-only |
| R9 | No se puede refundar si el juego está en estado `Completed` con un ganador y ese ganador tiene el número reservado por este order. | DECISIÓN D5 |
| R10 | `PurchaseAllocation` es append-only y no se modifica ni elimina en el refund. | Trazabilidad histórica |

### 3.2 WinnerPayout

| # | Invariante | Fuente |
|---|---|---|
| P1 | Solo puede existir un `WinnerPayout` por `GameWinner`. | UNIQUE(game_winner_id) en tabla propuesta |
| P2 | `GameWinner` debe existir y estar completo antes de crear el payout. | FK + validación en action |
| P3 | `winner_payouts.amount_cents` debe snapshotear `game.prize_cents` al momento del INSERT. | §2.2 |
| P4 | `winner_payouts.currency` debe snapshotear `game.currency`. | §2.2 |
| P5 | El payout es append-only — no se modifica ni elimina. | Inmutabilidad por booted hook |
| P6 | `game_events` debe registrar `WinnerPayoutRegistered` con `actor_user_id`, `game_winner_id`, `amount_cents`. | Auditoría |
| P7 | Dos requests concurrentes para el mismo `game_winner_id` solo pueden producir un INSERT exitoso. | UNIQUE constraint + lock |

---

## 4. Concurrencia y orden de locks

### 4.1 RefundOrderAction — orden propuesto

```
1. Order FOR UPDATE                       ← raíz del Refund (identifica payment)
2. Payment FOR UPDATE                     ← transición Approved → Refunded
3. GameEntry(s) FOR UPDATE  (sorted by id) ← transición Confirmed → Refunded
4. GameNumber(s) FOR UPDATE (sorted by id) ← transición Sold → Available
```

**Justificación del orden:**

- `Order` antes de `Payment`: reproduce el orden de `ApprovePaymentAction`
  (Game → Order → Payment). En refund no necesitamos el lock de `Game` porque
  el estado del juego no cambia (un juego `Completed` puede tener orders
  refundadas). Si en el futuro la lógica del motor reacciona a refunds, debe
  añadirse `Game FOR UPDATE` como primer lock.
- `GameEntry` antes de `GameNumber`: el entry referencia el number, nunca al
  revés.
- Sin advisory lock: el Refund no afecta la identidad social ni la unicidad del
  número dentro del juego (el número deja de estar vendido, pero `game_numbers`
  ya tiene su propia PK única).

**Riesgo de deadlock con ApprovePayment:**

`ApprovePaymentAction` toma `Game → Order → Payment → OrderItems →
NumberReservations → GameNumbers`.  
`RefundOrderAction` tomaría `Order → Payment → GameEntries → GameNumbers`.

Si ambos corren concurrentes sobre el mismo `order_id`, el orden `Order` primero
en ambos garantiza que quien obtenga el `Order FOR UPDATE` primero bloquea al
otro. No hay inversión de orden. **Deadlock no es posible entre Approve y Refund
para el mismo order.**

Sí podría haber deadlock si `RefundOrderAction` tomara múltiples orders del mismo
juego sin orden consistente. El diseño propuesto refunda **de a un Order por
transacción**, eliminando ese riesgo.

### 4.2 ProcessWinnerPayoutAction — orden propuesto

```
1. GameWinner FOR UPDATE   ← unicidad: previene dos payouts simultáneos
2. (INSERT winner_payouts con UNIQUE constraint como defensa en profundidad)
```

El payout no afecta `Order`, `Payment` ni `GameEntry`. No se necesita lock
cruzado con Commerce. El `UNIQUE(game_winner_id)` en `winner_payouts` es la
defensa final; el `FOR UPDATE` sobre `GameWinner` evita trabajo duplicado.

---

## 5. Auditoría administrativa

### 5.1 Eventos requeridos en `game_events`

| Tipo de evento | Cuándo se emite | Campos en `payload` |
|---|---|---|
| `order_refunded` | Al completar `RefundOrderAction` | `order_id`, `payment_id`, `buyer_user_id`, `actor_user_id`, `refund_reason`, `refunded_cents` |
| `winner_payout_registered` | Al completar `ProcessWinnerPayoutAction` | `game_winner_id`, `user_id`, `amount_cents`, `currency`, `actor_user_id`, `method` |

`game_events` es append-only. Los eventos se emiten **dentro de la transacción**,
igual que en `ApprovePaymentAction`.

### 5.2 Qué NO debe guardarse en `game_events`

- Credenciales bancarias o datos de cuenta del ganador.
- Comprobantes binarios (van en storage privado, referenciados por path).
- Tokens de gateway de pago externos.
- Números completos de tarjeta.

### 5.3 Auditoría del actor

Toda operación de Refund y Payout requiere `actor_user_id` (admin autenticado).
El campo `reviewed_by` de `Payment` ya registra quién aprobó el pago; para el
refund se necesitará un campo `refunded_by` o se registra únicamente en
`game_events`. Recomendación: registrar en `game_events` (no añadir columna a
`Payment`, que crece). **DECISIÓN D8.**

---

## 6. Contratos propuestos de datos

### 6.1 Tabla `refunds` (propuesta)

```sql
CREATE TABLE refunds (
    id          UUID PRIMARY KEY,
    order_id    UUID NOT NULL REFERENCES orders(id) RESTRICT,
    payment_id  UUID NOT NULL REFERENCES payments(id) RESTRICT,
    amount_cents BIGINT NOT NULL CHECK (amount_cents > 0),
    currency     CHAR(3) NOT NULL CHECK (currency ~ '^[A-Z]{3}$'),
    reason       TEXT NOT NULL,
    refunded_by  INTEGER NOT NULL REFERENCES users(id) RESTRICT,
    refunded_at  TIMESTAMPTZ NOT NULL,
    created_at   TIMESTAMPTZ NOT NULL,
    -- No updated_at: append-only.

    CONSTRAINT refunds_order_unique UNIQUE (order_id)
    -- Un solo refund por Order.
);
```

**Notas:**

- `amount_cents` viene de `order.total_cents` en el momento del refund.
- `currency` viene de `order.currency`.
- `UNIQUE(order_id)` garantiza idempotencia estructural: si la transacción se
  reintenta, el segundo INSERT falla con violación de unicidad y el código lo
  trata como "ya refundado".
- No se almacena `external_reference` de gateway: el modelo actual es manual.
  Si se integra un gateway, se añade esa columna con una migración futura.

### 6.2 Tabla `winner_payouts` (propuesta)

```sql
CREATE TABLE winner_payouts (
    id              UUID PRIMARY KEY,
    game_winner_id  UUID NOT NULL REFERENCES game_winners(id) RESTRICT,
    user_id         INTEGER NOT NULL REFERENCES users(id) RESTRICT,
    amount_cents    BIGINT NOT NULL CHECK (amount_cents > 0),
    currency        CHAR(3) NOT NULL CHECK (currency ~ '^[A-Z]{3}$'),
    method          VARCHAR(24) NOT NULL DEFAULT 'manual',
    notes           TEXT,
    paid_by         INTEGER NOT NULL REFERENCES users(id) RESTRICT,
    paid_at         TIMESTAMPTZ NOT NULL,
    created_at      TIMESTAMPTZ NOT NULL,

    CONSTRAINT winner_payouts_winner_unique UNIQUE (game_winner_id)
    -- Un solo payout por ganador.
);

-- Futuros: comprobantes de pago en storage privado, referenciados por path.
```

**Notas:**

- `amount_cents` y `currency` son snapshots de `game.prize_cents` y
  `game.currency` al momento del INSERT — no FK al juego para que el valor
  quede fijo aunque el juego cambie settings en el futuro.
- `user_id` es snapshot de `game_winner.user_id` (desnormalización deliberada
  para acceso directo sin JOIN a `game_winners`).
- `UNIQUE(game_winner_id)` garantiza idempotencia.

### 6.3 DTOs propuestos

```php
// RefundOrderData
readonly class RefundOrderData {
    public function __construct(
        public string $orderId,
        public int    $actorUserId,
        public string $reason,
    ) {}
}

// RefundOrderResult
readonly class RefundOrderResult {
    public function __construct(
        public string $refundId,
        public string $orderId,
        public string $paymentId,
        public int    $refundedCents,
        public string $currency,
        public bool   $wasAlreadyRefunded,
    ) {}
}

// ProcessWinnerPayoutData
readonly class ProcessWinnerPayoutData {
    public function __construct(
        public string  $gameWinnerId,
        public int     $actorUserId,
        public string  $method,     // 'manual' por ahora
        public ?string $notes,
    ) {}
}

// ProcessWinnerPayoutResult
readonly class ProcessWinnerPayoutResult {
    public function __construct(
        public string $payoutId,
        public string $gameWinnerId,
        public int    $userId,
        public int    $amountCents,
        public string $currency,
        public bool   $wasAlreadyProcessed,
    ) {}
}
```

---

## 7. Endpoints propuestos (sin implementar)

### 7.1 Rutas de Commerce Admin (nuevas)

| Método | URI | Descripción |
|---|---|---|
| `POST` | `/api/v1/admin/orders/{order}/refund` | Refundar un order pagado |
| `POST` | `/api/v1/admin/winners/{winner}/payout` | Registrar pago al ganador |
| `GET` | `/api/v1/admin/winners/{winner}/payout` | Consultar estado del payout |

### 7.2 Convenciones

- Autenticación: `sanctum` guard, middleware `admin`.
- Idempotency-Key header: **recomendado** para `POST /refund` y `POST /payout`,
  pero la idempotencia principal se basa en estado (UNIQUE constraint), no en
  la tabla `idempotency_keys` de Commerce (que es para el jugador).
- Respuesta de refund ya ejecutado: HTTP 200 con `was_already_refunded: true`,
  no HTTP 409 — coherente con el patrón de `ApprovePaymentAction`.

### 7.3 Rutas actuales de Commerce (auditoría — Fase 6.1)

Total de rutas registradas: **49** (`php artisan route:list --path=api/v1 --except-vendor`).

| Grupo | Cantidad |
|---|---|
| Auth (local + social) | 13 |
| Admin (games + motor) | 20 |
| Admin (commerce) | 6 |
| Player (me/orders, reservations, entries) | 7 |
| Public (games, draws, numbers) | 5 |
| Legacy (`/api/v1/user`) | 1 |
| Reservas | 1 |

No existe ninguna ruta de refund ni payout en el sistema actual.

---

## 8. Decisiones obligatorias

| # | Decisión | Estado |
|---|---|---|
| D1 | **¿Se puede refundar una Order cuyo GameEntry está en estado `Winner`?** — El jugador ganó con ese número; la transición `Winner → Refunded` NO está permitida en `EntryStatus`. El refund de la Order implicaría que el ganador devuelve su participación. Recomendación: **prohibir el refund si existe un `GameWinner` que referencie ese `game_entry_id`**. | DECISIÓN DE PRODUCTO REQUERIDA |
| D2 | **¿El Refund libera el GameNumber (lo devuelve a `Available`)?** — Si el juego sigue en `SalesOpen` o `SalesClosed`, devolver el número a `Available` permite que otro jugador lo compre. Si el juego está en `Running` o posterior, el número no puede revenderse. Recomendación: **solo liberar si el juego está en `SalesOpen` o `SalesClosed`; en otros estados, dejar el número en `Sold` y solo transicionar Entry a `Refunded`.** | DECISIÓN DE PRODUCTO REQUERIDA |
| D3 | **¿Se puede refundar un `Order` si el juego ya está `Completed`?** — Podría ser válido para ordenes cuyo ganador NO fue ese jugador. Ver D1. Recomendación: **permitir, salvo que el entry sea el `GameWinner`**. | DECISIÓN DE PRODUCTO REQUERIDA |
| D4 | **¿El refund de una Order transiciona la GameEntry a `Refunded` siempre, o solo si está `Confirmed`?** — `EntryStatus.allowedNextStates()` ya define que `Confirmed → Refunded` es válido. Recomendación: **siempre transicionar si está `Confirmed`; lanzar excepción si está en cualquier otro estado terminal**. | RECOMENDACIÓN TÉCNICA — confirmar |
| D5 | **¿Debe guardarse un campo `refunded_by` en la tabla `payments`, o basta con `game_events`?** — Añadir columna a `payments` crece el modelo. La alternativa es solo registrar en `game_events`. Recomendación: **solo `game_events` — no añadir columna a `payments`**. | RECOMENDACIÓN TÉCNICA — confirmar |
| D6 | **¿El WinnerPayout incluye comprobante/evidencia de transferencia?** — Si sí, se necesita una tabla `winner_payout_documents` análoga a `payment_documents`. Recomendación: **sí, futura, análoga a `PaymentDocument`; no en Fase 6.2 inicial**. | DECISIÓN DE PRODUCTO REQUERIDA |
| D7 | **¿El método de pago del WinnerPayout es siempre `manual` en esta fase?** — El enum `PaymentMethod` actualmente solo tiene `Manual`. Recomendación: **sí, solo `manual` en Fase 6.2**. | RECOMENDACIÓN TÉCNICA — confirmar |
| D8 | **¿Se necesita una tabla separada `refunds` o se añaden campos a `payments`?** — Tabla separada permite múltiples refunds futuros (parciales) y es más limpia. Recomendación: **tabla `refunds` separada con `UNIQUE(order_id)` para la política actual de refund total único**. | RECOMENDACIÓN TÉCNICA — confirmar |
| D9 | **¿Qué pasa con la tabla `idempotency_keys` para Refund?** — La tabla existe para el flujo del jugador (reserva de números). Los refunds son operaciones de admin; la idempotencia se maneja por estado (`UNIQUE(order_id)` en `refunds`). Recomendación: **no usar `idempotency_keys` para refunds; la UNIQUE constraint es suficiente**. | RECOMENDACIÓN TÉCNICA — confirmar |
| D10 | **¿El Refund notifica al jugador?** — Si se usan Notifications de Laravel (email/push), ¿en qué momento y desde dónde? Recomendación: **notificación vía evento `OrderRefunded` con listener post-commit; fuera del scope de 6.2 inicial, pero el evento debe emitirse para habilitarla**. | DECISIÓN DE PRODUCTO REQUERIDA |

---

## 9. Reporte final de auditoría

| Punto | Hallazgo |
|---|---|
| **Suite** | 977 tests / 4715 assertions — todos verdes al cierre de 6.1. |
| **Rutas** | 49 rutas registradas; ninguna de refund ni payout existe. |
| **Estados Refunded** | Ya existen en `OrderStatus`, `PaymentStatus` y `EntryStatus` con transiciones definidas. Ninguna Action los usa hoy. |
| **Fuente monetaria** | `order.total_cents` para Refund; `game.prize_cents` (snapshot) para Payout. `payment.amount_cents` debe coincidir con `order.total_cents` — invariante de negocio, no en DB. |
| **Acciones faltantes** | `RefundOrderAction`, `ProcessWinnerPayoutAction` — ninguna existe. |
| **Tablas faltantes** | `refunds`, `winner_payouts` — ninguna existe. `GameWinner` no tiene campos de payout. |
| **Orden de locks** | Refund: Order → Payment → GameEntries → GameNumbers. Payout: GameWinner (sólo). Sin deadlock con ApprovePayment. |
| **Auditoría** | `game_events` cubre ambas operaciones; eventos `order_refunded` y `winner_payout_registered` deben añadirse al enum `GameEventType`. |
| **Decisiones pendientes** | 5 de 10 requieren decisión de producto (D1, D2, D3, D6, D10). Las 5 restantes son recomendaciones técnicas listas para confirmar. |

---

## Apéndice — Dependencias de módulo confirmadas

```
Commerce ──imports──► RepeatNumberBingo (direction permitida)
RepeatNumberBingo  ──NO importa──► Commerce (invariante de arquitectura)

PurchaseAllocation vive en Commerce y referencia game_entry_id (uuid FK).
```

La tabla propuesta `winner_payouts` vive en Commerce (o en un módulo
`Financial` futuro), referenciando `game_winners.id` desde Commerce hacia RNB —
dirección de dependencia permitida.
