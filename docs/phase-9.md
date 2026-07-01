# Fase 9 — Notificaciones transaccionales

## 1. Alcance de Fase 9

Fase 9 implementa la capa de notificaciones transaccionales sobre la infraestructura Outbox de Fase 8.

Los handlers de `OutboxEventDispatcher` pasan de ser no-op a enviar notificaciones reales al usuario destinatario. El Outbox ya garantiza at-least-once; los handlers de Fase 9 deben ser idempotentes para tolerar esa garantía sin duplicar entregas visibles.

### División por subfase

| Subfase | Contenido |
|---------|-----------|
| 9.1 | Auditoría y contrato (este documento — sin código productivo) |
| 9.2 | Email transaccional: handlers reales + `notification_deliveries` + Mailable por evento |
| 9.3 | WhatsApp / canal adicional (pendiente de definición) |

---

## 2. Problema a resolver

Los 5 handlers del `OutboxEventDispatcher` son actualmente no-op:

```php
private function handlePaymentApproved(OutboxEvent $event): void
{
    Log::info('outbox.payment_approved.delivered', [...]);
    // ← nada más: el usuario no recibe ninguna notificación
}
```

El Outbox registra el evento y el worker lo procesa, pero el efecto final (notificar al usuario) **no ocurre**. El usuario no sabe que su pago fue aprobado, rechazado, reembolsado, ni que ganó.

Adicionalmente, las notificaciones de Auth (password reset y verificación de email) ya funcionan de forma síncrona, pero sin garantía durable si el mailer falla durante la request.

---

## 3. Mapa de notificaciones actuales

### 3.1 Auth notifications (no usan Outbox)

| Notificación | Origen | Evento relacionado | Usa Outbox | Mecanismo | Síncrona | Durable | Destinatario | Riesgo actual | Fase propuesta |
|---|---|---|---|---|---|---|---|---|---|
| **Password reset** | `SendPasswordResetLinkAction` → `Password::sendResetLink()` → broker nativo Laravel | ninguno | No | `User::sendPasswordResetNotification()` → `PasswordResetNotification` (Laravel default) | Sí | No (si el mailer falla, se pierde) | El propio usuario (email del registro) | Pérdida silenciosa si mailer falla durante request; usuario no sabe | 9.2: agregar `ShouldQueue` |
| **Email verification** | `SendEmailVerificationNotificationAction` → `$user->notify(new VerifyEmailNotification)` | ninguno | No | `VerifyEmailNotification` → canal `mail` | Sí | No | El propio usuario | Igual que password reset | 9.2: agregar `ShouldQueue` |

### 3.2 Domain notifications (deben salir desde Outbox)

| Notificación | Action de origen | Outbox event_type | Handler actual | Destinatario | Riesgo actual | Fase propuesta |
|---|---|---|---|---|---|---|
| **Pago aprobado** | `ApprovePaymentAction` | `payment_approved` | No-op log | Comprador (`buyer_user_id`) | No se notifica | 9.2 |
| **Pago rechazado** | `RejectPaymentAction` | `payment_rejected` | No-op log | Comprador (`buyer_user_id`) | No se notifica | 9.2 |
| **Reembolso de orden** | `RefundOrderAction` | `order_refunded` | No-op log | Comprador (`buyer_user_id`) | No se notifica | 9.2 |
| **Pago al ganador registrado** | `ProcessWinnerPayoutAction` | `winner_payout_registered` | No-op log | Ganador (`winner_user_id`) | No se notifica | 9.2 |
| **Ganador declarado** | `DrawGameNumberAction::resolveWinner()` | `game_winner_declared` | No-op log | Ganador (`winner_user_id`) | No se notifica | 9.2 |

### 3.3 Notificaciones sin canal real (invitación de jugador)

La invitación administrativa (`CreatePlayerInvitationAction`) devuelve el `plain_token` en el body de la response solo en entornos `testing`/`local`. En producción **no hay canal de entrega implementado**. Esta notificación queda fuera del alcance de Fase 9 (requiere definición de canal: email o fuera de banda).

---

## 4. Separación Auth vs Domain notifications

### 4.1 Auth notifications

```
Password reset     → broker nativo Laravel → PasswordResetNotification (default Laravel)
Email verification → User::notify(new VerifyEmailNotification)
```

**Regla**: no requieren Outbox porque:
- Son disparadas durante el ciclo de vida de la request del propio usuario.
- Si fallan, el usuario puede reintentar (resend, forgot-password de nuevo).
- No están vinculadas a una mutación de dominio crítica como una transacción financiera.

**Mejora propuesta en 9.2**: agregar `ShouldQueue` a `VerifyEmailNotification` y a la notificación de password reset, para que el mailer no bloquee la request. Con `QUEUE_CONNECTION=database` ya disponible, esta mejora no requiere infraestructura adicional.

### 4.2 Domain notifications (Commerce / Game)

```
payment_approved           → comprador
payment_rejected           → comprador
order_refunded             → comprador
winner_payout_registered   → ganador
game_winner_declared       → ganador
```

**Regla**: **deben salir desde `outbox_events`** porque:
- Están vinculadas a mutaciones de dominio críticas (aprobación de pago, declaración de ganador).
- La pérdida de una notificación de "ganaste" es inaceptable.
- El Outbox ya garantiza at-least-once para estas mutaciones.
- Duplicar garantías (Outbox + disparo directo desde Action) complicaría la idempotencia.

**Invariante**: ningún Action de dominio debe llamar a `Mail::`, `Notification::`, `notify()` o cualquier canal externo directamente. Las notificaciones de dominio solo salen desde los handlers del `OutboxEventDispatcher`.

---

## 5. Arquitectura propuesta

### 5.1 Diagrama de capas

```
OutboxEventProcessor
  → OutboxEventDispatcher::dispatch(OutboxEvent $event)
       ↓ match(event_type)
       → PaymentApprovedNotificationHandler
       → PaymentRejectedNotificationHandler
       → OrderRefundedNotificationHandler
       → WinnerPayoutRegisteredNotificationHandler
       → GameWinnerDeclaredNotificationHandler
            ↓ para cada handler (flujo claim-first):
            1. Calcular deduplication_key = "{outbox_event_id}:{recipient_id}:{channel}"
            2. INSERT notification_deliveries ON CONFLICT DO NOTHING (claim idempotente)
            3. Si ya existe con status=sent o status=queued → return (idempotente)
            4. Si existe status=pending/failed → evaluar si es reintentable
            5. Resolver User por ID del payload
            6. Validar estado actual del dominio (compatible con notificación)
            7. $user->notify(new DomainXxxNotification(...))
            8. UPDATE notification_deliveries SET status=queued/sent, queued_at/sent_at
```

### 5.2 Ubicación de archivos

```
app/Modules/Shared/Infrastructure/Outbox/Handlers/
    PaymentApprovedNotificationHandler.php
    PaymentRejectedNotificationHandler.php
    OrderRefundedNotificationHandler.php
    WinnerPayoutRegisteredNotificationHandler.php
    GameWinnerDeclaredNotificationHandler.php

app/Notifications/Domain/
    PaymentApprovedNotification.php
    PaymentRejectedNotification.php
    OrderRefundedNotification.php
    WinnerPayoutRegisteredNotification.php
    GameWinnerDeclaredNotification.php
```

### 5.3 Contrato de cada handler

El flujo es **claim-first**: el registro en `notification_deliveries` se hace **antes** de llamar a `notify()`, no después. Esto **reduce** duplicados en el caso común, pero no elimina la zona ambigua (ver §6.1).

```php
final class PaymentApprovedNotificationHandler
{
    public function handle(OutboxEvent $event): void
    {
        // 1. Extraer IDs del payload (solo IDs — sin PII)
        $payload     = $event->payload;
        $buyerUserId = $payload['buyer_user_id'];
        $paymentId   = $payload['payment_id'];
        $channel     = 'mail';

        // 2. Claim idempotente — INSERT ... ON CONFLICT DO NOTHING
        //    Nunca lanza UniqueConstraintViolationException.
        //    Retorna la fila existente si ya había sido insertada.
        $delivery = NotificationDelivery::claim(
            outboxEventId:   $event->id,
            recipientUserId: $buyerUserId,
            channel:         $channel,
        );

        // 3. Si ya está en estado final o encolado → no reenviar
        if ($delivery->isFinalOrQueued()) {
            return;
        }

        // 4. Si está pending reciente → no reintentar todavía
        //    Previene el doble-notify en retry inmediato post-caída
        if ($delivery->isPendingFresh()) {
            return;
        }

        // 5. Lookup de User (si falla, la fila queda pending; Outbox reintentará con backoff)
        $user = User::find($buyerUserId);
        if ($user === null) {
            throw new OutboxHandlerException("User {$buyerUserId} not found for outbox event {$event->id}");
        }

        // 6. Validar estado actual del dominio
        //    Si el pago fue revertido o no existe, descartar sin excepción
        $payment = Payment::find($paymentId);
        if ($payment === null || ! $payment->status->isApproved()) {
            Log::warning('outbox.payment_approved: payment not in expected state', [
                'outbox_event_id' => $event->id, 'payment_id' => $paymentId,
            ]);
            $delivery->markFailed('payment_not_in_expected_state');
            return; // El Outbox marcará processed_at
        }

        // 7. Enviar notificación — notify() encola el job (ShouldQueue), no garantiza entrega SMTP
        $user->notify(new PaymentApprovedNotification($paymentId, $payload['order_id']));

        // 8. Actualizar status a queued (el job fue encolado en la tabla jobs)
        $delivery->markQueued();
        // sent_at se actualiza opcionalmente en listener NotificationSent (ver §5.5, Opción B)
    }
}
```

**Nota**: `claim()` usa `INSERT ... ON CONFLICT DO NOTHING`. Si la fila ya existe (segundo intento del Outbox), `claim()` retorna la fila existente. Los métodos del modelo determinan qué hacer con ella según su estado actual (ver §6.1 y §6.4 para la política completa).

### 5.4 Contrato de cada Notification

Las `Notification` de dominio siguen el patrón de `VerifyEmailNotification`:
- `final class`, extiende `Notification`, implementa `ShouldQueue`.
- `via()` devuelve `['mail']` (Fase 9.2).
- `toMail()` retorna `MailMessage` (Markdown en Fase 9.2).
- No hace queries Eloquent en el constructor — recibe IDs del payload.
- La query al modelo (si es necesaria para el asunto o el cuerpo) va en `toMail()`.
- No incluye PII en la cabecera ni en el constructor.

### 5.5 Semántica de `ShouldQueue` y el ciclo de vida de `sent_at`

**`$user->notify()` NO garantiza entrega SMTP.** Cuando una `Notification` implementa `ShouldQueue`:

1. `$user->notify(new DomainXxxNotification(...))` → encola un job en la tabla `jobs`.
2. El job queda en la cola hasta que un worker activo lo ejecute.
3. El worker ejecuta el job → Laravel entrega el email al mailer (SMTP, Mailpit, log).
4. Solo en ese momento ocurre la entrega real.

Esto significa que `markQueued()` en el handler registra el momento en que el **job fue encolado**, no el momento de entrega real. La distinción importa para `sent_at`:

| Opción | `sent_at` se actualiza cuando | Ventaja | Riesgo |
|--------|-------------------------------|---------|--------|
| **A — job encolado** | `markQueued()` en el handler | Simple, sin listeners adicionales | `sent_at` ≠ entrega real; el job podría fallar después |
| **B — entrega real** | Listener `NotificationSent` + update en `notification_deliveries` | `sent_at` refleja la entrega efectiva | Más complejo; requiere listener global |

**Decisión para Fase 9.2**: implementar **Opción A** como base (simplicidad); documentar en §13 el path a Opción B si se requiere auditoría exacta de entrega.

**Cola y worker activo**: `QUEUE_CONNECTION=database` requiere que exista un worker activo para que los jobs encolados se procesen. Sin worker, `notify()` encola el job y el email **nunca se envía**. Ver §9.7 para detalles de operación del worker.

---

## 6. Idempotencia de notificaciones

### 6.1 Problema y zona ambigua

El Outbox garantiza **at-least-once**: el mismo `outbox_event_id` puede llegar dos veces al handler en los siguientes escenarios:
- El worker ejecuta el handler y cae antes de marcar `processed_at`.
- Un lock stale de 5 minutos libera el evento para un segundo worker.

Sin idempotencia, el usuario recibiría dos emails del mismo evento.

**Zona ambigua del flujo claim-first**: el flujo claim-first reduce duplicados, pero no los elimina completamente. Si el proceso cae después de `$user->notify()` y antes de `$delivery->markQueued()`, la fila en `notification_deliveries` permanece con `status=pending`. En un retry inmediato, el handler encontraría `pending` y podría volver a llamar `notify()` — resultando en un segundo email.

Esta zona ambigua es inherente a cualquier sistema at-least-once sin soporte de exactly-once por el proveedor externo. La política de Fase 9.2 la mitiga, pero no la elimina (ver §6.4).

### 6.2 Evaluación de opciones

**Opción A — Tabla `notification_deliveries` (recomendada)**

```sql
CREATE TABLE notification_deliveries (
    id                  UUID         NOT NULL,
    outbox_event_id     UUID         NOT NULL,
    event_type          VARCHAR(120) NOT NULL,
    recipient_user_id   BIGINT       NOT NULL,
    channel             VARCHAR(32)  NOT NULL,
    deduplication_key   VARCHAR(255) NOT NULL,  -- "{outbox_event_id}:{recipient_user_id}:{channel}"
    status              VARCHAR(32)  NOT NULL DEFAULT 'pending',  -- pending | queued | sent | failed
    queued_at           TIMESTAMPTZ  NULL,   -- cuando el job fue encolado (notify() exitoso)
    sent_at             TIMESTAMPTZ  NULL,   -- cuando el mailer confirmó la entrega (NotificationSent)
    failed_at           TIMESTAMPTZ  NULL,   -- cuando se descartó definitivamente
    attempts            INT          NOT NULL DEFAULT 0,
    last_error          TEXT         NULL,
    created_at          TIMESTAMPTZ  NOT NULL,
    updated_at          TIMESTAMPTZ  NULL,

    CONSTRAINT notification_deliveries_pkey PRIMARY KEY (id),
    CONSTRAINT notification_deliveries_dedup_key UNIQUE (deduplication_key)
)
```

**Ciclo de estados**:
```
pending  →  queued   →  sent
   ↓            ↓
 failed       failed (si el job de cola falla tras el máx. de reintentos)
```

**`deduplication_key`** = `"{outbox_event_id}:{recipient_user_id}:{channel}"`.

Esta clave soporta escenarios futuros donde un mismo evento notifica a múltiples destinatarios (e.g., ganador + administrador) en el mismo canal, o múltiples canales (mail + whatsapp). La constraint `UNIQUE(deduplication_key)` es estructuralmente más fuerte que `UNIQUE(outbox_event_id, channel)`: previene duplicados incluso si el mismo destinatario aparece en dos runs del handler.

La tabla es una **tabla de control**, no solo un log. El INSERT con `ON CONFLICT DO NOTHING` es la operación de claim; el registro existe antes de que se envíe la notificación.

**Opción B — Canal `database` de Laravel Notifications**

Laravel puede registrar notificaciones enviadas en la tabla `notifications`. No previene duplicados entre runs del worker: si el worker cae después de enviar pero antes de marcar `processed_at`, el segundo run insertará una segunda fila en `notifications`. No es suficiente como única garantía.

Puede ser útil como log complementario, pero no como mecanismo de idempotencia.

**Opción C — Idempotencia por proveedor externo**

Cabecera `Idempotency-Key` hacia proveedor de email (Resend, SendGrid). No todos los proveedores la soportan. No es garantía suficiente sola porque no previene el segundo intento si el primer intento nunca llegó al proveedor.

### 6.3 Decisión

**Se elige Opción A** (tabla `notification_deliveries`) como mecanismo principal de idempotencia. Razones:
- Independiente del proveedor de email.
- Funciona con cualquier canal (mail, WhatsApp, SMS).
- `UNIQUE(deduplication_key)` — con key = `"{outbox_event_id}:{recipient_user_id}:{channel}"` — es una garantía estructural fuerte que soporta múltiples destinatarios y múltiples canales por evento.
- El flujo claim-first (INSERT antes de `notify()`) reduce duplicados en el caso común: si la fila ya llegó a `queued` o `sent`, el retry no vuelve a notificar.
- La política de `pending` fresco (§6.4) mitiga la zona ambigua: un retry inmediato tras caída no reenvía si la fila aún es reciente.
- El canal `database` de Laravel puede activarse adicionalmente como log de auditoría, pero no como único guardia de idempotencia.

### 6.4 Política de `pending` y métodos del modelo

Un registro en `pending` significa que el claim fue exitoso pero aún no se sabe si el job fue encolado. La política para Fase 9.2:

| Condición | Decisión del handler | Justificación |
|-----------|---------------------|---------------|
| `status=queued` o `status=sent` | `isFinalOrQueued()=true` → return sin notify | El job fue encolado o la entrega fue confirmada |
| `status=pending` y `updated_at >= now() - 5 min` | `isPendingFresh()=true` → return sin notify | Posible zona ambigua: el job pudo haberse encolado justo antes de la caída |
| `status=pending` y `updated_at < now() - 5 min` | `isRetryablePending()=true` → puede reintentar | El tiempo transcurrido sugiere que el job nunca llegó a la cola |
| `status=failed` y `attempts < max_attempts` | `isRetryableFailed()=true` → puede reintentar | Fallo conocido con intentos disponibles |
| `status=failed` y `attempts >= max_attempts` | `isFinalOrQueued()=false`, `isRetryableFailed()=false` → descarta | Agotado; alertar operacionalmente |

Al reintentar un `pending` vencido o `failed` reintentable:
1. Incrementar `attempts` y actualizar `updated_at`.
2. Si supera `max_attempts` (sugerido: 3 para notificaciones), marcar `failed`.

**Métodos del modelo `NotificationDelivery`**:

```php
// Cubre queued y sent — estados donde el job fue encolado o entregado
public function isFinalOrQueued(): bool;

// Pending reciente: updated_at >= now() - PENDING_FRESH_SECONDS (sugerido: 300)
public function isPendingFresh(): bool;

// Pending vencido: listo para reintentar
public function isRetryablePending(): bool;

// Failed con intentos disponibles
public function isRetryableFailed(): bool;
```

`isAlreadyDelivered()` se elimina — es ambiguo (¿cubre pending? ¿sent? ¿queued?). Los métodos anteriores son explícitos en su semántica.

### 6.5 Garantía real de Fase 9.2

**La garantía de Fase 9.2 es `best-effort idempotency` sobre entrega `at-least-once`.** No se promete `exactly-once` visible al usuario.

- El sistema intenta no enviar dos veces el mismo email.
- En la zona ambigua (caída entre `notify()` y `markQueued()`), hay probabilidad baja — pero no nula — de un segundo envío.
- La ventana de `isPendingFresh()` (5 minutos) reduce esta probabilidad al costo de diferir el retry.

Para `exactly-once` real de email sería necesario:
- Soporte de `Idempotency-Key` por el proveedor externo (Resend, SES, Postmark — no universal), o
- Una capa de delivery tracking con callbacks/webhooks confiables del proveedor (Fase 9.3+).

Esta limitación debe documentarse en el código y comunicarse al equipo operacional.

---

## 7. Canales

### 7.1 Fase 9.2 — Email transaccional

**Solo email.** `via()` retorna `['mail']` para todas las Domain Notifications.

Las Auth Notifications (password reset, email verification) ya usan email. En 9.2 se agrega `ShouldQueue` para hacerlas asíncronas.

### 7.2 Fase 9.3 — WhatsApp

Canal adicional para las Domain Notifications. Requiere:
- Proveedor (Twilio, Meta Cloud API).
- Número de teléfono verificado en `users` (pendiente de diseño de schema).
- Handler adicional en `notification_deliveries` con `channel = 'whatsapp'`.

**No implementar en Fase 9.2.** El campo `channel` en `notification_deliveries` ya contempla este escenario.

### 7.3 SMS — Fuera de alcance

No hay requerimiento de SMS en el roadmap actual.

---

## 8. Templates mínimos

### Reglas de privacidad para todos los templates

**Variables prohibidas en cualquier template**:
- `email`, `name`, `phone` — PII directo del usuario.
- `reason` — motivo de rechazo (dato interno del revisor).
- `reviewer_user_id` — identidad interna del revisor.
- `path`, `disk`, `sha256`, `original_filename` — metadatos de documentos.
- `external_reference` — referencia bancaria.
- `idempotency_key_hash`, `request_fingerprint` — claves de control interno.
- `token`, `bank_account`, `card`, `account_number` — datos financieros sensibles.
- `password`, `signature` — credenciales.
- `rejection_reason` — razón interna de rechazo.

Los templates pueden mostrar IDs de referencia humana solo si no son sensibles (ej.: número de partida, nombre del juego — no el UUID interno).

---

### Template 1: `payment_approved`

| Campo | Valor |
|-------|-------|
| **Asunto** | `Tu pago ha sido aprobado` |
| **Destinatario** | Usuario comprador (`buyer_user_id`) |
| **Variables permitidas** | `game_id` (para lookup del nombre del juego), `order_id` (referencia de orden), `occurred_at` |
| **Variables prohibidas** | `payment_id` (UUID interno), `buyer_user_id`, `reviewer_user_id`, `path`, `disk`, `sha256` |
| **Cuerpo** | Tu pago para la partida **{game.name}** fue aprobado. Tu orden **#{order.short_ref}** está confirmada. |
| **CTA futuro** | Enlace a "Ver mi orden" en el frontend |

---

### Template 2: `payment_rejected`

| Campo | Valor |
|-------|-------|
| **Asunto** | `Tu pago no pudo ser procesado` |
| **Destinatario** | Usuario comprador (`buyer_user_id`) |
| **Variables permitidas** | `game_id` (nombre del juego), `order_id`, `occurred_at` |
| **Variables prohibidas** | `rejection_reason`, `reviewer_user_id`, `payment_id`, `path`, `disk` |
| **Cuerpo** | Tu pago para la partida **{game.name}** no pudo procesarse. Tus números han sido liberados. Puedes volver a intentarlo. |
| **CTA futuro** | Enlace a "Ver mis órdenes" |
| **Nota** | No incluir el motivo de rechazo en el email — es dato interno del revisor |

---

### Template 3: `order_refunded`

| Campo | Valor |
|-------|-------|
| **Asunto** | `Tu reembolso ha sido procesado` |
| **Destinatario** | Usuario comprador (`buyer_user_id`) |
| **Variables permitidas** | `game_id` (nombre del juego), `order_id`, `refund_id`, `occurred_at` |
| **Variables prohibidas** | `payment_id`, `external_reference`, `reason`, `reviewer_user_id` |
| **Cuerpo** | Tu orden de la partida **{game.name}** fue reembolsada. Referencia de reembolso: **{refund.short_ref}**. |
| **CTA futuro** | Enlace a historial de órdenes |

---

### Template 4: `winner_payout_registered`

| Campo | Valor |
|-------|-------|
| **Asunto** | `El pago de tu premio ha sido registrado` |
| **Destinatario** | Ganador (`winner_user_id`) |
| **Variables permitidas** | `game_id` (nombre del juego), `winner_payout_id`, `occurred_at` |
| **Variables prohibidas** | `external_reference`, `amount_cents`, `currency`, `method`, `notes`, `path`, `disk`, `sha256` |
| **Cuerpo** | El pago de tu premio de la partida **{game.name}** ha sido registrado por el equipo. Recibirás los fondos por el canal acordado. |
| **CTA futuro** | Enlace a detalle del payout (si hay vista pública) |
| **Nota** | No incluir monto ni referencia bancaria en el email |

---

### Template 5: `game_winner_declared`

| Campo | Valor |
|-------|-------|
| **Asunto** | `¡Felicitaciones! Has ganado la partida` |
| **Destinatario** | Ganador (`winner_user_id`) |
| **Variables permitidas** | `game_id` (nombre del juego), `game_winner_id`, `occurred_at` |
| **Variables prohibidas** | `game_draw_id`, `game_number_id`, `winner_user_id` (no exponer al usuario su propio ID interno) |
| **Cuerpo** | ¡Felicitaciones! Ganaste la partida **{game.name}**. Pronto recibirás más información sobre tu premio. |
| **CTA futuro** | Enlace a la página pública del ganador |

---

## 9. Configuración Mail / Queue

### 9.1 Estado actual (`.env`)

| Variable | Valor actual | Estado |
|----------|-------------|--------|
| `MAIL_MAILER` | `log` | ✓ seguro en desarrollo (log en `storage/logs`) |
| `MAIL_HOST` | `127.0.0.1` | Sin Mailpit configurado |
| `MAIL_PORT` | `2525` | Puerto que no está mapeado en docker-compose actual |
| `MAIL_USERNAME` | `null` | Sin autenticación |
| `MAIL_FROM_ADDRESS` | `hello@example.com` | Placeholder — cambiar antes de producción |
| `QUEUE_CONNECTION` | `database` | Usa tabla `jobs` en PostgreSQL |
| `REDIS_CLIENT` | `phpredis` | Extensión no instalada — no bloquea tests |
| `REDIS_HOST` | `redis` | Alias Docker OK |
| `REDIS_PORT` | `6379` | OK |

### 9.2 Estado en testing (`phpunit.xml`)

| Variable | Valor | Efecto |
|----------|-------|--------|
| `MAIL_MAILER` | `array` | Emails en memoria, ninguno enviado realmente |
| `QUEUE_CONNECTION` | `sync` | Jobs ejecutados inline, sin workers |
| `CACHE_STORE` | `array` | Cache en memoria |

Esto significa que la suite actual **no requiere Redis ni Mailpit**. Los tests de Fase 9.2 podrán usar `Notification::fake()` y `Mail::fake()` sin cambiar esta configuración.

### 9.3 Entorno local recomendado para Fase 9.2

Agregar **Mailpit** al `docker-compose.yml`:

```yaml
mailpit:
  image: axllent/mailpit:latest
  container_name: rifas_mailpit
  ports:
    - "8025:8025"   # UI web de Mailpit
    - "1025:1025"   # SMTP
  restart: unless-stopped
```

Y actualizar `.env`:

```env
MAIL_MAILER=smtp
MAIL_HOST=mailpit
MAIL_PORT=1025
MAIL_USERNAME=null
MAIL_PASSWORD=null
MAIL_FROM_ADDRESS="noreply@rifas.local"
MAIL_FROM_NAME="Rifas"
```

Así los emails se interceptan visualmente en `http://localhost:8025` sin salir a internet.

### 9.4 Producción esperada

| Variable | Valor recomendado |
|----------|------------------|
| `MAIL_MAILER` | `smtp` (+ proveedor: Resend, SES, Postmark) |
| `QUEUE_CONNECTION` | `database` (suficiente) o `redis` (si escala) |
| `REDIS_CLIENT` | `phpredis` (instalar extensión) o `predis` (sin extensión) |

### 9.5 ¿`database` queue o Redis para Fase 9.2?

**Recomendación: `database` queue para Fase 9.2.**

- La tabla `jobs` ya existe (`0001_01_01_000002_create_jobs_table.php`).
- `QUEUE_CONNECTION=database` ya está en `.env`.
- El Outbox reemplaza al queue para los eventos de dominio críticos; el queue se usa solo para Auth notifications.
- Redis agrega complejidad (phpredis no instalado) sin beneficio real en esta escala.
- Si escala en producción, migrar a Redis es un cambio de configuración, no de código.

### 9.6 ¿Instalar phpredis antes de Fase 9.2?

**No es necesario para Fase 9.2.** Las queues de Fase 9.2 pueden usar `database`. Si en Fase 9.3 se necesita Redis para performance o broadcasting, instalar entonces:

```dockerfile
RUN apk add --no-cache $PHPIZE_DEPS \
    && pecl install redis \
    && docker-php-ext-enable redis
```

### 9.7 Operación del worker de cola

`QUEUE_CONNECTION=database` requiere un **worker activo** para que los jobs encolados se procesen. Sin worker, `$user->notify()` deja el job en la tabla `jobs` y el email nunca se envía.

**Desarrollo local**:
```bash
# Dentro del contenedor Docker:
docker compose exec app php artisan queue:work --queue=default

# O directamente:
php artisan queue:work --queue=default
```

El worker procesa jobs indefinidamente. Para reiniciar tras cambios de código:
```bash
php artisan queue:restart
```

**Tests** (`phpunit.xml` override `QUEUE_CONNECTION=sync`):
- Los jobs se ejecutan inline, en el mismo proceso del test.
- `Notification::fake()` intercepta los envíos — no hay conexión real al mailer.
- No se requiere worker activo.

**Producción** (Fase 9.2+):
- Usar **Supervisor** para mantener el worker activo:
```ini
[program:rifas-worker]
command=php /var/www/artisan queue:work database --sleep=3 --tries=3 --timeout=90
autostart=true
autorestart=true
numprocs=1
redirect_stderr=true
```

**Tabla `failed_jobs`**: existe desde Fase 1 (`0001_01_01_000002_create_jobs_table.php`). Jobs que fallan tras los reintentos configurados se mueven aquí. Revisar con `php artisan queue:failed`.

---

## 10. Manejo de fallos

### 10.1 Interacción con el ciclo de vida de `outbox_events`

El handler de dominio corre dentro del `processOne()` del `OutboxEventProcessor`. Si lanza cualquier excepción:

```
attempts < max_attempts  → attempts++, next_attempt_at = now() + backoff, locked_at = NULL
attempts >= max_attempts → failed_at = now(), locked_at = NULL
```

`processed_at` **solo se marca cuando el handler completa sin excepción**.

### 10.2 Escenarios de fallo y respuesta

| Escenario | Respuesta del handler | Efecto en Outbox |
|-----------|----------------------|-----------------|
| `User::find($id)` retorna null | Lanzar `OutboxHandlerException` | Reintento con backoff |
| Payment ya no está en estado `approved` | Loguear + return sin excepción | Se marca `processed_at` — no reintenta (el estado cambió, no tiene sentido notificar) |
| Order ya está en estado terminal diferente al esperado | Loguear + return sin excepción | Se marca `processed_at` |
| Mailer falla (timeout, SMTP error) | Excepción propagada | Reintento con backoff |
| `notification_deliveries` ya tiene fila (idempotencia) | Return sin excepción | Se marca `processed_at` — idempotente |
| `notification_deliveries` INSERT falla por UNIQUE violation | INSERT ... ON CONFLICT DO NOTHING → return | Se marca `processed_at` — idempotente |

### 10.3 ¿Cuándo se reintenta vs cuándo se descarta?

| Situación | Decisión | Justificación |
|-----------|----------|--------------|
| Usuario no encontrado (posible race condition de borrado) | Reintenta | El usuario podría estar siendo creado concurrentemente o es un error transitorio |
| Modelo de dominio en estado incompatible | Descarta (mark processed) | El estado del dominio cambió; reenviar la notificación sería incorrecto |
| Mailer falla | Reintenta | Error transitorio; SMTP puede recuperarse |
| `failed_at` alcanzado (5 intentos) | Alerta operacional | Investigar manualmente; el usuario no fue notificado |

### 10.4 Registro de errores

El `OutboxEventProcessor` ya registra `last_error` en cada fallo. Adicionalmente, los handlers deben usar `Log::error()` con contexto estructurado para facilitar diagnóstico:

```php
Log::error('outbox.payment_approved.handler_failed', [
    'outbox_event_id' => $event->id,
    'error'           => $e->getMessage(),
    'buyer_user_id'   => $payload['buyer_user_id'] ?? null,
]);
```

---

## 11. Seguridad y privacidad

### 11.1 Invariantes de los handlers

- Los handlers **no leen** campos de modelos que no sean IDs para el lookup.
- Los handlers **no incluyen** en el `MailMessage` ningún dato sensible (ver §8).
- Las `Notification` de dominio **no reciben** el `OutboxEvent` completo — reciben solo los IDs necesarios.
- Los logs de error no incluyen tokens, passwords, ni datos financieros.

### 11.2 Guards de arquitectura (a crear en Fase 9.2)

```
Phase91NotificationArchitectureTest (Unit):
  1. Handlers no importan Mail:: ni Notification:: directamente
     (solo $user->notify())
  2. Domain Notifications no están en app/Notifications/Auth/
  3. Auth Notifications no están en app/Notifications/Domain/
  4. Ningún Action de dominio llama notify() o Mail::
  5. notification_deliveries no expone campos sensibles
  6. No existe gateway de pago en Notifications/Domain/
  7. No existe SMS en las Notifications
  8. No existe WhatsApp en Fase 9.2 (postergado)
```

---

## 12. Pruebas requeridas para Fase 9.2

### 12.1 Tests de integración por handler

Para cada uno de los 5 handlers:

```
tests/Integration/Shared/
  PaymentApprovedNotificationHandlerTest.php
  PaymentRejectedNotificationHandlerTest.php
  OrderRefundedNotificationHandlerTest.php
  WinnerPayoutRegisteredNotificationHandlerTest.php
  GameWinnerDeclaredNotificationHandlerTest.php
```

Cada test debe cubrir:
- Handler crea fila en `notification_deliveries` (status=`pending`) **antes** de llamar a `notify()`.
- Handler envía `Notification::fake()` al destinatario correcto.
- Handler no llama a `notify()` si `notification_deliveries` ya tiene status=`queued` (`isFinalOrQueued()=true`).
- Handler no llama a `notify()` si `notification_deliveries` ya tiene status=`sent` (`isFinalOrQueued()=true`).
- Handler no llama a `notify()` si `pending` es reciente (`isPendingFresh()=true`) — simular caída post-notify.
- Handler puede reintentar si `pending` está vencido (`isRetryablePending()=true`).
- Cuando `notify()` con `ShouldQueue` es llamado, `queued_at` se actualiza y status pasa a `queued`.
- Handler descarta correctamente si el modelo de dominio está en estado incompatible (status → `failed`, no exception).
- Handler lanza excepción si el usuario no existe (para que Outbox reintente); la fila en `notification_deliveries` queda con status=`pending`.
- Retry inmediato tras caída simulada post-`notify()` no duplica el envío (pending fresco bloquea el segundo call).
- Payload no contiene PII ni campos prohibidos.
- `deduplication_key` sigue el formato `"{outbox_event_id}:{recipient_user_id}:mail"`.
- `UNIQUE(deduplication_key)` impide dos filas con la misma clave aun bajo concurrencia.

### 12.2 Tests de Auth notifications (Fase 9.2)

```
tests/Feature/Auth/
  PasswordResetTest.php         ← ya existe (28 tests) — agregar test de ShouldQueue
  EmailVerificationTest.php     ← ya existe (18 tests) — agregar test de ShouldQueue
```

### 12.3 Tests de arquitectura

```
tests/Unit/Architecture/
  Phase91NotificationArchitectureTest.php   ← nuevo
```

Guards requeridos (mínimo):
1. Handler de `payment_approved` envía `Notification` al comprador.
2. Handler de `payment_rejected` envía `Notification` al comprador.
3. Handler de `order_refunded` envía `Notification` al comprador.
4. Handler de `winner_payout_registered` envía `Notification` al ganador.
5. Handler de `game_winner_declared` envía `Notification` al ganador.
6. `Notification::fake()` cubre todos los envíos — ningún email real en testing.
7. At-least-once no duplica delivery visible si `notification_deliveries` tiene fila.
8. Fallo del mailer mantiene `outbox_events` reintentable.
9. Payload del `MailMessage` no incluye PII.
10. Handler no usa datos sensibles (`path`, `disk`, `sha256`, `reason`, `reviewer_user_id`).
11. Ningún Action de dominio llama `notify()` o `Mail::` directamente.
12. No existe proveedor de gateway en ninguna `Notification`.
13. No existe SMS ni WhatsApp en `Notifications/Domain/`.
14. `RecordOutboxEventAction` no aparece en ningún handler de notificación.

### 12.4 Tests de `notification_deliveries`

```
tests/Integration/Shared/
  NotificationDeliveriesConstraintsTest.php
```

Covers:
- `UNIQUE(deduplication_key)` impide duplicados incluso con clave diferente por canal.
- `ON CONFLICT DO NOTHING` silencia la segunda inserción — no lanza excepción.
- `claim()` retorna la fila existente cuando ya fue insertada (segundo attempt del Outbox).
- `isFinalOrQueued()` retorna `true` cuando status=`queued` o `sent`.
- `isFinalOrQueued()` retorna `false` cuando status=`pending` o `failed`.
- `isPendingFresh()` retorna `true` cuando `updated_at >= now() - PENDING_FRESH_SECONDS`.
- `isPendingFresh()` retorna `false` cuando `updated_at < now() - PENDING_FRESH_SECONDS`.
- `isRetryablePending()` retorna `true` cuando `pending` vencido y `attempts < max_attempts`.
- `isRetryableFailed()` retorna `true` cuando `failed` y `attempts < max_attempts`.
- `markQueued()` actualiza status=`queued` y `queued_at`.
- `markFailed(string $reason)` actualiza status=`failed`, `failed_at`, `last_error`, incrementa `attempts`.
- `markSent()` actualiza status=`sent` y `sent_at` (para Opción B con listener).
- Dos inserciones con misma `deduplication_key` bajo concurrencia — solo una tiene éxito.
- El sistema no promete exactly-once — documentar como best-effort en el test de zona ambigua.

---

## 13. Plan de implementación — Fase 9.2

### Paso 1 — Migración `notification_deliveries`

```bash
php artisan make:migration create_notification_deliveries_table
```

Campos: ver §6.2. Incluir `UNIQUE(deduplication_key)` donde `deduplication_key = "{outbox_event_id}:{recipient_user_id}:{channel}"`.

### Paso 2 — Modelo `NotificationDelivery`

- `HasUuids`, `$incrementing = false`, `$keyType = 'string'`.
- `$fillable` con todos los campos de §6.2.
- Constantes de estado: `STATUS_PENDING`, `STATUS_QUEUED`, `STATUS_SENT`, `STATUS_FAILED`.
- Constante `PENDING_FRESH_SECONDS = 300` (5 minutos, ajustable).
- Constante `MAX_ATTEMPTS = 3`.
- Método estático `claim(string $outboxEventId, int $recipientUserId, string $channel): self` — INSERT ... ON CONFLICT DO NOTHING; retorna la fila (existente o nueva).
- Método `isFinalOrQueued(): bool` — `true` si status=`queued` o `sent`.
- Método `isPendingFresh(): bool` — `true` si status=`pending` y `updated_at >= now() - PENDING_FRESH_SECONDS`.
- Método `isRetryablePending(): bool` — `true` si status=`pending` vencido y `attempts < MAX_ATTEMPTS`.
- Método `isRetryableFailed(): bool` — `true` si status=`failed` y `attempts < MAX_ATTEMPTS`.
- Método `markQueued(): void` — status=`queued`, `queued_at = now()`, `updated_at = now()`.
- Método `markSent(): void` — status=`sent`, `sent_at = now()`, `updated_at = now()` (para Opción B con listener).
- Método `markFailed(string $reason): void` — status=`failed`, `failed_at = now()`, `last_error = $reason`, `attempts++`, `updated_at = now()`.

### Paso 3 — Handlers reales

Crear los 5 handlers en `app/Modules/Shared/Infrastructure/Outbox/Handlers/`.

Cada handler sigue el flujo claim-first de §5.3:
1. Extrae IDs del payload.
2. Llama `NotificationDelivery::claim()` — INSERT ... ON CONFLICT DO NOTHING.
3. Si `isFinalOrQueued()` → return (no reenviar).
4. Si `isPendingFresh()` → return (zona ambigua: evitar retry inmediato).
5. Hace lookup de `User` — si null, lanza excepción para reintento con backoff.
6. Valida estado del modelo de dominio — si incompatible, `markFailed()` + return sin excepción.
7. `$user->notify(new DomainXxxNotification(...))`.
8. `$delivery->markQueued()`.

Inyectar los handlers en `OutboxEventDispatcher` vía constructor.

### Paso 4 — Domain Notifications

Crear los 5 `Notification` en `app/Notifications/Domain/`. Cada una:
- `final class`, `extends Notification`, `implements ShouldQueue`.
- `via()` → `['mail']`.
- `toMail()` → Markdown `MailMessage` con el template del §8.
- No recibe PII en el constructor.

### Paso 5 — Mailpit en docker-compose.yml

Agregar servicio `mailpit` (ver §9.3).

### Paso 6 — Auth notifications como queued

Agregar `implements ShouldQueue` a `VerifyEmailNotification`. Evaluar si `PasswordResetNotification` (Laravel default) se puede sobrescribir via `User::sendPasswordResetNotification()`.

### Paso 7 — Tests

Escribir tests según §12. Pint → `git diff --check` → `git status --short`.

### Paso 8 — Guards de arquitectura

Crear `Phase91NotificationArchitectureTest` según §12.3.

---

## 14. Límites explícitos de Fase 9

Lo siguiente **no se implementa** en Fase 9.x y requiere aprobación antes de iniciar:

| Feature | Estado |
|---------|--------|
| Proveedor SMTP real (Resend, SES, Postmark) | Fuera de Fase 9.2 — se usa Mailpit |
| WhatsApp | Fuera de Fase 9.2 — postergado a 9.3 o posterior |
| SMS | Fuera de alcance |
| Gateway de pagos externo | Fuera de alcance |
| Webhooks externos | Fuera de alcance |
| Frontend de notificaciones | Fuera de alcance |
| Panel admin de notificaciones | Fuera de alcance |
| Templates definitivos con branding | Fuera de Fase 9.2 — templates mínimos en Markdown |
| Workers productivos adicionales | El Outbox worker actual (`ProcessOutboxEventsJob`) es suficiente |
| Nuevos eventos Outbox | Solo los 5 ya integrados en Fase 8 |
| Cambios en Auth, Commerce o Game Engine | Fuera de alcance |
| Cleanup histórico de `notification_deliveries` | Fuera de Fase 9.2 |
| `onOneServer()` en el scheduler | Requiere Redis compartido — postergado |
| phpredis instalado en imagen Docker | No necesario para 9.2 |
| Notificación de invitación de jugador | Requiere definición de canal separada |
