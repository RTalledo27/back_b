# Fase 7 — Identidad completa: password reset y verificación de correo

## 1. Alcance de Fase 7

Fase 7 cierra el sistema de identidad iniciado en Fase 5 añadiendo:

- Recuperación de contraseña (`forgot-password`).
- Reset de contraseña (`reset-password`).
- Verificación real de correo electrónico (`email/verify`).
- Reenvío del correo de verificación (`email/verification-notification`).
- Protección de endpoints según estado de verificación.
- Notificaciones mínimas (correo de reset, correo de verificación).

**No se implementa en Fase 7**: 2FA, SMS, magic links, phone login, OAuth adicional,
gateway de pagos, Outbox, notificaciones transaccionales generales, frontend.

El bloque se divide en:

- **7.1** — Auditoría y contrato (este documento). Sin código productivo.
- **7.2** — Password reset completo + tests.
- **7.3** — Email verification completo + tests + protección de endpoints.

---

## 2. Estado actual de identidad

### 2.1 Tabla `users`

| Campo | Tipo | Estado |
|-------|------|--------|
| `id` | BIGINT autoincrement | PK |
| `name` | VARCHAR | NOT NULL |
| `email` | VARCHAR | UNIQUE NOT NULL |
| `email_verified_at` | TIMESTAMP nullable | **Existe; siempre NULL para usuarios locales e invitados** |
| `password` | VARCHAR nullable | Nullable desde Fase 5; CHECK que no sea vacío si existe |
| `remember_token` | VARCHAR nullable | Presente (Laravel default) |
| `role` | VARCHAR(16) | NOT NULL DEFAULT 'player' CHECK IN ('admin','player') |
| `created_at` | TIMESTAMP | — |
| `updated_at` | TIMESTAMP | — |

### 2.2 Tabla `password_reset_tokens`

Creada por la migración base de Laravel `0001_01_01_000000_create_users_table.php`.

| Campo | Tipo |
|-------|------|
| `email` | VARCHAR (PK) |
| `token` | VARCHAR (bcrypt del token plano) |
| `created_at` | TIMESTAMP nullable |

La tabla existe y es funcional. Un solo registro por email (la PK es `email`).

### 2.3 Broker de passwords en `config/auth.php`

```php
'passwords' => [
    'users' => [
        'provider' => 'users',
        'table'    => 'password_reset_tokens',
        'expire'   => 60,   // minutos
        'throttle' => 60,   // segundos entre solicitudes
    ],
],
```

El broker nativo de Laravel está configurado y listo para usar.

### 2.4 Modelo `User`

- Implementa `HasApiTokens`, `HasFactory`, `Notifiable`.
- **No implementa `MustVerifyEmail`** — está comentado. Debe activarse en Fase 7.
- Cast `email_verified_at → datetime`; cast `password → hashed`.
- `role` excluido de `$fillable`.

### 2.5 Comportamientos por tipo de usuario

| Tipo | `password` | `email_verified_at` | Puede login local | Tiene token Sanctum tras auth |
|------|-----------|---------------------|-------------------|-------------------------------|
| Local (registrado) | bcrypt | NULL | Sí | Sí (`local-auth`) |
| Social-only (Google, verificado) | NULL | `now()` | No | Sí (`social:{provider}`) |
| Social-only (Facebook) | NULL | NULL | No | Sí (`social:{provider}`) |
| Invitado + activado | bcrypt | NULL | Sí | Sí (`local-auth`) |
| Invitado no activado | NULL | NULL | No | No |

### 2.6 Abilities actuales de tokens Sanctum

| Ability | Cuándo se emite |
|---------|-----------------|
| `auth:logout` | Siempre |
| `player:access` | Siempre |
| `user:read` | Siempre |
| `admin:access` | Si `role = admin` |
| `social_reauth` | Solo en tokens de social login (nombre `social:{provider}`) |

### 2.7 Endpoint `/me` — `AuthUserResource`

Expone: `id`, `name`, `email`, `role`, `email_verified` (bool), `email_verified_at`
(ISO 8601 UTC o null), `capabilities.can_access_admin`,
`capabilities.can_use_player_features`.

No expone: `password`, `remember_token`, tokens Sanctum, provider IDs, hashes de
invitación ni metadata interna.

### 2.8 Rate limits existentes

| Nombre | Clave | Límite |
|--------|-------|--------|
| `auth.register` | IP + email normalizado | 5/min |
| `auth.login` | IP + email normalizado | 5/min |
| `auth.activate` | IP + hmac(token) | 10/min |
| `admin.create-player` | user_id | 20/min |
| `auth.social.redirect` | IP + provider | 20/min |
| `auth.social.callback` | IP + provider | 20/min |
| `auth.social.exchange` | IP | 20/min |
| `auth.social.link.redirect` | user_id + provider | 20/min |
| `auth.social.link.callback` | IP + provider | 20/min |
| `auth.social.unlink` | user_id + provider | 10/min |

**No existen** rate limits para `forgot-password`, `reset-password`,
`email/verification-notification` ni `email/verify`.

### 2.9 Rutas existentes en `api/v1/auth`

```
POST       /auth/register            throttle:auth.register
POST       /auth/login               throttle:auth.login
POST       /auth/activate            throttle:auth.activate
POST       /auth/logout              auth:sanctum
GET        /auth/me                  auth:sanctum
GET        /auth/social-accounts     auth:sanctum
POST       /auth/social/exchange     throttle:auth.social.exchange
GET|HEAD   /auth/social/{provider}/redirect   throttle:auth.social.redirect
GET|HEAD   /auth/social/{provider}/callback   throttle:auth.social.callback
GET|HEAD   /auth/social/{provider}/link/redirect   auth:sanctum + throttle
GET|HEAD   /auth/social/{provider}/link/callback   throttle:auth.social.link.callback
DELETE     /auth/social/{provider}   auth:sanctum + throttle
```

---

## 3. Decisiones pendientes

Las siguientes decisiones no se pueden resolver solo leyendo el código; requieren
criterio de producto. Cada una se resuelve en la sección §4.

| D# | Pregunta |
|----|----------|
| D1 | ¿Usar broker nativo de Laravel o implementación propia para password reset? |
| D2 | ¿Respuesta uniforme para email existente/no existente en `forgot-password`? |
| D3 | ¿`reset-password` revoca todos los tokens Sanctum del usuario? |
| D4 | ¿`reset-password` setea `email_verified_at`? |
| D5 | ¿Usuario social-only (sin password) puede solicitar reset y crear credencial local? |
| D6 | ¿Usuario invitado no activado (sin password) puede usar reset o debe activar por invitación? |
| D7 | ¿Formato del token de verificación de email: firmado o tabla extra? |
| D8 | ¿TTL del link de verificación de email? |
| D9 | ¿Reenvío del link: cuántas veces y con qué rate limit? |
| D10 | ¿Qué endpoints se protegen detrás de verificación? |
| D11 | ¿Activación por invitación debe marcar `email_verified_at`? |

---

## 4. Decisiones recomendadas

### D1 — Broker nativo de Laravel

**Recomendación: usar el broker nativo.**

La tabla `password_reset_tokens` ya existe. El broker en `config/auth.php` ya
está configurado (`expire: 60`, `throttle: 60`). El broker nativo:

- Genera un token aleatorio con `Str::random(100)`.
- Almacena `Hash::make($token)` (bcrypt) en la tabla, no el token plano.
- Valida expiración automáticamente.
- Invalida el token al usarse (single-use).
- Aplica throttle interno de 60 segundos entre solicitudes por email.

La arquitectura del proyecto envuelve el broker en un `ForgotPasswordAction` y un
`ResetPasswordAction` para mantener coherencia con el patrón de Actions. No se
expone directamente el broker a los controllers.

### D2 — Respuesta uniforme en `forgot-password`

**Recomendación: siempre responder con el mismo mensaje de éxito**,
independientemente de si el email existe o no.

```json
{ "message": "If this email is registered, a password reset link has been sent." }
```

HTTP 200 en ambos casos. El log de auditoría solo se registra cuando el email sí
existe (`auth.password_reset_requested`, con `user_id`, sin email plano).

### D3 — Revocación de tokens tras reset

**Recomendación: revocar todos los tokens Sanctum del usuario al completar el reset.**

Si la contraseña fue comprometida, los tokens activos también están en riesgo.
El usuario deberá autenticarse nuevamente. Implementar con
`$user->tokens()->delete()` dentro de la misma transacción del reset.

### D4 — `email_verified_at` tras reset

**Recomendación: setear `email_verified_at = now()` tras un reset exitoso.**

El proceso de reset prueba control del buzón de correo. Es equivalente a
verificación de email. Esto beneficia a todos los usuarios locales que nunca
verificaron su correo.

### D5 — Usuario social-only puede usar reset

**Recomendación: PERMITIDO.**

Un usuario social-only tiene email registrado. Si solicita reset, recibe el link en
su buzón y puede crear una credencial local. El resultado es un usuario híbrido
(social + local), lo que es intencional y útil. El reset no altera ni revoca sus
cuentas sociales.

Implicación: `can_unlink` para ese proveedor sube de `false` a `true` porque ahora
existe un segundo método de autenticación.

### D6 — Usuario invitado no activado puede usar reset

**Recomendación: PERMITIDO.**

Un usuario invitado no activado tiene email registrado. Si solicita reset y lo usa,
obtiene una contraseña local (equivalente funcional de la activación). La invitación
queda huérfana (no consumida, pero sin utilidad). Este comportamiento es aceptable:
prueba control del buzón de correo con igual garantía que el token de invitación.

No hay riesgo de que alguien externo active una cuenta ajena porque el link de reset
llega al mismo buzón que el token de invitación.

### D7 — Formato del token de verificación de email

**Recomendación: URL firmada temporalmente con `URL::temporarySignedRoute()`.**

No se necesita tabla extra. El token no se persiste. La firma criptográfica
(`APP_KEY`) garantiza integridad y expiración. Resistente a enumeración porque
cada URL es única para el usuario y el timestamp de expiración.

El endpoint `POST /api/v1/auth/email/verify` recibe `id` y `hash` como parámetros
de la URL firmada, valida la firma y marca `email_verified_at`.

### D8 — TTL del link de verificación

**Recomendación: 60 minutos**, consistente con el TTL de password reset.

Configurable mediante variable de entorno `AUTH_EMAIL_VERIFY_TTL_MINUTES` (default 60).

### D9 — Rate limit de reenvío

**Recomendación: 3 solicitudes por 10 minutos por `user_id`.**

Clave: `auth.resend-verification:{user_id}`. Responde HTTP 429 con
`error: too_many_requests` si se excede.

### D10 — Endpoints protegidos por verificación

**Recomendación: proteger los endpoints de comercio del jugador.**

En Fase 7.3 se añade el middleware `verified` (de `MustVerifyEmail`) a:

```
POST /api/v1/games/{game}/reservations
POST /api/v1/me/orders/{order}/payment-evidence
```

Los endpoints de lectura (`/me/orders`, `/me/entries`, `/me/reservations`) NO se
protegen — el jugador puede ver su estado sin verificar.

Los endpoints admin no se protegen por verificación (la autenticidad se controla
por rol, no por correo verificado).

Si un jugador no verificado intenta reservar, recibe HTTP 403 con
`error: email_not_verified`.

### D11 — Activación por invitación no verifica email

**Recomendación: mantener comportamiento actual.**

`ActivatePlayerAction` NO setea `email_verified_at`. El token de invitación prueba
que el admin conoce el email del jugador, no que el jugador controla ese buzón.
Para verificar el correo, el jugador debe pasar por el flujo de verificación.

Excepción: en Fase 7.3 se puede ofrecer que la activación envíe automáticamente un
correo de verificación post-commit.

---

## 5. Contrato de password reset

### 5.1 `POST /api/v1/auth/forgot-password`

**Middleware**: `throttle:auth.forgot-password` (ver §8).

**Request**:

```json
{ "email": "player@example.com" }
```

Validación: `required|email|max:255`. El email se normaliza (trim + lowercase) antes
de buscar.

**Respuesta en todos los casos** (HTTP 200):

```json
{
  "message": "If this email is registered, a password reset link has been sent."
}
```

**Flujo interno (`ForgotPasswordAction`)**:

1. Normalizar email.
2. Llamar `Password::sendResetLink(['email' => $email])`.
3. El broker busca el usuario, genera el token, lo almacena hasheado (bcrypt) y
   delega el envío a `User::sendPasswordResetNotification($token)`.
4. Si el broker retorna `Password::RESET_THROTTLED`, silenciar (no revelar que
   fue throttled — ya se maneja con el rate limiter externo).
5. Log solo cuando el broker retorna `Password::RESET_LINK_SENT`:
   `auth.password_reset_requested`, con `user_id`.

### 5.2 `POST /api/v1/auth/reset-password`

**Middleware**: `throttle:auth.reset-password` (ver §8).

**Request**:

```json
{
  "token": "plain-token-from-email",
  "email": "player@example.com",
  "password": "nueva-password",
  "password_confirmation": "nueva-password"
}
```

Validación: `token required|string`, `email required|email`, `password required|min:8|confirmed`.

**Respuesta en éxito** (HTTP 200):

```json
{ "message": "Password has been reset successfully." }
```

**Respuesta en error** (HTTP 422):

```json
{ "message": "This password reset token is invalid.", "error": "invalid_reset_token" }
```

**Flujo interno (`ResetPasswordAction`)**:

1. Llamar al broker:

```php
Password::reset($credentials, function (User $user, string $password): void {
    DB::transaction(function () use ($user, $password): void {
        $user->password = $password;             // cast hashed lo hashea
        $user->forceFill(['email_verified_at' => now()]);
        $user->save();
        $user->tokens()->delete();              // revocar todos los tokens Sanctum
    });
});
```

2. El broker valida: token no expirado, hash correcto, email coincide.
3. Si `Password::INVALID_TOKEN` → throw exception mapeada a 422 con
   `error: invalid_reset_token`.
4. Si `Password::INVALID_USER` → misma respuesta (no revelar ausencia del usuario).
5. Log en éxito: `auth.password_reset_completed`, con `user_id`.

**Notas de seguridad**:

- El token plano nunca se almacena ni se loguea.
- `password_reset_tokens` almacena `Hash::make($token)` — si se compromete la DB, los tokens no son recuperables.
- La revocación de tokens Sanctum ocurre dentro de la misma transacción que el cambio de contraseña.
- `email_verified_at` se setea solo en éxito, dentro de la misma transacción.

---

## 6. Contrato de email verification

### 6.1 `MustVerifyEmail`

El modelo `User` debe implementar `Illuminate\Contracts\Auth\MustVerifyEmail`.
Esto añade:

- `hasVerifiedEmail(): bool` — `email_verified_at !== null`.
- `markEmailAsVerified(): bool` — setea `email_verified_at = now()`, dispara `Verified` event.
- `sendEmailVerificationNotification(): void` — override para usar nuestra notification.

No se usa el envío automático que Laravel hace en el registro (no hay
`Illuminate\Auth\Listeners\SendEmailVerificationNotification` registrado).
El envío es controlado desde `SendEmailVerificationAction`.

### 6.2 `POST /api/v1/auth/email/verification-notification`

**Middleware**: `auth:sanctum + throttle:auth.resend-verification`.

Sin body requerido. El usuario autenticado solicita reenvío.

**Respuesta en éxito** (HTTP 200):

```json
{ "message": "Verification email sent." }
```

**Respuesta si ya verificado** (HTTP 200):

```json
{ "message": "Email is already verified." }
```

No se lanza error si ya está verificado — respuesta uniforme para evitar que el
llamador infiera estado de otra cuenta.

**Flujo interno (`SendEmailVerificationAction`)**:

1. Si `$user->hasVerifiedEmail()` → retornar sin enviar, sin log.
2. Generar URL firmada con `URL::temporarySignedRoute('auth.email.verify', now()->addMinutes(config('auth.email_verify_ttl_minutes', 60)), ['id' => $user->id, 'hash' => sha256($user->email)])`.
3. Enviar `VerifyEmailNotification` al usuario con la URL.
4. Log: `auth.verification_email_sent`, con `user_id`.

### 6.3 `POST /api/v1/auth/email/verify/{id}/{hash}`

**Middleware**: `auth:sanctum + throttle:auth.verify-email`.

La URL viene firmada desde el correo. El frontend la parsea y hace POST al backend.

Parámetros de ruta: `id` (user id), `hash` (sha256 del email).
Query string: `expires`, `signature` (añadidos por `temporarySignedRoute`).

**Respuesta en éxito** (HTTP 200):

```json
{ "message": "Email verified successfully." }
```

**Respuesta si ya verificado** (HTTP 200):

```json
{ "message": "Email is already verified." }
```

**Respuesta en firma inválida o expirada** (HTTP 403, manejado por
`InvalidSignatureException` de Laravel):

```json
{ "message": "Invalid or expired verification link.", "error": "invalid_verification_link" }
```

**Flujo interno (`VerifyEmailAction`)**:

1. Resolver usuario por `$id`.
2. Validar que `hash_equals(sha256($user->email), $hash)` — evita que una URL para
   otro email sea usada.
3. Validar firma de Laravel (`Request::hasValidSignature()`).
4. Si ya verificado → retornar sin modificar.
5. `$user->markEmailAsVerified()` — setea `email_verified_at`, dispara `Verified` event.
6. Log: `auth.email_verified`, con `user_id`.

**Nota de ruta**: el nombre de ruta `auth.email.verify` debe registrarse con
nombre para que `temporarySignedRoute` pueda generar la URL correcta.

---

## 7. Seguridad

### 7.1 Anti-enumeración

| Endpoint | Comportamiento |
|----------|---------------|
| `forgot-password` | HTTP 200 siempre, mismo mensaje. Nunca revela si el email existe. |
| `reset-password` | HTTP 422 con `invalid_reset_token` tanto para token inválido como para usuario no encontrado. |
| `email/verification-notification` | HTTP 200 siempre (ya verificado o enviado). |

### 7.2 Tokens

| Token | Almacenamiento | TTL | Single-use |
|-------|---------------|-----|------------|
| Password reset | `Hash::make()` (bcrypt) en `password_reset_tokens` | 60 min | Sí (el broker invalida tras uso) |
| Email verification | No se persiste — URL firmada con `APP_KEY` | 60 min | No (re-submit es idempotente) |

### 7.3 Revocación

- Reset de contraseña revoca **todos** los tokens Sanctum del usuario (dentro de
  la misma transacción que el cambio de contraseña).
- Reset de contraseña invalida el registro en `password_reset_tokens` (el broker lo
  borra tras validar).
- No se revocan tokens Sanctum al verificar el email (la verificación no cambia
  credenciales).

### 7.4 Valores que NUNCA se loguean ni serializan

- Token plano de reset.
- Password plano o hash de password.
- URL de verificación completa.
- `email_verified_at` de otro usuario.

### 7.5 Protección de endpoints (`verified` middleware — Fase 7.3)

```
POST /api/v1/games/{game}/reservations          → requiere email verificado
POST /api/v1/me/orders/{order}/payment-evidence → requiere email verificado
```

Respuesta al jugador no verificado (HTTP 403):

```json
{ "message": "Your email address is not verified.", "error": "email_not_verified" }
```

---

## 8. Rate limits

Nuevos rate limiters a registrar en `AppServiceProvider::configureAuthRateLimiters()`:

| Nombre | Clave | Límite | Endpoint |
|--------|-------|--------|----------|
| `auth.forgot-password` | IP + email normalizado | 5/min | `POST /auth/forgot-password` |
| `auth.reset-password` | IP | 5/min | `POST /auth/reset-password` |
| `auth.resend-verification` | user_id | 3 / 10 min | `POST /auth/email/verification-notification` |
| `auth.verify-email` | user_id | 10/min | `POST /auth/email/verify/{id}/{hash}` |

Todos devuelven HTTP 429:

```json
{ "message": "Too many authentication attempts.", "error": "too_many_requests" }
```

**Nota**: el broker nativo también tiene un throttle interno (`throttle: 60` en
`config/auth.php`). El rate limiter externo es la primera barrera; el throttle del
broker es una segunda capa para `forgot-password`.

---

## 9. Notificaciones mínimas

En Fase 7 solo se implementan los correos necesarios para el flujo de identidad.
La infraestructura de notificaciones transaccionales completa (queue, Outbox) queda
para Fase 9.

### 9.1 `ResetPasswordNotification`

Override de `User::sendPasswordResetNotification($token)`.

- Extiende `Illuminate\Auth\Notifications\ResetPassword` o implementación propia
  con `Mailable`.
- Contenido mínimo: URL de reset con token en query string.
- URL: construida desde `config('app.frontend_url')` (allowlist; no desde request).
- No se envía en queue en Fase 7 — send sincrónico.
- Si el mail falla: se captura la excepción, se loguea como warning
  (`auth.password_reset_email_failed`, `user_id`), y se retorna éxito HTTP al
  usuario (no se expone el fallo de entrega — el usuario recibirá el correo si la
  configuración es correcta).

### 9.2 `VerifyEmailNotification`

- Implementación propia con URL firmada.
- Contenido mínimo: URL de verificación.
- URL: construida por el backend con `URL::temporarySignedRoute`.
- No se envía en queue en Fase 7 — send sincrónico.
- Si el mail falla: mismo comportamiento que `ResetPasswordNotification`.

### 9.3 Configuración de correo

El proyecto requiere un mail provider real en producción. En testing se usa
`Mail::fake()`. Variables:

```env
MAIL_MAILER=smtp         # smtp | ses | mailgun | log
MAIL_FROM_ADDRESS=noreply@example.com
MAIL_FROM_NAME="RepeatNumberBingo"
FRONTEND_URL=https://app.example.com  # base URL para construir links del correo
AUTH_EMAIL_VERIFY_TTL_MINUTES=60
```

Si `MAIL_MAILER=log`, los correos se escriben al log (útil en local). No impacta
los tests.

---

## 10. Endpoints propuestos

Nuevos endpoints en `routes/api.php`:

```
POST  /api/v1/auth/forgot-password
      throttle:auth.forgot-password
      → ForgotPasswordController

POST  /api/v1/auth/reset-password
      throttle:auth.reset-password
      → ResetPasswordController

POST  /api/v1/auth/email/verification-notification
      auth:sanctum, throttle:auth.resend-verification
      → SendVerificationEmailController

POST  /api/v1/auth/email/verify/{id}/{hash}
      auth:sanctum, throttle:auth.verify-email, signed
      → VerifyEmailController  (ruta con nombre: auth.email.verify)
```

---

## 11. Pruebas requeridas

### 11.1 Password reset — Fase 7.2

**`tests/Feature/Auth/PasswordResetTest.php`**

```
forgot-password:
  ✓ always responds HTTP 200 (existing email)
  ✓ always responds HTTP 200 (non-existing email)
  ✓ same body for existing and non-existing (anti-enumeration)
  ✓ sends reset email when user exists
  ✓ does not send email when user does not exist
  ✓ rate limited after 5 attempts from same IP+email
  ✓ social-only user (password=null) receives reset email
  ✓ non-activated invited user receives reset email

reset-password:
  ✓ valid token + valid email + valid password → 200
  ✓ invalid token → 422 invalid_reset_token
  ✓ expired token → 422 invalid_reset_token
  ✓ mismatched email → 422 invalid_reset_token
  ✓ password confirmation mismatch → 422 validation error
  ✓ password too short → 422 validation error
  ✓ sets email_verified_at after successful reset
  ✓ revokes all Sanctum tokens after successful reset
  ✓ allows login with new password after reset
  ✓ rejects login with old password after reset
  ✓ social-only user can set password via reset (becomes hybrid)
  ✓ non-activated invited user can set password via reset
  ✓ rate limited after 5 attempts
```

### 11.2 Email verification — Fase 7.3

**`tests/Feature/Auth/EmailVerificationTest.php`**

```
send-verification:
  ✓ requires auth (401 without token)
  ✓ returns 200 if already verified (no email sent)
  ✓ returns 200 and sends email if not verified
  ✓ rate limited (3 per 10 min per user)

verify:
  ✓ requires auth (401 without token)
  ✓ valid signature + correct hash → 200, email_verified_at set
  ✓ already verified → 200 (idempotent)
  ✓ invalid signature → 403 invalid_verification_link
  ✓ expired link → 403 invalid_verification_link
  ✓ hash mismatch (different email) → 403 invalid_verification_link
  ✓ /me returns email_verified: true after verification
  ✓ rate limited

protection (endpoints protegidos):
  ✓ unverified player cannot POST /games/{game}/reservations → 403 email_not_verified
  ✓ verified player can POST /games/{game}/reservations (existing behavior)
  ✓ unverified player cannot POST /me/orders/{order}/payment-evidence → 403 email_not_verified
  ✓ verified player can POST /me/orders/{order}/payment-evidence (existing behavior)
  ✓ admin endpoints unaffected
  ✓ read-only player endpoints unaffected (/me/orders, /me/entries)
```

### 11.3 Arquitectura — Fase 7.2 / 7.3

**`tests/Integration/Architecture/Phase7IdentityArchitectureTest.php`** (grep-based)

```
✓ ForgotPasswordController does not open DB::transaction
✓ ResetPasswordAction uses DB::transaction
✓ ResetPasswordAction calls tokens()->delete()
✓ token plain text does not appear in log calls inside actions
✓ VerifyEmailAction does not call User::find() without lockForUpdate
✓ Auth resources do not contain Eloquent queries
✓ VerifyEmailController is not referenced from player routes (no accidental public access)
✓ No user enumeration: ForgotPasswordAction does not branch on user existence in response
```

### 11.4 Regresión — tras cada bloque

```
php artisan test --compact tests/Feature/Auth
php artisan test --compact tests/Integration/Auth
```

Verificar que:
- Social login / link / unlink siguen verdes.
- Fase 6 (refunds, payouts) sigue verde.
- Suite completa verde.

---

## 12. Límites explícitos de Fase 7

**No se implementa:**

- 2FA (TOTP, SMS, authenticator app).
- Magic links.
- Phone login.
- OAuth adicional (providers nuevos).
- Cambio de email (requeriría re-verificación; se puede añadir en Fase 9+).
- Verificación de email para usuarios admin (no requerida por diseño).
- Notificaciones en queue / Outbox (Fase 9).
- Verificación de correo para usuarios de linking social (ya cubierto por
  `provider_email_verified_at`).
- Gateway de pagos.
- Frontend.

**El sistema de invitaciones no cambia.**
`ActivatePlayerAction` sigue sin setear `email_verified_at`. La invitación es un
canal de activación controlado por el admin, no un mecanismo de verificación de
correo.

---

## 13. Plan de implementación

### Fase 7.2 — Password reset

**Archivos nuevos**:

```
app/Actions/Auth/ForgotPasswordAction.php
app/Actions/Auth/ResetPasswordAction.php
app/Http/Controllers/Auth/ForgotPasswordController.php
app/Http/Controllers/Auth/ResetPasswordController.php
app/Http/Requests/Auth/ForgotPasswordRequest.php
app/Http/Requests/Auth/ResetPasswordRequest.php
app/Notifications/Auth/ResetPasswordNotification.php
tests/Feature/Auth/PasswordResetTest.php
tests/Integration/Architecture/Phase7IdentityArchitectureTest.php (parcial)
```

**Archivos modificados**:

```
app/Models/User.php                   → implements MustVerifyEmail (solo declaración)
                                         + sendPasswordResetNotification()
app/Providers/AppServiceProvider.php   → rate limiters auth.forgot-password + auth.reset-password
bootstrap/app.php                      → error mapping para invalid_reset_token
routes/api.php                         → 2 rutas nuevas
```

**Orden de implementación**:

1. Rate limiters en `AppServiceProvider`.
2. Form Requests.
3. `ForgotPasswordAction` + `ResetPasswordAction`.
4. `ResetPasswordNotification`.
5. Controllers.
6. Rutas.
7. Tests (todos del §11.1 + arquitectura parcial).
8. `vendor/bin/pint --dirty --format agent`.
9. Suite completa verde.

### Fase 7.3 — Email verification + protección de endpoints

**Archivos nuevos**:

```
app/Actions/Auth/SendEmailVerificationAction.php
app/Actions/Auth/VerifyEmailAction.php
app/Http/Controllers/Auth/SendVerificationEmailController.php
app/Http/Controllers/Auth/VerifyEmailController.php
app/Notifications/Auth/VerifyEmailNotification.php
app/Http/Middleware/EnsureEmailIsVerified.php  (si no se reutiliza el de Laravel)
tests/Feature/Auth/EmailVerificationTest.php
```

**Archivos modificados**:

```
app/Models/User.php                   → sendEmailVerificationNotification()
app/Providers/AppServiceProvider.php   → rate limiters auth.resend-verification + auth.verify-email
bootstrap/app.php                      → error mapping para invalid_verification_link + email_not_verified
routes/api.php                         → 2 rutas nuevas + verified en rutas de commerce player
```

**Orden de implementación**:

1. Implementar `MustVerifyEmail` completamente en `User`.
2. Rate limiters.
3. `VerifyEmailNotification`.
4. Actions.
5. Controllers.
6. Middleware `EnsureEmailIsVerified` (o alias del de Laravel).
7. Rutas + protección de endpoints de commerce.
8. Tests (todos del §11.2 + arquitectura completa).
9. `vendor/bin/pint --dirty --format agent`.
10. Suite completa verde — verificar especialmente que los tests de commerce del
    jugador que no crean usuario verificado no se rompan (ajustar factories con
    `email_verified_at: now()` donde sea necesario).

---

## 14. Fase 7.2 — Password reset implementado

### 14.1 Archivos creados

| Archivo | Propósito |
|---------|-----------|
| `app/Exceptions/Auth/PasswordResetException.php` | Excepción lanzada cuando el broker retorna un estado distinto de `PASSWORD_RESET` |
| `app/Http/Requests/Auth/ForgotPasswordRequest.php` | Valida y normaliza el email; expone `normalizedEmail()` |
| `app/Http/Requests/Auth/ResetPasswordRequest.php` | Valida email, token y password; expone `toCredentials()` |
| `app/Actions/Auth/SendPasswordResetLinkAction.php` | Llama al broker; loguea `auth.password_reset_requested` solo cuando se envía; devuelve `void` |
| `app/Actions/Auth/ResetPasswordAction.php` | Llama al broker; callback con `DB::transaction` — cambia password, setea `email_verified_at`, revoca tokens Sanctum |
| `app/Http/Controllers/Auth/ForgotPasswordController.php` | Invocable thin; siempre responde 200 (anti-enumeración) |
| `app/Http/Controllers/Auth/ResetPasswordController.php` | Invocable thin; delega en `ResetPasswordAction`; 200 en éxito |
| `tests/Feature/Auth/PasswordResetTest.php` | 27 tests / 67 assertions — flujo completo de forgot y reset |
| `tests/Integration/Architecture/Phase7IdentityArchitectureTest.php` | 13 tests grep-based — invariantes estructurales |

### 14.2 Archivos modificados

| Archivo | Cambio |
|---------|--------|
| `config/auth.php` | Añadida clave `password_reset_frontend_url` |
| `app/Providers/AppServiceProvider.php` | Añadidos rate limiters `auth.forgot-password` y `auth.reset-password`; añadido `configurePasswordResetUrl()` con `ResetPassword::createUrlUsing()` |
| `bootstrap/app.php` | Añadido mapping `PasswordResetException → 422 code: password_reset_invalid` |
| `routes/api.php` | Añadidas rutas `POST /auth/forgot-password` y `POST /auth/reset-password` |

### 14.3 Endpoints implementados

```
POST  api/v1/auth/forgot-password   throttle:auth.forgot-password (5/min por IP+email)
POST  api/v1/auth/reset-password    throttle:auth.reset-password  (5/min por IP)
```

Ambas rutas sin `auth:sanctum` — accesibles sin sesión activa.

### 14.4 Decisiones de implementación

| Decisión | Elegido |
|----------|---------|
| Respuesta `forgot-password` | HTTP 200 siempre con mensaje uniforme — nunca revela existencia del email |
| URL de reset | `ResetPassword::createUrlUsing()` con `FRONTEND_PASSWORD_RESET_URL` env var; fallback a `APP_URL/reset-password` |
| Transacción en reset | `DB::transaction` en callback de `Password::reset()`: password → `email_verified_at` → `tokens()->delete()` |
| `email_verified_at` tras reset | Se setea solo si era `null`; si ya estaba seteado se preserva la fecha original |
| Revocación de tokens Sanctum | `$user->tokens()->delete()` dentro de la misma transacción |
| Usuarios social-only | Permitido — obtienen credencial local, se convierten en híbridos |
| Usuarios invitados no activados | Permitido — equivalente funcional de la activación |
| Error de broker | `PasswordResetException` → HTTP 422 con `code: password_reset_invalid` (no `error`) |
| Log | `auth.password_reset_requested` (user_id, solo al enviar) y `auth.password_reset_completed` (user_id, al completar) |

### 14.5 Rate limits añadidos

| Nombre | Clave | Límite |
|--------|-------|--------|
| `auth.forgot-password` | `auth.forgot-password:{ip}:{normalized_email}` | 5/min |
| `auth.reset-password` | `auth.reset-password:{ip}` | 5/min |

### 14.6 Cobertura de tests

**Feature** (`tests/Feature/Auth/PasswordResetTest.php` — 27 tests, 67 assertions):

- `forgot-password`: HTTP 200 para email existente y no existente, mismo body (anti-enumeración), envía notificación solo si existe, no envía si no existe, valida formato, normaliza email con mayúsculas/espacios, está rate-limited, URL del link contiene `token=` y `email=`
- `reset-password`: token válido actualiza password, token inválido → 422, token expirado → 422 (updated `created_at` en DB), requiere confirmación, longitud mínima 8, revoca todos los tokens Sanctum, setea `email_verified_at` si null, preserva `email_verified_at` si ya estaba seteado, permite usuario social-only (crea credencial local), permite invitado no activado, permite login con nueva password, rechaza login con password anterior, no cambia role, no toca `user_social_accounts`, no crea usuario para email desconocido, responses no exponen el token plano, rate limit activo
- Rate limit tests usan IPs únicas para no interferir entre sí

**Arquitectura** (`tests/Integration/Architecture/Phase7IdentityArchitectureTest.php` — 13 tests):

- Controladores no llaman `DB::transaction`
- `ResetPasswordAction` llama `DB::transaction` y `tokens()->delete()`
- `ResetPasswordAction` maneja `email_verified_at`; `ResetPasswordController` no lo toca
- Ningún controlador consulta `password_reset_tokens` directamente
- `ForgotPasswordController` no ramifica en `RESET_LINK_SENT` ni `INVALID_USER`
- Ninguna acción inserta en `password_reset_tokens` ni usa `DB::insert`
- Acciones usan `Password::sendResetLink` / `Password::reset`; no `Mail::` ni `DB::statement`

### 14.7 Verificación final

```
php artisan route:list --path=api/v1/auth --except-vendor  → 14 rutas, 2 nuevas
php artisan test --compact tests/Feature/Auth/PasswordResetTest.php → 27 passed (67 assertions)
php artisan test --compact tests/Integration/Architecture/Phase7IdentityArchitectureTest.php → 13 passed (19 assertions)
php artisan test --compact tests/Feature/Auth tests/Integration/Auth tests/Integration/Architecture → 253 passed (779 assertions)
php artisan test --compact tests/Feature/Commerce → 145 passed (684 assertions)
```

Cero fallos en suite completa de Auth + Commerce.

---

## Apéndice — Hallazgos del audit (Fase 7.1)

| Hallazgo | Impacto |
|----------|---------|
| `password_reset_tokens` existe desde la migración base | Positivo — no se necesita nueva migración |
| Broker de passwords configurado con expire=60, throttle=60 | Positivo — reutilizable directamente |
| `User` no implementa `MustVerifyEmail` | Bloquea el middleware `verified`; debe activarse en 7.2 |
| `email_verified_at` existe pero siempre NULL para usuarios locales/invitados | Confirma que 7.3 tiene base correcta |
| `AuthUserResource` ya expone `email_verified` y `email_verified_at` | El contrato de `/me` no cambia |
| Social registration ya setea `email_verified_at` para Google | Correcto; Facebook queda NULL por diseño del proveedor |
| `ActivatePlayerAction` NO setea `email_verified_at` | Correcto por decisión D11 |
| No existen rate limiters para forgot/reset/verify | Deben añadirse en 7.2 y 7.3 |
| No existen rutas para forgot/reset/verify | A crear en 7.2 y 7.3 |
| Endpoints de commerce del jugador sin protección de verificación | A proteger en 7.3 |
