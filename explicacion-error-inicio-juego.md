# Explicacion del error al iniciar juego

## Resumen corto

El backend no esta fallando con un error ambiguo en este caso. El rechazo real que encontro Laravel para el intento de inicio fue:

- `error`: `game_not_ready_for_start`
- `reasons`: `["no_confirmed_entries"]`

Ademas, el ultimo error registrado confirma exactamente eso:

- Fecha del log: `2026-07-10 21:50:26`
- Mensaje: `Game is not ready to start: no_confirmed_entries.`

## Que valida el backend al iniciar

El endpoint `POST /api/v1/admin/games/{game}/start` termina ejecutando `StartGameAction`, y antes de cambiar el juego a `running` hace varias validaciones:

1. El juego debe estar en estado `sales_closed`.
2. Debe tener `scheduled_start_at`.
3. La hora actual no puede ser menor a `scheduled_start_at`.
4. Debe pasar el chequeo de readiness comercial.

Referencia:

- `StartGameAction` llama al checker en [app/Modules/RepeatNumberBingo/Application/Actions/StartGameAction.php](C:/Users/rogit/proyectos/rifas/backend_rifas_app/app/Modules/RepeatNumberBingo/Application/Actions/StartGameAction.php:179)

## Que significa "readiness" aqui

El checker real es `CommerceGameStartReadinessChecker`. Ese codigo acumula razones de rechazo y luego lanza una sola excepcion con todas ellas.

Las razones posibles son:

- `has_pending_orders`
- `has_payment_submitted_orders`
- `has_pending_payments`
- `has_under_review_payments`
- `has_active_reservations`
- `has_reserved_numbers`
- `no_confirmed_entries`

Referencia:

- Lista de checks en [app/Modules/Commerce/Infrastructure/GameLifecycle/CommerceGameStartReadinessChecker.php](C:/Users/rogit/proyectos/rifas/backend_rifas_app/app/Modules/Commerce/Infrastructure/GameLifecycle/CommerceGameStartReadinessChecker.php:44)
- Regla exacta de entries confirmadas en [app/Modules/Commerce/Infrastructure/GameLifecycle/CommerceGameStartReadinessChecker.php](C:/Users/rogit/proyectos/rifas/backend_rifas_app/app/Modules/Commerce/Infrastructure/GameLifecycle/CommerceGameStartReadinessChecker.php:63)

En tu caso, el motivo real fue este:

- Si `confirmedEntries === 0`, el backend agrega `no_confirmed_entries` y rechaza el inicio.

## Por que la UI se ve mas generica

La excepcion del backend si contiene detalle:

- mensaje: `Game is not ready to start: no_confirmed_entries.`
- error: `game_not_ready_for_start`
- reasons: arreglo con los codigos exactos

Eso se renderiza en `bootstrap/app.php`, donde Laravel devuelve JSON con `error` y `reasons`.

Referencia:

- Excepcion con reasons en [app/Modules/RepeatNumberBingo/Domain/Exceptions/GameNotReadyForStart.php](C:/Users/rogit/proyectos/rifas/backend_rifas_app/app/Modules/RepeatNumberBingo/Domain/Exceptions/GameNotReadyForStart.php:17)
- Mapeo HTTP JSON en [bootstrap/app.php](C:/Users/rogit/proyectos/rifas/backend_rifas_app/bootstrap/app.php:250)

Entonces, si en pantalla aparece algo como:

- `Laravel rechazo el inicio del juego por estado o readiness actual. Revisa el contexto y vuelve a intentarlo.`

eso no parece venir del backend como mensaje final exacto, sino de una capa posterior que probablemente:

- detecta `error = game_not_ready_for_start`
- ignora o no muestra `reasons`
- reemplaza el mensaje especifico por uno mas general

## Como encajan tus capturas

Las capturas son consistentes con el rechazo por `no_confirmed_entries`:

- aparece `Estado: Ventas cerradas`, que es compatible con intentar iniciar
- no hay draws aun, lo cual no bloquea por si solo
- el aviso previo ya dice que se necesita al menos una `entry confirmada`

O sea: el juego si llego a la fase correcta para intentar iniciar, pero el backend detecto que no existe ninguna entrada confirmada asociada al juego.

## Prueba en tests

Este comportamiento ya esta cubierto por test:

- el test espera `422`
- espera `error = game_not_ready_for_start`
- espera que exista `reasons`

Referencia:

- [tests/Feature/Game/AdminEngineEndpointsTest.php](C:/Users/rogit/proyectos/rifas/backend_rifas_app/tests/Feature/Game/AdminEngineEndpointsTest.php:97)

## Conclusion

La causa actual no es que Laravel este escondiendo la razon internamente. La razon exacta del backend para este caso fue:

- `no_confirmed_entries`

Si el mensaje que ves en frontend se siente poco exacto, el problema mas probable esta en la presentacion del error, no en la deteccion del backend. El backend ya esta enviando un payload suficientemente preciso para mostrar algo como:

- `No se puede iniciar el juego porque no hay entries confirmadas.`
