# Fase 8 — Outbox: durabilidad de eventos y efectos secundarios

## 1. Alcance de Fase 8

Fase 8 introduce durabilidad de entrega para los efectos secundarios del dominio
(notificaciones al jugador, actualizaciones de CRM, webhooks externos futuros)
eliminando la ventana de pérdida que existe actualmente entre el `COMMIT` de
PostgreSQL y el despacho del evento en PHP.

No altera ningún estado de negocio. No introduce nuevos endpoints ni lógica de
dominio. No implementa integraciones externas. Solo garantiza que cuando algo
debe ocurrir *después* de un commit, la intención de entrega no se pierde
aunque el proceso muera inmediatamente después de confirmar la transacción.

La garantía de entrega es **al menos una vez (at-least-once)**: el evento no
se pierde si el dominio hace COMMIT, pero el worker puede intentar entregar
más de una vez si cae después de ejecutar el efecto externo y antes de marcar
`processed_at`. Por eso los consumidores deben ser idempotentes. `processed_at`
no equivale a "exactly once".

---

## 2. Problema a resolver

### 2.1 Ventana actual de pérdida

Todos los eventos de dominio del backend se despachan *después* del commit, fuera
de la transacción, en dos variantes:

**Variante A — `ShouldDispatchAfterCommit`** (seis eventos de Fase 1):

```php
// Laravel difiere el dispatch hasta que la transacción de DB confirme.
// Si el proceso cae entre el COMMIT y que Laravel llegue a llamar Event::dispatch(),
// el evento se pierde sin reintento.
final class GameCreated implements ShouldDispatchAfterCommit { ... }
```

**Variante B — Dispatch manual en try/catch** (resto de eventos):

```php
// Patrón dominante: el Action o Controller confirma la transacción
// y luego llama event() o dispatch() envuelto en try/catch+report.
DB::transaction(fn () => ...);          // COMMIT aquí
try {
    event(new OrderRefunded(...));       // ← ventana de pérdida
} catch (Throwable $e) {
    report($e);
}
```

Si el proceso PHP cae entre el `COMMIT` y la línea `event(...)`, el efecto
secundario se pierde para siempre. En producción esto ocurre por:

- Deploy / restart del servidor web.
- OOM killer.
- Excepción fatal no capturada en código de bootstrap.
- Timeout de FPM entre la confirmación y el dispatch.
- Crash del worker de cola entre el `COMMIT` y el `dispatch()`.

### 2.2 Estado actual de listeners

No existe ningún listener registrado para ningún evento de dominio. Los eventos
se despachan al bus de Laravel pero nadie los consume. El sistema está preparado
para consumidores futuros pero sin garantía de entrega.

### 2.3 Por qué no basta with `ShouldQueue` en los listeners

Encolar el listener dentro de la transacción del Action tampoco resuelve el
problema: si la transacción hace rollback, el job queda encolado pero el estado
no existe. Usar `afterCommit: true` en la conexión de cola y `ShouldDispatchAfterCommit`
desplaza la ventana pero no la elimina: sigue existiendo entre el commit de
PostgreSQL y el momento en que Laravel escribe la fila en la tabla `jobs`.

La solución canónica es el **Outbox pattern**: insertar la intención de entrega
**dentro de la misma transacción** del dominio. Si el dominio hace rollback, el
outbox también. Si el dominio confirma, el outbox también confirma. Un worker
independiente procesa el outbox con reintentos.

---

## 3. Mapa de eventos actuales

### 3.1 Módulo RepeatNumberBingo — eventos de ciclo de vida (Fase 1)

Patrón: `ShouldDispatchAfterCommit` — Laravel difiere el dispatch.

| Evento | Origen | Cuándo | Dentro de tx | Durable | Riesgo si falla | Consumidores actuales | Consumidores futuros |
|--------|--------|--------|--------------|---------|-----------------|----------------------|----------------------|
| `GameCreated` | `CreateGameAction` | Juego creado en `draft` | No (post-commit) | No | Ninguno (solo auditoría) | Ninguno | Broadcasting admin panel |
| `GamePublished` | `PublishGameAction` | `draft → published` | No (post-commit) | No | Ninguno | Ninguno | Broadcasting admin |
| `GameSalesOpened` | `OpenGameSalesAction` | `published → sales_open` | No (post-commit) | No | Ninguno | Ninguno | Broadcasting público |
| `GameSalesClosed` | `CloseGameSalesAction` | `sales_open → sales_closed` | No (post-commit) | No | Ninguno | Ninguno | Broadcasting |
| `GameScheduledStartSet` | `SetScheduledStartAtAction` | Fecha de inicio configurada | No (post-commit) | No | Ninguno | Ninguno | Broadcasting |
| `GameCancelled` | `CancelGameAction` | `* → cancelled` | No (post-commit) | No | Notificación a jugadores perdida | Ninguno | Notificación jugadores (Fase 9) |

### 3.2 Módulo RepeatNumberBingo — motor (Fases 3 y 4)

Patrón: plain `Dispatchable`, dispatch manual en `dispatchSafely()` o try/catch.

| Evento | Origen | Cuándo | Dentro de tx | Durable | Riesgo si falla | Consumidores actuales | Consumidores futuros |
|--------|--------|--------|--------------|---------|-----------------|----------------------|----------------------|
| `GameStarted` | `StartGameAction` | `sales_closed → running` | No (post-commit) | No | Broadcasting perdida | Ninguno | Broadcasting |
| `GamePaused` | `PauseGameAction` / auto-pausa | `running → paused` | No (post-commit) | No | Broadcasting perdida | Ninguno | Broadcasting |
| `GameResumed` | `ResumeGameAction` | `paused → running` | No (post-commit) | No | Broadcasting perdida | Ninguno | Broadcasting |
| `GameNumberDrawn` | `DrawGameNumberAction` / `ExecuteScheduledGameDrawAction` | Cada extracción | No (post-commit) | No | Broadcasting perdida | Ninguno | Broadcasting público (ya via `PublicGameUpdated`) |
| `GameWinnerDeclared` | `DrawGameNumberAction` (rama winner) | Ganador detectado | No (post-commit) | No | Notificación ganador perdida | Ninguno | Notificación ganador (Fase 9) |
| `GameCompleted` | `DrawGameNumberAction` (rama winner) | Partida completada | No (post-commit) | No | Notificación admin perdida | Ninguno | Notificación admin (Fase 9) |
| `GameCountersRebuilt` | `RebuildGameNumberCountersAction` | Contadores reconstruidos | No (post-commit) | No | Log perdido | Ninguno | Telemetría |
| `GameNumbersSold` | Archivo existe — dispatch no confirmado | Probable: en venta aprobada | No determinado | No | Bajo (auditoría duplicada en `game_events`) | Ninguno | Broadcasting |

### 3.3 Módulo RepeatNumberBingo — broadcasting (Fase 4)

Patrón: `ShouldBroadcast + ShouldDispatchAfterCommit`.

| Evento | Canal | Cuándo | Dentro de tx | Durable | Riesgo si falla | Consumidores actuales |
|--------|-------|--------|--------------|---------|-----------------|----------------------|
| `PublicGameUpdated` | `games.{slug}` | Después de cada draw / pausa / reanudación / inicio | No (post-commit) | No (best-effort) | Frontend no recibe actualización; puede recuperar por REST | Frontend público via Reverb |

### 3.4 Módulo Commerce (Fase 2)

Patrón mixto: `GameNumbersReserved` usa `ShouldDispatchAfterCommit`; resto son plain `Dispatchable` con dispatch manual post-commit.

| Evento | Origen | Cuándo | Dentro de tx | Durable | Riesgo si falla | Consumidores actuales | Consumidores futuros |
|--------|--------|--------|--------------|---------|-----------------|----------------------|----------------------|
| `GameNumbersReserved` | `ReserveGameNumbersAction` | Reserva creada | No (post-commit) | No | Ninguno (orden persiste) | Ninguno | Broadcasting, notificación confirmación |
| `PaymentEvidenceSubmitted` | `SubmitPaymentEvidenceOrchestrator` | Evidencia subida | No (post-commit) | No | Admin no recibe alerta | Ninguno | Notificación admin (Fase 9) |
| `PaymentApproved` | `ApprovePaymentController` | Admin aprueba pago | No (post-commit) | No | **Jugador no recibe confirmación** | Ninguno | Notificación jugador (Fase 9) |
| `PaymentRejected` | `RejectPaymentController` | Admin rechaza pago | No (post-commit) | No | **Jugador no recibe rechazo** | Ninguno | Notificación jugador (Fase 9) |
| `OrderReservationsExpired` | `ExpireOrderAction` | TTL expirado | No (post-commit) | No | Jugador no recibe aviso de expiración | Ninguno | Notificación jugador (Fase 9) |
| `OrderCancelledByUser` | `CancelOrderAction` | Jugador cancela | No (post-commit) | No | Confirmación de cancelación perdida | Ninguno | Email confirmación (Fase 9) |

### 3.5 Módulo Commerce — financiero (Fase 6)

Patrón: plain `Dispatchable`, dispatch manual en try/catch post-commit.

| Evento | Origen | Cuándo | Dentro de tx | Durable | Riesgo si falla | Consumidores actuales | Consumidores futuros |
|--------|--------|--------|--------------|---------|-----------------|----------------------|----------------------|
| `OrderRefunded` | `RefundOrderAction` | Admin registra reembolso | No (post-commit) | No | **Jugador no recibe confirmación de reembolso** | Ninguno | Notificación jugador, CRM (Fase 9) |
| `WinnerPayoutRegistered` | `ProcessWinnerPayoutAction` | Admin registra pago al ganador | No (post-commit) | No | **Ganador no recibe confirmación de cobro** | Ninguno | Notificación ganador, CRM (Fase 9) |

### 3.6 Identidad / Auth (Fase 7)

Patrón: notificaciones síncronas en el hilo HTTP. Sin cola. Sin outbox.

| Mecanismo | Origen | Cuándo | Dentro de tx | Durable | Riesgo si falla |
|-----------|--------|--------|--------------|---------|-----------------|
| `ResetPassword::class` (Laravel nativo) | `Password::sendResetLink()` en `SendPasswordResetLinkAction` | Usuario solicita reset | No (síncrono, fuera de tx) | No | Email de reset no enviado — usuario no puede resetear |
| `VerifyEmailNotification` | `User::notify()` en `SendEmailVerificationNotificationAction` | Usuario solicita verificación | No (síncrono, fuera de tx) | No | Email de verificación no enviado — usuario no puede verificar |

Ambas son síncronas: si el servidor SMTP no responde, la action lanza excepción
(o falla silenciosamente según configuración del mailer). No hay reintento
automático. No se persiste la intención de envío.

### 3.7 Jobs existentes

| Job | Tipo | Scheduler | Reintentos | ShouldBeUnique |
|-----|------|-----------|------------|----------------|
| `ExpirePendingOrdersJob` | `ShouldQueue` | Cada minuto | — (no definido) | Sí (`uniqueFor=60s`) |
| `DispatchDueGameDrawsJob` | `ShouldQueue` | Cada N segundos | — | No |
| `ExecuteScheduledGameDrawJob` | `ShouldQueue` + `ShouldBeUnique` | Por tick | 4, backoff [1,5,10] | Sí (`uniqueFor=120s`) |

Queue connection por defecto: `database`. En producción: `redis`.
`failed_jobs` table existe (Laravel default). No hay listener de `JobFailed` registrado.

---

## 4. Diferencia entre auditoría y delivery

### 4.1 `game_events` — historial de dominio (append-only)

- **Qué es:** registro inmutable de hechos del dominio. Cada fila representa un hecho ocurrido.
- **Cuándo se inserta:** dentro de la misma transacción del Action que origina el hecho.
- **Garantía:** si la transacción confirma, el evento está en `game_events`. Si hace rollback, no está.
- **Para qué sirve:** auditoría, reconstrucción de estado, verificación de integridad, reporting interno.
- **Para qué NO sirve:** entrega de efectos secundarios (notificaciones, webhooks, broadcasting externo).

### 4.2 `outbox_events` — cola de delivery durable (nuevo en Fase 8.2)

- **Qué es:** tabla de entrega durable de efectos secundarios.
- **Cuándo se inserta:** dentro de la misma transacción del Action, justo antes del `COMMIT`.
- **Garantía:** si el dominio confirma, la intención de entrega también confirma. Si el proceso cae antes de entregar, un worker reintenta desde la fila.
- **Para qué sirve:** notificaciones, emails, webhooks externos, broadcasting con reintentos, CRM sync.
- **Para qué NO sirve:** reconstrucción de estado del dominio, historial público de extracciones, fuente de verdad financiera.

### 4.3 Reglas de separación

| Regla | Motivo |
|-------|--------|
| No reconstruir estado financiero desde `outbox_events` | La fuente es `orders`, `payments`, `refunds`, `winner_payouts` |
| No usar outbox como historial público | Para eso está `game_events` |
| No meter payloads sensibles en outbox | Puede ser leído por workers externos; limitar PII |
| No confundir deduplicación de outbox con idempotencia del dominio | Son capas distintas |
| Un evento de dominio puede generar varios outbox rows (uno por consumidor) | O un row con múltiples fanout si el worker lo resuelve |

---

## 5. Eventos candidatos para Outbox

### 5.1 Obligatorios en Fase 8.2

Criterio: existen usuarios reales que esperan una notificación tras el evento, y perder el evento tiene impacto directo en experiencia de usuario o en operación financiera.

| Evento | Razón | Consumidor esperado |
|--------|-------|---------------------|
| `PaymentApproved` | El jugador necesita saber que su pago fue aprobado y su participación confirmada | Email / WhatsApp al comprador |
| `PaymentRejected` | El jugador necesita saber que su pago fue rechazado para reintentar | Email / WhatsApp al comprador |
| `OrderRefunded` | El jugador debe recibir confirmación de reembolso | Email al comprador |
| `WinnerPayoutRegistered` | El ganador debe recibir confirmación del cobro | Email / WhatsApp al ganador |
| `GameWinnerDeclared` | El ganador necesita saber que ganó; momento de alto impacto emocional y operativo | Notificación urgente al ganador |

### 5.2 Opcionales (Fase 8.3 o posterior)

| Evento | Razón | Prioridad |
|--------|-------|-----------|
| `GameCompleted` | Admin puede querer alerta automática de cierre | Baja |
| `GameCancelled` | Jugadores con reservas activas deberían ser notificados | Media |
| `OrderReservationsExpired` | Aviso al usuario de expiración | Media |
| `OrderCancelledByUser` | Confirmación al usuario | Baja |
| `GameStarted` | Admin / broadcasting | Baja |
| `PaymentEvidenceSubmitted` | Alerta al admin de revisión pendiente | Media |

### 5.3 Fuera de alcance

| Evento | Razón de exclusión |
|--------|-------------------|
| `GameCreated`, `GamePublished`, `GameSalesOpened`, `GameSalesClosed`, `GameScheduledStartSet` | Solo cambios administrativos; sin consumidor de notificación directo en Fase 8 |
| `GameNumberDrawn` | Ya cubierto por `PublicGameUpdated` (broadcasting best-effort); el historial está en `game_draws` |
| `GamePaused`, `GameResumed` | Broadcasting best-effort suficiente; sin consumidor de notificación |
| `GameCountersRebuilt` | Operación interna; solo telemetría |
| `GameNumbersReserved` | La reserva persiste; el usuario puede consultar su estado por API |
| `GameNumbersSold` | Cubierto por `PaymentApproved` que ya es candidato obligatorio |

### 5.4 Auth notifications — tratamiento especial

Las notificaciones de auth (`ResetPassword`, `VerifyEmailNotification`) son síncronas
hoy. El modelo correcto a futuro es encolarlas via `ShouldQueue` en el `Notification`,
no via Outbox. Son independientes de transacciones de dominio y no requieren el mismo
patrón de atomicidad.

Se puede hacer esto en Fase 9 añadiendo `implements ShouldQueue` al `Notification`,
sin tocar la lógica del `Action`. No requiere Outbox.

---

## 6. Contrato de tabla `outbox_events`

### 6.1 Campos mínimos

```sql
CREATE TABLE outbox_events (
    id                 UUID         NOT NULL,           -- UUID v7 generado en PHP
    event_type         VARCHAR(120) NOT NULL CHECK (trim(event_type) <> ''),
    aggregate_type     VARCHAR(80)  NOT NULL CHECK (trim(aggregate_type) <> ''),
    aggregate_id       UUID         NULL,               -- NULL para eventos sin agregado raíz
    deduplication_key  VARCHAR(255) NULL,               -- NULL si no se requiere deduplicación
    payload            JSONB        NOT NULL CHECK (jsonb_typeof(payload) = 'object'),
    available_at       TIMESTAMPTZ  NOT NULL,           -- permite scheduling (DEFAULT NOW())
    processed_at       TIMESTAMPTZ  NULL,
    failed_at          TIMESTAMPTZ  NULL,
    attempts           INT          NOT NULL DEFAULT 0 CHECK (attempts >= 0),
    last_error         TEXT         NULL,
    created_at         TIMESTAMPTZ  NOT NULL,

    CONSTRAINT outbox_events_pkey PRIMARY KEY (id)
);
```

### 6.2 Campos adicionales recomendados

Tras evaluar los casos de uso concretos del backend se recomienda añadir:

```sql
    locked_at          TIMESTAMPTZ  NULL,    -- cuándo fue tomado por un worker
    locked_by          VARCHAR(255) NULL,    -- identificador del worker (hostname:pid)
    next_attempt_at    TIMESTAMPTZ  NULL,    -- backoff: cuándo reintentar
    max_attempts       INT          NOT NULL DEFAULT 5 CHECK (max_attempts > 0),
```

**Justificación:**

- `locked_at` / `locked_by`: necesarios para el patrón `FOR UPDATE SKIP LOCKED`. Permiten
  detectar locks huérfanos (worker muerto) sin una tabla separada.
- `next_attempt_at`: permite backoff exponencial sin requerir que el worker calcule el
  tiempo en base a `attempts` en cada poll. El worker solo consulta `WHERE next_attempt_at <= NOW()`.
- `max_attempts`: permite valores distintos por `event_type` si se requiere en el futuro.
  Por ahora DEFAULT 5 es suficiente.

**Campos no incluidos (y por qué):**

- `priority`: no hay evidencia de que se necesite priorización diferenciada en Fase 8.
  Añadirlo aumenta la complejidad del índice. Postergado.
- `queue`: un outbox simple procesa todo en una sola fila de trabajo. Si se requieren
  múltiples workers especializados, se añade en Fase 8.3.

### 6.3 Índices

```sql
-- Índice de trabajo: pending + available_at
CREATE INDEX outbox_events_pending_idx
    ON outbox_events (available_at, id)
    WHERE processed_at IS NULL AND failed_at IS NULL;

-- Índice para deduplicación parcial (si se usa deduplication_key)
CREATE UNIQUE INDEX outbox_events_dedup_unprocessed_idx
    ON outbox_events (deduplication_key)
    WHERE deduplication_key IS NOT NULL AND processed_at IS NULL;

-- Índice de lookup por agregado (para auditoría/debug)
CREATE INDEX outbox_events_aggregate_idx
    ON outbox_events (aggregate_type, aggregate_id)
    WHERE aggregate_id IS NOT NULL;
```

**Nota sobre el índice de deduplicación:** el índice parcial `WHERE ... processed_at IS NULL`
permite múltiples filas históricas con la misma `deduplication_key` (ya procesadas), pero
solo una fila pendiente. Esto es correcto: el constraint previene duplicados en vuelo, no
duplicados históricos.

### 6.4 Constraints adicionales

```sql
-- Consistencia de estado: no puede estar procesado Y fallido al mismo tiempo
ALTER TABLE outbox_events
    ADD CONSTRAINT chk_not_both_processed_and_failed
    CHECK (NOT (processed_at IS NOT NULL AND failed_at IS NOT NULL));

-- Intentos no pueden exceder max
ALTER TABLE outbox_events
    ADD CONSTRAINT chk_attempts_le_max
    CHECK (attempts <= max_attempts);
```

### 6.5 UUID v7 — generado en PHP, no en PostgreSQL

Consistente con la convención del proyecto (`Str::uuid7()` vía `HasUuids`):

```php
// En el Action, dentro de la transacción:
DB::table('outbox_events')->insert([
    'id'            => (string) Str::uuid7(),
    'event_type'    => 'payment_approved',
    'aggregate_type' => 'order',
    'aggregate_id'  => $orderId,
    'payload'       => json_encode([...]),
    'available_at'  => now(),
    'created_at'    => now(),
]);
```

No usar `DEFAULT gen_random_uuid()` en PostgreSQL — mantiene consistencia con UUID v7
del proyecto y permite que el PHP conozca el ID antes del INSERT (para logging, etc.).

---

## 7. Idempotencia y deduplicación

### 7.1 Eventos que necesitan `deduplication_key`

| Evento | `deduplication_key` propuesta | Razón |
|--------|-------------------------------|-------|
| `PaymentApproved` | `payment_approved:{payment_id}` | Un solo pago puede aprobarse una sola vez; idempotencia ya en `ApprovePaymentAction` |
| `PaymentRejected` | `payment_rejected:{payment_id}` | Ídem |
| `OrderRefunded` | `order_refunded:{order_id}` | Un solo refund por order; `UNIQUE(order_id)` en `refunds` |
| `WinnerPayoutRegistered` | `winner_payout_registered:{game_winner_id}` | Un solo payout por ganador |
| `GameWinnerDeclared` | `game_winner_declared:{game_id}` | Un único ganador por partida |
| `GameCancelled` | `game_cancelled:{game_id}` | Un juego solo se puede cancelar una vez |

Para eventos que pueden ocurrir múltiples veces por agregado (`PaymentEvidenceSubmitted`,
`GameNumbersReserved`, etc.) no se usa `deduplication_key` — la unicidad la garantizan
las constraints del dominio.

### 7.2 Cálculo de `deduplication_key`

La clave se calcula en el Action, dentro de la transacción, con los IDs de dominio
canónicos. No se usa hash — los IDs ya son suficientemente únicos. Un string legible
facilita el debugging.

### 7.3 Comportamiento ante duplicados

**Importante:** PostgreSQL aborta la transacción completa al recibir una `UNIQUE violation`.
No se debe capturar `UniqueConstraintViolationException` para continuar dentro de la misma
transacción — la transacción ya está abortada en ese punto y cualquier operación posterior
fallará con "current transaction is aborted".

**Estrategia preferida — `ON CONFLICT DO NOTHING`:**

```sql
INSERT INTO outbox_events (id, event_type, deduplication_key, payload, ...)
VALUES (...)
ON CONFLICT (deduplication_key)
WHERE deduplication_key IS NOT NULL AND processed_at IS NULL
DO NOTHING;
```

En Laravel, con statement SQL explícito dentro de la transacción del Action:

```php
DB::statement('
    INSERT INTO outbox_events (id, event_type, deduplication_key, payload, available_at, created_at)
    VALUES (?, ?, ?, ?, ?, ?)
    ON CONFLICT (deduplication_key)
    WHERE deduplication_key IS NOT NULL AND processed_at IS NULL
    DO NOTHING
', [$id, $eventType, $dedupKey, $payload, now(), now()]);
```

Si el INSERT no inserta ninguna fila (duplicate silenciado), el dominio continúa normalmente
sin error. El índice único parcial es defensa de integridad — no el mecanismo de control de flujo.

**Estrategia alternativa — check previo bajo lock consistente:**

Si se necesita saber explícitamente si se insertó o no (para logging, etc.):

```php
$exists = DB::table('outbox_events')
    ->where('deduplication_key', $dedupKey)
    ->whereNull('processed_at')
    ->lockForUpdate()
    ->exists();

if (! $exists) {
    DB::table('outbox_events')->insert([...]);
}
```

Ambas estrategias deben ejecutarse **dentro de la misma transacción del dominio**.

**Reglas:**

- El índice único parcial `WHERE deduplication_key IS NOT NULL AND processed_at IS NULL`
  es la última línea de defensa de integridad, no el flujo principal.
- No se captura `UniqueConstraintViolationException` como flujo normal.
- Si se usa `ON CONFLICT DO NOTHING`, el dominio puede continuar sin abortar.
- Una fila con `processed_at IS NOT NULL` no bloquea una nueva inserción con la misma
  `deduplication_key` — el índice es parcial y solo aplica a filas pendientes.

### 7.4 Consumidores idempotentes

El worker de outbox garantiza entrega **at-least-once** (puede entregar más de una vez
ante crashes entre la entrega y el marcado como `processed_at`). Los consumidores
(notificaciones, CRM) deben ser idempotentes:

- Email: si se envía dos veces, el usuario recibe dos emails. Mitigación: flag "enviado"
  propio del consumidor, o verificar antes de enviar si ya se envió.
- CRM: upsert en lugar de insert.
- Broadcasting: idempotente por naturaleza (el estado ya está en DB).

---

## 8. Worker / procesador futuro (`ProcessOutboxEventsJob`)

### 8.1 Contrato del worker

```php
// Fase 8.2 — diseño propuesto (NO IMPLEMENTAR en 8.1)

final class ProcessOutboxEventsJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 1;       // El job en sí no se reintenta; el outbox maneja reintentos
    public int $timeout = 60;

    public function handle(OutboxEventProcessor $processor): void
    {
        $processor->processBatch(batchSize: 50);
    }
}
```

### 8.2 Algoritmo del procesador

```
-- FASE 1: CLAIM (dentro de una transacción)
BEGIN

  -- Seleccionar filas candidatas:
  --   · no procesadas ni fallidas definitivamente
  --   · disponibles y listas para reintentar
  --   · sin lock activo O con lock vencido (worker muerto)
  SELECT id, event_type, aggregate_id, payload, attempts, max_attempts
  FROM outbox_events
  WHERE processed_at IS NULL
    AND failed_at IS NULL
    AND available_at <= NOW()
    AND (next_attempt_at IS NULL OR next_attempt_at <= NOW())
    AND (
        locked_at IS NULL
        OR locked_at < NOW() - INTERVAL '5 minutes'   -- stale lock recovery
    )
  ORDER BY available_at ASC, id ASC
  LIMIT 50
  FOR UPDATE SKIP LOCKED   -- evita que dos workers reclamen la misma fila en este SELECT

  -- Marcar las filas reclamadas DENTRO de la misma transacción:
  UPDATE outbox_events
  SET locked_at = NOW(),
      locked_by = :worker_id       -- hostname:pid
  WHERE id IN (...)

COMMIT   -- el lock persiste en la fila; otros workers no las tomarán

-- FASE 2: PROCESS (fuera de la transacción de claim)
Para cada fila reclamada:
  try:
    dispatch al consumidor correspondiente
    -- Éxito: marcar procesada y limpiar lock
    UPDATE outbox_events
    SET processed_at = NOW(),
        locked_at    = NULL,
        locked_by    = NULL
    WHERE id = ?

  catch (fallo reintentable):
    attempts += 1
    -- Fallo parcial: incrementar contador, programar reintento, liberar lock
    UPDATE outbox_events
    SET attempts        = :new_attempts,
        last_error      = :error_message,
        next_attempt_at = NOW() + backoff(attempts),
        locked_at       = NULL,
        locked_by       = NULL
    WHERE id = ?

  catch (fallo final — attempts >= max_attempts):
    -- Fallo permanente: marcar muerta, limpiar lock
    UPDATE outbox_events
    SET failed_at  = NOW(),
        last_error = :error_message,
        locked_at  = NULL,
        locked_by  = NULL
    WHERE id = ?
```

**Por qué este diseño funciona:**

- `FOR UPDATE SKIP LOCKED` evita que dos workers reclamen la misma fila *durante* la
  transacción de claim (garantía a nivel de sesión PostgreSQL).
- `locked_at` persistente evita que otra corrida del worker tome la fila *después* del
  COMMIT de claim — el SELECT excluye filas con `locked_at` reciente.
- `locked_at < NOW() - INTERVAL '5 minutes'` permite recuperar filas si el worker muere
  entre el COMMIT de claim y el UPDATE de resultado (stale lock recovery).
- El procesamiento ocurre *fuera* de la transacción de claim para no mantener el lock de
  fila durante la llamada al consumidor externo (que puede tardar segundos).

### 8.3 Backoff exponencial

| intento | espera mínima |
|---------|--------------|
| 1 | 30 segundos |
| 2 | 2 minutos |
| 3 | 10 minutos |
| 4 | 1 hora |
| 5 (max_attempts) | marcar `failed_at` |

### 8.4 Garantías del diseño

| Garantía | Mecanismo |
|---------|-----------|
| Dos workers no reclaman la misma fila simultáneamente | `FOR UPDATE SKIP LOCKED` en transacción de claim |
| Fila reclamada no la toma otro worker tras el COMMIT | `locked_at` persistente + WHERE excluye locks recientes |
| Recuperación de worker muerto | `locked_at < NOW() - 5min` en siguiente poll (stale lock) |
| Entrega at-least-once — evento no se pierde tras COMMIT de dominio | Fila persiste hasta `processed_at`; reintentos automáticos |
| Posible entrega múltiple — consumidor debe ser idempotente | Si el worker cae entre entrega y marcado, la fila se reintenta |
| Inserción duplicada no aborta transacción | `ON CONFLICT DO NOTHING` — nunca `UniqueConstraintViolationException` como flujo |
| Duplicados históricos permitidos, duplicados en vuelo bloqueados | Índice único parcial `WHERE processed_at IS NULL` |

### 8.5 Scheduler del worker

```php
// routes/console.php (Fase 8.2)
Schedule::job(new ProcessOutboxEventsJob)
    ->everyMinute()
    ->withoutOverlapping(2);
```

Alternativamente: un loop continuo con `->everySecond()` si la latencia importa.
La frecuencia exacta se define en 8.2 según los SLA de notificaciones.

---

## 9. Seguridad y privacidad

### 9.1 Qué NO debe ir en el payload de outbox

| Campo | Razón |
|-------|-------|
| Tokens planos (reset, verification signature) | Se pueden usar para autenticar sin credenciales |
| Password reset token | Sensible — solo el broker de Laravel lo maneja |
| Email verification signature completa | No se necesita en el payload; el consumidor puede generar una nueva URL firmada si necesita |
| `idempotency_key_hash` / `request_fingerprint` | Metadatos internos de idempotencia; no útiles para consumidores externos |
| `disk` / `path` de archivos de storage privado | Rutas internas; nunca expuestas en eventos públicos |
| `sha256` de documentos | Hash interno de integridad |
| Datos bancarios del ganador | Nunca en el payload |
| Número completo de tarjeta | Nunca |
| Tokens de gateway de pagos | Nunca |
| Binarios / adjuntos codificados en Base64 | El consumer debe descargar desde storage |
| `result_payload` de `draw_commands` | Metadato interno del motor |

### 9.2 Qué SÍ puede ir en el payload

```jsonc
// Ejemplo: PaymentApproved outbox event
{
  "schema_version": 1,
  "payment_id": "019688...",
  "order_id": "019688...",
  "game_id": "019688...",
  "buyer_user_id": 42,          // ID numérico para lookup, no email ni nombre
  "game_entry_ids": ["..."],    // para contexto del consumidor
  "occurred_at": "2026-06-30T..."
}
```

El consumidor hace lookup del email/nombre del usuario cuando necesita enviar la notificación.
No se serializa PII innecesaria en el payload del outbox.

### 9.3 Acceso a la tabla `outbox_events`

- Solo accesible por el worker y el Action que escribe.
- No expuesta por ningún endpoint API.
- No indexada por campo de usuario — si se necesita "ver los eventos de un usuario" se hace join con las tablas operativas.
- `last_error` puede contener stack traces — asegurar que no se logueen a sistemas externos sin sanitizar.

---

## 10. Relación con notificaciones y gateway

### 10.1 Fase 8 solo garantiza durabilidad

Fase 8 no implementa ningún proveedor de comunicación. El outbox es un buffer durable.
El consumidor real (email provider, WhatsApp, CRM) llega en Fase 9.

### 10.2 Arquitectura propuesta para notificaciones (Fase 9)

```
Fase 8 (Outbox):
  Action → INSERT outbox_events (dentro de tx)
  ProcessOutboxEventsJob → SELECT outbox row → dispatch OutboxEventDispatched

Fase 9 (Notificaciones):
  OutboxEventDispatcher → match event_type → NotificationHandler
  NotificationHandler → User::find(buyer_user_id)->notify(new PaymentApprovedNotification(...))
  PaymentApprovedNotification → implements ShouldQueue → SMTP / WhatsApp provider
```

### 10.3 Gateway de pagos

Los webhooks de gateway de pagos (Stripe, Culqi, Niubiz) también deben usar el outbox
para garantizar que los eventos de pago externos disparan las transiciones de dominio
de forma durable (at-least-once), con reintentos y deduplicación en el consumidor.
Esto es materia de Fase 9+.

### 10.4 Auth notifications — camino alternativo

Como se indicó en §5.4, las notificaciones de auth no necesitan outbox. El camino
recomendado para Fase 9 es:

```php
// Fase 9 — sin modificar Actions de auth:
final class VerifyEmailNotification extends Notification implements ShouldQueue { ... }
```

`ShouldQueue` encola la notificación en la tabla `jobs` (database driver), que ya existe.
Si falla, `failed_jobs` captura el error. Es suficiente para las garantías requeridas de auth.

---

## 11. Pruebas requeridas para Fase 8.2

Lista mínima de tests a implementar al introducir el outbox:

### 11.1 Tests de inserción atómica

```text
test_outbox_event_is_inserted_within_domain_transaction
  → Verificar que la fila de outbox existe después del COMMIT del Action

test_rollback_does_not_leave_outbox_event
  → Si la transacción del dominio hace rollback, no debe quedar fila en outbox_events

test_commit_leaves_exactly_one_outbox_event
  → Verificar que un solo Action insertando = una sola fila (sin duplicados)
```

### 11.2 Tests del worker

```text
test_worker_processes_pending_outbox_event
  → Un evento pendiente pasa a processed_at tras ejecutarse el job

test_worker_claims_row_and_sets_locked_at
  → Tras el claim, locked_at y locked_by quedan seteados en la fila

test_row_with_recent_locked_at_is_not_claimed_by_another_worker
  → Una fila con locked_at reciente (< 5 min) no aparece en el SELECT del siguiente worker

test_row_with_stale_locked_at_can_be_reclaimed
  → Una fila con locked_at > 5 min (worker muerto) sí puede ser reclamada

test_two_workers_do_not_process_same_event
  → Concurrencia real: dos workers simultáneos con la misma fila → solo uno la procesa
  (FOR UPDATE SKIP LOCKED + locked_at persistente)

test_worker_clears_lock_on_success
  → Tras processed_at, locked_at y locked_by quedan NULL

test_worker_clears_lock_on_retryable_failure
  → Tras fallo reintentable, locked_at y locked_by quedan NULL; next_attempt_at seteado

test_worker_clears_lock_on_final_failure
  → Tras failed_at, locked_at y locked_by quedan NULL

test_worker_increments_attempts_on_failure
  → Fallo del consumer → attempts += 1, last_error seteado

test_max_attempts_marks_failed
  → Tras N fallos → failed_at seteado, processed_at NULL

test_processed_at_is_set_only_once
  → Verificar que processed_at no se sobreescribe en replay

test_worker_respects_next_attempt_at
  → Una fila con next_attempt_at > NOW() no se procesa hasta que vence

test_at_least_once_delivery_simulation
  → Si el worker procesa pero no marca processed_at (simulando crash), la fila es
  reintentada → at-least-once garantizado; el consumidor debe manejar el duplicado
```

### 11.3 Tests de deduplicación

```text
test_on_conflict_do_nothing_does_not_abort_transaction
  → INSERT con ON CONFLICT DO NOTHING + mismo deduplication_key → no lanza excepción,
  la transacción continúa normalmente, cero filas insertadas

test_no_unique_constraint_violation_as_control_flow
  → Nunca se usa UniqueConstraintViolationException como flujo esperado de deduplicación

test_deduplication_prevents_duplicate_outbox_entry
  → Dos transacciones con mismo deduplication_key → solo una fila creada

test_processed_event_allows_new_event_with_same_dedup_key
  → Un evento ya processed_at no bloquea uno nuevo con la misma clave
  (el índice es parcial WHERE processed_at IS NULL)

test_idempotent_consumer_handles_duplicate_delivery
  → Consumidor recibe el mismo evento dos veces → produce el efecto una sola vez
  (verificar con spy/mock del canal de entrega)
```

### 11.4 Tests de seguridad del payload

```text
test_outbox_payload_does_not_contain_sensitive_fields
  → El payload JSON no contiene: token, hash, password, disk, path, sha256, fingerprint

test_outbox_payload_does_not_contain_pii_beyond_user_id
  → Solo user_id numérico; no email, nombre, teléfono en payload
```

### 11.5 Regresión

```text
test_full_suite_still_passes_with_outbox_table
  → php artisan test --compact → 1173+ passed, sin regresiones
```

---

## 12. Plan de implementación

### 12.1 Fase 8.2 — Infraestructura Outbox

1. **Migración** `create_outbox_events_table` — campos del §6.1 + §6.2, índices del §6.3, constraints del §6.4.
2. **Modelo** `OutboxEvent` — `HasUuids`, `UPDATED_AT = null`, sin booted hooks de inmutabilidad (el worker muta `processed_at`, `locked_at`, etc.).
3. **Helper de inserción** — método `OutboxEvent::record(string $eventType, ...)` usado desde Actions dentro de transacción.
4. **Processor** — `OutboxEventProcessor::processBatch()` con algoritmo del §8.2.
5. **Job** — `ProcessOutboxEventsJob` + registro en `routes/console.php`.
6. **Tests** — todos los del §11.

Comenzar con **un solo evento candidato** (`PaymentApproved`) para validar el ciclo completo
antes de añadir los demás. Expandir a `PaymentRejected`, `OrderRefunded`, `WinnerPayoutRegistered`,
`GameWinnerDeclared` en la misma fase.

### 12.2 Fase 8.3 — Eventos opcionales y monitoring

1. Añadir eventos opcionales del §5.2 al outbox.
2. Implementar alertas de `failed_at` (admin panel o log estructurado).
3. Cleanup de eventos históricos procesados (TTL configurable, job de limpieza).
4. Métricas: tiempo en cola, attempts promedio, tasa de fallos por `event_type`.
5. Soporte multi-queue si se requiere (campo `queue` opcional en tabla).

---

## 13. Límites explícitos de Fase 8

**No se implementa en Fase 8:**

| Feature | Razón |
|---------|-------|
| Tabla `outbox_events` (ya que es 8.1 solo de diseño) | Se implementa en 8.2 |
| Worker real en producción | 8.2 |
| Proveedor de email / SMTP real | Fase 9 |
| WhatsApp / SMS | Fase 9+ |
| Gateway de pagos externo | Fase 9+ |
| Webhooks de pago | Fase 9+ |
| CRM sync | Fase 9+ |
| Broadcasting nuevo | Ya existe `PublicGameUpdated`; sin cambios |
| Mail real | Sin cambios en Fase 8 |
| Reintentos productivos de notificaciones | 8.2 |
| Cambio de email | No planificado |
| 2FA | No planificado |
| Frontend | No planificado |

---

## Apéndice A — Inventario de eventos y su estado

### A.1 RNB — Fase 1 (`ShouldDispatchAfterCommit`)

| Clase | Archivo | Durable | Outbox candidato |
|-------|---------|---------|-----------------|
| `GameCreated` | `Domain/Events/GameCreated.php` | No | No (Fase 8) |
| `GamePublished` | `Domain/Events/GamePublished.php` | No | No (Fase 8) |
| `GameSalesOpened` | `Domain/Events/GameSalesOpened.php` | No | No (Fase 8) |
| `GameSalesClosed` | `Domain/Events/GameSalesClosed.php` | No | No (Fase 8) |
| `GameScheduledStartSet` | `Domain/Events/GameScheduledStartSet.php` | No | No (Fase 8) |
| `GameCancelled` | `Domain/Events/GameCancelled.php` | No | Opcional 8.3 |

### A.2 RNB — Motor (plain `Dispatchable`)

| Clase | Durable | Outbox candidato |
|-------|---------|-----------------|
| `GameStarted` | No | Opcional 8.3 |
| `GamePaused` | No | No (Fase 8) |
| `GameResumed` | No | No (Fase 8) |
| `GameNumberDrawn` | No | No — cubierto por broadcasting |
| `GameWinnerDeclared` | No | **Obligatorio 8.2** |
| `GameCompleted` | No | Opcional 8.3 |
| `GameCountersRebuilt` | No | No (solo telemetría) |
| `GameNumbersSold` | No | No (cubierto por PaymentApproved) |

### A.3 RNB — Broadcasting

| Clase | Driver | Durable |
|-------|--------|---------|
| `PublicGameUpdated` | Reverb (`ShouldBroadcast`) | No (best-effort) |

### A.4 Commerce — Fase 2

| Clase | Durable | Outbox candidato |
|-------|---------|-----------------|
| `GameNumbersReserved` | No | No (Fase 8) |
| `PaymentEvidenceSubmitted` | No | Opcional 8.3 |
| `PaymentApproved` | No | **Obligatorio 8.2** |
| `PaymentRejected` | No | **Obligatorio 8.2** |
| `OrderReservationsExpired` | No | Opcional 8.3 |
| `OrderCancelledByUser` | No | Opcional 8.3 |

### A.5 Commerce — Fase 6

| Clase | Durable | Outbox candidato |
|-------|---------|-----------------|
| `OrderRefunded` | No | **Obligatorio 8.2** |
| `WinnerPayoutRegistered` | No | **Obligatorio 8.2** |

### A.6 Auth — Notificaciones síncronas

| Mecanismo | Durable | Camino recomendado |
|-----------|---------|-------------------|
| `ResetPassword` (Laravel) | No | `ShouldQueue` en Notification (Fase 9) |
| `VerifyEmailNotification` | No | `ShouldQueue` en Notification (Fase 9) |

---

## Apéndice B — Implementación de Fase 8.2

### B.1 Archivos creados

| Archivo | Tipo | Propósito |
|---------|------|-----------|
| `database/migrations/2026_06_30_100000_create_outbox_events_table.php` | Migración | Tabla con constraints, índices y contrato completo |
| `app/Models/OutboxEvent.php` | Eloquent Model | Modelo de lectura para el procesador; `UPDATED_AT = null`, casts completos |
| `app/Modules/Shared/Application/DTOs/OutboxRecordResult.php` | DTO | Resultado de `RecordOutboxEventAction` (`inserted`, `outboxEventId`) |
| `app/Modules/Shared/Application/Actions/RecordOutboxEventAction.php` | Action | Inserta fila de outbox dentro de transacción activa con `ON CONFLICT DO NOTHING` |
| `app/Modules/Shared/Infrastructure/Outbox/OutboxEventDispatcher.php` | Infrastructure | Rutea evento outbox a su handler; solo `payment_approved` en Fase 8.2 (stub de log) |
| `app/Modules/Shared/Infrastructure/Outbox/OutboxEventProcessor.php` | Infrastructure | Claim two-phase (`FOR UPDATE SKIP LOCKED` + `locked_at` persistente) + process |
| `app/Modules/Shared/Application/Jobs/ProcessOutboxEventsJob.php` | Job | `ShouldQueue`, `tries = 1`, `timeout = 55`, delega a `OutboxEventProcessor::processBatch()` |

### B.2 Archivos modificados

| Archivo | Cambio |
|---------|--------|
| `app/Modules/Commerce/Application/Actions/ApprovePaymentAction.php` | Agrega constructor con `RecordOutboxEventAction`; inserta evento `payment_approved` dentro de la transacción en rama `wasTransitionApplied = true` |
| `routes/console.php` | Registra `Schedule::job(new ProcessOutboxEventsJob)->everyMinute()->withoutOverlapping(2)` |

### B.3 Tests creados

| Archivo | Suite | Qué verifica |
|---------|-------|-------------|
| `tests/Integration/Shared/OutboxTableConstraintsTest.php` | Integration | CHECK constraints de la tabla, índice único parcial de deduplicación |
| `tests/Integration/Shared/RecordOutboxEventActionTest.php` | Integration | Transacción guard, inserción feliz, ON CONFLICT DO NOTHING, rollback, dedup |
| `tests/Integration/Commerce/OutboxPaymentApprovedIntegrationTest.php` | Integration | Inserción atomíca al aprobar pago, payload correcto, sin datos sensibles, idempotencia, rollback |
| `tests/Integration/Shared/OutboxEventProcessorTest.php` | Integration | Claim, locks frescos/vencidos, success/fallo/final, clears lock en todos los outcomes |
| `tests/Unit/Architecture/Phase82OutboxArchitectureTest.php` | Unit (grep) | Transaction guard, ON CONFLICT sin captura de excepción, no HTTP en Dispatcher, payload limpio, FOR UPDATE SKIP LOCKED, lock clearing, ShouldQueue, tries=1 |

### B.4 Payload del evento `payment_approved`

```jsonc
{
  "schema_version": 1,
  "payment_id": "01968b...",    // UUID v7
  "order_id": "01968b...",      // UUID v7
  "game_id": "01968b...",       // UUID v7
  "buyer_user_id": 42,          // ID entero — el consumer hace lookup de email/nombre
  "occurred_at": "2026-06-30T12:00:00+00:00"
}
```

Campos **no incluidos** por política de privacidad: email, nombre, teléfono, path de evidencia, disk, sha256, idempotency_key, request_fingerprint, token, datos bancarios, reviewer_user_id.

`deduplication_key`: `payment_approved:{payment_id}` — previene duplicados en caso de replay del `IdempotentCommandExecutor`.

### B.5 Integración con el flujo de aprobación

```
ApprovePaymentController
  └─ IdempotentCommandExecutor::execute(command, afterCommit)
       ├─ command:      ApprovePaymentAction::executeWithinTransaction()
       │                  └─ [DENTRO DE TRANSACCIÓN]
       │                       ├─ mutations (payment, order, numbers, entries, allocations)
       │                       ├─ GameEvent::create (PaymentApproved + NumberSold)
       │                       └─ RecordOutboxEventAction::execute('payment_approved', ...)
       │                            └─ INSERT INTO outbox_events ... ON CONFLICT DO NOTHING
       │                       ← COMMIT (todo o nada)
       └─ afterCommit:  PaymentApproved::dispatch()  ← legacy best-effort (sin cambios)
                        GameNumbersSold::dispatch()  ← legacy best-effort (sin cambios)
```

El evento de outbox se inserta **dentro** de la misma transacción del dominio.  
Los dispatches legacy del `afterCommit` permanecen sin modificar (best-effort, sin garantía de entrega).

### B.6 Estado del dispatcher en Fase 8.2

`OutboxEventDispatcher::handlePaymentApproved()` registra en log (`outbox.payment_approved.delivered`) la intención de entrega pero **no envía ninguna notificación real**. El proveedor real (email, WhatsApp) llega en Fase 9.

Solo `payment_approved` está registrado en el `match`. Cualquier otro `event_type` lanza `RuntimeException` → el procesador marca `failed_at`.

### B.7 Restricciones vigentes en Fase 8.2

No implementado (pendiente Fases 8.3 / 9):

- `PaymentRejected`, `OrderRefunded`, `WinnerPayoutRegistered`, `GameWinnerDeclared`
- Notificaciones reales (email provider, WhatsApp)
- Panel admin de outbox, métricas, cleanup histórico
- Multi-queue, prioridad
- Webhooks externos
