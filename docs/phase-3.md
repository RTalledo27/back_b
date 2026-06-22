# Fase 3 — Motor de juego (rifas)

## 1. Alcance

Fase 3 implementa el motor de extracciones del `RepeatNumberBingo`:

- Inicio controlado de una partida (`SalesClosed → Running`).
- Sorteos con reemplazo: cada extracción es independiente y un número puede repetirse.
- Registro inmutable de cada extracción en `game_draws`.
- Conteo por número en `game_number_counters` (proyección reconstruible).
- Detección y declaración atómica del ganador.
- Un único ganador por partida.
- Finalización atómica (`Running → Resolving → Completed`).
- Reconstrucción de contadores desde el historial.
- Idempotencia por comando (`draw_commands`).
- API administrativa síncrona.
- Concurrencia segura con PostgreSQL.

**No incluido en esta fase** (ver §31): Jobs automáticos, broadcasting, payouts, frontend, publicación pública del ganador.

---

## 2. Decisiones de arquitectura

- **Monolito modular** `App\Modules\{Commerce, RepeatNumberBingo, Shared}` con dirección de dependencias `Commerce → RepeatNumberBingo` (RNB nunca importa Commerce).
- **Clean Architecture pragmática + DDD ligero**: Actions, DTOs readonly, Domain Events, Query Objects, Resources segregados.
- **Inversión de dependencias** para el readiness comercial: RNB define el puerto `GameStartReadinessChecker`, Commerce lo implementa.
- **Strategy** (`DrawNumberStrategy`) para el origen de aleatoriedad; producción usa `random_int()`, tests inyectan determinista.
- **Estado canónico vs proyección**: `game_draws` es fuente oficial inmutable; `game_number_counters` es derivada y reconstruible.
- **Game como mutex raíz** del motor: Start, Draw, Rebuild y Approve toman `games.id FOR UPDATE` primero.
- **Idempotencia interna**: `draw_commands` con `UNIQUE(game_id, command_id)`. El motor no reutiliza `idempotency_keys` de Commerce.
- **Sin Outbox** en esta fase — los Domain Events son posteriores al commit con manejo aislado de fallos por listener.

---

## 3. Entidades nuevas

| Tabla | Propósito | Append-only |
|-------|-----------|-------------|
| `game_draws` | Historial canónico de extracciones | Sí (ORM + booted) |
| `game_number_counters` | Proyección reconstruible `(hits_count, last_draw_sequence)` | No (rebuildable) |
| `game_winners` | Declaración del ganador (uno por juego) | Sí |
| `draw_commands` | Idempotencia del motor `(game_id, command_id)` | Sí (insert único final) |

Más columnas añadidas a `games`:
- `started_at TIMESTAMPTZ NULL` — momento del `SalesClosed → Running`.
- `completed_at TIMESTAMPTZ NULL` — momento del `Resolving → Completed`.

---

## 4. Migraciones

Total Fase 3: **12** migraciones (orden cronológico):

- `2026_06_22_080000_alter_games_add_started_completed_at`
- `2026_06_22_080001_alter_game_numbers_add_composite_unique`
- `2026_06_22_080002_alter_game_entries_add_composite_constraints`
- `2026_06_22_080003_create_game_draws_table`
- `2026_06_22_080004_create_game_number_counters_table`
- `2026_06_22_080005_create_game_winners_table`
- `2026_06_22_080006_create_draw_commands_table`
- `2026_06_22_080010_alter_game_numbers_add_number_composite_unique`
- `2026_06_22_080011_alter_game_entries_add_number_composite_unique`
- `2026_06_22_080012_alter_game_draws_add_number_composite_constraints`
- `2026_06_22_080013_alter_game_winners_add_number_composite_fks`
- `2026_06_22_080020_alter_game_events_type_check_add_counters_rebuilt`

Total proyecto (verificado con `php artisan migrate:status`): **28** migraciones — desglose:

| Bloque | Cantidad | Prefijos |
|--------|----------|----------|
| Base Laravel (users, cache, jobs) | 3 | `0001_01_01_*` |
| Fase 1 (auth + games + numbers + events) | 5 | `2026_06_20_09*` |
| Fase 2 (Commerce: orders, items, reservations, payments, documents, entries, allocations, idempotency) | 8 | `2026_06_20_134*` |
| Fase 3 (motor) | 12 | `2026_06_22_080*` |
| **Total** | **28** | |

---

## 5. Constraints PostgreSQL

UNIQUE:
- `game_draws (game_id, sequence)`
- `game_draws (id, game_id)` y `(id, game_id, game_number_id)`
- `game_number_counters (game_id, game_number_id)`
- `game_winners (game_id)`, `(game_entry_id)`, `(game_draw_id)`
- `draw_commands (game_id, command_id)`, `(game_draw_id)`
- `game_numbers (id, game_id)` y `(id, game_id, number)`
- `game_entries (id, game_id)` y `(id, game_id, game_number_id)`

CHECK:
- `game_draws.sequence > 0`, `drawn_number >= 1`
- `game_number_counters.hits_count >= 0`, `last_draw_sequence > 0 OR NULL`
- `game_winners.winning_hits > 0`
- `game_events.type` (lista cerrada incluyendo `counters_rebuilt`)

---

## 6. Foreign keys compuestas

Defensa estructural cross-game y cross-number:

```
game_entries  (game_number_id, game_id) → game_numbers(id, game_id)
game_draws    (game_number_id, game_id, drawn_number) → game_numbers(id, game_id, number)
counters      (game_number_id, game_id) → game_numbers(id, game_id)
game_winners  (game_entry_id, game_id, game_number_id) → game_entries(id, game_id, game_number_id)
game_winners  (game_draw_id, game_id, game_number_id) → game_draws(id, game_id, game_number_id)
game_winners  (game_number_id, game_id) → game_numbers(id, game_id)
draw_commands (game_draw_id, game_id) → game_draws(id, game_id)
```

Postgres rechaza directamente: drawn_number ≠ game_number.number, winner.entry ≠ winner.number, draws de otro juego, etc.

---

## 7. Índice parcial winner-único por juego

```sql
CREATE UNIQUE INDEX game_entries_one_winner_per_game
ON game_entries (game_id)
WHERE status = 'winner';
```

Cubre el caso "futura ruta que mute `GameEntry` por fuera del Action": como mucho una `Entry` con `status='winner'` por juego.

---

## 8. Estados y transiciones

```
Draft → Published → SalesOpen → SalesClosed → Running → Resolving → Completed
                                                  ↓
                                                Paused
Cualquiera (no terminal) → Cancelled
```

Fase 3 implementa:

- `SalesClosed → Running` (`StartGameAction`).
- `Running → Resolving → Completed` (`DrawGameNumberAction`, rama winner, dentro de la misma transacción).

**`Resolving` es una transición interna no observable externamente** durante esta fase. `RebuildGameNumberCountersAction` considera una fila persistida con `status=resolving` como corrupción.

---

## 9. Integración Commerce ↔ Start

Inversión de dependencias:

- `RepeatNumberBingo/Application/Contracts/GameStartReadinessChecker.php` define el puerto.
- `Commerce/Infrastructure/GameLifecycle/CommerceGameStartReadinessChecker.php` lo implementa.
- Binding en `AppServiceProvider::register()`.

Comportamiento: el checker requiere transacción activa, asume `Game FOR UPDATE` ya tomado por el caller, no abre transacción propia y acumula todas las razones antes de lanzar `GameNotReadyForStart`.

Razones estables:

```
has_pending_orders
has_payment_submitted_orders
has_pending_payments
has_under_review_payments
has_active_reservations
has_reserved_numbers
no_confirmed_entries
```

`ApprovePaymentAction` también lockea `games` primero (modificación cross-fase 3.3) → la carrera Approve ↔ Start se serializa sobre la misma fila y la invariante "ninguna venta tras `started_at`" queda garantizada.

---

## 10. Orden global de locks

| Operación | Secuencia FOR UPDATE |
|-----------|----------------------|
| Reserve (Fase 2) | Game → GameNumbers |
| Approve (Fase 2 + 3.3) | Game → Order → Payment → OrderItems → Reservations → GameNumbers |
| Reject / Expire / Cancel | Order → Payment → OrderItems → Reservations → GameNumbers (sin Game) |
| **Start (3.4)** | Game (readiness es read-only bajo el lock) |
| **Draw (3.5–3.6)** | Game → GameNumber → GameEntry; UPSERT counter, INSERT draw, INSERT command |
| **Rebuild (3.7)** | Game; SELECT game_draws, DELETE+INSERT counters |

`Game` es el mutex raíz del agregado RNB.

---

## 11. Inicio de partida

`StartGameAction` (Bloque 3.4):

1. `Game FOR UPDATE`.
2. Clasificación: corrupción / `Completed` consistente → `GameAlreadyCompleted`; idempotente `AlreadyStarted` si ya está `Running` con `started_at`.
3. Validar: `status=SalesClosed`, `started_at=null`, `completed_at=null`, `scheduled_start_at!=null`, `now() >= scheduled_start_at`.
4. `readiness.assertReadyForStart($gameId)` (`GameStartReadinessChecker`).
5. `$startedAt = CarbonImmutable::now()` (único, usado en columna, audit y evento).
6. `transitionTo(Running)`, `save()`.
7. `INSERT game_events(GameStarted)` dentro de la transacción.
8. Commit. Tras commit: `dispatch(GameStarted)` con `try/catch+report`.

Outcome: `Started` o `AlreadyStarted` (sin re-emisión de auditoría ni evento).

---

## 12. Readiness comercial

Cubierto por §9. Tests:

- `CommerceGameStartReadinessCheckerTest` (acumulación de razones, `LogicException` fuera de tx, no mutación).
- `StartGameReadinessTest` (Bloque 3.4 — varios bloqueadores activos).

---

## 13. Estrategia de sorteo

`DrawNumberStrategy` interface en `Application/Contracts`. Implementaciones:

- **Productiva**: `CryptographicallySecureDrawNumberStrategy` — `random_int($min, $max)`. CSPRNG (Linux `getrandom`, Windows `BCryptGenRandom`). Sin sesgo, sin `rand()` / `mt_rand()`.
- **Test**: `DeterministicDrawNumberStrategy` en `tests/Support/` — secuencia inyectable. Architectural test rechaza su presencia en `app/`.

Binding: `AppServiceProvider::register()` ata el contrato a la implementación criptográfica. Tests overridean vía `$this->app->instance(DrawNumberStrategy::class, ...)`.

**Limitación documentada**: `random_int()` es CSPRNG suficiente para integridad, pero no es commit-reveal ni verificable públicamente. La verificabilidad pública avanzada queda postergada.

---

## 14. Idempotencia mediante `draw_commands`

Tabla estructuralmente completa — no admite estados incompletos:

```
draw_commands (
    id UUID v7 PK,
    game_id UUID NOT NULL,
    command_id UUID NOT NULL,
    game_draw_id UUID NOT NULL,
    result_payload JSONB NOT NULL,
    completed_at TIMESTAMPTZ NOT NULL,
    created_at TIMESTAMPTZ NOT NULL
)
UNIQUE (game_id, command_id)
UNIQUE (game_draw_id)
```

Insertada como única fila completa al final de la transacción del Draw. Si la transacción falla, no queda fila.

`result_payload` versionado con `schema_version=1`; `wasReplay` no se persiste (representa la invocación, no el hecho histórico).

`X-Draw-Command-Id` obligatorio en HTTP. El controller **no** genera el ID si falta.

---

## 15. Flujo de extracción

`DrawGameNumberAction::execute()`:

```
BEGIN
  SELECT * FROM games WHERE id=? FOR UPDATE          -- root lock
  SELECT * FROM draw_commands WHERE (game_id, command_id)=?
    → si existe → DrawGameNumberResult::fromArray(payload, asReplay=true)
                  early return (sin Strategy, sin draw, sin counter, sin audit)
  classify game state                                -- Completed/corruption/etc.
  ensure no winner exists yet
  sequence = MAX(game_draws.sequence) + 1            -- bajo el lock raíz
  drawnNumber = $drawStrategy->generate(min, max)
  validate drawnNumber ∈ [min, max]                  -- defensa
  SELECT * FROM game_numbers WHERE (game_id, number)=? FOR UPDATE
  SELECT * FROM game_entries WHERE game_number_id=? FOR UPDATE
  validateParticipation()                            -- determina numberIsSold
  $drawnAt = CarbonImmutable::now()
  INSERT INTO game_draws (...)                       -- fuente oficial primero
  UPSERT game_number_counters RETURNING hits_count, last_draw_sequence
  branch:
    no entry + hits == required  → audit UnownedNumberReachedThreshold
    sold + entry + hits < required → continue
    sold + entry + hits == required → resolveWinner($completedAt = now())
    sold + entry + hits > required → GameParticipationIntegrityViolation
  audit (if applicable, dentro de tx)
  build result
  INSERT INTO draw_commands (id, command_id, game_draw_id, result_payload, completed_at)
COMMIT

post-commit:
  dispatchSafely(GameNumberDrawn)
  if winner:
    dispatchSafely(GameWinnerDeclared)
    dispatchSafely(GameCompleted)
```

---

## 16. Historial oficial `game_draws`

- Append-only (booted hook + `UPDATED_AT=null`).
- `UNIQUE(game_id, sequence)` garantiza monotonicidad.
- `sequence > 0` (CHECK).
- FK compuesta de 3 cols protege `drawn_number == game_numbers.number`.
- Insertado **antes** que la proyección counter (orden semántico explícito).

---

## 17. Proyección `game_number_counters`

- Reconstruible 100% desde `game_draws`.
- `UPSERT` atómico:

```sql
INSERT INTO game_number_counters (...) VALUES (..., 1, :seq, ...)
ON CONFLICT (game_id, game_number_id) DO UPDATE SET
    hits_count = game_number_counters.hits_count + 1,
    last_draw_sequence = EXCLUDED.last_draw_sequence,
    updated_at = NOW()
RETURNING hits_count, last_draw_sequence
```

- Validación inline: `last_draw_sequence === sequence` post-upsert.

---

## 18. Resolución del ganador

`DrawGameNumberAction::resolveWinner()` (dentro de la misma transacción del draw):

1. Revalidar entry (`Confirmed`, mismo game / number).
2. `$completedAt = CarbonImmutable::now()` (timestamp de evaluación, distinto de `$drawnAt`).
3. `entry->transitionTo(Winner)`, save.
4. `GameWinner::create(...)` con `won_at = $completedAt`.
5. `game->transitionTo(Resolving)`, save → `transitionTo(Completed)`, `completed_at = $completedAt`, save.
6. Audits: `WinningNumberDetected`, `WinnerDeclared`, `GameCompleted`.

Invariante temporal: `draw.drawn_at <= winner.won_at` y `winner.won_at == game.completed_at`.

Sin campos financieros en `game_winners` (ni premio, ni moneda, ni payout).

---

## 19. Replay del draw ganador

El paso 2 del flujo (lookup en `draw_commands`) se ejecuta **antes** del classify state. Esto permite que un replay del comando ganador funcione aunque la partida esté ya `Completed`:

- `wasReplay=true`.
- `winner_created=true`, `winner_entry_id`, `current_hits=hits_required`, `game_status=completed`, `drawn_at` históricos.
- **Cero** eventos.

Un comando **nuevo** después de `Completed` → `GameAlreadyCompleted` (422).

---

## 20. Reconstrucción de counters

`RebuildGameNumberCountersAction` (Bloque 3.7):

1. `Game FOR UPDATE`.
2. Leer `game_draws` (sin lock — append-only + Game lock serializa).
3. Verificar integridad del historial (`MIN(sequence)=1`, `MAX=count`, sin huecos).
4. Construir mapa esperado: `(game_number_id => [hits_count, last_draw_sequence])`.
5. Leer y normalizar mapa actual.
6. **Verificar lifecycle y winner** (rechaza si Draws antes de `started_at`, completed sin Winner, etc. — §31).
7. Si `current === expected` → `AlreadyConsistent` (sin escrituras).
8. Si difieren: `DELETE counters`, bulk INSERT (chunks 500), re-leer y re-comparar exactamente.
9. Audit `CountersRebuilt` solo en Rebuilt.

Outcomes:

- `Rebuilt` → audit + Domain Event.
- `AlreadyConsistent` → cero escrituras, cero audit, cero event.

**No modifica** `game_draws`, `draw_commands`, `game_winners`, `game_entries`, ni timestamps del Game.

---

## 21. Auditorías

Todas dentro de la transacción que origina el hecho:

| Tipo | Disparador | Una vez por… |
|------|------------|--------------|
| `game_started` | StartGameAction | inicio fresco |
| `unowned_number_reached_threshold` | DrawGameNumberAction | la igualdad exacta del counter |
| `winning_number_detected` | DrawGameNumberAction (rama winner) | resolución |
| `winner_declared` | idem | resolución |
| `game_completed` | idem | resolución |
| `counters_rebuilt` | RebuildGameNumberCountersAction | outcome Rebuilt |

`number_drawn` **no** se emite (cada draw vive en `game_draws`, que ya es append-only y indexado).

Payloads no incluyen `email`, `name`, `phone`, `amount`, `price`, `document_path`, `account`.

---

## 22. Domain Events

Plain `Dispatchable` (no `ShouldDispatchAfterCommit`), despachados explícitamente tras `COMMIT`:

- `GameStarted`
- `GameNumberDrawn`
- `GameWinnerDeclared`
- `GameCompleted`
- `GameCountersRebuilt`

`dispatchSafely()` aisla cada dispatch (un listener fallido en `GameNumberDrawn` no impide `GameWinnerDeclared` ni `GameCompleted`).

**Replays** (`wasReplay=true` o `AlreadyStarted` / `AlreadyConsistent`) **no** despachan.

---

## 23. Limitación de entrega no durable

`dispatchSafely` garantiza:

- Commit antes del dispatch.
- Listener fallido se reporta y no revierte estado.
- Replays no re-emiten.

**No implementado** (postergado a infraestructura):

- **Entrega durable exactly-once ante crash entre `COMMIT` y `dispatch()`**. Si el proceso muere ahí, el evento no se entrega y no hay reintento automático.
- **Outbox** o equivalente.

Los audits dentro de la transacción siguen siendo la fuente operativa.

---

## 24. API administrativa

Seis endpoints (rutas con nombre):

| Método | Path | Nombre | Status |
|--------|------|--------|--------|
| POST | `/api/v1/admin/games/{game}/start` | `admin.games.start` | 200 (started / already_started) |
| POST | `/api/v1/admin/games/{game}/draws` | `admin.games.draws.store` | 201 nuevo / 200 replay |
| POST | `/api/v1/admin/games/{game}/counters/rebuild` | `admin.games.counters.rebuild` | 200 (rebuilt / already_consistent) |
| GET | `/api/v1/admin/games/{game}/draws` | `admin.games.draws.index` | 200 paginado |
| GET | `/api/v1/admin/games/{game}/counters` | `admin.games.counters.index` | 200 paginado |
| GET | `/api/v1/admin/games/{game}/winner` | `admin.games.winner.show` | 200 / 404 |

Todos en `auth:sanctum + admin`. Sin middleware `idempotent` (la idempotencia del draw vive en `draw_commands` + `X-Draw-Command-Id`).

`X-Draw-Command-Id` obligatorio en `POST /draws`: header ausente o UUID inválido → 422.

---

## 25. Policies

`GamePolicy::start`, `draw`, `rebuildCounters`, `viewDraws`, `viewCounters`, `viewWinner`. Todas validan **solo** identidad/rol (`$user->isAdmin()`). Sin lógica de estado, fechas, readiness, integridad — esas reglas viven en Actions y Queries.

---

## 26. Query Objects y filtros

| Query | Filtros (allow-list) | Orden | Paginado |
|-------|----------------------|-------|----------|
| `ListGameDrawsQuery` | `number`, `sequence_from/to`, `drawn_from/to` | `sequence ASC` | Sí (default 50, max 100) |
| `ListGameNumberCountersQuery` | `number_from/to`, `min_hits`, `max_hits`, `status` | `number ASC` | Sí (default 50, max 100) |
| `GetGameWinnerQuery` | `game_id` (route) | n/a | n/a (eager `gameNumber:id,number`, `draw:id,sequence`) |

`game_id` **nunca** se acepta como filtro — siempre proviene del route binding. Validaciones cross-range en FormRequests rechazan `from > to` con 422.

---

## 27. Paginación

- Estructura estándar Laravel: `data`, `links`, `meta`.
- `per_page` clampeado entre 1 y 100 en FormRequest y Query Object.
- `meta.total` calculado solo dentro del `game_id` de la ruta — verificado por `AdminEngineGameIsolationTest`.

---

## 28. Privacidad

Resources segregados (Admin only en esta fase):

- Sin email, nombre, teléfono, dirección.
- Sin monto del premio, moneda, payout, cuenta bancaria.
- Sin `result_payload` interno del DrawCommand.
- Sin `order_id`, `payment_id`, `document_id`, `disk`, `path`.

Verificado por `test_winner_returns_resource_without_pii` y `test_start_returns_200_and_resource_without_commerce_fields`.

---

## 29. Códigos HTTP

| Excepción | Status | Error code |
|-----------|--------|-----------|
| `GameAlreadyCompleted` | 422 | `game_already_completed` |
| `GameHasNoScheduledStart` | 422 | `game_has_no_scheduled_start` |
| `GameStartTooEarly` | 422 | `game_start_too_early` |
| `GameNotReadyForStart` | 422 | `game_not_ready_for_start` + `reasons` |
| `InvalidDrawCommandId` | 422 | `invalid_draw_command_id` |
| `GameNotAcceptingPayments` (Commerce) | 422 | `game_not_accepting_payments` |
| `GameLifecycleIntegrityViolation` | 409 | `game_lifecycle_integrity_violation` |
| `GameParticipationIntegrityViolation` | 409 | `game_participation_integrity_violation` |
| `RebuildIntegrityViolation` | 409 | `rebuild_integrity_violation` |
| `DrawnNumberOutOfRange` | 500 | `internal_engine_error` (`report()` + mensaje genérico) |

Mensajes de integridad no exponen contexto interno; el detalle queda en logs.

---

## 30. Estrategia de pruebas y concurrencia

**545 tests, 2510 assertions, todos verde** al cierre de Fase 3.

Cobertura por capa:

- **Unit** — Enums, VOs, DTOs, outcomes, mapeos.
- **Architecture** — RNB no importa Commerce, no `rand()`/`mt_rand()`, sin Repository genérico, runner determinista solo en `tests/Support`, sin guarda temporal Phase-3.5.
- **Feature** — Action flows, idempotencia, endpoints HTTP, autorización, allow-lists, privacy, response shape, lifecycle invariants.
- **Integration** — Lock order (DB::listen), rollback con triggers PostgreSQL temporales, listener-isolation, `LogicException` fuera de tx, FKs compuestas cross-game.
- **Concurrencia real con dos procesos PHP** (`Symfony\Component\Process`): Approve↔Start, Start↔Start, Draw↔Draw, Winner↔Winner, Rebuild↔Draw. Runner protegido contra DB no-test (`tests/Support/run-engine-action.php`).

---

## 31. Decisiones postergadas

- **Sorteos automáticos mediante Job + scheduler** (Fase 4).
- **Redis como coordinador adicional del motor** (Fase 4).
- **Broadcasting / Reverb** (Fase 4 o posterior).
- **Endpoint público de historial / counters / winner** (Fase 4).
- **Publicación pública del ganador** (Fase 4 + UX/legal).
- **Frontend** (fuera del scope del backend).
- **Payouts** y evidencia de pago al ganador (Fase 5+).
- **Outbox para eventos durables** (infraestructura).
- **Verificabilidad pública / commit-reveal** del sorteo.
- **Pausa y reanudación operativa** del motor (enum admite `Paused` pero sin caso de uso).
- **Cancelación de una partida iniciada** (enum admite `Cancelled` con `started_at != null`; rebuild ya verifica esa rama).
- **Triggers PostgreSQL de inmutabilidad** (defense in depth — hoy solo booted hooks + tests).
- **Hardening adicional de auditoría** (firma, hash chain, etc.).

---

## Invariantes confirmadas

### Propiedad antes de iniciar
- Una partida no puede iniciar mientras existan órdenes pendientes, pagos pendientes/under_review, reservas activas, números reserved, ni cero entries confirmadas.
- La garantía no proviene de que el readiness checker bloquee esas filas: las consulta en modo lectura **después** de que el caller (`StartGameAction`) ya tomó `games FOR UPDATE`. La invariante se sostiene porque `Approve`, `Reserve` y `Start` se serializan sobre la misma fila `games` (mutex raíz), de modo que readiness observa estado estable. `Reject`, `Cancel` y `Expire` solo liberan propiedad, por lo que el peor escenario es un **falso negativo temporal** del inicio (el administrador reintenta).

### Sorteo
- Cada comando nuevo produce máximo un Draw.
- El mismo command ID devuelve el snapshot histórico.
- El mismo número puede salir repetidamente.
- `game_draws` es la fuente oficial.
- Counters son reconstruibles.
- Toda extracción bloquea primero Game.

### Ganador
- Exactamente un Winner por juego (UNIQUE + partial unique index).
- Exactamente una Entry winner por juego.
- Entry, Draw, GameNumber y Winner pertenecen al mismo juego y número (FKs compuestas).
- El ganador aparece exactamente cuando alcanza `hits_required`.
- El Draw ganador es el último.
- Juego finaliza en `completed`.
- `winner.won_at = game.completed_at`.
- `drawn_at <= won_at`.

### Rebuild
- Nunca modifica Draws, Winner, Entry, Game state ni timestamps.
- Solo reemplaza counters si la proyección difiere.
- Historial incompatible (huecos, draws antes de `started_at`, draws después de `completed_at`, winner-mismatch, estado `resolving`, completed sin timestamps) provoca rollback.

---

## Auditoría final del código

- ✅ RNB no importa Commerce (`Phase32EngineArchitectureTest`).
- ✅ Commerce implementa el puerto de readiness (`AppServiceProvider` binding).
- ✅ Actions no dependen de HTTP (`DrawGameNumberActionArchitectureTest`, `RebuildActionArchitectureTest`).
- ✅ Controllers delgados (delegan a Actions / Queries).
- ✅ Queries no mutan estado (read-only).
- ✅ Resources solo transforman (no DB).
- ✅ Sin Service Locator.
- ✅ Sin Repository genérico.
- ✅ Sin Pipeline innecesario.
- ✅ Strategy determinista solo en `tests/Support` (architectural test).
- ✅ Sin `rand()` / `mt_rand()` (architectural test).
- ✅ Sin guarda temporal Fase 3.5 (`NoPhase35TemporaryGuardArchitectureTest`).
- ✅ Sin campos financieros en `game_winners`.
- ✅ Sin reutilización de `idempotency_keys` de Commerce — `draw_commands` aislado en RNB.

---

## Apéndice — comandos de verificación

```bash
php artisan migrate:fresh
php artisan route:list
php artisan test --compact
vendor/bin/pint --dirty --format agent
```

Resultado al cierre de Fase 3 (2026-06-22):

- Migraciones aplicadas: **28** (verificado con `php artisan migrate:status`).
- Endpoints `/api/v1/*`: **30** (incluye los 6 nuevos del motor).
- Suite: **547 passed / 2516 assertions** tras añadir los dos tests adicionales del contrato `per_page`.
- Pint: **passed**.

### Contrato definitivo de `per_page`

| Valor | Endpoint draws / counters |
|-------|---------------------------|
| omitido | 50 (default) |
| 1 ≤ n ≤ 100 | aceptado |
| 100 | aceptado |
| 0, negativos, > 100 | **422** con `assertJsonValidationErrors(['per_page'])` |

Los Form Requests rechazan explícitamente fuera de `[1, 100]`. Los Query Objects conservan `clamp(1, 100)` como defensa interna por si fueran invocados fuera de HTTP, pero **no** corrigen silenciosamente entradas HTTP — el rechazo es duro a nivel Presentation.
