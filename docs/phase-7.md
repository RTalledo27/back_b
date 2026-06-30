# Fase 7 — Identidad completa: password reset y verificación de correo

## 1. Resumen final

Fase 7 cierra el sistema de identidad añadiendo las dos piezas que faltaban tras Fase 5 (auth base) y Fase 6 (commerce):

- **Fase 7.1** — Auditoría y contrato de identidad (sin código productivo).
- **Fase 7.2** — Password reset completo con broker nativo de Laravel.
- **Fase 7.3** — Email verification con URL firmada temporal + protección de endpoints de comercio.
- **Fase 7.4** — Hardening, guards de arquitectura adicionales y documentación final.

Resultado: sistema de identidad completo, auditado y sin deuda técnica en el dominio de autenticación.

---

## 2. Endpoints finales

### 2.1 Password reset

```
POST  /api/v1/auth/forgot-password
      throttle:auth.forgot-password (5/min por IP+email normalizado)
      → ForgotPasswordController
      Sin auth:sanctum — accesible sin sesión activa.

POST  /api/v1/auth/reset-password
      throttle:auth.reset-password (5/min por IP)
      → ResetPasswordController
      Sin auth:sanctum — accesible sin sesión activa.
```

### 2.2 Email verification

```
POST  /api/v1/auth/email/verification-notification
      auth:sanctum, throttle:auth.resend-verification (3 por 10 min por user_id)
      → SendEmailVerificationNotificationController

POST  /api/v1/auth/email/verify/{id}/{hash}
      auth:sanctum, signed, throttle:auth.verify-email (6/min por user_id+IP)
      → VerifyEmailController
      name: auth.email.verify
```

### 2.3 Rutas de identidad preexistentes

```
POST  /api/v1/auth/register          throttle:auth.register
POST  /api/v1/auth/login             throttle:auth.login
POST  /api/v1/auth/activate          throttle:auth.activate
POST  /api/v1/auth/logout            auth:sanctum
GET   /api/v1/auth/me                auth:sanctum
GET   /api/v1/auth/social-accounts   auth:sanctum
GET   /api/v1/auth/social/{provider}/redirect   throttle
GET   /api/v1/auth/social/{provider}/callback   throttle
POST  /api/v1/auth/social/exchange   throttle
GET   /api/v1/auth/social/{provider}/link/redirect   auth:sanctum + throttle
GET   /api/v1/auth/social/{provider}/link/callback   throttle
DELETE /api/v1/auth/social/{provider}   auth:sanctum + throttle
```

---

## 3. Password reset implementado

### 3.1 Archivos creados

| Archivo | Propósito |
|---------|-----------|
| `app/Exceptions/Auth/PasswordResetException.php` | Excepción para estados de broker distintos de `PASSWORD_RESET` → HTTP 422 |
| `app/Http/Requests/Auth/ForgotPasswordRequest.php` | Valida y normaliza el email; expone `normalizedEmail()` |
| `app/Http/Requests/Auth/ResetPasswordRequest.php` | Valida email, token y password; expone `toCredentials()` |
| `app/Actions/Auth/SendPasswordResetLinkAction.php` | Llama al broker; loguea solo cuando se envía; devuelve `void` |
| `app/Actions/Auth/ResetPasswordAction.php` | Llama al broker; callback con `DB::transaction`: password → `email_verified_at` → `tokens()->delete()` |
| `app/Http/Controllers/Auth/ForgotPasswordController.php` | Thin controller; siempre responde 200 (anti-enumeración) |
| `app/Http/Controllers/Auth/ResetPasswordController.php` | Thin controller; delega en `ResetPasswordAction`; 200 en éxito |

### 3.2 Flujo `POST /auth/forgot-password`

1. Normalizar email (trim + lowercase).
2. `Password::sendResetLink(['email' => $normalizedEmail])`.
3. El broker busca el usuario, genera token, almacena `Hash::make($token)` (bcrypt) en `password_reset_tokens`.
4. Delega envío a `User::sendPasswordResetNotification($token)`.
5. Si `RESET_THROTTLED` → silenciar (barrera externa: rate limiter).
6. Log `auth.password_reset_requested` con `user_id` **solo** cuando el broker retorna `RESET_LINK_SENT`.
7. Siempre HTTP 200 con el mismo mensaje — nunca revela si el email existe.

**Respuesta (HTTP 200, siempre):**

```json
{ "message": "Si el correo existe, enviaremos instrucciones para restablecer la contraseña." }
```

### 3.3 Flujo `POST /auth/reset-password`

```php
Password::reset($credentials, function (User $user, string $password): void {
    DB::transaction(function () use ($user, $password): void {
        $user->forceFill(['password' => $password]);
        if ($user->email_verified_at === null) {
            $user->forceFill(['email_verified_at' => now()]);
        }
        $user->save();
        $user->tokens()->delete();   // revocar todos los tokens Sanctum
    });
});
```

**Respuesta en éxito (HTTP 200):**

```json
{ "message": "Contraseña actualizada correctamente." }
```

**Respuesta en error (HTTP 422):**

```json
{ "message": "No se pudo restablecer la contraseña con los datos proporcionados.", "code": "password_reset_invalid" }
```

El mismo código para token inválido, token expirado, email no coincide, usuario no encontrado — nunca se diferencia el motivo.

---

## 4. Email verification implementado

### 4.1 Archivos creados

| Archivo | Propósito |
|---------|-----------|
| `app/Exceptions/Auth/EmailVerificationException.php` | Excepción para id/hash mismatch → HTTP 422 |
| `app/Http/Middleware/EnsureEmailIsVerified.php` | Middleware `verified`; 403 JSON `email_not_verified` si no verificado |
| `app/Notifications/Auth/VerifyEmailNotification.php` | Notificación con URL firmada; soporta `FRONTEND_EMAIL_VERIFY_URL` |
| `app/Actions/Auth/SendEmailVerificationNotificationAction.php` | Envía `VerifyEmailNotification` solo si no verificado; loguea |
| `app/Actions/Auth/VerifyEmailAction.php` | Valida id + `hash_equals(sha1, hash)`; idempotente; `forceFill email_verified_at` |
| `app/Http/Controllers/Auth/SendEmailVerificationNotificationController.php` | Thin controller; siempre 200 |
| `app/Http/Controllers/Auth/VerifyEmailController.php` | Thin controller; delega en `VerifyEmailAction` |

### 4.2 Flujo `POST /auth/email/verification-notification`

1. Si `$user->hasVerifiedEmail()` → retornar sin enviar (idempotente).
2. Enviar `VerifyEmailNotification` con `URL::temporarySignedRoute('auth.email.verify', ...)`.
3. Log `auth.verification_email_sent` con `user_id`.
4. Siempre HTTP 200 — no revela estado de verificación del usuario.

**Respuesta (HTTP 200, siempre):**

```json
{ "message": "Si tu correo aún no está verificado, enviaremos un enlace de verificación." }
```

### 4.3 Flujo `POST /auth/email/verify/{id}/{hash}`

La URL viene firmada desde el correo. El frontend la parsea y hace POST al backend.

1. Validar firma Laravel (`signed` middleware — `ValidateSignature`).
2. Validar que `(string) $user->getKey() === $id`.
3. Validar que `hash_equals(sha1($user->getEmailForVerification()), $hash)`.
4. Si ya verificado → retornar sin modificar (idempotente).
5. `$user->forceFill(['email_verified_at' => now()])->save()`.
6. Log `auth.email_verified` con `user_id`.

**Respuesta en éxito (HTTP 200):**

```json
{ "message": "Correo verificado correctamente.", "email_verified": true }
```

**Respuesta en firma inválida/expirada (HTTP 422):**

```json
{ "message": "No se pudo verificar el correo con los datos proporcionados.", "code": "email_verification_invalid" }
```

**Respuesta en id/hash mismatch (HTTP 422):**

```json
{ "message": "No se pudo verificar el correo con los datos proporcionados.", "code": "email_verification_invalid" }
```

---

## 5. Rate limits

### 5.1 Rate limiters de identidad completos

| Nombre | Clave | Límite | Endpoint |
|--------|-------|--------|----------|
| `auth.register` | IP + email normalizado | 5/min | `POST /auth/register` |
| `auth.login` | IP + email normalizado | 5/min | `POST /auth/login` |
| `auth.activate` | IP + hmac(token) | 10/min | `POST /auth/activate` |
| `auth.forgot-password` | IP + email normalizado | 5/min | `POST /auth/forgot-password` |
| `auth.reset-password` | IP | 5/min | `POST /auth/reset-password` |
| `auth.resend-verification` | user_id | 3 por 10 min | `POST /auth/email/verification-notification` |
| `auth.verify-email` | user_id + IP | 6/min | `POST /auth/email/verify/{id}/{hash}` |
| `admin.create-player` | user_id | 20/min | `POST /admin/players` |
| `auth.social.redirect` | IP + provider | 20/min | Social redirect |
| `auth.social.callback` | IP + provider | 20/min | Social callback |
| `auth.social.exchange` | IP | 20/min | Social exchange |
| `auth.social.link.redirect` | user_id + provider | 20/min | Social link redirect |
| `auth.social.link.callback` | IP + provider | 20/min | Social link callback |
| `auth.social.unlink` | user_id + provider | 10/min | Social unlink |

**Respuesta HTTP 429:**

```json
{ "message": "Too many authentication attempts.", "error": "too_many_requests" }
```

### 5.2 Throttle interno del broker de passwords

El broker nativo de Laravel aplica `throttle: 60` segundos entre solicitudes por email (configurado en `config/auth.php`). Es una segunda barrera después del rate limiter externo.

---

## 6. Seguridad

### 6.1 Anti-enumeración

| Endpoint | Comportamiento |
|----------|----------------|
| `POST /auth/forgot-password` | HTTP 200 siempre, mismo mensaje — nunca revela si el email existe |
| `POST /auth/reset-password` | HTTP 422 con `password_reset_invalid` para token inválido, expirado, email incorrecto o usuario inexistente |
| `POST /auth/email/verification-notification` | HTTP 200 siempre — no revela si el email está verificado |

### 6.2 Almacenamiento de tokens

| Token | Almacenado en DB | Formato en DB | Single-use |
|-------|-----------------|---------------|------------|
| Password reset | `password_reset_tokens` | `Hash::make($token)` (bcrypt) | Sí — broker borra tras validar |
| Email verification | No se persiste | N/A — URL firmada con `APP_KEY` | No — re-submit es idempotente |

El token plano de reset **nunca** se loguea, serializa en responses ni se almacena sin hashear.

### 6.3 Revocación tras reset de contraseña

Dentro de la misma `DB::transaction`:
1. Cambio de password → `password_reset_tokens` se borra (broker).
2. `$user->tokens()->delete()` — todos los tokens Sanctum revocados.
3. `email_verified_at = now()` si era null.

Garantiza atomicidad: nunca queda un estado intermedio (password cambiado pero tokens activos).

### 6.4 Comparación de hash en tiempo constante

`VerifyEmailAction` usa `hash_equals(sha1($user->getEmailForVerification()), $hash)` para comparar en tiempo constante, previniendo timing attacks.

### 6.5 Firma de URL de verificación

`URL::temporarySignedRoute('auth.email.verify', now()->addMinutes($ttl), ...)` — firmada con `APP_KEY`. La firma no se persiste en DB. La expiración está embebida en la URL (`expires` query param) y validada por Laravel.

### 6.6 `verified` middleware no global

El middleware `verified` (`EnsureEmailIsVerified`) está registrado **únicamente como alias** en `bootstrap/app.php`. No se aplica como middleware global, de grupo `web`, ni de grupo `api`. Se aplica solo a las dos rutas de escritura de commerce.

---

## 7. Comportamiento por tipo de usuario

| Tipo | `email_verified_at` | Puede reservar | Puede subir evidencia | Puede login | Puede hacer reset |
|------|---------------------|----------------|------------------------|-------------|-------------------|
| Local (registrado, no verificado) | NULL | ✗ 403 | ✗ 403 | ✓ | ✓ (reset verifica email) |
| Local verificado | timestamp | ✓ | ✓ | ✓ | ✓ |
| Social Google (email verificado por proveedor) | timestamp (al crear) | ✓ | ✓ | Via social | ✓ (se vuelve híbrido) |
| Social Facebook (sin verificación explícita) | Rechazado en callback — no se crea usuario | N/A | N/A | N/A | N/A |
| Social-only sin password | timestamp o NULL según proveedor | Según estado | Según estado | Via social | ✓ (crea credencial local, se vuelve híbrido) |
| Invitado no activado (password null) | NULL | ✗ | ✗ | ✗ | ✓ (reset = activación equivalente) |
| Invitado activado, no verificado | NULL | ✗ | ✗ | ✓ | ✓ |
| Admin | NULL o timestamp | Por rol | Por rol | ✓ | ✓ |

### 7.1 Casos de borde documentados

**Usuario social-only usa reset:** obtiene credencial local, se convierte en híbrido. Sus cuentas sociales no se tocan. `can_unlink` para ese proveedor sube de `false` a `true`.

**Usuario invitado no activado usa reset:** equivalente funcional de la activación. La invitación queda huérfana (no consumida). Prueba control del buzón con la misma garantía que el token de invitación.

**Reset en usuario ya verificado:** `email_verified_at` se preserva — no se sobrescribe si ya tenía valor.

**Login de usuario no verificado:** el login local siempre funciona. No hay bloqueo global por estado de verificación. La restricción aplica solo a las dos rutas de escritura de commerce.

**`ActivatePlayerAction` no setea `email_verified_at`:** el token de invitación prueba que el admin conoce el email, no que el jugador controla el buzón. Decidido en 7.1 (D11) y preservado.

---

## 8. Endpoints protegidos por `verified`

Solo los siguientes dos endpoints requieren email verificado:

```
POST /api/v1/games/{game}/reservations
      middleware: auth:sanctum, verified, idempotent

POST /api/v1/me/orders/{order}/payment-evidence
      middleware: auth:sanctum, verified, idempotent
```

**Respuesta para usuario no verificado (HTTP 403):**

```json
{ "message": "Debes verificar tu correo antes de realizar esta acción.", "code": "email_not_verified" }
```

---

## 9. Endpoints explícitamente NO protegidos por `verified`

| Endpoint | Razonamiento |
|----------|-------------|
| `GET /api/v1/auth/me` | El usuario necesita ver su estado aunque no esté verificado |
| `GET /api/v1/me/orders` | Lectura — accesible sin verificar |
| `GET /api/v1/me/entries` | Lectura — accesible sin verificar |
| `GET /api/v1/me/reservations` | Lectura — accesible sin verificar |
| `POST /api/v1/auth/login` | No se bloquea login globalmente |
| `POST /api/v1/auth/register` | El flujo de registro crea usuario no verificado |
| `POST /api/v1/auth/forgot-password` | Accesible sin sesión |
| `POST /api/v1/auth/reset-password` | Accesible sin sesión |
| `GET /api/v1/auth/social/*` | OAuth no requiere verificación previa |
| `GET /api/v1/public/*` | Rutas públicas sin auth |
| `POST|GET /api/v1/admin/*` | Acceso controlado por rol, no por verificación de correo |

---

## 10. Tests y verificación final

### 10.1 Cobertura de tests por suite

| Suite | Archivo | Tests | Assertions |
|-------|---------|-------|------------|
| Feature/Auth | `PasswordResetTest.php` | 28 | ~70 |
| Feature/Auth | `EmailVerificationTest.php` | 18 | ~55 |
| Feature/Commerce | `EmailVerificationCommerceTest.php` | 7 | 9 |
| Integration/Architecture | `Phase7IdentityArchitectureTest.php` | 24 | 266 |

### 10.2 Escenarios cubiertos

**Password reset (28 tests):**
- `forgot-password`: HTTP 200 para email existente y no existente, mismo body (anti-enumeración), envía notificación solo si existe, no envía si no existe, valida formato, requiere email, normaliza email (mayúsculas/espacios), rate limit activo, URL contiene `token=` y `email=`
- `reset-password`: token válido actualiza password, token inválido → 422 `password_reset_invalid`, token expirado → 422, email que no coincide → 422, requiere confirmación, longitud mínima 8, revoca todos los tokens Sanctum, setea `email_verified_at` si null, preserva `email_verified_at` si ya seteado, permite usuario social-only (crea credencial local), permite invitado no activado, permite login con nueva password, rechaza login con password anterior, no cambia role, no toca `user_social_accounts`, no crea usuario para email desconocido, responses no exponen token plano, rate limit activo

**Email verification (18 tests):**
- resend: 200 + notification para no verificado; 200 + sin notification para verificado (idempotente); 401 sin auth; 429 tras 3 requests
- verify: 200 + `email_verified_at` set para URL válida; idempotente si ya verificado; 422 id mismatch; 422 hash mismatch; 422 URL expirada; 422 firma adulterada; 401 sin auth; `/me` refleja `email_verified: true` tras verificar; 429 tras 6 requests
- seguridad/hardening: login no bloqueado globalmente para usuario no verificado; `/me` expone `email_verified_at: null` cuando no verificado
- behavioral: local register → unverified; Google OAuth → verified; Facebook unverified email → rechazado (`error=verified_email_required`, usuario no creado)

**Commerce/verified (7 tests):**
- No verificado no puede POST reservation → 403 `email_not_verified`
- Verificado puede POST reservation → 201
- No verificado no puede POST payment-evidence → 403 `email_not_verified`
- Verificado puede POST payment-evidence → 200
- No verificado puede GET `/me/orders` → 200
- No verificado puede GET `/me/entries` → 200
- No verificado puede GET `/public/games` → 200

**Arquitectura (24 guards):**
- Controladores no llaman `DB::transaction`
- `ResetPasswordAction` llama `DB::transaction` y `tokens()->delete()`
- `ResetPasswordAction` maneja `email_verified_at`; `ResetPasswordController` no lo toca
- Ningún controlador consulta `password_reset_tokens` directamente
- `ForgotPasswordController` no ramifica en `RESET_LINK_SENT` ni `INVALID_USER`
- Ninguna acción inserta en `password_reset_tokens` directamente
- Acciones usan `Password::sendResetLink` / `Password::reset`; no `Mail::` ni `DB::statement`
- `VerifyEmailAction` usa `hash_equals` y `getEmailForVerification()`
- `VerifyEmailAction` no toca tokens ni role
- `VerifyEmailController` no valida id/hash (la acción lo hace)
- `SendEmailVerificationNotificationController` no ramifica en `hasVerifiedEmail`
- `EnsureEmailIsVerified` retorna JSON 403, no redirect; incluye `email_not_verified`
- `VerifyEmailNotification` usa `temporarySignedRoute` (con TTL), no `signedRoute`
- `verified` registrado solo como alias, no como middleware global
- No existen archivos 2FA/SMS/phone/magic-link en directorios de auth
- `AuthUserResource` no contiene queries Eloquent
- `verified` aparece exactamente 2 veces en `routes/api.php`

### 10.3 Suite completa al cierre de Fase 7.4

```
php artisan test --compact → 1169+ passed (5300+ assertions)
vendor/bin/pint --dirty --format agent → {"tool":"pint","result":"passed"}
git diff --check → exit 0
```

---

## 11. Límites explícitos

**No se implementa en Fase 7:**

| Feature | Estado |
|---------|--------|
| 2FA (TOTP, authenticator app) | No implementado |
| SMS / OTP por SMS | No implementado |
| Phone login | No implementado |
| Magic links | No implementado |
| Outbox / notificaciones en queue | No implementado — envío síncrono |
| Gateway de pagos | No implementado |
| Frontend | No implementado |
| Cambio de email (requeriría re-verificación) | No implementado |
| Bloqueo global de login por email no verificado | No implementado — solo 2 rutas de escritura |
| Verificación de email para admins | No requerido por diseño |
| OAuth providers adicionales | No implementado |

---

## 12. Pendientes fuera de Fase 7

| Item | Fase sugerida |
|------|---------------|
| Notificaciones en queue / Outbox pattern | Fase 9 |
| Cambio de email con re-verificación | Fase 9+ |
| 2FA opcional (TOTP) | No planificado |
| Email de bienvenida al registro | Fase 9 |
| Email de confirmación de reserva / pago | Fase 9 |
| Expiración automática de tokens Sanctum | Configuración en producción |
| Rotación de `APP_KEY` con re-firma de URLs pendientes | Ops |

---

## Apéndice — Tabla `users` al cierre de Fase 7

| Campo | Tipo | Estado |
|-------|------|--------|
| `id` | BIGINT autoincrement | PK |
| `name` | VARCHAR | NOT NULL |
| `email` | VARCHAR | UNIQUE NOT NULL |
| `email_verified_at` | TIMESTAMP nullable | Seteado por reset o verificación explícita |
| `password` | VARCHAR nullable | Nullable (social-only); CHECK no vacío si existe |
| `remember_token` | VARCHAR nullable | Presente (Laravel default) |
| `role` | VARCHAR(16) | NOT NULL DEFAULT 'player' |
| `created_at`, `updated_at` | TIMESTAMP | — |

**`password_reset_tokens`:**

| Campo | Tipo |
|-------|------|
| `email` | VARCHAR (PK) |
| `token` | VARCHAR (bcrypt del token plano) |
| `created_at` | TIMESTAMP nullable |

**`AuthUserResource` expone:**
`id`, `name`, `email`, `role`, `email_verified` (bool), `email_verified_at` (ISO 8601 UTC o null), `capabilities.can_access_admin`, `capabilities.can_use_player_features`.

**`AuthUserResource` nunca expone:**
`password`, `remember_token`, tokens Sanctum, `provider_user_id`, hashes de invitación, metadata interna.
