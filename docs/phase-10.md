# Fase 10 — Integración de pagos externos

## 1. Alcance de esta fase

La Fase 10 comienza con una auditoría de contrato. El Bloque 10.1 (Fase 10.1)
documenta la integración futura entre Commerce y un proveedor externo de pagos, sin añadir
un proveedor, endpoints, migraciones ni cambios en el flujo de negocio actual.

Esta fase no implementa Culqi, Niubiz, Stripe ni otro gateway real. Tampoco
implementa credenciales, checkout externo, redirecciones, webhooks, frontend,
Outbox nuevo o cambios en Commerce, Game o Auth.

## 2. Flujo vigente y fuente de verdad

El flujo vigente es manual y conserva PostgreSQL como fuente de verdad:

1. El jugador reserva números mediante `POST /api/v1/games/{game}/reservations`.
2. El jugador adjunta evidencia mediante
   `POST /api/v1/me/orders/{order}/payment-evidence`.
3. `SubmitPaymentEvidenceAction` persiste la evidencia en `payment_documents`,
   actualiza el pago y el pedido, y mantiene la operación idempotente.
4. Un administrador consulta la evidencia y ejecuta
   `POST /api/v1/admin/payments/{payment}/approve` o
   `POST /api/v1/admin/payments/{payment}/reject`.
5. La aprobación confirma las entradas y los números; el rechazo libera las
   reservas según las reglas actuales.
6. Un administrador puede solicitar el reembolso mediante
   `POST /api/v1/admin/orders/{order}/refund`.

`Payment` y `Order` son la fuente de verdad del estado comercial actual.
`PaymentDocument` es la evidencia manual inmutable. `Refund` es el registro
inmutable del reembolso. La disponibilidad, venta y asignación de números
continúan siendo responsabilidad de las tablas operativas de Commerce.

El pago de un ganador sigue siendo un registro administrativo manual mediante
`ProcessWinnerPayoutAction`; no es una operación del gateway.

## 3. Estados actuales

### `PaymentStatus`

Los valores actuales son:

* `pending`: pago creado, todavía sin evidencia resuelta;
* `under_review`: evidencia presentada y pendiente de revisión;
* `approved`: pago aprobado y aplicado al pedido;
* `rejected`: pago rechazado;
* `cancelled`: pago cancelado por una regla vigente;
* `refunded`: pago reembolsado.

### `OrderStatus`

Los valores actuales son:

* `pending`: pedido abierto sin evidencia resuelta;
* `payment_submitted`: evidencia presentada;
* `paid`: pago aprobado y pedido confirmado;
* `rejected`: evidencia rechazada;
* `expired`: pedido vencido antes de ser resuelto;
* `cancelled`: pedido cancelado;
* `refunded`: pedido reembolsado.

Las transiciones válidas permanecen definidas por `PaymentStatus` y
`OrderStatus`. Este bloque no agrega valores a los enums existentes.

## 4. Contrato conceptual futuro

La integración futura debe estar detrás de un límite de aplicación y no debe
acoplar el dominio a un SDK. Los nombres conceptuales auditados son:

* `PaymentGatewayProvider`: capacidades de un proveedor, como crear un intento,
  confirmar una operación y verificar una notificación;
* `PaymentGatewayTransaction`: referencia normalizada del proveedor, estado,
  importe, moneda y timestamps relevantes;
* `PaymentGatewayWebhook`: evento externo recibido, firma verificada, identidad
  del proveedor y resultado de procesamiento;
* `PaymentGatewayAttempt`: intento de checkout o reintento, su clave de
  idempotencia y su relación con el pedido.

Estos nombres describen un contrato futuro y no representan clases, tablas ni
servicios implementados en 10.1. La implementación posterior debe ubicar los
adaptadores en Infrastructure, mantener las Actions como orquestadoras y
evitar que el dominio conozca HTTP, SDKs o credenciales.

## 5. Fuente de verdad por etapa futura

La separación propuesta es:

| Etapa | Fuente primaria | Regla |
| --- | --- | --- |
| Antes del pago | `Order` y `Payment` | El importe, moneda y vigencia salen del pedido persistido. |
| Checkout | `PaymentGatewayAttempt` futuro | El intento relaciona la solicitud externa con el pedido. |
| Autorizado | Transacción del proveedor normalizada | No equivale a pago confirmado hasta cumplir la política de captura. |
| Capturado o pagado | `Payment` y `Order` | La confirmación comercial se persiste en PostgreSQL. |
| Fallido | Intento y `Payment` | Un fallo técnico no debe convertir automáticamente un pago en rechazo comercial. |
| Expirado | Intento y `Order` | La expiración debe respetar la vigencia del pedido y sus transiciones. |
| Reembolsado | `Refund`, `Payment` y `Order` | El reembolso se registra una sola vez y mantiene su auditoría. |
| Notificación tardía o duplicada | `PaymentGatewayWebhook` futuro | Se deduplica y se compara con el estado terminal existente. |
| Reconciliación | Proveedor frente a PostgreSQL | Las diferencias se detectan y resuelven explícitamente. |

El proveedor nunca es fuente de verdad del estado público de la aplicación.
Una llamada externa no debe ejecutarse dentro de la transacción que confirma
el estado comercial.

## 6. Estados propuestos para una futura migración

La nomenclatura conceptual propuesta es:

* `manual_pending`;
* `gateway_pending`;
* `gateway_authorized`;
* `gateway_paid`;
* `gateway_failed`;
* `gateway_expired`;
* `gateway_refunded`.

No se deben insertar estos valores en los enums actuales sin una decisión de
compatibilidad y una migración. La correspondencia inicial orientativa sería:

| Estado futuro | Estado actual compatible | Observación |
| --- | --- | --- |
| `manual_pending` | `pending` o `under_review` | Depende de si ya existe evidencia manual. |
| `gateway_pending` | `pending` | El intento futuro distingue el origen. |
| `gateway_authorized` | Sin equivalente seguro | No debe marcarse `approved` antes de capturar. |
| `gateway_paid` | `approved` y `paid` | Requiere confirmación comercial persistida. |
| `gateway_failed` | Sin transición automática obligatoria | Un error transitorio no es rechazo comercial. |
| `gateway_expired` | `cancelled` o `expired` | Depende de la regla de vigencia del pedido. |
| `gateway_refunded` | `refunded` y `refunded` | Debe existir un `Refund` único. |

La migración futura debe conservar lecturas y escrituras del flujo manual hasta
que exista una política de transición aprobada.

## 7. Idempotencia y concurrencia

El contrato actual usa `Idempotency-Key` para las operaciones Commerce que
producen efectos. `IdempotencyContext`, `IdempotencyKeyStore` y
`IdempotentCommandExecutor` normalizan la solicitud, calculan un hash del
payload, reclaman la clave y guardan el resultado. Una repetición con el mismo
payload puede reproducir el resultado; una repetición con payload diferente
debe fallar como conflicto.

Para una integración futura:

* crear un intento debe reclamar una clave por usuario, pedido, operación y
  proveedor;
* reintentar checkout con la misma clave y huella debe devolver el intento
  anterior, sin una segunda operación externa;
* reusar una clave con otra huella debe ser un conflicto estable;
* confirmar autorización o captura debe bloquear `Payment` y `Order`, validar
  sus relaciones y aplicar una única transición en una transacción;
* una transición terminal repetida debe ser un resultado idempotente o una
  discrepancia explícita, nunca una segunda venta o reembolso;
* un doble approval y un doble release deben quedar protegidos por los locks,
  constraints y Actions actuales;
* un webhook repetido debe usar el identificador del proveedor y el proveedor
  como deduplicación, nunca el payload crudo;
* un evento Outbox repetido debe conservar una sola `deduplication_key`;
* `notification_deliveries` deduplica la entrega posterior, pero no reemplaza
  la idempotencia del pago.

La reclamación de una clave debe ocurrir antes de efectos externos o mutaciones
comerciales. Los locks y las transacciones protegen el estado confirmado; no
garantizan que un proveedor externo haya ejecutado una operación.

## 8. Diseño futuro de webhooks

Como propuesta, no implementada en 10.1, el endpoint podría ser:

`POST /api/v1/webhooks/payments/{provider}`

El adaptador futuro debería:

1. validar el proveedor esperado y la versión del contrato;
2. verificar firma y timestamp con comparación en tiempo constante;
3. rechazar mensajes sin autenticidad verificable sin exponer detalles;
4. guardar, con acceso restringido, una referencia al payload original y su
   hash, sin mostrarlo al frontend;
5. deduplicar por `provider` e identificador del evento;
6. procesar el evento dentro de una transacción con locks sobre `Payment` y
   `Order`;
7. tratar estados terminales como replay seguro o discrepancia auditable;
8. distinguir errores reintentables de errores permanentes;
9. registrar el resultado de procesamiento y permitir una reconciliación
   posterior.

El endpoint, la política de replay operativo, el procesamiento comercial y la
política de respuestas HTTP requieren una fase posterior aprobada. Las tablas
internas del ledger se crean en la Fase 10.3, pero no existe endpoint público
ni procesamiento externo.

## 9. Seguridad y datos sensibles

La aplicación no debe recibir ni persistir números completos de tarjeta, CVV,
claves privadas, tokens secretos ni credenciales del proveedor. Los logs deben
contener identificadores técnicos mínimos y nunca secretos, payloads completos
ni datos de autenticación.

Las credenciales futuras deben vivir exclusivamente en variables de entorno o
en un gestor de secretos. Sandbox y producción deben tener configuración y
credenciales separadas. Las respuestas públicas solo deben incluir estados y
referencias no sensibles; nunca deben incluir el payload completo del
proveedor, tokens de sesión o material criptográfico.

## 10. Integración con Outbox y Notifications

Cuando una futura confirmación cambie el estado comercial, la mutación de
`Payment`/`Order` y el registro Outbox correspondiente deben confirmarse en la
misma transacción. Los handlers de Outbox seguirán siendo responsables de
notificaciones; las Actions de dominio no deben llamar `notify()`, `Mail::` ni
`Notification::`.

Los eventos existentes relacionados con el flujo aprobado son:

* `payment_approved`;
* `payment_rejected`;
* `order_refunded`;
* `winner_payout_registered`;
* `game_winner_declared`.

10.1 no agrega eventos ni modifica handlers o `notification_deliveries`.
La entrega continúa siendo durable y de mejor esfuerzo con semántica de
reintento `at-least-once`; no se promete `exactly-once`. La ejecución futura
seguirá usando el `worker` de la cola y el `scheduler` ya documentados para
Outbox, sin convertirlos en fuente de verdad.

## 11. Evaluación conceptual de proveedores

La comparación inicial para Perú debe considerar Culqi y Niubiz, y Stripe solo
si el modelo comercial y la disponibilidad regional lo justifican. También
debe mantenerse el flujo manual como alternativa operativa durante la adopción.

La decisión debe evaluar cobertura de moneda y país, checkout, autorización y
captura, reembolsos, firma de webhooks, identificadores idempotentes,
reconciliación, soporte, costos, sandbox y requisitos de cumplimiento. No se
selecciona proveedor ni se incorporan SDKs o credenciales en esta fase.

## 12. Pruebas requeridas para una fase de implementación

Antes de activar un proveedor deberán existir, como mínimo:

* pruebas de contrato del adaptador con un `Fake` determinista;
* creación, reintento y conflicto de `PaymentGatewayAttempt`;
* concurrencia sobre confirmación, captura y reembolso;
* firmas válidas, inválidas, expiradas y payload alterado;
* replay y webhook duplicado sin doble transición;
* estados terminales y late webhook;
* reconciliación de diferencias entre proveedor y PostgreSQL;
* ausencia de PII, CVV, secretos y payload completo en logs o Resources;
* confirmación de que Outbox se registra una sola vez y que Notifications
  conserva su deduplicación;
* regresión completa de Commerce, Game, Auth y Architecture sin Redis externo.

## Fase 10.2 — Foundation interna de gateway (Bloque 10.2)

Este bloque prepara una base interna y sustituible para una futura pasarela,
sin conectar con un proveedor externo. Se crearon los contratos
`PaymentGatewayProvider`, `PaymentGatewayProviderRegistry`,
`PaymentGatewayTransactionStatus`, `PaymentGatewayCreateAttemptData`,
`PaymentGatewayCreateAttemptResult`, `PaymentGatewayConfirmData`,
`PaymentGatewayConfirmResult`, `PaymentGatewayWebhookPayload`,
`PaymentGatewayWebhookNormalizer`, `PaymentGatewayWebhookVerifier` y
`PaymentGatewayException`.

La única implementación disponible es `FakePaymentGatewayProvider`. Permite
crear intentos, confirmar estados `authorized`, `paid`, `failed` y `expired`,
simular fallos, repetir solicitudes y deduplicar eventos por proveedor e
identificador de evento. No realiza llamadas HTTP ni depende de un SDK.

La configuración segura está en `config/payment_gateways.php` y usa:

* `PAYMENT_GATEWAY_PROVIDER=fake`;
* `PAYMENT_GATEWAY_ENV=sandbox`;
* `PAYMENT_GATEWAY_WEBHOOK_TOLERANCE_SECONDS=300`;
* `PAYMENT_GATEWAY_PUBLIC_KEY=`;
* `PAYMENT_GATEWAY_SECRET_KEY=`;
* `PAYMENT_GATEWAY_WEBHOOK_SECRET=`.

En el bloque 10.2 no se crearon migraciones ni tablas
`payment_gateway_attempts`, `payment_gateway_transactions` o
`payment_gateway_webhooks`. Esa foundation se mantuvo en contratos y memoria
de prueba para no alterar el flujo manual; el ledger persistente se implementa
únicamente en la sección 10.3.

La idempotencia se expresa con `idempotencyKeyHash` y `requestFingerprint`.
Una repetición idéntica devuelve el resultado anterior; una huella diferente
produce `PaymentGatewayException`. El diseño no persiste el token plano ni
emite eventos Outbox nuevos.

El parsing interno usa `FakePaymentGatewayWebhookNormalizer` y la firma de
prueba usa `FakePaymentGatewayWebhookVerifier`, con formato temporal y
tolerancia configurada. No existe endpoint público, controller productivo ni
persistencia de payload completo. Un payload normalizado conserva únicamente
referencias y datos mínimos de la transacción.

Las pruebas de foundation cubren determinismo, replay, conflicto, fallos,
estados terminales, firma válida e inválida, expiración de firma, webhook
duplicado, ausencia de HTTP externo, ausencia de rutas públicas y preservación
del flujo manual. No se modificaron Actions de Commerce ni tipos Outbox.

## Fase 10.3 — Gateway persistence ledger

Este bloque agrega únicamente un ledger interno de persistencia para preparar
una futura integración, sin seleccionar ni conectar un proveedor real. Las
tres tablas PostgreSQL son:

* `payment_gateway_attempts`: intento asociado a `Order` y `Payment`, con
  proveedor, entorno, hashes de idempotencia y fingerprint, estado, monto,
  moneda y referencias mínimas de checkout;
* `payment_gateway_transactions`: transacción del proveedor asociada al
  intento y al `Payment`, con estado, monto, moneda, fechas técnicas y solo un
  hash de referencia cruda;
* `payment_gateway_webhooks`: identificador del evento, tipo, verificación de
  firma, hash del payload y resultado temporal de procesamiento.

Los modelos internos son `PaymentGatewayAttempt`,
`PaymentGatewayTransaction` y `PaymentGatewayWebhook`. Sus identificadores son
UUID v7 generados por PHP; PostgreSQL no genera UUID ni recibe el payload
completo. Las constraints únicas protegen, respectivamente,
`(provider, idempotency_key_hash)`, `(provider, provider_transaction_id)` y
`(provider, provider_event_id)`.

`RecordPaymentGatewayAttemptAction`,
`RecordPaymentGatewayTransactionAction` y
`RecordPaymentGatewayWebhookAction` controlan sus propias transacciones. Una
repetición idéntica devuelve el registro existente. Una misma clave o
identificador con fingerprint, referencias o datos inmutables diferentes
produce `PaymentGatewayException::idempotencyConflict()`. El patrón
`insertOrIgnore` más `lockForUpdate` permite replays seguros bajo concurrencia.

El ledger no modifica `Payment`, `Order` ni sus estados, no llama a ningún
proveedor, no realiza HTTP, no registra Outbox y no crea endpoints públicos.
El `FakePaymentGatewayProvider` continúa siendo suficiente para las pruebas de
foundation y ledger. No se almacenan números de tarjeta, CVV, secretos,
credenciales, tokens ni payloads completos. La deduplicación es idempotencia
de mejor esfuerzo respaldada por PostgreSQL. No se afirma exactly-once.

La siguiente etapa, sujeta a aprobación, puede construir orquestación interna
sobre este ledger, pero deberá conservarlo como registro técnico y mantener el
flujo manual durante la transición.

## Fase 10.4 — Gateway orchestration with fake provider

Esta fase agrega la orquestación interna de gateway usando únicamente
`FakePaymentGatewayProvider`, los contratos de 10.2 y el ledger de 10.3. No
existe proveedor real, SDK, HTTP externo, endpoint de checkout ni webhook
público.

Las actions creadas son:

* `CreateGatewayPaymentAttemptAction`: bloquea `Order` y `Payment` en ese
  orden, valida usuario y estados `pending`, solicita un attempt al registry,
  y registra el resultado en `payment_gateway_attempts`;
* `ConfirmGatewayPaymentAttemptAction`: recupera el attempt, confirma mediante
  el fake, registra una transacción técnica y devuelve el resultado normalizado;
* `RecordGatewayWebhookNotificationAction`: valida la firma con el verifier
  fake, normaliza el payload y registra únicamente su referencia técnica en
  `payment_gateway_webhooks`.

Los DTOs de orquestación son `readonly` y no contienen PII, secretos ni
payloads completos. Los resultados exponen solo identificadores técnicos,
estado, monto, moneda, fechas y referencias fake necesarias para continuar el
flujo interno.

La idempotencia se mantiene en dos niveles: el fake rechaza la reutilización
de una clave con fingerprint distinto y PostgreSQL protege el ledger con sus
constraints únicas. Un replay del mismo attempt devuelve el registro previo;
un replay de confirmación no crea otra transacción; un webhook repetido no
crea otra fila. Las discrepancias se propagan como errores internos
testeables, sin revelar secretos.

La orquestación no cambia `PaymentStatus` a `approved`, no cambia
`OrderStatus` a `paid`, no llama `ApprovePaymentAction`, no confirma números,
no registra Outbox y no genera notificaciones. La confirmación almacenada es
únicamente técnica; la transición comercial queda reservada para la Fase
10.5.

La Fase 10.5 podrá decidir la integración comercial, las transiciones de
`Payment` y `Order`, captura o reembolso externo, reconciliación y sus
controles operativos. Ninguna de esas capacidades forma parte de 10.4.

## Fase 10.5 — Gateway commercial settlement

El settlement recibe una `PaymentGatewayTransaction` persistida y solo aplica
la transición si el estado técnico es `paid` o `captured`. Verifica proveedor,
entorno `sandbox`, relaciones entre transaction, attempt, `Payment`, `Order` y
`Game`, además de monto exacto y moneda exacta. Los estados `authorized`,
`failed` y `expired` no producen transición comercial, rechazo automático ni
liberación automática.

La mutación reutiliza `ApplyApprovedPaymentTransitionAction`, compartida con
`ApprovePaymentAction`, y conserva el orden canónico de locks:

`Game -> Order -> Payment -> OrderItems -> NumberReservations -> GameNumbers`

Las filas gateway se leen antes de la transición para validar el contrato y se
marca `applied_at` después de completar la cadena comercial bajo los locks
canónicos. No se hacen llamadas externas dentro de la transacción. `applied_at`
es una marca técnica durable de que esa transaction ya aplicó el settlement.

El camino gateway permite únicamente `Payment: pending -> approved` y
`Order: pending -> paid`; no inventa un reviewer. El procesamiento deja
`reviewed_by` nulo y usa el origen explícito `gateway`. Un replay de la misma
transaction devuelve el snapshot existente sin duplicar entries, allocations,
game events, Outbox o notifications. Otra transaction pagada para el mismo
`Payment`, o cualquier discrepancia de relación, proveedor, entorno, monto o
moneda, produce un conflicto estable.

La aprobación comercial y el único evento Outbox `payment_approved` se
confirman en la misma transacción. Las notifications siguen siendo responsabilidad
de los handlers de Outbox; ninguna Action llama `notify()`, `Mail::` o
`Notification::` directamente. La idempotencia es de mejor esfuerzo respaldada
por PostgreSQL y los locks; no se afirma exactly-once.

Las pruebas cubren `paid`, `captured`, estados no aplicables, discrepancias,
replay e idempotencia, dos procesos concurrentes, ausencia de duplicados y
preservación del flujo manual. El fake continúa siendo el único proveedor y no
hay endpoint público, checkout, webhook HTTP ni credenciales reales.

## Fase 10.6 — Durable gateway webhook processing pipeline

Este bloque conecta únicamente el webhook fake ya verificado con el ledger
durable y el settlement comercial existente. No crea endpoint HTTP público,
controller, SDK, proveedor real, checkout, redirección ni credenciales reales.

`RecordGatewayWebhookNotificationAction` verifica la firma, normaliza el
evento y persiste solo metadatos seguros: proveedor, evento, tipo, hash del
payload, estado, monto, moneda, entorno, `occurred_at`,
`provider_attempt_id` y `provider_transaction_id`. No se persisten payloads,
tarjetas, CVV, tokens, secretos ni PII. PostgreSQL deduplica por
`(provider, provider_event_id)` y un conflicto de metadatos inmutables se
rechaza de forma estable.

`ProcessGatewayWebhookAction` recibe el UUID durable del webhook, bloquea esa
fila con `lockForUpdate`, valida firma y metadatos contra
`PaymentGatewayAttempt`, y registra o reproduce una
`PaymentGatewayTransaction`. `paid` y `captured` llaman al settlement
existente; este confirma `Payment`, `Order`, entries, números y el evento
Outbox ya existente `payment_approved` en la misma transacción. `authorized`,
`failed` y `expired` solo dejan estado técnico y no rechazan, liberan ni
modifican Commerce.

El estado técnico se confirma primero. La marca `processed_at` se confirma en
una transacción posterior, de modo que un fallo entre settlement y marcado es
recuperable: `applied_at` evita repetir la transición comercial y la
idempotencia de PostgreSQL evita duplicar la transacción. Los fallos dejan
`failed_at`, `last_error` seguro y `processing_attempts`; los estados
desconocidos producen un fallo controlado y no se ocultan. Dos procesos reales
compitiendo sobre el mismo webhook reutilizan las filas y los locks sin crear
duplicados.

La entrega posterior sigue siendo responsabilidad del Outbox existente y de
sus cinco handlers. Este bloque no añade eventos, notificaciones, endpoints ni
dependencias de Redis. La garantía es de entrega al menos una vez con
idempotencia durable; no se afirma `exactly-once`.

Las pruebas cubren estados pagado, capturado, autorizado, fallido y expirado,
replay, conflicto de metadatos, firma inválida, inconsistencias de monto,
moneda, proveedor y entorno, recuperación después de `applied_at` y dos
procesos concurrentes. También verifican que el estado comercial, la
transacción técnica y el Outbox permanezcan coherentes.

## Fase 10.7 — Gateway HTTP boundary con fake provider

Este bloque expone únicamente el boundary HTTP mínimo para probar y operar el
flujo de pagos con el fake provider. La feature está desactivada por defecto
mediante `PAYMENT_GATEWAY_HTTP_ENABLED=false`; cuando está desactivada, las
rutas responden `404`.

El jugador autenticado y con correo verificado puede crear y consultar un
attempt propio:

* `POST /api/v1/me/orders/{order}/gateway-attempts` requiere
  `auth:sanctum`, `verified`, `Idempotency-Key` y el proveedor permitido;
* `GET /api/v1/me/orders/{order}/gateway-attempts/{attempt}` devuelve el
  attempt únicamente si pertenece al jugador y al order indicado.

La creación toma monto, moneda, `Payment` y demás identificadores del ledger
interno. El cliente no puede enviar ni alterar esos valores, estados,
checkout URL o referencias del proveedor. La respuesta Resource solo contiene
`id`, `provider`, `status`, `amount_cents`, `currency`, `checkout_url` y
`expires_at`. Un replay idéntico es estable; la misma clave con otro body o
proveedor produce un conflicto.

El endpoint público de webhook es:

`POST /api/v1/webhooks/payments/{provider}`

No usa Sanctum. Requiere `Content-Type: application/json`, un cuerpo dentro de
`PAYMENT_GATEWAY_WEBHOOK_MAX_BODY_BYTES`, y los headers
`X-Gateway-Event-Id`, `X-Gateway-Timestamp` y `X-Gateway-Signature`. La firma
se verifica sobre el cuerpo HTTP crudo, con tolerancia temporal del fake
verifier. El provider se resuelve mediante el registry permitido; no hay
llamadas HTTP externas ni SDK real.

El procesamiento es síncrono en esta fase: registra el webhook, lo procesa y
solo después devuelve `{"received":true}`. Una firma inválida devuelve `401`,
un provider desconocido `404`, un replay válido `200`, un conflicto inmutable
`409` y un fallo interno `500`, siempre con mensajes estables y sin secretos,
payloads ni detalles internos. El procesamiento se basa en el ledger de
PostgreSQL y conserva la idempotencia durable del webhook, la transaction y el
settlement; se trata de una garantía de mejor esfuerzo con reintentos seguros,
no de `exactly-once`.

Los endpoints usan rate limits separados para creación, lectura y webhook.
No se añaden Outbox events, notifications ni escrituras adicionales para
emitir respuestas HTTP. No se implementan WhatsApp, SMS, gateway real,
frontend, checkout, autenticación adicional ni nuevas capacidades de negocio.

Operación local: activar la feature solo en el entorno de prueba, mantener
`QUEUE_CONNECTION=database`, ejecutar el worker documentado y usar Mailpit
para las notificaciones existentes. Un smoke test debe crear un attempt con
`Idempotency-Key`, enviar un webhook firmado sobre el body exacto, comprobar
`{"received":true}`, y consultar el order y el payment para verificar el
settlement. El replay del mismo evento debe ser seguro.

En producción se requiere un secreto gestionado fuera del repositorio,
HTTPS, límites de cuerpo y rate limits revisados, logs sin PII ni firmas, y
monitoreo de respuestas `401`, `409` y `500`. Esta fase no afirma entrega
exacta, disponibilidad de un proveedor real ni recuperación automática ante
fallos externos. La recuperación se realiza reenviando eventos válidos o
reprocesando el ledger durable según los procedimientos de las fases 10.3 a
10.6.

## 14. Próxima fase y límites

La siguiente etapa, sujeta a aprobación, es Fase 10.8 y podrá evaluar un
proveedor real, checkout, captura externa, reembolso, cancelación y
reconciliación. No forman parte de esta fase.
