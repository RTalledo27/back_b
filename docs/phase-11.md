# Fase 11 — Prize settlement, payout audit y transparencia

## Estado y límites de la fase

La Fase 11 define cómo demostrar que un premio fue respaldado, reclamado,
ejecutado, conciliado y recibido. La subfase 11.1 es únicamente una auditoría
y un contrato documental. No agrega lógica productiva.

En 11.1 no se crean migraciones, modelos, endpoints, controllers, requests,
resources, policies, actions, jobs, eventos Outbox, notificaciones,
integraciones bancarias, APIs de Yape o Plin, proveedor de payouts ni cambios
en Game Engine o frontend.

El método de auditoría usado fue lectura directa de clases, migraciones,
rutas, tests, `rg`, `git grep`, `php artisan route:list` y la documentación
de Fases 6, 8, 9 y 10. CodeGraph no estaba disponible en esta sesión.

## Estado actual real

### Declaración del ganador

`DrawGameNumberAction` es la única Action que resuelve el ganador. Dentro de
una transacción y bajo el lock de `Game`:

1. `game_draws` registra primero el número sorteado y es el historial oficial.
2. `game_number_counters` se actualiza como proyección.
3. Solo un número `Sold` con un `GameEntry` `Confirmed` puede ganar.
4. Al alcanzar `hits_required`, el entry pasa a `Winner`.
5. Se inserta un único `GameWinner`.
6. `Game` pasa por `Resolving` a `Completed` y `next_draw_at` queda nulo.
7. Se escriben los eventos `winning_number_detected`, `winner_declared` y
   `game_completed`.
8. Se registra `game_winner_declared` en Outbox dentro de la transacción.

`game_winners` tiene unicidad por juego, entry y draw. `GameWinner` es
append-only. La integridad del historial evita modificar o borrar draws por
Eloquent, pero no convierte la selección aleatoria en una prueba pública de
imparcialidad.

### Registro actual del payout

`ProcessWinnerPayoutAction` permite a un administrador registrar manualmente un
payout para un juego `Completed` con ganador y premio positivo. El Action:

- bloquea `Game`, `GameWinner` y el payout existente;
- toma `amount_cents` y `currency` de `Game` como snapshot;
- fija `method = manual`;
- almacena `external_reference`, `notes`, actor y timestamps;
- inserta un `WinnerPayoutDocument` en storage privado;
- calcula y persiste SHA-256 del documento;
- registra `payout_paid` en `game_events`;
- registra `winner_payout_registered` en Outbox;
- confirma todo en una transacción;
- despacha `WinnerPayoutRegistered` después del commit.

La ruta actual es:

~~~text
POST /api/v1/admin/games/{game}/winner/payout
GET  /api/v1/admin/games/{game}/winner/payout
~~~

El `ProcessWinnerPayoutRequest` exige administrador, `Idempotency-Key`,
`external_reference` y documento PDF/JPG/PNG. `UNIQUE(game_winner_id)` y
`UNIQUE(idempotency_key_hash)` impiden dos registros para el mismo ganador o
la misma clave. La idempotencia actual es best-effort durable y no es
exactly-once.

El nombre de auditoría `payout_paid` es una limitación histórica: actualmente
significa que un administrador registró el payout, no que una transferencia
fue ejecutada o recibida.

### Notificación actual

`WinnerPayoutRegisteredNotificationHandler` consume el evento Outbox, reclama
una entrega idempotente y encola `WinnerPayoutRegisteredNotification` por
correo. El mensaje comunica que el payout fue registrado y que los fondos se
recibirán por el canal acordado. No contiene evidencia de ejecución, liquidación
bancaria ni confirmación del ganador.

### Visibilidad actual

El administrador puede consultar el payout y metadata no sensible del
documento mediante `WinnerPayoutResource`. No se exponen `disk`, `path`,
`sha256`, hashes de idempotencia ni fingerprint.

El contrato público actual del ganador solo expone número, secuencia, hits y
`won_at`. No existe manifiesto público de auditoría, recibo público ni estado
público de claim o payout.

| Capacidad | Estado | Riesgo actual |
|---|---|---|
| Declaración de ganador | Implementada | Requiere distinguir integridad de imparcialidad |
| Snapshot de premio y moneda | Implementada | No prueba fondos disponibles |
| Registro administrativo manual | Implementada | `registered` no significa `paid` |
| Comprobante privado y hash | Implementada | No prueba ejecución ni recepción |
| Payout único por ganador | Implementada | No existe ciclo de aprobación |
| Funding del premio | No implementada | El juego puede no estar respaldado |
| Winner claim | No implementada | No hay plazo, identidad ni aceptación |
| Dual control | No implementada | El mismo rol puede registrar sin checker separado |
| Ejecución financiera | No implementada | No hay integración ni confirmación bancaria |
| Conciliación | No implementada | No existe cierre financiero |
| Confirmación del ganador | No implementada | No se demuestra recepción efectiva |
| Transparencia pública | No implementada | No hay manifiesto ni recibo verificable |
| Commit-reveal | No implementada | No hay prueba pública de imparcialidad |

## Contrato conceptual de prize funding

El premio anunciado actual es `Game.prize_cents` y `Game.currency`. En el
futuro debe distinguirse explícitamente:

~~~text
premio anunciado != premio financiado != premio reservado != premio pagado
~~~

Estados propuestos:

~~~text
unfunded → funded → reserved → released
~~~

- `unfunded`: existe un premio anunciado, pero no evidencia aprobada de fondos.
- `funded`: el responsable verificó evidencia privada suficiente.
- `reserved`: los fondos están apartados para un juego iniciado o ventas
  cerradas según la política aprobada.
- `released`: el juego fue cancelado o cerrado y la reserva fue liberada según
  conciliación.

Contrato futuro recomendado:

- no publicar ni iniciar un juego sin `funded` o garantía equivalente;
- fijar monto y moneda antes de la primera venta;
- no permitir cambiar el snapshot después de la primera venta;
- registrar responsable, timestamp, monto, moneda, referencia segura, hash y
  evidencia privada;
- reservar antes de iniciar el motor;
- liberar solo por transición compensatoria auditada;
- bloquear el cierre si existe discrepancia entre premio anunciado y premio
  disponible.

La duración de la garantía, los instrumentos aceptables, la liberación y las
obligaciones legales son decisiones de producto y legales pendientes. No se
deciden en 11.1.

## Contrato conceptual de winner claim

Estados propuestos:

~~~text
pending_claim → identity_pending → verified
                             ↘ rejected
pending_claim ───────────────→ expired
~~~

El plazo debe iniciar cuando el ganador sea publicado o notificado, tener una
duración configurable y registrar el instante de vencimiento. El claim debe
permitir aceptación expresa, verificación de identidad, corrección controlada
de datos y destino de pago separado de la evidencia bancaria.

Se requiere:

- canal de contacto verificado;
- datos mínimos definidos por una política legal pendiente;
- almacenamiento privado y cifrado para datos sensibles;
- protección contra suplantación y reutilización de un claim;
- rechazo con código de razón;
- flujo para ganador no localizable;
- vencimiento sin borrar el historial;
- acceso administrativo con permisos específicos;
- vista pública limitada a alias y estados no sensibles.

No se define en esta fase una política legal definitiva de identidad,
retención, impuestos ni ganador no localizable.

## Ciclo de vida del payout

Estados propuestos:

~~~text
draft → awaiting_approval → approved → processing → paid
                                      ↘ failed → processing
draft/awaiting_approval → cancelled
paid → disputed
~~~

| Estado | Significado futuro |
|---|---|
| `draft` | Datos preparados, todavía no enviados a aprobación |
| `awaiting_approval` | Maker terminó la solicitud y espera checker |
| `approved` | Checker diferente autorizó el monto y destino |
| `processing` | Tesorería inició la ejecución manual o externa |
| `paid` | Existe evidencia de ejecución aceptada por la operación |
| `failed` | La ejecución falló; requiere nuevo intento o compensación |
| `cancelled` | Se detuvo antes de ejecutar y existe razón auditada |
| `disputed` | El ganador o la operación cuestionó el pago |

Reglas explícitas:

- `registered != approved`;
- `approved != executed`;
- `executed != received`;
- `paid != confirmed_by_winner`;
- `draft → paid` es inválido;
- `awaiting_approval → paid` es inválido;
- `failed → paid` requiere nuevo intento o reconciliación;
- `cancelled → processing` es inválido;
- no se puede marcar `paid` sin aprobación, ejecución identificada y evidencia;
- no se cierra un payout disputado sin resolución auditada.

## Payout manual para MVP

El flujo inicial propuesto es:

~~~text
maker/admin
  → crea payout
checker/admin diferente
  → aprueba payout
tesorería
  → ejecuta transferencia manual
sistema
  → registra ejecución y comprobante privado
ganador
  → confirma recepción o abre disputa
~~~

Métodos conceptuales:

~~~text
bank_transfer
yape
plin
cash
other
~~~

Los datos de destino sensibles deben cifrarse si se almacenan, mostrarse
enmascarados y nunca incluir CVV, credenciales, claves, CCI completa o número
completo de cuenta. La evidencia completa queda en storage privado. No se
integran APIs externas en esta fase.

## Dual control

El modelo futuro debe separar:

~~~text
created_by
approved_by
executed_by
confirmed_by_winner_at
~~~

Invariantes:

- `created_by != approved_by`;
- un administrador no puede aprobar su propio payout;
- quien ejecuta debe estar identificado;
- no se puede marcar `paid` sin aprobación;
- no se puede sustituir silenciosamente un comprobante;
- toda corrección genera un evento compensatorio;
- los eventos históricos no se eliminan.

Permisos conceptuales:

~~~text
winner-payout.view
winner-payout.create
winner-payout.approve
winner-payout.execute
winner-payout.reconcile
winner-payout.resolve-dispute
winner-payout.view-sensitive
~~~

No se implementan estos permisos en 11.1.

## Auditoría inmutable

El diseño futuro puede usar `winner_payout_events` append-only con:

~~~text
id
winner_payout_id
event_type
from_status
to_status
actor_user_id
actor_type
reason_code
safe_metadata
correlation_id
occurred_at
created_at
~~~

Eventos conceptuales:

~~~text
payout_created
payout_submitted_for_approval
payout_approved
payout_rejected
payout_processing_started
payout_execution_recorded
payout_failed
payout_cancelled
winner_receipt_confirmed
winner_dispute_opened
winner_dispute_resolved
payout_reconciled
~~~

`safe_metadata` solo puede contener referencias técnicas no sensibles,
categorías, hashes, códigos de razón y datos enmascarados. No puede contener
DNI, teléfono, cuenta, CCI, tokens, credenciales, comprobantes completos,
paths públicos ni secretos.

Los eventos serán append-only, con actor, timestamp UTC, correlation ID y
retención definida. Una corrección se registra como nuevo evento; nunca se
edita o borra el evento original.

## Evidencia privada y recibo público

El comprobante privado real y el recibo público generado por el sistema son
artefactos diferentes.

El comprobante privado futuro requiere:

- storage privado;
- allowlist de MIME y límite de tamaño;
- hash SHA-256;
- subida append-only;
- uploader y timestamp;
- acceso autorizado y URL temporal;
- prohibición de reemplazo silencioso;
- auditoría de descargas y política de retención.

El recibo público no es el comprobante bancario. Solo puede contener:

~~~text
schema_version
game_reference
winner_alias
prize_amount
currency
payout_status
paid_at
method_category
proof_digest
receipt_generated_at
~~~

No debe permitir reconstruir cuentas, CCI, Yape, Plin, referencias bancarias,
PII ni paths internos. El sistema podrá firmar o encadenar el manifiesto en
una fase posterior, pero 11.1 no crea esa firma.

## Conciliación y cierre financiero

La cadena financiera futura es:

~~~text
prize_announced
  → prize_funded
  → payout_approved
  → payout_executed
  → payout_reconciled
  → winner_confirmed
~~~

Discrepancias mínimas:

- monto o moneda distinta;
- payout duplicado;
- ganador equivocado;
- pago sin aprobación;
- pago sin funding;
- comprobante ausente;
- referencia duplicada;
- payout marcado pagado sin ejecución;
- ejecución sin conciliación;
- disputa abierta;
- ganador sin confirmación de recepción.

Un juego solo puede llegar a `financially_closed` cuando el premio está
conciliado, no hay discrepancias ni disputas abiertas, existe comprobante
privado, existe audit trail y se cumplió la política de confirmación o
vencimiento del ganador.

## Transparencia pública futura

Rutas conceptuales, no registradas en 11.1:

~~~http
GET /api/v1/public/games/{slug}/audit
GET /api/v1/public/games/{slug}/audit/receipt
~~~

El manifiesto futuro puede incluir:

~~~text
schema_version
game_reference
slug
rules_version
rules_digest
sales_opened_at
sales_closed_at
game_status
draw_strategy
draws
winning_draw
winning_number
winning_entry_reference
winner_alias
prize_amount
currency
claim_public_status
payout_public_status
paid_at
method_category
proof_digest
audit_generated_at
~~~

Debe usar un alias anonimizado estable y no publicar nombre completo, DNI,
email, teléfono, dirección, cuentas, CCI, Yape, Plin, comprobante completo,
referencia bancaria completa, actor administrativo, IP, tokens o paths.

## Integridad e imparcialidad del sorteo

### Integridad del historial

Actualmente se puede verificar parcialmente que el historial no fue cambiado:

- `game_draws` es append-only y fuente oficial;
- `sequence` es único por juego y debe ser positivo;
- las foreign keys compuestas mantienen la pertenencia al juego;
- `GameDraw`, `GameWinner` y `GameEvent` bloquean update/delete mediante el ORM;
- el motor usa locks PostgreSQL e idempotencia por `DrawCommand`;
- los eventos de ganador se confirman con la misma transacción del draw.

Esto no protege contra un administrador con acceso directo a PostgreSQL ni
crea una cadena criptográfica de auditoría. Tampoco prueba que el operador no
haya influido en una fuente de aleatoriedad antes del sorteo.

### Imparcialidad

La estrategia actual `crypto_secure` usa `random_int` y excluye números sin
participación confirmada que alcanzaron el umbral. El ganador exige número
vendido, entry confirmado y `hits_required`.

Esto demuestra reglas de elegibilidad y una fuente criptográfica local, pero
no demuestra selección anticipadamente impredecible para un observador
externo. No existe seed pública, commitment, entropy externa ni algoritmo de
verificación público.

## Commit-reveal conceptual

Una fase futura puede añadir:

~~~text
server_seed
server_seed_commitment
public_entropy
draw_sequence
previous_draw_hash
current_draw_hash
server_seed_reveal
verification_algorithm
~~~

Flujo:

~~~text
antes del juego → publicar commitment
al cerrar ventas → fijar entropy pública
durante draws → encadenar hashes
al finalizar → revelar seed
verificador público → recalcular secuencia
~~~

Esto puede probar que la secuencia coincide con un compromiso previo y la
entropy definida. No prueba que la regla de negocio sea justa, que el premio
esté financiado, que el usuario sea el ganador legal o que el dinero haya sido
recibido.

Los juegos existentes no tienen esos campos; no se deben inventar seeds
retroactivas. La migración debe distinguir juegos antiguos, rotación y custodia
de secretos, recuperación de fallos y compatibilidad con `game_draws`.

## APIs futuras

### Player

~~~http
GET  /api/v1/me/winnings
GET  /api/v1/me/winnings/{winner}
POST /api/v1/me/winnings/{winner}/claim
POST /api/v1/me/winnings/{winner}/confirm-receipt
POST /api/v1/me/winnings/{winner}/dispute
~~~

Requerirían `auth:sanctum`, ownership, verificación de identidad cuando
corresponda, idempotencia en mutaciones y respuestas sin secretos.

### Admin

~~~http
GET  /api/v1/admin/winner-payouts
GET  /api/v1/admin/winner-payouts/{payout}
POST /api/v1/admin/games/{game}/winner-payouts
POST /api/v1/admin/winner-payouts/{payout}/submit
POST /api/v1/admin/winner-payouts/{payout}/approve
POST /api/v1/admin/winner-payouts/{payout}/reject
POST /api/v1/admin/winner-payouts/{payout}/mark-processing
POST /api/v1/admin/winner-payouts/{payout}/mark-paid
POST /api/v1/admin/winner-payouts/{payout}/reconcile
POST /api/v1/admin/winner-payouts/{payout}/resolve-dispute
~~~

Requerirían middleware admin, permisos separados, dual control, idempotencia,
validación de transición, audit trail y manejo seguro de documentos.

### Public

~~~http
GET /api/v1/public/games/{slug}/audit
GET /api/v1/public/games/{slug}/audit/receipt
~~~

Serían públicos, de solo lectura, cacheables y sin PII. Ninguna de estas rutas
se registra en 11.1.

## Eventos futuros

| Evento | Clasificación conceptual |
|---|---|
| `winner_claim_submitted` | audit-only + notification privada |
| `winner_identity_verified` | audit-only |
| `winner_identity_rejected` | audit-only + notification privada |
| `winner_payout_submitted` | audit-only + Outbox privado |
| `winner_payout_approved` | audit-only + Outbox privado |
| `winner_payout_processing` | audit-only |
| `winner_payout_paid` | audit-only + Outbox privado |
| `winner_payout_failed` | audit-only + Outbox privado |
| `winner_payout_receipt_confirmed` | audit-only + Outbox privado |
| `winner_payout_disputed` | audit-only + Outbox privado |
| `winner_payout_dispute_resolved` | audit-only + notification privada |
| `game_financially_closed` | audit-only + timeline pública limitada |

La compatibilidad conceptual con `game_winner_declared` y
`winner_payout_registered` se mantiene. `winner_payout_registered` debe
conservarse como evento histórico de registro hasta que una subfase futura
apruebe su deprecación o redefinición. No se cambia en 11.1.

## Plan de subfases restantes

### Fase 11.2A — Prize funding foundation

- **Objetivo:** respaldar, reservar y liberar el premio anunciado.
- **Alcance:** aggregate de funding, evidencia privada, auditoría append-only,
  gates de publicación, reserva al iniciar y liberación por cancelación.
- **Endpoints:** registro y consulta administrativa del funding; no hay endpoint
  público ni operación de claim.
- **Eventos:** `funding_created`, `funding_recorded`, `funding_reserved` y
  `funding_released`, sin ampliar Outbox.
- **Tests:** migración histórica, transiciones, locks, idempotencia, privacidad
  y recuperación transaccional.
- **Límites:** no ejecutar transferencias ni integrar bancos, Yape o Plin; claim,
  identidad y destino del ganador pertenecen a 11.2B.
- **Cierre:** 11.2A exige funding antes de iniciar; el claim queda en 11.2B.

#### Alcance ejecutado de 11.2A

Este bloque implementa únicamente el respaldo, la reserva y la liberación del
premio. `GamePrizeFunding` es un aggregate separado de `Game`,
con una fila única por juego y `amount_cents`/`currency`
copiados exclusivamente desde `Game`.

Los juegos creados por `CreateGameAction` nacen en `unfunded`
y registran `funding_created` en la misma transacción. La migración
crea para juegos anteriores el estado técnico `legacy_unverified`;
esto no demuestra que el premio histórico haya sido financiado ni inventa
evidencia retroactiva.

Las transiciones implementadas son:

```text
unfunded → funded
legacy_unverified → funded
funded → reserved
funded → released
reserved → released
```

`PublishGameAction` exige `funded` para juegos con registro
de funding. `StartGameAction` bloquea primero `Game` y luego
`GamePrizeFunding`; reserva el premio en la misma transacción que
cambia el juego a `running`. La cancelación libera `funded` o
`reserved` con `release_reason_code = game_cancelled`. Un
juego `completed` no libera automáticamente el premio.

La evidencia se guarda en storage privado, con MIME detectado por el servidor,
SHA-256, tamaño y metadata append-only. No se publican `disk`,
`path` ni el hash completo. Los eventos `funding_created`,
`funding_recorded`, `funding_reserved` y
`funding_released` son append-only y no usan Outbox.

Endpoints administrativos:

```text
POST /api/v1/admin/games/{game}/prize-funding
GET  /api/v1/admin/games/{game}/prize-funding
```

El registro requiere `auth:sanctum`, administración,
`Idempotency-Key`, multipart y documento privado. No acepta
`amount_cents` ni `currency` del cliente. El replay devuelve
el resultado existente y no duplica documento ni evento; una huella distinta
produce conflicto.

El lock order es `Game → GamePrizeFunding`; los documentos se
escriben antes de abrir la transacción y se compensan si la transacción falla.
La operación no crea Outbox, Notification, payout, claim ni integración
externa.

#### Fase 11.2B — Winner claim e identidad

El bloque 11.2B queda implementado como un flujo de reclamación e identidad
manual, separado de `GameWinner`, `GamePrizeFunding` y `WinnerPayout`.

#### Alcance implementado de 11.2B

Al declararse un ganador, `DrawGameNumberAction` crea dentro de la misma
transacción un `WinnerClaim` en estado `pending_claim`. La migración crea para
ganadores históricos un claim `is_legacy = true`, con referencia técnica y sin
inventar ventana, verificación, funding ni payout retroactivo.

El claim conserva estos momentos: `claim_window_started_at`, `expires_at`,
`claimed_at`, `identity_submitted_at`, `verified_at`, `rejected_at` y
`expired_at`. La ventana nueva comienza en `GameWinner.won_at` y usa
`WINNER_CLAIM_TTL_DAYS` (30 por defecto, validado entre 1 y 3650 días).
Los claims legacy no se vencen automáticamente porque no tienen una ventana
inventada.

Los estados permitidos son:

```text
pending_claim -> identity_pending -> verified
pending_claim -> expired
identity_pending -> rejected
```

El jugador solo puede consultar sus propios claims y enviar una reclamación
con correo verificado, `Idempotency-Key`, nombre legal, tipo y número de
documento, consentimiento y evidencia de identidad. El agregado valida
ownership, ventana, estado y cantidad de documentos. Los datos sensibles del
perfil se guardan con casts `encrypted`; los archivos usan el disco privado
`winner_identity_documents`, MIME detectado por el servidor, tamaño limitado,
SHA-256 y metadata append-only. Las respuestas del jugador no incluyen PII de
identidad, hashes, rutas ni nombres de disco.

El administrador puede listar y revisar claims mediante:

```text
GET  /api/v1/me/winnings
GET  /api/v1/me/winnings/{winner}
POST /api/v1/me/winnings/{winner}/claim
GET  /api/v1/admin/winner-claims
GET  /api/v1/admin/winner-claims/{claim}
GET  /api/v1/admin/winner-claims/{claim}/documents/{identityDocument}/download
POST /api/v1/admin/winner-claims/{claim}/verify
POST /api/v1/admin/winner-claims/{claim}/reject
```

La revisión requiere administración, impide auto-revisión, exige
`identity_pending` y registra únicamente códigos de rechazo permitidos:
`identity_mismatch`, `document_unreadable`, `document_incomplete`,
`duplicate_claim` y `other_review_reason`. Cada cambio produce un evento
append-only en `winner_claim_events`; no se amplía Outbox ni se crean
notificaciones nuevas en este bloque.

La expiración se ejecuta con `ExpireWinnerClaimsJob` cada minuto. El orden de
bloqueo de las acciones es `Game -> GameWinner -> WinnerClaim`; las acciones
exigen una transacción activa. La creación al declarar ganador es idempotente
por `game_winner_id`; el envío y la revisión usan idempotencia durable con
huella de request. Un replay compatible devuelve el estado existente y no
duplica claim, documento ni evento; una huella distinta produce conflicto.

El flujo de archivos aplica compensación si falla la transacción. La descarga
es privada, exige permisos y usa `Cache-Control: no-store, private`. No se
implementan cuentas bancarias, Yape, Plin, transferencia, payout automático
ni confirmación financiera: `verified` demuestra revisión administrativa de
identidad, no recepción del dinero.

### 11.3 — Payout manual con dual control

- **Objetivo:** separar maker, checker y tesorería.
- **Alcance:** estados, permisos, aprobación, ejecución manual y evidencia
  append-only.
- **Endpoints probables:** crear, enviar, aprobar, rechazar y marcar
  procesamiento/pago.
- **Eventos:** audit trail y Outbox privado solo si el contrato lo aprueba.
- **Tests:** self-approval, concurrencia, idempotencia, transiciones y storage.
- **Límites:** no proveedor automático ni datos bancarios innecesarios.
- **Cierre:** ningún `paid` sin aprobación, ejecución y evidencia.

### 11.4 — Winner confirmation, reconciliation y disputes

- **Objetivo:** demostrar o disputar recepción y conciliar el premio.
- **Alcance:** confirmación del ganador, discrepancias, resolución y
  `financially_closed`.
- **Endpoints probables:** confirm receipt, dispute y reconcile.
- **Eventos:** confirmación, disputa, resolución y cierre financiero.
- **Tests:** disputas, doble confirmación, vencimiento y compensaciones.
- **Límites:** no modificar historial de draws ni ocultar eventos.
- **Cierre:** cada juego cerrado tiene conciliación y estado verificable.

### 11.5 — Public transparency manifest

- **Objetivo:** publicar auditoría y recibo seguro.
- **Alcance:** Resources, queries, alias estable, digest y endpoints GET.
- **Eventos:** ninguno necesario para leer un snapshot público.
- **Tests:** contrato estable, privacidad, fechas UTC y no N+1.
- **Límites:** no publicar PII, cuentas, comprobantes ni actores internos.
- **Cierre:** un tercero puede verificar el resultado publicado sin acceder a
  storage privado.

### 11.6 — Provably fair draw / commit-reveal

- **Objetivo:** añadir verificabilidad pública de la secuencia futura.
- **Alcance:** commitment, entropy, hash chain, reveal y verificador.
- **Eventos:** audit-only, salvo contrato posterior.
- **Tests:** vectores deterministas, replay, reveal inválido y compatibilidad.
- **Límites:** no reescribir juegos antiguos ni afirmar imparcialidad absoluta.
- **Cierre:** un juego nuevo puede recalcular su secuencia con el algoritmo
  publicado.

### 11.7 — Closure and hardening

- **Objetivo:** cerrar payout, transparencia y sorteos verificables.
- **Alcance:** guards, reconciliación operativa, retención, permisos y
  documentación final.
- **Tests:** suite completa, arquitectura, seguridad, concurrencia y smoke tests.
- **Límites:** no añadir canales o productos no aprobados.
- **Cierre:** garantías y no garantías auditadas, sin exactly-once ni entrega
  bancaria garantizada.

## Garantías reales y no garantías

Actualmente el sistema garantiza, dentro de sus límites, un historial de
draws append-only a nivel ORM, ganador único por juego, snapshots monetarios,
registro idempotente de payout, documento privado y notificación Outbox
at-least-once.

Actualmente no garantiza premio financiado, aprobación separada, ejecución
bancaria, recepción del ganador, conciliación, ausencia de disputa,
transparencia pública ni imparcialidad verificable por commit-reveal.

No se afirma exactly-once, entrega garantizada de correo, entrega bancaria
garantizada ni payout automático. No existe payout automático implementado.
No existe seed pública ni commit-reveal implementado.
