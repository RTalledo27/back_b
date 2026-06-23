# Fase 4 — Motor automático, lectura pública y broadcasting

## 1. Propósito

La Fase 4 automatiza la ejecución de sorteos de `RepeatNumberBingo` sin cambiar
la fuente de verdad: PostgreSQL sigue siendo el estado canónico. El scheduler,
los jobs, la API pública y el broadcasting son capas de coordinación o lectura;
el historial oficial de números sorteados continúa en `game_draws` y la
proyección reconstruible continúa en `game_number_counters`.

No se implementan frontend, pagos, payouts, compra de cartones, autenticación de
jugadores, canales privados, presencia ni Outbox.

## 2. Campos de scheduling

`games` incorpora los campos operativos del motor:

| Campo | Uso |
| --- | --- |
| `draw_interval_seconds` | Tamaño de la grilla temporal de sorteos automáticos. |
| `auto_draw_enabled` | Habilita o deshabilita el motor automático por partida. |
| `started_at` | Inicio real de ejecución de la partida. |
| `paused_at` | Última pausa operativa o automática. |
| `resumed_at` | Última reanudación operativa. |
| `completed_at` | Cierre real cuando existe ganador. |
| `next_draw_at` | Próximo tick elegible para dispatch. |
| `last_consumed_tick_at` | Último tick ejecutado y confirmado. |

La configuración administrativa queda bloqueada al iniciar cuando la
automatización está activa.

## 3. Grilla temporal y UUID v5

La grilla se calcula desde el tick actual y `draw_interval_seconds`. Un tick
vence cuando `next_draw_at <= now()`.

Cada tick genera un `command_id` determinístico con UUID v5 usando:

```text
namespace = ENGINE_DRAW_COMMAND_NAMESPACE
name      = game_id|scheduled_draw_at
```

La combinación `game_id + command_id`, el `ShouldBeUnique` del job y la
restricción única de `draw_commands` hacen que el mismo tick sea reproducible y
seguro ante reintentos. Si el comando ya existe, el resultado se reconstruye
desde `draw_commands.result_payload` y se marca como replay.

## 4. Dispatcher y scheduler

`routes/console.php` programa `DispatchDueGameDrawsJob` con la cadencia
`ENGINE_DISPATCH_POLL_SECONDS` y `withoutOverlapping(1)`.

Flujo:

1. El scheduler dispara `DispatchDueGameDrawsJob`.
2. `DispatchDueGameDrawsAction` lista partidas `running`, con
   `auto_draw_enabled=true` y `next_draw_at` vencido.
3. Cada candidata se revalida bajo `FOR UPDATE SKIP LOCKED`.
4. Se crea un `EngineTick` con `game_id`, `scheduled_at` y `command_id`.
5. Se encola un `ExecuteScheduledGameDrawJob` por tick.

El dispatcher no modifica calendario, estado, sorteos ni contadores.

## 5. Ejecución transaccional

`ExecuteScheduledGameDrawAction` es el único consumidor del tick programado:

1. Abre transacción.
2. Bloquea primero `Game`.
3. Revisa replay por `draw_commands`.
4. Valida que el tick todavía corresponda al `next_draw_at` actual.
5. Ejecuta `DrawGameNumberAction::executeWithinTransaction()`.
6. Persiste el Draw en `game_draws`.
7. Actualiza la proyección `game_number_counters`.
8. Declara ganador y completa la partida si corresponde.
9. Calcula el siguiente `next_draw_at` o `null` si hubo ganador.
10. Inserta auditoría agregada `engine_ticks_skipped` cuando aplica.
11. Actualiza `last_consumed_tick_at` y `next_draw_at`.
12. Confirma la transacción.
13. Emite eventos internos y actualización pública después del commit.

Draw, avance del calendario y auditoría agregada se confirman juntos en la
misma transacción.

## 6. Idempotencia, replay y `skip_to_next`

Un replay del mismo tick:

- no ejecuta la estrategia de sorteo;
- no inserta otro Draw;
- no modifica counters;
- no mueve calendario;
- no emite broadcast público.

La política de catch-up soportada es `skip_to_next`: si el proceso llega tarde,
ejecuta el tick seleccionado y avanza a la primera posición de grilla
estrictamente futura. Los ticks intermedios no se ejecutan individualmente; se
registran en una única auditoría `engine_ticks_skipped` con:

- `policy`;
- `command_id`;
- `consumed_tick_at`;
- `first_skipped_at`;
- `last_skipped_at`;
- `next_draw_at`;
- `skipped_ticks`.

## 7. Clasificación de fallos y auto-pausa

`ExecuteScheduledGameDrawJob` clasifica excepciones así:

| Tipo | Ejemplos | Comportamiento |
| --- | --- | --- |
| Esperadas | juego inexistente, ya completado, automatización activa/inactiva, transición inválida esperada | Se registra y termina sin retry. |
| Transitorias | errores no clasificados como esperados ni integridad | Se relanza para retry del job. |
| Integridad | violaciones de lifecycle/participación, número fuera de rango, versión de replay inválida, configuración inválida, transiciones internas inválidas, unique/SQLSTATE `23*` | Se reporta, se auto-pausa y termina sin retry infinito. |

Las excepciones desconocidas no se tratan como integridad por defecto: quedan en
la ruta transitoria y se relanzan.

La auto-pausa ocurre después del rollback del Draw fallido, en una transacción
nueva. Bloquea primero `Game`, solo aplica si la partida sigue `running`,
`auto_draw_enabled=true` y `next_draw_at` corresponde al mismo tick. Si otra
ejecución ya pausó la partida, el outcome es idempotente y no duplica
`game_auto_paused`.

## 8. REST público

La recuperación del cliente se hace por REST. Endpoints públicos de solo lectura:

```text
GET /api/v1/public/games/{slug}
GET /api/v1/public/games/{slug}/draws
GET /api/v1/public/games/{slug}/number-counters
```

Exponen solo estado público: status, configuración pública mínima, último Draw,
historial, counters, ganador público y `next_draw_at` cuando la automatización
está activa. Una partida inexistente o no pública responde 404 sin filtrar su
existencia.

Las fechas salen en UTC con formato ISO 8601. Los recursos públicos no exponen
IDs internos, auditoría, command IDs, metadata interna, datos privados de
usuarios ni errores del motor.

## 9. Broadcasting público best-effort

El canal público es:

```text
games.{slug}
```

El evento estable es:

```text
public.game.updated.v1
```

Payload público:

```text
schema_version
reason
game_slug
status
occurred_at
latest_draw
next_draw_at
winner
```

Razones actuales: `started`, `number_drawn`, `paused`, `resumed`. Un Draw
ganador usa `number_drawn`; el snapshot ya refleja `status=completed`, ganador y
`next_draw_at=null`.

Los broadcasts se disparan después del commit, no serializan modelos Eloquent y
no escriben filas adicionales para entregar el evento. Si falla el broadcasting,
el error se reporta y el estado confirmado no se revierte.

## 10. Configuración

Variables:

```env
ENGINE_DRAW_INTERVAL_MIN=10
ENGINE_DRAW_INTERVAL_MAX=3600
ENGINE_DISPATCH_POLL_SECONDS=15
ENGINE_DISPATCH_BATCH_SIZE=200
ENGINE_CATCH_UP_POLICY=skip_to_next
ENGINE_DRAW_COMMAND_NAMESPACE=a1b2c3d4-e5f6-4789-abcd-ef0123456789
```

`ENGINE_DISPATCH_POLL_SECONDS` debe ser uno de:

```text
1, 2, 5, 10, 15, 20, 30
```

## 11. Operación y recuperación

Comandos útiles:

```bash
php artisan route:list
php artisan schedule:list
php artisan schedule:work
php artisan schedule:interrupt
php artisan queue:work
php artisan test --compact
vendor/bin/pint --dirty --format agent
```

Estrategia de recuperación:

1. Corregir la causa de integridad en PostgreSQL.
2. Reanudar la partida con la acción administrativa de resume.
3. El calendario se realinea desde la grilla y el siguiente tick vuelve a pasar
   por `ExecuteScheduledGameDrawAction`.
4. El cliente puede reconectarse y recuperar el snapshot completo por REST.

## 12. Garantías y no garantías

Garantías implementadas:

- PostgreSQL es la fuente de verdad.
- `game_draws` es el historial oficial.
- `game_number_counters` es una proyección de lectura reconstruible.
- Cada Draw automático confirmado avanza calendario y auditoría en la misma
  transacción.
- Replays del mismo tick no duplican Draw ni broadcast.
- Fallos de integridad auto-pausan sin bucle infinito.
- Fallos de broadcasting no revierten Draw, calendario ni counters.
- La suite estándar no requiere Redis ni Reverb reales.

No garantías de esta fase:

- No hay entrega durable de broadcasts ante caída del proceso después del commit.
- No hay persistencia de entregas ni Outbox.
- No hay garantía de entrega única global para WebSocket.
- Redis no es fuente de verdad.
- El cliente no debe depender del socket para reconstruir estado; debe usar REST.
