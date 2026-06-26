# Fase 5 - Identidad, acceso y administracion operativa

Documento vivo de la Fase 5. Estado implementado:

- Bloque 5.1: contrato de identidad y persistencia.
- Bloque 5.2: registro y autenticacion local con Sanctum.
- Bloque 5.3: registro asistido y activacion.
- Bloque 5.4: login social con Google y Facebook.

## 1. Modelo de identidad

La identidad canonica sigue siendo una sola entidad:

```text
users
├── credencial local opcional
├── cuenta Google opcional
├── cuenta Facebook opcional
└── invitacion o activacion administrativa opcional
```

No existen tablas separadas para jugadores, administradores, usuarios sociales o
usuarios invitados.

## 2. Identificador y roles

- `users.id` permanece como `BIGINT` interno.
- `users.email` es el identificador unico actual para login y recuperacion.
- `users.password` es nullable para permitir cuentas sociales o pendientes de
  activacion sin credencial local.
- `users.role` conserva `admin|player`, respaldado por `App\Enums\UserRole` y
  un `CHECK` PostgreSQL.
- `role` no esta en `$fillable`; solo cambia mediante `ChangeUserRoleAction`.
- `users.email_verified_at` existe para verificacion de correo.
- No existe todavia columna de estado `active`, `suspended` o `blocked`.

## 3. Cuentas sociales

Tabla: `user_social_accounts`.

Campos:

- `id UUID v7`
- `user_id`
- `provider`
- `provider_user_id`
- `provider_email`
- `provider_email_verified_at`
- timestamps con zona horaria

Constraints:

- `UNIQUE(provider, provider_user_id)`
- `UNIQUE(user_id, provider)`
- `provider IN ('google','facebook')`
- `provider_user_id` no vacio

No se agregan `google_id` ni `facebook_id` a `users`.
No se almacenan access tokens ni refresh tokens del proveedor.

## 4. Invitaciones y activacion administrativa

Tabla: `user_invitations`.

Campos:

- `id UUID v7`
- `user_id`
- `invited_by_user_id nullable`
- `token_hash`
- `expires_at`
- `consumed_at nullable`
- `revoked_at nullable`
- timestamps con zona horaria

Constraints:

- `token_hash` unico.
- `token_hash` debe ser hex SHA-256 de 64 caracteres.
- una sola invitacion activa por usuario:
  `consumed_at IS NULL AND revoked_at IS NULL`.
- una invitacion no puede estar consumida y revocada al mismo tiempo.
- indice parcial para expiraciones activas.

El token plano no se persiste ni se serializa desde el modelo.

Semantica:

- consumida (`consumed_at`) y revocada (`revoked_at`) son estados terminales
  distintos.
- una invitacion activa para unicidad puede estar expirada; la expiracion no se
  incluye en el indice parcial porque depende del tiempo actual.
- una invitacion valida para activacion debe estar activa y no expirada.
- el modelo evalua expiracion, consumo, revocacion, actividad y validez mediante
  metodos semanticos sin consultas ni efectos secundarios.

## 5. Registro local

Endpoint:

```text
POST /api/v1/auth/register
```

Reglas:

- normaliza `email` con trim + lowercase antes de validar unicidad.
- crea solo usuarios `player`.
- `role`, `permissions`, `abilities` y `email_verified_at` estan prohibidos en
  el request.
- `password` es obligatoria para registro local, confirmada, hasheada por el
  cast del modelo y nunca serializada.
- no establece `email_verified_at`; la verificacion de correo sigue pendiente.
- emite token Sanctum desde `RegisterPlayerAction`, dentro de transaccion.
- responde mediante `AuthTokenResource`.

## 6. Login local

Endpoint:

```text
POST /api/v1/auth/login
```

Reglas:

- normaliza `email` con trim + lowercase.
- usuario inexistente, password incorrecto y `password = null` devuelven la
  misma respuesta de credenciales invalidas.
- no crea password local automaticamente.
- no revoca tokens previos al iniciar sesion.
- emite un nuevo token Sanctum desde `AuthenticateUserAction`.

## 7. Logout

Endpoint:

```text
POST /api/v1/auth/logout
```

Requiere `auth:sanctum`. Revoca solo el token actual mediante
`LogoutCurrentTokenAction`; no cierra otros dispositivos.

## 8. Usuario actual

Endpoint canonico:

```text
GET /api/v1/auth/me
```

Alias temporal compatible:

```text
GET /api/v1/user
```

Ambos usan `AuthUserResource` y devuelven el mismo contrato:

- `id`
- `name`
- `email`
- `role`
- `email_verified`
- `email_verified_at`
- `capabilities.can_access_admin`
- `capabilities.can_use_player_features`

No expone password, remember token, tokens Sanctum, provider IDs, invitaciones,
hashes ni metadata interna.

## 9. Creacion asistida de jugadores

Endpoint:

```text
POST /api/v1/admin/players
```

Requiere `auth:sanctum` + rol `admin`.

### Reglas de creacion

- el admin envia `name` y `email`; `role`, `permissions` y `abilities` estan
  prohibidos.
- el email se normaliza (trim + lowercase) antes de validar.
- el usuario creado siempre recibe rol `player`; no es posible elevar roles.
- el usuario nuevo se crea con `password = null` y sin `email_verified_at`.

### Flujo transaccional

Dentro de una sola transaccion:

1. `pg_advisory_xact_lock(emailAdvisoryLockKey(email))` — serializa operaciones
   concurrentes para el mismo email antes de cualquier lectura.
   En PostgreSQL, una transaccion abortada invalida toda consulta posterior hasta
   que se emita el `ROLLBACK`; por eso el lock advisory se adquiere primero y
   el flujo no depende de capturar `UniqueConstraintViolationException`.
2. `SELECT users FOR UPDATE` por email normalizado.
3. Si no existe, `INSERT` del nuevo usuario player pendiente.
4. Si el usuario es admin o ya tiene password → devolver `already_registered`
   sin crear invitacion.
5. Revocar cualquier invitacion activa anterior del usuario.
6. Generar token criptograficamente aleatorio (`Str::random(64)`).
7. Calcular `hash('sha256', $token)` y persistir unicamente el hash.
8. Crear nueva invitacion con `expires_at = now() + INVITATION_TTL_DAYS (7)`.
9. COMMIT.

### Outcomes

| Outcome | HTTP | Descripcion |
| --- | --- | --- |
| `invited` | 201 | usuario nuevo creado, invitacion emitida |
| `reinvited` | 201 | usuario existente sin password, invitacion reemitida |
| `already_registered` | 200 | usuario con password o admin; sin invitacion |

### Entrega del token

El token plano nunca se almacena en la base de datos ni se escribe en logs.
En entorno `testing` o `local`, se devuelve en el campo `plain_token` del
recurso (condicionado explicitamente por `app()->environment('testing', 'local')`).
En produccion se debe usar un contrato de entrega seguro (correo real, SMS, etc.)
o una operacion administrativa segura fuera de banda.

No se simula correo enviado; no existe proveedor de email ficticio.

### Renovacion de invitaciones

Al reinvitar un usuario pendiente, la accion revoca cualquier invitacion activa
anterior dentro de la misma transaccion antes de crear la nueva. Esto garantiza
la invariante de unicidad del indice parcial:
`user_invitations_one_active_per_user_idx ON (user_id) WHERE consumed_at IS NULL AND revoked_at IS NULL`.

## 10. Activacion de jugadores

Endpoint:

```text
POST /api/v1/auth/activate
```

Publico (sin autenticacion). El request recibe `token`, `password` y
`password_confirmation`.

### Flujo transaccional

1. Calcular `hash('sha256', $token)`.
2. Leer la invitacion sin lock para obtener `user_id` (mantiene orden de bloqueo).
3. `SELECT users FOR UPDATE` por `user_id`.
4. `SELECT user_invitations FOR UPDATE` por `token_hash`.
5. Validar que la invitacion no este consumida, revocada ni expirada.
6. Validar que el usuario no tenga password (no sobrescribir).
7. Establecer password hasheada mediante el cast `hashed` del modelo.
8. Marcar `consumed_at = now()`.
9. COMMIT.
10. Emitir token Sanctum mediante `IssueSanctumTokenAction`.

El orden de bloqueo (usuario → invitacion) es consistente con
`CreatePlayerInvitationAction` para prevenir deadlocks.

### Reglas de activacion

- token single-use; intentos repetidos reciben `consumed`.
- token invalido, expirado, revocado o ya consumido devuelven HTTP 422 con
  `error: invalid_activation_token` y campo `reason` descriptivo.
- no eleva roles.
- no sobrescribe password ya establecida; devuelve `already_active`.
- no establece `email_verified_at`; el token de invitacion no prueba control
  del correo.
- un rollback por cualquier causa conserva usuario e invitacion sin cambios.

### Outcomes de error

| Reason | Descripcion |
| --- | --- |
| `not_found` | token desconocido |
| `expired` | invitacion expirada |
| `revoked` | invitacion revocada |
| `consumed` | invitacion ya utilizada |
| `already_active` | usuario ya tiene password |

## 11. Tokens Sanctum

Los tokens locales se crean con nombre `local-auth` y abilities explicitas:

```text
auth:logout
player:access
user:read
admin:access   # solo si el usuario ya es admin
```

Las rutas administrativas siguen autorizandose por rol/policies; las abilities
son un contrato minimo del token, no reemplazan las Policies existentes.

## 12. Rate limits

Rate limiters nombrados:

```text
auth.register          — IP + email normalizado, 5/min
auth.login             — IP + email normalizado, 5/min
auth.activate          — IP + hash_hmac(token), 10/min
admin.create-player    — user_id del admin autenticado, 20/min
```

El limitador de activacion usa `hash_hmac('sha256', token, 'rate-limit')` como
clave derivada: el token plano nunca se almacena en la capa de cache.
Todos devuelven HTTP 429 con:

```json
{
  "message": "Too many authentication attempts.",
  "error": "too_many_requests"
}
```

## 13. Auditoria

Los actions emiten logs estructurados (`Log::info`) sin token plano, sin
password ni hash sensible:

- `auth.player_invited`: `user_id`, `invited_by`, `outcome`, `expires_at`.
- `auth.player_invite_skipped`: `reason`, `invited_by`.
- `auth.player_activated`: `user_id`.

No se usa `game_events` para auditar usuarios.

## 14. Garantias y limites

- La unicidad del usuario se garantiza por `UNIQUE(email)` en PostgreSQL.
- La unicidad de invitacion activa se garantiza por el indice parcial
  `user_invitations_one_active_per_user_idx`.
- El token plano nunca persiste en DB ni logs.
- Los hashes nunca se exponen en respuestas HTTP.
- `pg_advisory_xact_lock(emailAdvisoryLockKey)` antes del `SELECT FOR UPDATE`
  serializa operaciones concurrentes por email. No se captura
  `UniqueConstraintViolationException`: en PostgreSQL esa excepcion aborta la
  transaccion entera, invalidando toda consulta posterior hasta el `ROLLBACK`.
- La activacion no verifica correo; `email_verified_at` permanece null hasta
  que se implemente verificacion real.

## 15. Verificacion de correo

`users.email_verified_at` existe, pero ni el registro local ni la activacion lo
establecen automaticamente. La verificacion de correo queda fuera del Bloque 5.3.

## 17. Login social con Google y Facebook

### Proveedores

Allowlist estricta: `google`, `facebook`. Cualquier otro proveedor devuelve 404
(constraint de ruta) o un error controlado.

### Endpoints

```text
GET  /api/v1/auth/social/{provider}/redirect
GET  /api/v1/auth/social/{provider}/callback
POST /api/v1/auth/social/exchange
```

### Flujo redirect / callback / exchange

1. **redirect**: crea un `OauthAttempt` con `state_hash = SHA-256(plainState)` y
   `expires_at = now() + AUTH_SOCIAL_STATE_TTL_SECONDS (600)`. Redirige al
   proveedor con el `state` embebido en la URL.

2. **callback**: el proveedor redirige el navegador a esta URL con `code` y `state`.
   - Valida proveedor.
   - Pre-check optimista del estado (sin lock) para fallo rapido.
   - Intercambia el `code` con el proveedor via `SocialProviderAdapter`.
   - Llama a `HandleSocialCallbackAction`, que dentro de una transaccion:
     - Bloquea el `OauthAttempt` FOR UPDATE.
     - Llama a `ResolveSocialIdentityAction` (savepoint anidado).
     - En exito: genera `exchangeCode`, guarda `hash('sha256', code)` en
       `exchange_code_hash`, actualiza `expires_at = now() + exchange_ttl (300)`.
     - En error: marca `consumed_at = now()` para impedir replay.
   - Redirige al frontend con `?code={plainCode}&provider={provider}` (exito)
     o `?error={codigo}&provider={provider}` (error).

3. **exchange**: POST del SPA con el `code` (64 chars).
   - `CompleteSocialExchangeAction` busca `OauthAttempt` por `hash('sha256', code)`.
   - Valida no expirado, no consumido.
   - Marca `consumed_at = now()`.
   - Emite token Sanctum via `IssueSanctumTokenAction`.
   - El token Sanctum NUNCA aparece en una URL.

### Tabla `oauth_attempts`

| Campo | Tipo | Descripcion |
| --- | --- | --- |
| `id` | UUID v7 | PK |
| `provider` | VARCHAR(32) | google o facebook (CHECK constraint) |
| `state_hash` | CHAR(64) | SHA-256 del state plano, unico |
| `exchange_code_hash` | CHAR(64) nullable | SHA-256 del codigo de intercambio |
| `user_id` | BIGINT nullable | FK → users, set tras exito en callback |
| `expires_at` | TIMESTAMPTZ | TTL del state; se actualiza a exchange TTL en callback |
| `consumed_at` | TIMESTAMPTZ nullable | seteado al consumir el exchange code o cerrar por error |

Constraints PostgreSQL:
- `CHECK (provider IN ('google','facebook'))`
- `CHECK (state_hash ~ '^[a-f0-9]{64}$')`
- `CHECK (exchange_code_hash IS NULL OR exchange_code_hash ~ '^[a-f0-9]{64}$')`
- Indice parcial UNIQUE en `exchange_code_hash WHERE NOT NULL`

### Resolucion de identidad

`ResolveSocialIdentityAction` dentro de transaccion:

1. `pg_advisory_xact_lock(social_identity_key)` — serializa callbacks concurrentes
   para la misma identidad `(provider, provider_user_id)`.
2. Busca `UserSocialAccount` por `(provider, provider_user_id)` FOR UPDATE.
   - Si existe: outcome `authenticated` → mismo usuario.
3. Si no existe, valida email verificado del proveedor.
   - Sin email verificado: outcome `verified_email_required`.
4. `pg_advisory_xact_lock(email_key)` — misma clave que `CreatePlayerInvitationAction`
   para contender cuando dos flujos distintos compiten por el mismo email.
5. Busca `User` por email normalizado FOR UPDATE.
   - Si existe: outcome `account_link_required` (sin auto-vinculacion).
6. Si no existe: crea `User` (role=player, password=null) + `UserSocialAccount`.
   Establece `email_verified_at` solo cuando el adapter confirma verificacion.
   Outcome: `created`.

### Outcomes del callback

| Outcome | Resultado | Descripcion |
| --- | --- | --- |
| `authenticated` | code → exchange | Identidad social ya vinculada; misma cuenta |
| `created` | code → exchange | Usuario nuevo creado |
| `account_link_required` | redirect con error | Email ya existe; sin auto-vinculacion |
| `verified_email_required` | redirect con error | No hay email verificado del proveedor |

### Roles y seguridad

- Usuario social nuevo siempre `player`.
- Login social no crea ni eleva roles de administrador.
- No se persisten: access token, refresh token, ID token ni payload crudo del proveedor.
- `provider_email_verified_at` se establece solo cuando el adapter confirma verificacion.
- `UserSocialAccount.provider_email` almacena el email normalizado.
- El `state` plano nunca se almacena; solo su SHA-256.
- El codigo de intercambio plano nunca se almacena; solo su SHA-256.
- El token Sanctum nunca aparece en una URL ni en query string.

### Rate limits

```text
auth.social.redirect   — IP + provider, 20/min
auth.social.callback   — IP + provider, 20/min
auth.social.exchange   — IP, 20/min
```

Todos devuelven HTTP 429 con `error: too_many_requests`.

### Configuracion

```env
FRONTEND_URL=
AUTH_SOCIAL_STATE_TTL_SECONDS=600
AUTH_SOCIAL_EXCHANGE_TTL_SECONDS=300

GOOGLE_CLIENT_ID=
GOOGLE_CLIENT_SECRET=
GOOGLE_REDIRECT_URI=

FACEBOOK_CLIENT_ID=
FACEBOOK_CLIENT_SECRET=
FACEBOOK_REDIRECT_URI=
```

Configuracion incompleta devuelve 503 con `error: provider_not_configured`.

### Garantias y limites

- `pg_advisory_xact_lock` serializa callbacks concurrentes por identidad social.
- Email-based advisory lock (mismo namespace que `CreatePlayerInvitationAction`)
  previene race conditions entre flujos sociales y de invitacion admin.
- No existe auto-link por email: un email ya registrado devuelve
  `account_link_required` siempre.
- El vinculo definitivo (linking/unlinking autenticado) se implementa en 5.5.
- Callbacks repetidos para el mismo state son rechazados (`callback_already_processed`).
- Codigos de intercambio son single-use (single-use: `consumed_at`).

### Auditoria

- `auth.social_authenticated`: `user_id`, `provider`.
- `auth.social_user_created`: `user_id`, `provider`.
- `auth.social_exchange_completed`: `user_id`, `provider`.

Sin tokens OAuth ni hashes sensibles en logs.

## 18. Bloque 5.5 — Linking y unlinking de cuentas sociales

### Migracion

`2026_06_25_000002_add_purpose_to_oauth_attempts_table`:

- Agrega `purpose VARCHAR(8) DEFAULT 'login' CHECK (purpose IN ('login','link'))`.
- Agrega `initiated_by_user_id BIGINT NULL REFERENCES users ON DELETE SET NULL`.
- PostgreSQL CHECK: `(purpose='login' AND initiated_by_user_id IS NULL) OR (purpose='link' AND initiated_by_user_id IS NOT NULL)`.
- Todos los intentos existentes mantienen `purpose='login'` y `initiated_by_user_id=NULL`.

### Endpoints

```text
GET    /api/v1/auth/social-accounts                   [auth:sanctum]
GET    /api/v1/auth/social/{provider}/link/redirect   [auth:sanctum]
GET    /api/v1/auth/social/{provider}/link/callback   [publico]
DELETE /api/v1/auth/social/{provider}                 [auth:sanctum]
```

Proveedor permitido: `google|facebook`. Cualquier otro da 404 por restriccion de ruta.

### Persistencia del intento de linking

Se reutiliza la tabla `oauth_attempts` con `purpose='link'`. El campo
`initiated_by_user_id` registra el usuario que inicio el flujo en el redirect.
Es la **unica fuente de verdad** para saber a quien vincular la identidad.
Nunca se acepta el user_id desde parametros de request o query string.

El pre-check en `SocialLinkCallbackController` filtra por `purpose='link'` antes de
llamar al adaptador OAuth, impidiendo que un intento de login sea usado como
intento de link (y viceversa).

### Reglas de vinculacion

| Caso | Resultado |
|------|-----------|
| Identidad libre | `social_linked` — se crea `user_social_accounts` |
| Identidad ya vinculada al mismo usuario | `already_linked` — idempotente |
| Identidad vinculada a otro usuario | `social_identity_conflict` — rechazado |
| Usuario ya tiene el proveedor vinculado (otra identidad) | `provider_already_linked` — rechazado |

No hay auto-linking por coincidencia de email. El usuario destino siempre es
`initiated_by_user_id`, ignorando el email que devuelva el proveedor.

No se emite nuevo token Sanctum ni se cambia el rol del usuario.

### Reglas de desvinculacion y reautenticacion

- Usuario con contrasena local: debe enviar `current_password` en el body.
- Usuario solo-social (sin contrasena): debe presentar un `PersonalAccessToken`
  real (no un `TransientToken` de `Sanctum::actingAs()`) con nombre exacto
  `social:{provider}`, ability `social_reauth`, creado dentro de los ultimos
  `AUTH_SOCIAL_REAUTH_TTL_SECONDS` (default: 300 s) y proveedor todavia
  vinculado en `user_social_accounts`. Token local, token antiguo o
  `TransientToken` son rechazados con HTTP 422.
- No se puede desvincular el ultimo metodo de autenticacion
  (`last_authentication_method`, HTTP 422).
- La operacion es transaccional: bloquea `users FOR UPDATE` antes de leer
  `user_social_accounts`, previniendo carreras con operaciones concurrentes.

`can_unlink` en el listado se calcula como `totalMethods > 1`, donde
`totalMethods = (password != null ? 1 : 0) + count(social_accounts)`.

### Concurrencia

Secuencia de locks en `HandleSocialLinkCallbackAction`:

1. `OauthAttempt FOR UPDATE` (con filtro `purpose='link'`).
2. `User FOR UPDATE` por `initiated_by_user_id`.
3. `pg_advisory_xact_lock(userProviderLinkKey(user_id, provider))`.
4. `UserSocialAccount FOR UPDATE` por `(user_id, provider)`.
5. `pg_advisory_xact_lock(socialIdentityLockKey(provider, provider_user_id))`.
6. `UserSocialAccount FOR UPDATE` por `(provider, provider_user_id)`.
7. `INSERT` de la nueva cuenta social.

Secuencia de locks en `UnlinkSocialAccountAction`:

1. `User FOR UPDATE`.
2. `pg_advisory_xact_lock(userProviderLinkKey(user_id, provider))`.
3. `UserSocialAccount FOR UPDATE` por `(user_id, provider)`.

Este orden elimina el ciclo conocido entre linking y unlinking (ambos adquieren
el lock sobre `User` antes que sobre `UserSocialAccount`). No se afirma que el
deadlock sea imposible de forma global: otras operaciones concurrentes no
contempladas aqui podrian introducir nuevos ciclos.

El advisory lock en `(user_id, provider)` (paso 5) previene una carrera donde
dos operaciones de linking para el mismo usuario y proveedor (pero distinto
`provider_user_id`) pasen el check del paso 6 simultaneamente y generen una
violacion de UNIQUE en PostgreSQL que aborta la transaccion sin poder capturarse.

### Rate limits

| Nombre | Clave | Limite |
|--------|-------|--------|
| `auth.social.link.redirect` | user_id + provider | 20/min |
| `auth.social.link.callback` | IP + provider | 20/min |
| `auth.social.unlink` | user_id + provider | 10/min |

Respuesta de limite: HTTP 429 con `{"error": "too_many_requests"}`.

### Seguridad

- Ningun token Sanctum aparece en query string ni en la URL de redireccion.
- El `state` solo se persiste como `SHA-256(plainState)`.
- No se persisten: access token, refresh token, ID token completo ni payload raw.
- El `frontend_url` viene de configuracion; no se acepta redirect URI arbitraria del request.
- Errores OAuth producen codigos estables (`oauth_error`, `invalid_state`, etc.) sin filtrar secretos.
- Facebook siempre produce `provider_email_verified_at = NULL` (Graph API no incluye campo explicito).

### Configuracion (Bloque 5.5)

```env
AUTH_SOCIAL_REAUTH_TTL_SECONDS=300
```

`AUTH_SOCIAL_REAUTH_TTL_SECONDS` controla el TTL del token de reautenticacion
social usado para el unlink. Un `PersonalAccessToken` con nombre `social:{provider}`
y ability `social_reauth` creado hace mas de este umbral es rechazado.

### Pruebas focalizadas (Bloque 5.5)

`tests/Feature/Auth/SocialLinkTest.php` — 49 tests:

- Listado: visibilidad de proveedor, mascara de email, flags `can_unlink`, ocultacion de hashes e IDs internos.
- Redirect: requiere auth, crea intento con `purpose='link'` + `user_id`, rechaza proveedor invalido, solo persiste hash.
- Callback: stateless, identidad libre vincula, no crea usuario nuevo, no cambia rol, no emite token, idempotente, conflicto, ya vinculado al proveedor, sin auto-linking por email, Facebook sin verificacion, repeticion no llama al adaptador.
- Unlink: requiere contrasena cuando existe, falla con contrasena incorrecta, usuario solo-social sin contrasena, preserva otras cuentas, no elimina usuario ni cambia rol, protege ultimo metodo, rate limit estable.
- Regresion: flujo de login 5.4 sigue funcionando.

`tests/Integration/Auth/SocialLinkConcurrencyTest.php` — 4 tests / 24 assertions:

- Determinismo de clave advisory `userProviderLinkKey`.
- Dos usuarios compiten por la misma identidad: solo uno gana (`social_linked`), el otro obtiene `social_identity_conflict`.
- Mismo usuario, dos intentos para el mismo proveedor: solo uno vincula (`social_linked`), el otro obtiene `provider_already_linked`.
- Carrera link + unlink simultaneos: estado final siempre consistente (0 o 1 cuenta, sin duplicados ni corrupcion).

## 19. Bloque 5.6 — Lectura administrativa de partidas

### Endpoints

```text
GET /api/v1/admin/games           [auth:sanctum + admin]   admin.games.index
GET /api/v1/admin/games/{game}    [auth:sanctum + admin]   admin.games.show
```

Ambos requieren rol `admin`. Los jugadores reciben 403; sin autenticacion, 401.

### Politica

`GamePolicy` ampliada con:

- `viewAny(User $user): bool` — Admin solamente; usado por `ListAdminGamesRequest`.
- `view(User $user, Game $game): bool` — Admin solamente; usado por `ShowAdminGameRequest`.

### Listado — GET /api/v1/admin/games

Devuelve todos los estados, incluyendo `draft` y `cancelled`. Paginado por
`created_at DESC, id DESC` (orden estable incluso con timestamps identicos).

**Filtros disponibles:**

| Parametro | Tipo | Descripcion |
|-----------|------|-------------|
| `status` | string | Valor exacto del enum `GameStatus` |
| `search` | string (max 100) | Busqueda parcial case-insensitive en `name` o `slug` (`ilike`) |
| `published` | boolean | `true` → misma lista explicita que `ListPublicGamesQuery`; `false` → complemento |
| `auto_draw_enabled` | boolean | Filtra por dibujo automatico |
| `created_from` | date | Fecha minima de creacion (inclusivo) |
| `created_to` | date | Fecha maxima de creacion (inclusivo); >= `created_from` |
| `per_page` | integer | 1–100, default 20 (>100 → 422) |

Conceptos:
- **`published` no es una columna persistida.** Es un filtro derivado que refleja
  exactamente la visibilidad publica del proyecto:
  `published=true` → `whereIn('status', GameStatus::publiclyVisible())` —
  7 estados (published, sales_open, sales_closed, running, paused, resolving,
  completed). Se usa `whereIn` (no `whereNotIn([draft, cancelled])`) para evitar
  que un futuro estado nuevo quede expuesto publicamente sin intencion.
  `published=false` → `whereNotIn('status', GameStatus::publiclyVisible())`
  (actualmente draft y cancelled).
  La unica fuente de verdad es `GameStatus::publiclyVisible()` en el enum de
  dominio, compartida por `ListPublicGamesQuery` y `ListAdminGamesQuery`.
- Filtros de boolean enviados como query string se leen con `$request->boolean()`.
- Combinacion de filtros aplica AND; todos opcionales.

**Contrato del recurso (`AdminGameSummaryResource`):**

```json
{
  "data": [{
    "id": "uuid",
    "slug": "my-game",
    "name": "My Game",
    "description": null,
    "status": "sales_open",
    "number_range": {"min": 1, "max": 100, "hits_required": 3},
    "ticket_price": {"amount_cents": 500, "currency": "PEN"},
    "prize": {"amount_cents": 50000, "currency": "PEN"},
    "schedule": {
      "sales_opens_at": "ISO8601 UTC",
      "sales_closes_at": "ISO8601 UTC",
      "scheduled_start_at": "ISO8601 UTC",
      "draw_interval_seconds": 30,
      "auto_draw_enabled": true
    },
    "lifecycle": {
      "started_at": null,
      "paused_at": null,
      "completed_at": null
    },
    "numbers": {
      "total": 100,
      "sold": 45,
      "reserved": 5,
      "available": 50
    },
    "ops": {
      "draws_total": 12,
      "orders_pending": 3,
      "payments_under_review": 1,
      "entries_confirmed": 40
    },
    "created_by": 1,
    "created_at": "ISO8601 UTC"
  }],
  "links": {...},
  "meta": {...}
}
```

`numbers.total` = `number_max - number_min + 1` (sin query).
`sold/reserved/available` — `withCount` con condicion en una sola query.
`ops.*` — subqueries correlacionadas en `addSelect`; `DB::table()` para
operaciones cross-modulo (sin importar modelos de Commerce desde RNB).
Todos los agregados son estables para N games en una sola pagina (sin N+1).

No se expone: `settings`, `next_draw_at`, `last_consumed_tick_at`, PII de
jugadores, tokens, hashes de OAuth ni invitaciones.

### Detalle — GET /api/v1/admin/games/{game}

El binding `{game}` usa UUID. Devuelve cualquier estado (incluyendo `draft`
y `cancelled`). UUID inexistente → 404.

**Contrato del recurso (`AdminGameDetailResource`, wrap `data`):**

```json
{
  "data": {
    "...campos del resumen menos ops...",
    "engine": {
      "next_draw_at": "ISO8601 UTC o null",
      "last_consumed_tick_at": "ISO8601 UTC o null"
    },
    "settings": {"...configuracion interna..."},
    "latest_draw": {"sequence": 5, "number": 42, "drawn_at": "ISO8601 UTC"},
    "winner": {
      "user_id": 7,
      "game_number_id": "uuid",
      "winning_number": 42,
      "game_draw_id": "uuid",
      "winning_draw_sequence": 5,
      "winning_hits": 3,
      "won_at": "ISO8601 UTC"
    },
    "commerce": {
      "reservations": {"total": 5},
      "orders": {
        "pending": 3, "payment_submitted": 1, "paid": 40,
        "rejected": 2, "expired": 1, "cancelled": 4, "refunded": 0
      },
      "payments": {
        "pending": 1, "under_review": 2, "approved": 38,
        "rejected": 3, "cancelled": 2, "refunded": 0
      },
      "entries": {
        "confirmed": 40, "cancelled": 4, "refunded": 0, "winner": 1
      }
    },
    "projection": {
      "draws_total": 87,
      "distinct_drawn_numbers": 32,
      "max_counter_hits": 5,
      "last_drawn_number": 42
    }
  }
}
```

`latest_draw` y `winner` son `null` cuando no existen.

**Reservaciones — por que solo existe `total`:**
`NumberReservation` no tiene columna de estado (`id`, `order_id`,
`game_number_id` unicamente). El ciclo de vida de una reserva es implicito
en el `Order.status` del padre — esa es la fuente unica de verdad, por diseno.
No se inventan estados derivados ni se hace JOIN adicional: `reservations.total`
cuenta las filas en `number_reservations` que pertenecen a la partida via
`game_numbers.game_id`. Tests: `test_detail_reservations_has_total_only_no_status_breakdown`
y `test_detail_reservations_do_not_include_other_games` confirman el contrato.

**Implementacion sin N+1:**
- `with(['latestDraw', 'winner.gameNumber:id,number', 'winner.draw:id,sequence'])`
- `withCount([sold_count, reserved_count, available_count])`
- `computeCommerce()` — 4 queries con PostgreSQL `FILTER (WHERE ...)` para
  conteos por estado en una sola pasada por categoria.
- `computeProjection()` — 2 queries: `COUNT` + `COUNT(DISTINCT)` sobre
  `game_draws`; `MAX(hits_count)` sobre `game_number_counters`.
- Total de SELECTs <= 16 (incluyendo auth).

**Privacidad:**
- `winner` expone solo `user_id`; nunca nombre, email, password, ni tokens.
- `commerce` expone solo conteos por estado; no raw metadata de pagos.
- Rutas de evidencia (`disk`, `path`, `original_filename`, `sha256` de
  `payment_documents`) nunca aparecen en la respuesta.
- Claves de idempotencia, `rejection_reason`, `reviewed_by` nunca expuestos.

### Arquitectura

- `ListAdminGamesRequest` — valida filtros; autoriza via `Gate::allows('viewAny', Game::class)`.
- `ShowAdminGameRequest` — autoriza via `Gate::allows('view', $game)`.
- `ListAdminGamesQuery` — paginacion con `withCount` condicional + subqueries
  via `DB::table()` (sin importar modelos de Commerce → respeta limite de modulo);
  usa `GameStatus::publiclyVisible()` para el filtro `published`.
- `GetAdminGameDetailQuery` — eager loads + `computeCommerce()` + `computeProjection()`;
  agrega resultados via `$game->setAttribute(...)`.
- `AdminGameSummaryResource` — listado; sin settings, sin estado del motor.
- `AdminGameDetailResource` — detalle completo; `$wrap = 'data'`.
- `ListAdminGamesController` y `ShowAdminGameController` — invokables delgados.

**Visibilidad centralizada:**
`GameStatus::publiclyVisible()` en `app/Modules/RepeatNumberBingo/Domain/Enums/GameStatus.php`
es la unica fuente de verdad para los estados publicamente visibles (7 de 9).
`ListPublicGamesQuery` y `ListAdminGamesQuery` delegan a este metodo; no mantienen
copias propias. `Phase5ArchitectureTest::test_list_public_games_query_calls_game_status_publicly_visible`
y `::test_list_admin_games_query_calls_game_status_publicly_visible` impiden divergencia.

### Pruebas focalizadas (Bloque 5.6 + hardening)

`tests/Feature/Game/AdminGameListTest.php` — 26 tests:

- Auth: requiere autenticacion; rol `admin` obligatorio.
- Status coverage: devuelve todos los estados incluyendo `draft` y `cancelled`.
- Filtros: `status` exacto (422 si invalido); busqueda parcial por nombre;
  busqueda por slug; `published=true/false`; `auto_draw_enabled=true/false`
  (422 si valor no-booleano); rango de fechas; rango invertido → 422;
  combinacion de multiples filtros.
- `published=true` — test basico (excluye draft/cancelled) y test exhaustivo
  que crea uno de cada uno de los 7 estados publicos y verifica presencia
  exacta de todos ellos (`test_list_published_true_matches_all_public_statuses`).
  Confirma que el filtro usa `whereIn` con whitelist explicita igual a
  `ListPublicGamesQuery`, no `whereNotIn` fragil.
- Orden: `created_at DESC, id DESC`; orden estable cuando `created_at` identico.
- Contrato: campos esperados presentes; `settings` y estado del motor ausentes.
- Conteos de numeros: `sold`, `reserved`, `available`, `total`.
- Ops: `draws_total`, `orders_pending`, `payments_under_review`,
  `entries_confirmed` correctos; no contaminan con datos de otras partidas.
- Paginacion: `per_page` > 100 → 422; meta de paginacion presente.
- N+1: `Model::preventLazyLoading` + contador de SELECTs <= 5 para 1 y 5 partidas.

`tests/Feature/Game/AdminGameDetailTest.php` — 26 tests:

- Auth: requiere autenticacion; rol `admin` obligatorio; UUID inexistente → 404.
- Contrato: todos los campos presentes incluyendo `commerce` y `projection`.
- Settings y estado del motor expuestos correctamente.
- Conteos de numeros correctos.
- Commerce por estado: ordenes (7 estados), pagos (6 estados), entradas (4 estados).
- Reservaciones: `commerce.reservations` solo expone `total` (schema sin columna de
  estado); `array_keys` verificado para confirmar ausencia de breakdowns de estado;
  aislamiento entre partidas probado; 2 tests dedicados.
- Projection: `draws_total`, `distinct_drawn_numbers`, `max_counter_hits`,
  `last_drawn_number`; ceros cuando sin sorteos.
- Latest draw presente/null; winner completo/null.
- Accesible para `draft` y `cancelled`.
- Privacidad: PII del ganador ausente (email, nombre); PII de jugadores via
  ordenes ausente; `disk`/`path`/`original_filename`/`sha256` de
  `payment_documents` ausentes; `rejection_reason` y `reviewed_by` ausentes;
  `'"idempotency_key"'` ausente; tokens OAuth, exchange codes ausentes.
- N+1: `Model::preventLazyLoading` + SELECTs <= 16.

Total Bloque 5.6 hardening final: 948 tests / 4200 assertions (suite completa verde).

## 20. Bloque 5.7 — Cierre y hardening de Fase 5

### Cambios implementados

**1. Visibilidad centralizada (`GameStatus::publiclyVisible()`)**
- Se extrae la lista de 7 estados publicos al enum de dominio.
- `ListPublicGamesQuery` y `ListAdminGamesQuery` delegan a `GameStatus::publiclyVisible()`.
- La constante privada `PUBLIC_STATUSES` de `ListAdminGamesQuery` fue eliminada.
- Tests contractuales en `Phase5ArchitectureTest` impiden que cualquiera de las
  dos queries vuelva a mantener su propia copia.

**2. Guardas arquitectonicas (`Phase5ArchitectureTest` — 19 tests)**

| Test | Regla |
|------|-------|
| `test_publicly_visible_returns_exactly_seven_statuses` | 7 estados; Draft y Cancelled ausentes |
| `test_list_public_games_query_calls_game_status_publicly_visible` | Sin copia local en ListPublicGamesQuery |
| `test_list_admin_games_query_calls_game_status_publicly_visible` | Sin copia local ni PUBLIC_STATUSES |
| `test_oauth_attempts_migration_does_not_define_provider_token_columns` | Sin access/refresh/id_token en schema |
| `test_oauth_attempts_migration_stores_hashes_not_plain_state_or_code` | Solo state_hash y exchange_code_hash |
| `test_socialite_does_not_appear_in_app_models` | Laravel\Socialite fuera de modelos |
| `test_social_provider_adapter_lives_in_services_not_domain` | Adapter en app/Services/Auth/ |
| `test_auth_controllers_do_not_own_transactions_or_multi_step_writes` | Sin DB::transaction en controllers |
| `test_auth_resources_execute_no_queries` | Sin queries en Resources/Auth |
| `test_auth_write_actions_control_their_own_transactions` (7×DataProvider) | Cada action wraps DB::transaction |
| `test_new_social_users_are_forced_to_player_role` | forceFill con UserRole::Player |
| `test_email_match_returns_account_link_required_not_auto_link` | Sin auto-vinculacion silenciosa |
| `test_fake_adapters_do_not_exist_in_production_code` | FakeSocialProviderAdapter solo en tests/ |

**3. Flujos E2E admin-game (`AdminGameE2EFlowTest` — 5 tests)**


- Login con credenciales reales → token Sanctum → listar partidas (admin ve Draft + Published + Cancelled).
- Token → filtrar por status=sales_open.
- Token → obtener detalle completo incluyendo commerce y projection.
- Token de jugador no puede acceder a /admin/games (403).
- Token revocado por logout no puede acceder a /admin/games (401).

**4. Flujos E2E de identidad (`IdentityE2EFlowTest` — 5 tests)**

| Test | Cadena |
|------|--------|
| `test_local_chain_register_token_me_logout_rejected` | register → token → me → logout → token rechazado |
| `test_assisted_chain_create_activate_token_me` | admin login → crear player → activate → token → me |
| `test_social_chain_redirect_callback_exchange_token_me` | redirect → callback (fake) → exchange → token → me |
| `test_link_chain_local_user_links_google_then_logs_in_as_same_user` | local → link redirect → link callback → list accounts → social login → mismo User |
| `test_unlink_chain_social_only_user_keeps_other_provider_after_unlink` | reauth token real → unlink → otro proveedor preservado → usuario existe |

Ningun test usa `Sanctum::actingAs()`, Google/Facebook real, Redis ni Reverb.
El guard de Sanctum se limpia con `Auth::forgetGuards()` entre pasos que cambian de usuario.

### Higiene confirmada

- No existen `tests/Feature/Feature/` ni `tests/Feature/Integration/` residuales.
- No existen `Fake*.php` en `app/`.
- No existen imports de `App\Modules\Commerce` en `RepeatNumberBingo`.
- Pint verde sobre todos los archivos modificados.

### Totales Fase 5 (Bloque 5.7 incluido)

Suite completa al cierre de la fase: **977 tests / 4715 assertions**, todos verdes.

Desglose por archivo de test de identidad:

| Archivo | Tests |
|---------|-------|
| `LocalAuthenticationTest` | 16 |
| `AssistedRegistrationTest` | 18 |
| `PlayerActivationTest` | 20 |
| `SocialLoginTest` | 40 |
| `SocialLinkTest` | 49 |
| `IdentityPersistenceTest` | 14 |
| `AdminAccessTest` | 4 |
| `UserRoleAssignmentTest` | 4 |
| `IdentityE2EFlowTest` | 5 |
| `SocialLinkConcurrencyTest` (Integration) | 4 |
| `Phase5ArchitectureTest` (Unit) | 19 |
| `AdminGameE2EFlowTest` | 5 |

## 21. Pendiente para bloques siguientes

- Recuperacion de contrasena.
- Verificacion de correo real.

No se implementa todavia Outbox, pagos externos, payouts, frontend, 2FA, SMS ni
login por telefono.
