# Aislamiento determinista de tests backend

## Causa raíz

La configuración histórica enviaba todas las ejecuciones a `backend_rifas_app_test`. Cada proceso PHPUnit mantiene su propio estado estático, por lo que `LazilyRefreshDatabase` ejecuta `migrate:fresh` una vez por proceso. Los tests con `DatabaseTruncation` también ejecutan `migrate:fresh` y después truncan tablas fuera de una transacción. Además, los tests de concurrencia abren conexiones o procesos PostgreSQL adicionales.

Dos comandos simultáneos sobre la misma base podían eliminar tablas mientras el otro proceso migraba o ejecutaba assertions. Los síntomas reproducidos fueron `Duplicate table`, ausencia de `migrations`, `users`, `orders` y `notification_deliveries`, y datos visibles desde otra suite.

## Estrategia T4

- Cada invocación local crea una base PostgreSQL efímera `backend_rifas_app_test_<token>`.
- Cada invocación usa un contenedor CLI efímero y no altera el contenedor HTTP `rifas_app`.
- Los procesos hijos de los tests de concurrencia heredan la base exclusiva de su invocación.
- Las pruebas destructivas de migración usan otra base efímera exclusiva.
- CI ejecuta la suite de forma serial dentro de un job con su propio servicio PostgreSQL y `backend_rifas_app_test_ci`.

No se habilita paralelismo interno de PHPUnit. La separación permite ejecutar comandos independientes al mismo tiempo sin compartir esquema.

## Guard de seguridad

`scripts/test-backend.ps1` valida el nombre antes de ejecutar Docker. Rechaza:

- `backend_rifas_app` y el `DB_DATABASE` principal configurado;
- `backend_rifas_app_test`, porque es la base compartida histórica;
- `postgres` y `defaultdb`;
- nombres vacíos o no marcados;
- tokens con caracteres distintos de letras, números y guion bajo.

`tests/TestCase.php` repite la validación antes de inicializar Laravel y vuelve a comprobar la conexión resuelta después del bootstrap. Esto evita que un config cache incorrecto apunte a otra base.

## Comandos locales

Suite completa con token generado automáticamente:

```powershell
powershell -ExecutionPolicy Bypass -File scripts/test-backend.ps1
```

Suite o archivo focal:

```powershell
powershell -ExecutionPolicy Bypass -File scripts/test-backend.ps1 -Path tests/Feature/Auth
```

La salida compacta se activa con `-Compact`. Ejecutar `php artisan test` directamente queda bloqueado por `tests/TestCase.php` cuando apunta a la base compartida histórica.

Validar el guard sin crear bases ni ejecutar Docker:

```powershell
powershell -ExecutionPolicy Bypass -File scripts/test-backend-guard.tests.ps1
```

Token explícito y seguro para diagnóstico reproducible:

```powershell
powershell -ExecutionPolicy Bypass -File scripts/test-backend.ps1 `
  -Token auth_01 `
  -Path tests/Feature/Auth
```

Conservar temporalmente la base tras la ejecución:

```powershell
powershell -ExecutionPolicy Bypass -File scripts/test-backend.ps1 `
  -Token diagnose_01 `
  -KeepDatabase `
  -Path tests/Feature/Auth
```

La base conservada debe eliminarse manualmente solo después de validar su prefijo y confirmar que pertenece a la ejecución.

## Ejecución simultánea

Dos comandos pueden correr juntos si usan tokens distintos:

```powershell
powershell -File scripts/test-backend.ps1 -Token auth_a -Path tests/Feature/Auth
powershell -File scripts/test-backend.ps1 -Token outbox_b -Path tests/Integration/Shared
```

Reutilizar el mismo token falla durante `createdb`; el segundo proceso no adopta ni elimina una base creada por otro.

## Tests de concurrencia

Los tests que usan `DatabaseTruncation`, PDO adicional o procesos PHP deben ejecutarse mediante el runner. Sus conexiones reciben `DB_DATABASE` desde el contenedor aislado. El cleanup interno puede truncar su propia base, pero nunca la de otra invocación.

## Migraciones y rollback

El modo `migration` crea una base exclusiva, ejecuta `migrate:fresh`, rollback y migrate, muestra `migrate:status` y finalmente elimina la base:

```powershell
powershell -File scripts/test-backend.ps1 -Mode migration -Token migration_01
```

Para una migración focal:

```powershell
powershell -File scripts/test-backend.ps1 `
  -Mode migration `
  -Token notification_migration `
  -MigrationPath database/migrations/2026_07_01_001719_create_notification_deliveries_table.php
```

## CI

El job backend usa un servicio PostgreSQL privado del job, `APP_ENV=testing`, `DB_DATABASE=backend_rifas_app_test_ci`, permisos `contents: read` y una sola ejecución serial de PHPUnit. No usa bases externas, `continue-on-error` ni pasos de deployment.

## Cleanup seguro

El runner elimina únicamente una base que él mismo creó y cuyo nombre vuelve a pasar el guard. No elimina `backend_rifas_app_test`, volúmenes ni contenedores de desarrollo. `-KeepDatabase` desactiva el drop para diagnóstico.

## Troubleshooting

- `Refusing protected or shared database`: use el runner sin `-DatabaseName` o proporcione un token válido.
- `database already exists`: otro proceso usa el mismo token; elija uno distinto.
- `rifas_postgres must be running`: inicie únicamente los servicios locales necesarios y vuelva a ejecutar.
- Fallo durante migraciones: use `-KeepDatabase`, inspeccione esa base aislada y elimínela después de validar el nombre.
- Fallos de producto repetibles en una base aislada no deben clasificarse como flakiness.

## Qué no hacer

- No ejecutar dos `php artisan test` contra `backend_rifas_app_test`.
- No ejecutar `migrate:fresh`, rollback o truncate contra `backend_rifas_app`.
- No usar `docker compose down -v`, prune ni eliminación masiva de bases.
- No editar `.env` para alternar manualmente entre principal y testing.
- No aumentar timeouts, omitir tests ni relajar assertions para ocultar colisiones.

## Riesgos pendientes

- La primera ejecución de este workflow en GitHub Actions sigue pendiente; la validación realizada en B21 cubre su sintaxis y semántica local, no sustituye esa ejecución remota.
- Los tests que usan procesos reales siguen dependiendo de recursos suficientes de Docker y PostgreSQL.
- Una terminación forzada del proceso host puede dejar una DB efímera. Debe listarse y eliminarse manualmente por nombre exacto validado.
- La estrategia evita colisiones de base; no pretende resolver conflictos sobre archivos temporales que un test futuro pueda compartir fuera de PostgreSQL.
