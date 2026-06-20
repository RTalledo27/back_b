# Catálogo de patrones para Laravel

Esta referencia clasifica cada elemento correctamente. No todos son patrones GoF.

## Arquitecturas y enfoques

### Monolito modular

**Categoría:** arquitectura.

Organiza una sola aplicación desplegable por dominios o módulos. Es la opción predeterminada para productos nuevos y medianos.

**Usar cuando:** se necesita separación clara sin complejidad operativa de microservicios.

**Evitar:** módulos que se importan mutuamente sin límites, tablas compartidas sin dueño y servicios globales.

### Clean Architecture pragmática

**Categoría:** estilo arquitectónico.

Separa Presentation, Application, Domain e Infrastructure y dirige dependencias hacia el negocio.

**Usar cuando:** el sistema contiene reglas importantes e integraciones cambiantes.

**No hacer:** duplicar cada modelo Eloquent con entidades, mappers y repositorios si no aporta aislamiento real.

### DDD ligero

**Categoría:** enfoque de modelado.

Modela lenguaje, reglas, entidades, Value Objects, servicios y eventos alrededor del dominio.

**Usar cuando:** existen invariantes y procesos de negocio, como partidas, cartones, pagos o contratos.

**No hacer:** aplicar Aggregates, Event Sourcing y repositorios universales por obligación académica.

### CQRS ligero

**Categoría:** patrón arquitectónico de aplicación.

Separa escrituras mediante Actions y lecturas mediante Query Objects.

**Usar cuando:** las consultas y comandos tienen necesidades distintas.

**Evitar:** bases separadas, buses y proyecciones si un Eloquent Query resuelve el problema.

### Event-Driven Architecture

**Categoría:** estilo arquitectónico.

Los módulos reaccionan a eventos relevantes. En un monolito, úsala con moderación para efectos secundarios desacoplados.

**Evitar:** convertir procesos síncronos obligatorios en listeners impredecibles.

---

## Patrones de diseño

### Strategy

Encapsula algoritmos intercambiables bajo un contrato.

Ejemplos: `BallDrawStrategy`, `PaymentStrategy`, `PricingStrategy`.

**Señal:** varios comportamientos con la misma entrada/salida.

**Error:** usar Strategy para dos ramas triviales que nunca cambiarán.

### Factory

Centraliza creación compleja o dependiente de una variante.

Ejemplos: `BingoCardFactory`, `PaymentGatewayFactory`.

**Señal:** construir el objeto requiere reglas, dependencias o selección por tipo.

**Error:** factories que solo ejecutan `new Foo()`.

### State

Encapsula transiciones y, cuando es necesario, comportamiento por estado.

Ejemplos: partida `draft/running/paused/completed`; pago `pending/approved/rejected`.

**Progresión recomendada:** Enum -> matriz de transiciones -> clases State si crece el comportamiento.

### Adapter

Traduce un proveedor externo a un contrato propio.

Ejemplos: `MercadoPagoAdapter`, `WhatsAppCloudAdapter`, `S3StorageAdapter`.

**Regla:** el dominio no conoce SDKs ni payloads del proveedor.

### Observer

Notifica a interesados cuando ocurre un hecho.

En Laravel, preferir Domain Events + Listeners. Eloquent Observers solo para automatismos del ciclo de persistencia.

### Chain of Responsibility / Pipeline

Pasa un contexto por pasos independientes.

Ejemplos: validación de compra, procesamiento de importación, creación de contrato.

**Usar cuando:** los pasos son secuenciales, reutilizables o configurables.

**Evitar:** ocultar el orden, las transacciones o el flujo de errores.

### Repository

Abstrae acceso a persistencia mediante métodos orientados al dominio.

**Usar cuando:** agregados complejos, varias fuentes, persistencia reemplazable o consultas semánticas compartidas.

**No usar:** para duplicar `Model::find()`, `create()` o `update()`.

### Specification

Encapsula reglas booleanas combinables.

Ejemplos: `CardEligibleForClaim`, `UserCanJoinGame`, `OrderCanBeRefunded`.

**Usar cuando:** una regla se reutiliza, combina y necesita pruebas aisladas.

### Builder

Construye objetos complejos paso a paso.

Útil para payloads, reportes o configuraciones complejas. No reemplaza DTOs simples.

### Decorator

Añade comportamiento alrededor de una implementación sin cambiarla.

Ejemplos: cachear un Repository, registrar métricas alrededor de un Adapter.

### Facade

Ofrece una interfaz simplificada a un subsistema. Las Facades de Laravel son además proxies al contenedor.

**Usar con cuidado:** cómodas en bordes de framework; evitar acoplar el dominio a Facades.

---

## Patrones de aplicación y DDD

### Action / Use Case

Una clase representa una intención: `StartBingoGameAction`, `ApprovePaymentAction`.

Debe coordinar reglas, persistencia y transacción. Evita Actions CRUD sin intención si el flujo es trivial.

### DTO

Objeto tipado para transportar datos entre capas.

Debe ser inmutable cuando sea posible y no contener dependencias HTTP.

### Query Object

Encapsula una lectura compleja o reutilizable.

No necesita interfaz salvo que exista una razón de sustitución.

### Domain Service

Regla del negocio que no corresponde naturalmente a una entidad o Value Object.

Debe usar lenguaje del dominio, no ser un `GeneralService`.

### Value Object

Objeto inmutable definido por sus valores e invariantes.

Ejemplos: `Money`, `BallNumber`, `CardMatrix`, `DateRange`, `Percentage`.

### Domain Event

Hecho relevante ocurrido en el dominio, nombrado en pasado.

Ejemplos: `BallDrawn`, `WinnerDeclared`, `PaymentApproved`.

### Aggregate

Conjunto de entidades modificado a través de una raíz que protege invariantes.

Úsalo solo cuando exista una frontera transaccional clara. En Laravel pragmático, no fuerces agregados para cada relación Eloquent.

### Domain Policy

Regla o decisión del dominio que combina varias entidades o datos. No confundir con Laravel Policies, que resuelven autorización del usuario.

---

## Patrones de integración y confiabilidad

### Idempotency

La misma solicitud puede repetirse sin duplicar efectos.

Aplicar a webhooks, jobs, pagos, claims, importaciones y generación de documentos.

### Transactional Outbox

Guarda el evento saliente dentro de la misma transacción que el cambio de negocio; otro proceso lo publica.

Usar cuando perder el evento tras el commit sea inaceptable.

### Retry con backoff

Reintenta errores transitorios con espera creciente y límite. No reintentar validaciones o errores permanentes.

### Circuit Breaker

Detiene llamadas temporalmente cuando un proveedor falla repetidamente.

### Optimistic Locking

Detecta cambios concurrentes con una versión o timestamp.

Útil cuando los conflictos son poco frecuentes.

### Pessimistic Locking

Bloquea filas con `FOR UPDATE` durante una transacción.

Útil para asignaciones, secuencias, cupos o extracción de bolas.

### Cache-Aside

La aplicación consulta cache y, si falta, carga desde DB y guarda. Siempre define invalidación y fuente oficial.

---

## Mecanismos de Laravel que no son patrones por sí solos

- Form Requests: validación/autorización HTTP.
- API Resources: transformación de respuesta.
- Policies/Gates: autorización.
- Jobs/Queues/Horizon: ejecución asíncrona y operación de colas.
- Events/Listeners: mecanismo que puede implementar Observer o arquitectura por eventos.
- Service Container/Providers: inyección y configuración.
- `DB::transaction()`: mecanismo transaccional.
- `lockForUpdate()`: bloqueo pesimista.
- Redis Lock: bloqueo distribuido.
- Reverb/WebSockets: transporte en tiempo real.
- Migraciones e índices: esquema y consistencia.

Clasifica correctamente estos mecanismos al explicarlos.
