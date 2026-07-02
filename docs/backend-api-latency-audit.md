# Backend API Latency Audit

## Entorno

- Repositorio: `backend_rifas_app`
- Fecha: `2026-07-01`
- Ejecucion local: Docker (`rifas_app`, `rifas_postgres`, `rifas_redis`)
- PHP: `8.2.12` en host para Artisan; contenedor basado en `php:8.3-cli-alpine`
- Laravel: `12.62.0`
- DB: PostgreSQL (`postgres:5432`, base `backend_rifas_app`)
- Cache driver: `database`
- Queue driver: `database`
- Session driver: `database`
- `APP_DEBUG=true`
- Config/runtime cache: el contenedor ahora arranca con `php artisan optimize`

## Endpoints auditados

- `POST /api/v1/auth/login`
- `GET /api/v1/auth/me`
- `GET /api/v1/me/orders`
- `GET /api/v1/public/games`
- `GET /api/v1/admin/games`

## Hallazgo principal

La lentitud local no estaba explicada por consultas pesadas ni por falta de indices en los endpoints medidos. El cuello de botella dominante era el runtime local:

1. El contenedor servia la app con `php artisan serve`, o sea CLI SAPI.
2. `opcache.enable_cli` estaba desactivado.
3. El codigo vive en bind mount de Docker sobre Windows, lo que penaliza mucho el bootstrap y la lectura de archivos.

Como efecto secundario, `POST /api/v1/auth/login` tambien paga varias queries extra del rate limiter porque `CACHE_STORE=database`.

## Baseline resumido

### Perfil interno antes del cambio

| Endpoint | Tiempo total | Queries | Observacion |
| --- | ---: | ---: | --- |
| `POST /auth/login` | `5742.95 ms` | `10` | Login valido; cache database visible en rate limiter |
| `GET /auth/me` | `4233.39 ms` | `3` | SQL total muy bajo |
| `GET /me/orders` | `3792.83 ms` | `4` | Solo count paginado sobre `orders` |
| `GET /public/games` | `4233.41 ms` | `1` | Count sobre `games` |
| `GET /admin/games` | `5583.60 ms` | `4` | Count simple; no habia dataset real |

### Medicion HTTP antes del cambio

- `GET /auth/me`: ~`3.94s` a `5.01s`
- `GET /me/orders`: ~`3.50s` a `4.26s`
- `GET /public/games`: ~`3.46s` a `4.16s`
- `GET /admin/games`: ~`4.60s` a `11.34s`
- `POST /auth/login`: la primera corrida HTTP quedo invalidada por quoting de `curl`, asi que la linea base confiable de login se tomo del perfil interno y de logs del contenedor.

## Perfil SQL

Las queries observadas fueron chicas y consistentes con el dataset local:

- `personal_access_tokens`: lookup por PK + update de `last_used_at`
- `users`: lookup por PK
- `orders`: `count(*) where user_id = ?`
- `games`: `count(*)` o `count(*) where status in (...)`
- `cache`: lecturas/escrituras del rate limiter de login

No aparecio evidencia de:

- N+1 en los endpoints auditados
- joins costosos
- scans grandes por volumen
- resources recorriendo relaciones grandes
- necesidad justificada de nuevos indices para este problema local

## Indices revisados

Se verificaron indices utiles ya presentes en:

- `users.email`
- `personal_access_tokens.token`
- `personal_access_tokens (tokenable_type, tokenable_id)`
- `games.slug`
- `orders (user_id, status)`
- `orders (game_id, status)`

Conclusión: para el estado actual de la base local, el problema no era ausencia de indices.

## Cambios aplicados

### 1. OPcache para CLI en local

Archivo: `docker/php/Dockerfile`

- se habilito `opcache.enable_cli=1`
- se agregaron ajustes razonables para desarrollo local:
  - `opcache.validate_timestamps=1`
  - `opcache.revalidate_freq=0`
  - `opcache.memory_consumption=192`
  - `opcache.interned_strings_buffer=16`
  - `opcache.max_accelerated_files=20000`

### 2. Warmup de caches de Laravel al arrancar

Archivo: `docker-compose.yml`

- el servicio `app` ahora arranca con:

```sh
php artisan optimize && php artisan serve --host=0.0.0.0 --port=8000
```

Esto deja precalentados config, routes, events y views antes de las mediciones.

## Medicion despues del cambio

### Perfil interno despues del cambio

| Endpoint | Tiempo total | Queries | Mejora |
| --- | ---: | ---: | ---: |
| `POST /auth/login` | `4502.50 ms` | `12` | `21.60%` |
| `GET /auth/me` | `2867.58 ms` | `3` | `32.26%` |
| `GET /me/orders` | `3516.89 ms` | `4` | `7.28%` |
| `GET /public/games` | `2492.42 ms` | `1` | `41.13%` |
| `GET /admin/games` | `4505.63 ms` | `4` | `19.31%` |

Notas:

- En `auth/login`, el SQL del rate limiter sigue visible por `cache=database`.
- En `auth/me`, `me/orders` y `admin/games`, el SQL sigue estando muy por debajo del tiempo total.
- En `public/games`, aun con una sola query, la request completa seguia costando mucho mas que el tiempo SQL.

### Medicion HTTP despues del cambio

Corridas seriales de 5 repeticiones por endpoint:

| Endpoint | Promedio | Rango |
| --- | ---: | --- |
| `POST /auth/login` | `5654.80 ms` | `4061.32` - `11189.97` |
| `GET /auth/me` | `3691.36 ms` | `3526.51` - `4009.51` |
| `GET /me/orders` | `3909.97 ms` | `3667.51` - `4173.29` |
| `GET /public/games` | `3313.90 ms` | `3099.36` - `3730.33` |
| `GET /admin/games` | `3962.52 ms` | `3700.36` - `4399.97` |

Observaciones:

- Las mediciones paralelas fueron descartadas porque `php artisan serve` serializa requests y contamina la latencia con cola artificial.
- El primer hit de `auth/login` siguio teniendo outlier alto; despues cae al rango ~`4.0s` a `4.5s`.
- La percepcion frontend mejora, pero el runtime local todavia no es “rapido”.

## Endpoint por endpoint

### `POST /api/v1/auth/login`

- Query funcional principal: lookup de usuario por email + `Hash::check` + insercion de token Sanctum.
- Overhead adicional: rate limiter con `cache=database`.
- No aparecio evidencia de query compleja.
- La mejora vino del runtime, no de SQL.

### `GET /api/v1/auth/me`

- Solo 3 queries: token, usuario, update `last_used_at`.
- Es el mejor ejemplo de que el problema no era DB: la request tarda segundos aun con SQL minimo.

### `GET /api/v1/me/orders`

- 4 queries con paginacion vacia.
- Sin N+1 en el caso medido.
- La mejora fue menor porque el endpoint ya estaba relativamente cerca del piso local del runtime.

### `GET /api/v1/public/games`

- Solo 1 query (`count(*)` sobre `games` filtrando por status).
- Fue de los endpoints que mas mejoro tras activar OPcache CLI.

### `GET /api/v1/admin/games`

- 4 queries en el caso real medido.
- La consulta pesada estructural esta en la lista admin cuando haya datos reales, pero el problema observado en este entorno vacio seguia siendo bootstrap/runtime.

## Riesgos y pendientes

1. `php artisan serve` sigue siendo un servidor de desarrollo y no representa bien latencia productiva.
2. El bind mount Docker sobre Windows sigue penalizando I/O; este audit lo reduce, pero no lo elimina.
3. `auth/login` todavia paga el costo del rate limiter en tabla `cache`.
4. `admin/games` debe reprofilearse con volumen real antes de descartar optimizaciones SQL adicionales.
5. Hay un `mailpit` visible en el diff de `docker-compose.yml` que no pertenece a este trabajo y debe tratarse aparte.

## Tests y validacion

- `git diff --check`: sin errores de formato bloqueantes; solo advertencia CRLF/LF en `docker/php/Dockerfile`.
- Host-side (`php artisan test` desde Windows) no es una señal confiable aqui porque `.env` apunta a `DB_HOST=postgres`, hostname que solo resuelve dentro de Docker. Por eso aparecieron fallos espurios como `could not translate host name "postgres"`.
- En contenedor, con ejecucion serial para evitar colisiones de base de pruebas, pasaron:
  - `docker exec rifas_app php artisan test tests/Feature/Auth/AdminAccessTest.php --stop-on-failure`
  - `docker exec rifas_app php artisan test tests/Feature/Game/PublicGameReadApiTest.php --stop-on-failure`
  - `docker exec rifas_app php artisan test tests/Feature/Auth/LocalAuthenticationTest.php --filter "test_login_with_valid_credentials_creates_valid_token|test_me_requires_authentication|test_me_and_legacy_user_alias_share_the_same_public_contract"`
- Un intento previo de correr suites en paralelo dentro del contenedor produjo fallos de migracion/deadlock (`oauth_attempts already exists`, deadlock al dropear tablas). Es ruido del metodo de ejecucion, no señal sobre los cambios de latencia.

## Archivos tocados por este audit

Modificados por este trabajo:

- `docker/php/Dockerfile`
- `docker-compose.yml`
- `docs/backend-api-latency-audit.md`

No se agregaron migraciones ni cambios de contrato HTTP.
