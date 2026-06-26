# Fase 2 — Commerce (rifas)

## 1. Alcance

Fase 2 implementa el ciclo comercial completo sobre la base de RepeatNumberBingo
(Fase 1). El módulo `App\Modules\Commerce` cubre:

- Reserva múltiple atómica de números con TTL.
- Submisión de evidencia de pago manual a almacenamiento privado.
- Aprobación / rechazo administrativo.
- Cancelación por parte del jugador.
- Expiración automática por orden.
- Generación de participaciones confirmadas (`game_entries`) y enlace de venta
  (`purchase_allocations`).
- Consultas segregadas por audiencia (Player / Admin / Public).
- Descarga privada de evidencias por administradores.
- Auditoría append-only y eventos de dominio post-commit.
- Idempotencia para todas las operaciones de mutación.

Dirección de dependencias: `Commerce → RepeatNumberBingo` (nunca a la inversa).

---

## 2. Entidades y relaciones

| Tabla | Propósito |
|-------|-----------|
| `orders` | Cesta de reserva por usuario y partida (UUID v7). |
| `order_items` | Línea por número reservado (snapshot de precio unitario). |
| `number_reservations` | Apartado fuerte de un `game_number` mientras la orden no expire/cancele. |
| `payments` | Un único `Payment` por `Order` (UNIQUE order_id). |
| `payment_documents` | Evidencias subidas al pago. |
| `game_entries` | Participación confirmada (creada en la aprobación). |
| `purchase_allocations` | Enlace 1-1 entre `order_item` y `game_entry`. |
| `idempotency_keys` | Claves HTTP `Idempotency-Key` por usuario+método+path. |

Aristas (FKs):

```
orders ──< order_items >── game_numbers ──< number_reservations >── orders
orders ── payment (1-1)
payment ──< payment_documents
order_items ── purchase_allocation (1-1) ── game_entry
```

---

## 3. Estados y transiciones

### `OrderStatus`

```
pending ──► payment_submitted ──► paid
   │             │                  │
   │             └── (admin reject) ┘──► rejected
   │
   ├── (player) ──► cancelled
   └── (TTL)    ──► expired
```

Reglas garantizadas en código:

- `pending` es el único estado desde el que se acepta `cancel` o `submit_evidence`.
- `payment_submitted` **no** expira jamás (la query del batch filtra `status=pending`).
- `paid`, `rejected`, `expired`, `cancelled`, `refunded` son terminales.

### `PaymentStatus`

```
pending ──► under_review ──► approved
              │                │
              └─► rejected ◄───┘ (no se vuelve a aprobar)
pending ──► cancelled  (sigue al order)
```

### `GameNumberStatus`

```
available ──► reserved ──► sold
                │
                └──► available  (reject / expire / cancel)
```

Transiciones prohibidas (validadas por `transitionTo()` en el modelo):

- `available → sold`
- `sold → available`
- `payment_submitted → expired`
- Aprobar un pago sin evidencia.
- Rechazar un pago ya aprobado.
- Cancelar una orden bajo revisión.
- Venta o expiración parcial de una orden.

---

## 4. Flujo de reserva

`POST /api/v1/games/{game}/reservations` (`ReserveGameNumbersAction`).

1. Lock `Game` → validar `sales_open`, snapshot de `ticket_price_cents` y `currency`.
2. Lock `GameNumbers` solicitados (`ORDER BY id FOR UPDATE`).
3. Validar pertenencia y disponibilidad bajo lock.
4. Calcular totales del lado del servidor.
5. Crear `Order` con `expires_at = now() + commerce.reservation.ttl_minutes`.
6. Crear `OrderItems`, `NumberReservations`, `Payment(pending)`.
7. Transicionar `GameNumber → reserved`.
8. Audit `NumberReserved` (1 fila por orden).
9. Tras commit → dispatch `GameNumbersReserved`.

Garantía de exclusividad: `UNIQUE(number_reservations.game_number_id)`.
Idempotencia: `Idempotency-Key` HTTP + `IdempotentCommandExecutor`.

---

## 5. Flujo de evidencia

`POST /api/v1/me/orders/{order}/payment-evidence` (`SubmitPaymentEvidenceAction`).

1. Validar MIME real (finfo + sniff RIFF/WEBP) — no se confía en `mime_type` del cliente.
2. Streaming SHA-256 + tamaño.
3. Persistir bytes a `payment_evidences` (disco privado, path UUID-v7).
4. En transacción: lock Order → lock Payment → crear `PaymentDocument` →
   transicionar Order a `payment_submitted` y Payment a `under_review` →
   limpiar `expires_at` → audit `PaymentEvidenceSubmitted`.
5. En caso de fallo posterior al `put()`: `safelyDelete()` (con `report()` si
   falla) y release de la idempotency key (`safelyRelease()`) — nunca reemplaza
   la excepción original.

Constraint anti-duplicado: `UNIQUE(payment_documents.payment_id, sha256)`.

---

## 6. Flujo de aprobación

`POST /api/v1/admin/payments/{payment}/approve` (`ApprovePaymentAction`).

Orden canónico de locks: **Order → Payment → OrderItems → NumberReservations → GameNumbers**.

Reglas:
- Sólo desde `Payment.under_review`. Si ya `approved`, devuelve `wasTransitionApplied=false`
  reconstruyendo el resultado desde tablas operativas.
- Crea `GameEntry(confirmed)` y `PurchaseAllocation(order_item ↔ game_entry)` por línea.
- Transiciona `GameNumber → sold`, `Order → paid`, `Payment → approved`.
- Audit `PaymentApproved`. Post-commit: dispatch `PaymentApproved`.

---

## 7. Flujo de rechazo

`POST /api/v1/admin/payments/{payment}/reject` (`RejectPaymentAction`).

Orden canónico de locks: **Order → Payment → OrderItems → NumberReservations → GameNumbers**.
(OrderItems se bloquean por consistencia aunque no se muten.)

Reglas:
- Sólo desde `Payment.under_review`. Si ya `rejected`, idempotente
  reconstruyendo desde `order_items + game_numbers`.
- Suelta `GameNumber → available`, borra reservations, `Order → rejected`,
  `Payment → rejected` (con `rejection_reason`, `reviewed_by`, `reviewed_at`).
- Audit `PaymentRejected`. Post-commit: dispatch `PaymentRejected`.

---

## 8. Flujo de expiración

Batch `php artisan schedule:run` → `ExpirePendingOrdersJob` cada minuto.

`ExpireOrderAction` por orden:
- Orden canónico de locks: **Order → Payment → OrderItems → NumberReservations → GameNumbers**.
- Filtro de elegibilidad: `status = pending AND expires_at <= now()`.
- Si ya no es `pending` (carrera con submit/approve/reject/cancel) →
  `SkippedStateChanged` sin efecto.
- Si aplica: suelta números, borra reservations, `Order → expired`, `Payment → cancelled`.
- Audit `ReservationExpired`. Post-commit (sólo si el outcome es `Expired`):
  dispatch `OrderReservationsExpired`.

`ExpirePendingOrdersAction` (batch):
- `chunkById` sobre la query elegible.
- Cada orden corre en su propia transacción a través del Action por-orden.
- Errores individuales se `report()` con contexto y continúan con la siguiente
  orden — un fallo no detiene el batch.

---

## 9. Flujo de cancelación

`POST /api/v1/me/orders/{order}/cancel` (`CancelOrderAction`).

- Política: el dueño de la orden (`OrderPolicy::cancel`).
- Orden canónico de locks: **Order → Payment → OrderItems → NumberReservations → GameNumbers**.
- Sólo desde `Order.pending`. Si ya `cancelled`, devuelve `AlreadyCancelled` sin
  duplicar audit/event. Cualquier otro estado lanza `InvalidOrderTransition` (422).
- Suelta `GameNumber → available`, borra reservations, `Order → cancelled`,
  `Payment → cancelled`.
- Audit `GameCancelled` con `payload.event_subtype = order_cancelled_by_user`.
- Post-commit: dispatch `OrderCancelledByUser`.

---

## 10. Idempotencia

Cuatro capas, en orden de evaluación:

1. **HTTP `Idempotency-Key`** (header) — middleware `idempotent` + tabla
   `idempotency_keys` con `UNIQUE(user_id, request_method, request_path, key)`.
   Implementación: `INSERT ... ON CONFLICT DO NOTHING` evaluado vía
   `DB::affectingStatement()`.
2. **Estado** — cada Action revalida el estado bajo lock y, si ya está en el
   estado destino, reconstruye el resultado desde tablas operativas y devuelve
   `wasTransitionApplied=false`.
3. **Constraints PostgreSQL** — `UNIQUE` impide duplicados a nivel BD.
4. **Dispatch post-commit condicional** — `IdempotentCommandExecutor` invoca el
   callback `afterCommit` sólo cuando `wasTransitionApplied = true`, evitando
   reemisión de eventos en replays.

La auditoría crítica (`game_events`) se escribe **dentro de la transacción**.
Los `Domain Events` son efectos secundarios **posteriores al commit**.

---

## 11. Orden global de locks

| Action | Secuencia FOR UPDATE |
|--------|----------------------|
| `ReserveGameNumbersAction` | Game → GameNumbers (orden por id) |
| `SubmitPaymentEvidenceAction` | Order → Payment |
| `ApprovePaymentAction` | Order → Payment → OrderItems → NumberReservations → GameNumbers |
| `RejectPaymentAction` | Order → Payment → OrderItems → NumberReservations → GameNumbers |
| `ExpireOrderAction` | Order → Payment → OrderItems → NumberReservations → GameNumbers |
| `CancelOrderAction` | Order → Payment → OrderItems → NumberReservations → GameNumbers |

Todas las colecciones se bloquean en `ORDER BY id` determinista.
El grafo es acíclico y evita deadlocks entre operaciones concurrentes sobre la
misma orden.

Verificado por `tests/Integration/Commerce/PaymentActionsLockOrderTest.php`
(usa `DB::listen` para capturar cada `FOR UPDATE`).

---

## 12. Almacenamiento privado

- Disco `payment_evidences` (`local`, root `storage/app/payment_evidences`).
- **No** hay symlink público.
- Path estricto `${payment_id}/${doc_id}.${ext}` con UUID v7 — sin componentes
  del usuario.
- `disk` y `path` viven en `payment_documents` y **nunca** se aceptan desde la
  request.
- Validación de tipo: finfo + sniff RIFF/WEBP. Extensión derivada del MIME real,
  no del nombre cliente.
- SHA-256 streaming evita cargar el archivo entero en memoria.

Descarga: `GET /api/v1/admin/payments/{payment}/documents/{document}/download`.
- Policy: `PaymentPolicy::downloadDocument` (sólo admin).
- Valida `document.payment_id === payment.id` → 404 sin filtrar existencia.
- 404 controlado si el archivo no existe en disco.
- Headers:
  - `Cache-Control: no-store, private` (Symfony normaliza alfabéticamente)
  - `X-Content-Type-Options: nosniff`
  - `Content-Type` desde DB.

No existen endpoints `DELETE` para pagos, documentos o participaciones.

---

## 13. Auditoría y eventos

`game_events` es **append-only** (booted hook impide UPDATE/DELETE). Se usa
**únicamente como auditoría**. Ninguna operación normal lee de ahí.

Reconstrucción operativa garantizada:
- Aprobación / rechazo / cancelación / expiración: `order_items + game_numbers + payment`.
- Resultados y consultas: tablas operativas (`orders`, `payments`, `game_entries`).

Eventos de dominio (plain `Dispatchable`, **no** `ShouldDispatchAfterCommit` —
los dispatcha el Action explícitamente tras `DB::commit()`):

| Evento | Disparado por |
|--------|---------------|
| `GameNumbersReserved` | ReserveGameNumbersAction |
| `PaymentEvidenceSubmitted` | SubmitPaymentEvidenceAction |
| `PaymentApproved` | ApprovePaymentAction |
| `PaymentRejected` | RejectPaymentAction |
| `OrderReservationsExpired` | ExpireOrderAction |
| `OrderCancelledByUser` | CancelOrderAction |

Garantía exactly-once verificada en:
- `PaymentEventsReplayTest`, `ExpireOrderDispatchTest`, `ApprovePaymentTest`,
  `RejectPaymentTest`, `CancelOrderTest`, `ReserveGameNumbersTest`.

Fallos posteriores al commit (listener crash) se `report()` pero no revierten
estado ni ejecutan compensaciones destructivas.

---

## 14. Endpoints

### Player (`auth:sanctum`)

| Método | Path | Acción |
|--------|------|--------|
| `POST` | `/api/v1/games/{game}/reservations` | Reservar números |
| `POST` | `/api/v1/me/orders/{order}/payment-evidence` | Subir evidencia |
| `POST` | `/api/v1/me/orders/{order}/cancel` | Cancelar orden propia |
| `GET` | `/api/v1/me/reservations` | Listar reservas activas |
| `GET` | `/api/v1/me/orders` | Listar órdenes (filtro `status`) |
| `GET` | `/api/v1/me/orders/{order}` | Detalle de orden propia |
| `GET` | `/api/v1/me/entries` | Listar participaciones (filtro `game_id`) |

### Admin (`auth:sanctum + admin`)

| Método | Path | Acción |
|--------|------|--------|
| `POST` | `/api/v1/admin/payments/{payment}/approve` | Aprobar (idempotent) |
| `POST` | `/api/v1/admin/payments/{payment}/reject` | Rechazar (idempotent) |
| `GET` | `/api/v1/admin/payments` | Listar (filtro `status`) |
| `GET` | `/api/v1/admin/payments/{payment}` | Detalle |
| `GET` | `/api/v1/admin/payments/{payment}/documents/{document}/download` | Descarga privada |
| `GET` | `/api/v1/admin/orders` | Listar (filtros `status`, `game_id`) |
| `GET` | `/api/v1/admin/games/{game}/numbers` | Grid de números |

### Public (sin auth)

| Método | Path | Acción |
|--------|------|--------|
| `GET` | `/api/v1/public/games/{slug}/numbers` | `{id, number, status}` solamente |

Notas del contrato público de números:

- `id` es el UUID real de `game_numbers`.
- Ese mismo `id` debe enviarse dentro de `game_number_ids` al reservar.
- Conocer el UUID no sustituye autenticación, validación de pertenencia,
  disponibilidad, idempotencia ni concurrencia.
- El contrato no expone `game_id`, identidades, relaciones ni metadata sensible.

---

## 15. Policies

- `PaymentPolicy`: `approve`, `reject`, `view`, `viewAny`, `downloadDocument` → admin.
- `OrderPolicy`: `cancel` → dueño de la orden.

Endpoints público y `me/*` no requieren policy adicional: el listado público
nunca expone identidades, y `me/*` filtran por `user_id` actual.

---

## 16. Query Objects

Todos en `App\Modules\Commerce\Application\Queries`. Reglas comunes:

- Filtros validados por **allow-list** explícita (`ALLOWED_STATUS_FILTERS`).
- Orden por defecto **fijo en el código**; el cliente no puede elegir columna.
- Relaciones eager loaded internamente; el cliente no envía nombres de relaciones.
- Audiencia codificada en el namespace (Player / Admin / Public) y refuerzada
  por Resources separados (`Resources/Player`, `Resources/Admin`, `Resources/Public`).

| Query | Audiencia | Filtros | Orden | Eager load | Paginación |
|-------|-----------|---------|-------|------------|------------|
| `ListMyReservationsQuery` | Player | `user_id` (forzado), order.status ∈ {pending, payment_submitted} | `created_at desc` | `order`, `gameNumber.game` | sí, 20 |
| `ListMyOrdersQuery` | Player | `user_id` (forzado), opcional `status` ∈ allow-list | `created_at desc` | `payment`, `items` | sí, 20 |
| `GetMyOrderQuery` | Player | `id + user_id` | n/a | `payment`, `items.gameNumber`, `reservations`, `game` | n/a |
| `ListMyEntriesQuery` | Player | `user_id` (forzado), opcional `game_id` | `confirmed_at desc` | `game`, `gameNumber` | sí, 20 |
| `ListAdminPaymentsQuery` | Admin | opcional `status` ∈ allow-list | `submitted_at desc nulls last` | `order.game` | sí, 20 |
| `GetAdminPaymentDetailQuery` | Admin | `id` | n/a | `order.items.gameNumber`, `reviewer`, `documents.uploader` | n/a |
| `ListAdminOrdersQuery` | Admin | opcional `status`, opcional `game_id` | `created_at desc` | `user`, `payment`, `game` | sí, 20 |
| `ListGameNumbersAdminQuery` | Admin | `game_id` (route) | `number asc` | — (joins en controller) | **no** |
| `ListGameNumbersPublicQuery` | Public | `slug` válido (excluye Draft/Cancelled) | `number asc` | — | **no** |

**Listados sin paginar** (`ListGameNumbersAdminQuery`, `ListGameNumbersPublicQuery`):
el tamaño está acotado por `game.number_max - game.number_min`, configurado por
el admin a valores pequeños (típicamente decenas/cientos). Si esto cambia en el
futuro habrá que paginar; el doc-decision lo flaggea aquí.

Para el grid admin, la decoración `active_reservation` + `sold_entry` se hace
en el controller con **dos SELECTs adicionales** (`whereIn`) — no hay N+1.

---

## 17. Constraints PostgreSQL

| Tabla | Constraint |
|-------|------------|
| `number_reservations` | `UNIQUE(game_number_id)`, `UNIQUE(order_id, game_number_id)` |
| `game_entries` | `UNIQUE(game_number_id)` |
| `game_numbers` | `UNIQUE(game_id, number)` |
| `order_items` | `UNIQUE(order_id, game_number_id)` |
| `purchase_allocations` | `UNIQUE(order_item_id)`, `UNIQUE(game_entry_id)` |
| `payments` | `UNIQUE(order_id)` |
| `payment_documents` | `UNIQUE(payment_id, sha256)`, `UNIQUE(disk, path)` |
| `idempotency_keys` | `UNIQUE(user_id, request_method, request_path, key)` |

Todas son **defensa en profundidad** detrás del lock canónico.

---

## 18. Configuración de Redis y scheduler

Producción y desarrollo (Docker):

```env
QUEUE_CONNECTION=redis
CACHE_STORE=redis
REDIS_HOST=redis
REDIS_PORT=6379
```

Ambos deben apuntar al **mismo Redis** — `QUEUE_CONNECTION` indica dónde se
ejecuta el job, `CACHE_STORE` indica dónde viven el lock `ShouldBeUnique` y el
mutex del scheduler.

Scheduler en [routes/console.php](../routes/console.php):

```php
Schedule::job(ExpirePendingOrdersJob::class)
    ->everyMinute()
    ->withoutOverlapping(2);   // MINUTOS — mutex del scheduler
```

```php
final class ExpirePendingOrdersJob implements ShouldBeUnique {
    public int $uniqueFor = 60;    // SEGUNDOS — TTL del lock de cola
}
```

Test environment (`phpunit.xml`): `QUEUE_CONNECTION=sync`, `CACHE_STORE=array`
— la suite estándar no requiere Redis. Comportamiento Redis-específico iría a
una suite opcional separada.

Si el deploy escala a múltiples cajas con `schedule:run`, evaluar
`->onOneServer()` (requiere caché compartida ya en su sitio).

---

## 19. Estrategia de pruebas

**313 tests, 863 assertions, todas en verde.**

Estructura:

- `tests/Unit/` — modelos, enums, VOs, factories de datos puros.
- `tests/Feature/` — endpoints HTTP completos con Sanctum, validan policies,
  resources, formas de response, idempotencia HTTP, dispatch de eventos.
- `tests/Integration/` — concurrencia PostgreSQL real, orden de locks vía
  `DB::listen`, immutabilidad de tablas append-only, comportamiento del batch
  bajo carrera con submit/approve.

Cobertura crítica del módulo Commerce:

| Aspecto | Test |
|--------|------|
| Lock canónico approve/reject | `Integration/Commerce/PaymentActionsLockOrderTest` |
| Concurrencia approve+expire / reject+expire | `Integration/Commerce/ExpireOrderConcurrencyTest` |
| Replay de aprobación / rechazo sin doble efecto | `Feature/Commerce/PaymentEventsReplayTest` |
| Replay reserva | `Feature/Commerce/ReserveGameNumbersTest` |
| Replay evidencia | `Feature/Commerce/SubmitPaymentEvidenceTest` |
| Replay cancelación | `Feature/Commerce/CancelOrderTest` |
| Replay expiración | `Feature/Commerce/ExpireOrderTest`, `ExpireOrderDispatchTest` |
| Privacidad public | `Feature/Commerce/PublicGameNumbersTest` |
| Privacidad player | `Feature/Commerce/PlayerQueriesTest` |
| Privacidad admin + cross-id download | `Feature/Commerce/DownloadPaymentDocumentTest`, `AdminQueriesTest` |
| Immutabilidad append-only | `Integration/Game/*ImmutabilityTest` |

---

## 20. Decisiones postergadas

Lista explícita de scope-out de Fase 2:

- **Múltiples intentos de pago por orden** — el modelo actual es 1 Payment / 1 Order.
- **Reembolsos** — `PaymentStatus::Refunded` existe en el enum pero no hay flujo.
- **Cancelación administrativa de órdenes bajo revisión** — sólo el dueño
  cancela, y sólo desde `pending`.
- **Limpieza de archivos huérfanos** en `payment_evidences` (los hay sólo si
  falla la compensación tras un crash entre `put()` y commit).
- **Triggers PostgreSQL de inmutabilidad** en `game_events` (hoy es booted-hook
  de Eloquent — defensa adicional a nivel BD pendiente).
- **Proveedor de pagos externo** (Stripe / Culqi / Niubiz).
- **Payouts** al organizador.
- **Broadcasting / WebSockets** para refrescar grid público en tiempo real.
- **Motor automático del juego** — sorteos, contadores, ganador, repartos.
  Materia de Fase 3.

---

## Apéndice — comandos de verificación

```bash
php artisan migrate:fresh
php artisan test --compact
vendor/bin/pint --dirty --format agent
php artisan route:list
php artisan schedule:list
```

Resultado al cierre de Fase 2 (2026-06-22):

- Migraciones aplicadas: 17.
- Suite: **313 passed / 863 assertions**.
- Pint: **passed**.
- Endpoints Commerce + RNB visibles: 22 (11 nuevos en Bloque 2.6).
- Scheduler: `ExpirePendingOrdersJob` cada minuto.
