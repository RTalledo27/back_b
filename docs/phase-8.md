# Fase 8 — Outbox: durabilidad de entrega at-least-once

## 1. Resumen final de Fase 8

Fase 8 introduce el patrón **Transactional Outbox** en el backend de rifas para garantizar que los efectos secundarios del dominio (notificaciones, integraciones externas) **no se pierdan** aunque el proceso PHP muera entre el `COMMIT` de la transacción de dominio y el envío efectivo.

La garantía de entrega es **at-least-once**: el evento se registra atómicamente con la mutación de dominio, y un worker de polling lo entrega al menos una vez al consumidor. Los consumidores deben ser idempotentes para tolerarla.

La Fase 8 se divide en tres subpartes:

| Subparte | Contenido |
|----------|-----------|
| 8.1 | Contrato Outbox: tabla, modelo, recorder, processor, dispatcher, job, scheduler |
| 8.2 | Primer evento integrado: `payment_approved` |
| 8.3 | Cuatro eventos críticos: `payment_rejected`, `order_refunded`, `winner_payout_registered`, `game_winner_declared` |
| 8.4 | Cierre: auditoría, guards de arquitectura, documentación final |

---

## 2. Problema resuelto

Antes de Fase 8, todos los eventos de dominio se despachaban fuera de la transacción:

```php
// Patrón pre-Fase-8: ventana de pérdida entre COMMIT y dispatch
DB::transaction(fn () => $action->execute($data));   // ← COMMIT
try {
    event(new PaymentApproved(...));                  // ← puede perderse
} catch (Throwable $e) {
    report($e);
}
```

Si el proceso caía entre `COMMIT` y `event()` (deploy, OOM, timeout, crash), el evento se perdía de forma permanente sin opción de reintento.

El patrón Outbox resuelve esto insertando la intención de entrega **dentro de la misma transacción** que la mutación de dominio, en la tabla `outbox_events`. Un worker independiente la procesa con garantía de reintento.

---

## 3. Tabla `outbox_events`

```sql
CREATE TABLE outbox_events (
    id                UUID         NOT NULL,          -- app-generated uuid7
    event_type        VARCHAR(120) NOT NULL,          -- dominio del evento
    aggregate_type    VARCHAR(80)  NOT NULL,          -- tipo del agregado
    aggregate_id      UUID         NULL,              -- ID del agregado
    deduplication_key VARCHAR(255) NULL,              -- clave de deduplicación
    payload           JSONB        NOT NULL,          -- contenido del evento
    available_at      TIMESTAMPTZ  NOT NULL,          -- desde cuándo está disponible
    processed_at      TIMESTAMPTZ  NULL,              -- cuando fue procesado
    failed_at         TIMESTAMPTZ  NULL,              -- cuando falló permanentemente
    attempts          INT          NOT NULL DEFAULT 0,
    last_error        TEXT         NULL,
    locked_at         TIMESTAMPTZ  NULL,              -- worker lock
    locked_by         VARCHAR(255) NULL,
    next_attempt_at   TIMESTAMPTZ  NULL,              -- retry backoff
    max_attempts      INT          NOT NULL DEFAULT 5,
    created_at        TIMESTAMPTZ  NOT NULL,

    CONSTRAINT outbox_events_pkey PRIMARY KEY (id),
    CONSTRAINT chk_event_type_not_empty   CHECK (trim(event_type) <> ''),
    CONSTRAINT chk_aggregate_type_not_empty CHECK (trim(aggregate_type) <> ''),
    CONSTRAINT chk_payload_is_object      CHECK (jsonb_typeof(payload) = 'object'),
    CONSTRAINT chk_attempts_non_negative  CHECK (attempts >= 0),
    CONSTRAINT chk_max_attempts_positive  CHECK (max_attempts > 0),
    CONSTRAINT chk_attempts_le_max        CHECK (attempts <= max_attempts),
    CONSTRAINT chk_not_both_processed_and_failed
        CHECK (NOT (processed_at IS NOT NULL AND failed_at IS NOT NULL))
)
```

**Índices:**

```sql
-- Worker query: pending rows por disponibilidad
CREATE INDEX outbox_events_pending_idx
    ON outbox_events (available_at, id)
    WHERE processed_at IS NULL AND failed_at IS NULL;

-- Deduplicación: clave única para filas no procesadas
CREATE UNIQUE INDEX outbox_events_dedup_unprocessed_idx
    ON outbox_events (deduplication_key)
    WHERE deduplication_key IS NOT NULL AND processed_at IS NULL;

-- Auditoría por agregado
CREATE INDEX outbox_events_aggregate_idx
    ON outbox_events (aggregate_type, aggregate_id)
    WHERE aggregate_id IS NOT NULL;
```

Los IDs se generan con `Str::uuid7()` en la aplicación. La migración **no usa** `gen_random_uuid()`.

---

## 4. Recorder (`RecordOutboxEventAction`)

```
app/Modules/Shared/Application/Actions/RecordOutboxEventAction.php
```

Responsabilidades:
- Validar que hay una transacción activa (`DB::transactionLevel() === 0` → `LogicException`).
- Insertar la fila en `outbox_events` con `INSERT ... ON CONFLICT DO NOTHING`.
- Retornar `OutboxRecordResult { inserted: bool, outboxEventId: ?string }`.

**Invariante crítica**: usa `ON CONFLICT DO NOTHING` sobre el índice parcial de deduplicación. Nunca usa `catch (UniqueConstraintViolationException)` como flujo de control, porque en PostgreSQL capturar esa excepción aborta la transacción activa.

```php
DB::affectingStatement(
    'INSERT INTO outbox_events (...) VALUES (...) ON CONFLICT (...) DO NOTHING',
    [...],
);
```

---

## 5. Processor, Job y Scheduler

### `OutboxEventProcessor`

```
app/Modules/Shared/Infrastructure/Outbox/OutboxEventProcessor.php
```

**Algoritmo en dos fases:**

**Fase 1 — Claim** (dentro de una transacción):
```sql
SELECT id FROM outbox_events
WHERE processed_at IS NULL AND failed_at IS NULL
  AND available_at <= NOW()
  AND (next_attempt_at IS NULL OR next_attempt_at <= NOW())
  AND (locked_at IS NULL OR locked_at < NOW() - INTERVAL '5 minutes')
ORDER BY available_at ASC, id ASC
LIMIT :batch
FOR UPDATE SKIP LOCKED
```
Luego `UPDATE locked_at, locked_by`. El `COMMIT` del claim persiste el lock antes de procesar.

**Fase 2 — Process** (fuera de la transacción de claim):

| Resultado | Acción |
|-----------|--------|
| Éxito | `processed_at = NOW()`, `locked_at = NULL`, `locked_by = NULL` |
| Reintentable (`attempts < max_attempts`) | `attempts++`, `next_attempt_at = NOW() + backoff`, `locked_at = NULL` |
| Final (`attempts >= max_attempts`) | `failed_at = NOW()`, `locked_at = NULL` |

**Backoff schedule** (segundos por intento): `[1 → 30, 2 → 120, 3 → 600, 4 → 3600]`, luego 3600.

**Stale lock recovery**: locks con `locked_at < NOW() - INTERVAL '5 minutes'` se reclaman automáticamente (crash recovery).

### `ProcessOutboxEventsJob`

```
app/Modules/Shared/Application/Jobs/ProcessOutboxEventsJob.php
```

- `implements ShouldQueue`
- `$tries = 1` — el job en sí no usa el retry de Laravel; el retry es responsabilidad de la tabla outbox
- `$timeout = 55` — deja margen para el scheduler de 60 s

### Scheduler

```php
// routes/console.php
Schedule::job(new ProcessOutboxEventsJob)
    ->everyMinute()
    ->withoutOverlapping(2);
```

`withoutOverlapping(2)`: mutex de 2 minutos a nivel scheduler. `FOR UPDATE SKIP LOCKED` en el procesador garantiza que múltiples workers concurrentes no procesen el mismo evento.

---

## 6. Dispatcher (`OutboxEventDispatcher`)

```
app/Modules/Shared/Infrastructure/Outbox/OutboxEventDispatcher.php
```

Enruta el evento a su handler según `event_type`:

```php
match ($event->event_type) {
    'payment_approved'         => $this->handlePaymentApproved($event),
    'payment_rejected'         => $this->handlePaymentRejected($event),
    'order_refunded'           => $this->handleOrderRefunded($event),
    'winner_payout_registered' => $this->handleWinnerPayoutRegistered($event),
    'game_winner_declared'     => $this->handleGameWinnerDeclared($event),
    default => throw new RuntimeException(
        "OutboxEventDispatcher: unknown event_type '{$event->event_type}'."
    ),
};
```

Los 5 handlers son **no-op log** en Fase 8. El `RuntimeException` en el `default` hace que el processor marque `failed_at` en lugar de descartar silenciosamente.

---

## 7. Eventos integrados y payloads finales

Todos los payloads tienen `schema_version: 1` y contienen **solo IDs y metadatos mínimos** (ver § 10 para política de privacidad).

### `payment_approved`

- **Action**: `ApprovePaymentAction`
- **deduplication_key**: `payment_approved:{payment_id}`
- **aggregate**: `payment`

```json
{
  "schema_version": 1,
  "payment_id": "uuid",
  "order_id": "uuid",
  "game_id": "uuid",
  "buyer_user_id": 42,
  "occurred_at": "2026-06-30T21:00:00+00:00"
}
```

### `payment_rejected`

- **Action**: `RejectPaymentAction`
- **deduplication_key**: `payment_rejected:{payment_id}`
- **aggregate**: `payment`

```json
{
  "schema_version": 1,
  "payment_id": "uuid",
  "order_id": "uuid",
  "game_id": "uuid",
  "buyer_user_id": 42,
  "occurred_at": "2026-06-30T21:00:00+00:00"
}
```

### `order_refunded`

- **Action**: `RefundOrderAction`
- **deduplication_key**: `order_refunded:{order_id}`
- **aggregate**: `order`

```json
{
  "schema_version": 1,
  "refund_id": "uuid",
  "order_id": "uuid",
  "payment_id": "uuid",
  "game_id": "uuid",
  "buyer_user_id": 42,
  "occurred_at": "2026-06-30T21:00:00+00:00"
}
```

### `winner_payout_registered`

- **Action**: `ProcessWinnerPayoutAction`
- **deduplication_key**: `winner_payout_registered:{game_winner_id}`
- **aggregate**: `game_winner`

```json
{
  "schema_version": 1,
  "winner_payout_id": "uuid",
  "game_winner_id": "uuid",
  "game_id": "uuid",
  "winner_user_id": 42,
  "occurred_at": "2026-06-30T21:00:00+00:00"
}
```

### `game_winner_declared`

- **Action**: `DrawGameNumberAction` → método privado `resolveWinner()`
- **deduplication_key**: `game_winner_declared:{game_id}`
- **aggregate**: `game`

```json
{
  "schema_version": 1,
  "game_winner_id": "uuid",
  "game_id": "uuid",
  "game_draw_id": "uuid",
  "game_number_id": "uuid",
  "winner_user_id": 42,
  "occurred_at": "2026-06-30T21:00:00+00:00"
}
```

---

## 8. Deduplication keys

| Evento | deduplication_key |
|--------|-------------------|
| `payment_approved` | `payment_approved:{payment_id}` |
| `payment_rejected` | `payment_rejected:{payment_id}` |
| `order_refunded` | `order_refunded:{order_id}` |
| `winner_payout_registered` | `winner_payout_registered:{game_winner_id}` |
| `game_winner_declared` | `game_winner_declared:{game_id}` |

La clave es única en el índice parcial `WHERE deduplication_key IS NOT NULL AND processed_at IS NULL`. Una vez procesado el evento, un nuevo evento del mismo tipo puede insertarse con la misma clave (replay o reintento de la operación de dominio).

---

## 9. At-least-once y consumidores idempotentes

### At-least-once

El worker **puede entregar el mismo evento más de una vez** en los siguientes escenarios:

1. El worker ejecuta el handler, el proceso cae antes de marcar `processed_at`.
2. Un lock stale de 5 minutos libera el evento a un segundo worker que no sabe que el primero terminó.
3. En despliegues multi-worker, `FOR UPDATE SKIP LOCKED` previene duplicados dentro de un batch, pero no entre batches consecutivos si el primero falló a medias.

### Qué significa para los consumidores (Fase 9)

Cada handler de `OutboxEventDispatcher` que se implemente en Fase 9 **debe ser idempotente**:

- Verificar si el efecto externo ya se realizó antes de ejecutarlo (ej.: ¿ya se envió el email para este `payment_id`?).
- Usar el `outbox_event_id` como clave de idempotencia ante el proveedor externo (ej.: cabecera `Idempotency-Key` hacia una API de email).
- No asumir que `processed_at IS NULL` implica que el efecto no fue realizado.

### Idempotencia de dominio

Cada action que inserta un evento outbox ya implementa idempotencia a nivel de dominio:
- Rama de replay detectada → retorna sin insertar outbox.
- `ON CONFLICT DO NOTHING` sobre el índice parcial → segunda inserción silenciada.

---

## 10. Privacidad y seguridad

Los payloads de outbox contienen **únicamente IDs numéricos o UUIDs**. Nunca incluyen:

| Campo prohibido | Razón |
|-----------------|-------|
| `email`, `name`, `phone` | PII directo |
| `reason` | Motivo de rechazo/acción de revisor |
| `reviewer_user_id` | Identidad del revisor |
| `path`, `disk`, `sha256`, `original_filename` | Metadatos de documento de pago |
| `external_reference` | Referencia bancaria sensible |
| `idempotency_key_hash`, `request_fingerprint` | Claves de control interno |
| `token`, `bank_account`, `card`, `account_number` | Datos financieros sensibles |
| `password`, `signature` | Credenciales |

Los guards de arquitectura (`Phase82OutboxArchitectureTest`, `Phase83OutboxArchitectureTest`) verifican en cada CI que ningún payload incluya estos campos.

El dispatcher (`OutboxEventDispatcher`) no importa `Illuminate\Http`, `Mail`, `Notification`, `Sms`, `Http::`, ni ningún cliente externo.

---

## 11. Eventos explícitamente NO integrados en Fase 8

Los siguientes eventos existen en el dominio pero **no tienen outbox** en Fase 8:

| Evento | Razón |
|--------|-------|
| `game_completed` | Cubierto indirectamente por `game_winner_declared` |
| `game_cancelled` | Fuera del alcance de Fase 8 |
| `order_reservations_expired` | Fuera del alcance de Fase 8 |
| `order_cancelled_by_user` | Fuera del alcance de Fase 8 |
| `game_started` | Fuera del alcance de Fase 8 |
| `payment_evidence_submitted` | Fuera del alcance de Fase 8 |
| `game_number_drawn` | Alta frecuencia; consideración de volumen para Fase 9 |
| `game_paused`, `game_resumed` | Fuera del alcance de Fase 8 |

Los dispatches legacy de estos eventos (Fases 1–7) permanecen sin modificar como best-effort.

---

## 12. Tests y verificación

### Archivos de tests

| Archivo | Tipo | Tests | Cobertura |
|---------|------|-------|-----------|
| `tests/Integration/Shared/OutboxTableConstraintsTest.php` | Integration | 9 | Constraints DB, índice dedup |
| `tests/Integration/Shared/RecordOutboxEventActionTest.php` | Integration | — | Recorder, transacción, dedup |
| `tests/Integration/Shared/OutboxEventProcessorTest.php` | Integration | — | Claim, process, stale lock, backoff |
| `tests/Integration/Shared/OutboxDispatcherPhase83Test.php` | Integration | 8 | Dispatcher, 5 tipos, RuntimeException |
| `tests/Integration/Commerce/OutboxPaymentApprovedIntegrationTest.php` | Integration | 6 | payment_approved end-to-end |
| `tests/Integration/Commerce/OutboxPaymentRejectedIntegrationTest.php` | Integration | 6 | payment_rejected end-to-end |
| `tests/Integration/Commerce/OutboxOrderRefundedIntegrationTest.php` | Integration | 6 | order_refunded end-to-end |
| `tests/Integration/Commerce/OutboxWinnerPayoutIntegrationTest.php` | Integration | 5 | winner_payout_registered end-to-end |
| `tests/Integration/Game/OutboxGameWinnerDeclaredIntegrationTest.php` | Integration | 6 | game_winner_declared end-to-end |
| `tests/Unit/Architecture/Phase82OutboxArchitectureTest.php` | Unit | 10 | Guards infraestructura 8.2 |
| `tests/Unit/Architecture/Phase83OutboxArchitectureTest.php` | Unit | 11 | Guards payloads 8.3 |
| `tests/Unit/Architecture/Phase84OutboxFinalAuditTest.php` | Unit | 16 | Auditoría final 8.4 |

### Invariantes verificados por guards de arquitectura

1. `RecordOutboxEventAction` requiere transacción activa.
2. `ON CONFLICT DO NOTHING` — no `catch UniqueConstraintViolationException`.
3. `OutboxEventDispatcher` no importa capa HTTP.
4. Payloads sin campos sensibles (5 actions auditadas).
5. `FOR UPDATE SKIP LOCKED` en el claim del processor.
6. `locked_at = NULL` en success y failure.
7. `ProcessOutboxEventsJob` implementa `ShouldQueue`, `$tries = 1`.
8. Scheduler: `everyMinute()` + `withoutOverlapping(2)`.
9. Migración sin `gen_random_uuid()`, con JSONB check, pending index, dedup index.
10. Sin endpoints HTTP para outbox.
11. Dispatcher tiene exactamente 5 handlers.
12. Tipos prohibidos no están en dispatcher ni en llamadas a outbox.
13. `RecordOutboxEventAction` no se llama desde Controllers.

### Resultado de la suite final (Fase 8.4)

```
≥ 1284 passed / 0 failures
```

---

## 13. Límites explícitos de Fase 8

No implementado en Fase 8 (reservado para Fase 9):

- Notificaciones reales: email, WhatsApp, SMS, push
- Integración con proveedores externos (gateway, CRM)
- Webhooks externos
- Panel admin de outbox o dashboard de monitoreo
- Métricas avanzadas (latencia de entrega, tasa de fallo)
- Cleanup histórico de eventos procesados
- Múltiples queues o priorización de eventos
- `onOneServer()` para deployments multi-server (requiere Redis compartido)

---

## 14. Pendientes para Fase 9

1. **Implementar handlers reales** en `OutboxEventDispatcher` para cada evento:
   - `handlePaymentApproved()` → enviar email/WhatsApp de confirmación al comprador.
   - `handlePaymentRejected()` → notificar al comprador del rechazo.
   - `handleOrderRefunded()` → notificar al comprador del reembolso.
   - `handleWinnerPayoutRegistered()` → notificar al ganador del pago registrado.
   - `handleGameWinnerDeclared()` → notificar a todos los participantes del resultado.

2. **Idempotencia en handlers**: usar `outbox_event_id` como `Idempotency-Key` ante proveedores externos.

3. **Alertas de `failed_at`**: monitorear eventos con `failed_at IS NOT NULL` y alertar operaciones.

4. **Cleanup histórico**: política de retención para `processed_at IS NOT NULL` (ej.: borrar tras 30 días).

5. **`onOneServer()`**: agregar al scheduler cuando se configure Redis compartido en producción.

6. **Integrar eventos adicionales** si se requiere: `game_started`, `order_cancelled_by_user`, etc.

---

## 15. Nota de infraestructura (Docker / GD / Redis)

### Docker Compose

Los tres servicios se levantan via `docker compose up -d`:

| Servicio | Container | Puerto externo |
|----------|-----------|----------------|
| `app` | `rifas_app` | `8000:8000` |
| `postgres` | `rifas_postgres` | `55432:5432` |
| `redis` | `rifas_redis` | `6379:6379` |

La red `backend_rifas_app_default` proporciona resolución DNS interna:
- `app` alcanza `postgres` en `postgres:5432`.
- `app` alcanza `redis` en `redis:6379`.

### GD

La extensión GD está instalada en la imagen (`docker/php/Dockerfile`):

```dockerfile
RUN docker-php-ext-configure gd --with-freetype --with-jpeg && \
    docker-php-ext-install gd
```

Requerida para `UploadedFile::fake()->image()` en tests de Feature.

### phpredis

El `.env` tiene `REDIS_CLIENT=phpredis`, pero la extensión `phpredis` **no está instalada** en la imagen actual. Esto no bloquea los tests porque `phpunit.xml` override: `CACHE_STORE=array`, `QUEUE_CONNECTION=sync`.

**Pendiente de infraestructura**: si Fase 9 necesita Redis real en producción o tests de integración, agregar al Dockerfile:

```dockerfile
RUN apk add --no-cache $PHPIZE_DEPS \
    && pecl install redis \
    && docker-php-ext-enable redis
```

Y crear commit separado `chore: install phpredis extension`.

### Recuperación del container

Si el container `rifas_app` cae (OOM u otro motivo), recuperarlo con:

```bash
docker compose down --remove-orphans
docker compose up -d
```

No usar `docker run` manual — el container debe ser siempre gestionado por Compose para tener el puerto `8000` mapeado y el alias `postgres` resuelto por red interna.
