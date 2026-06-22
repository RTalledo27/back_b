---
name: laravel-clean-architecture
description: Diseña, implementa, revisa y refactoriza aplicaciones Laravel con monolito modular, DDD pragmático y patrones bien justificados. Úsala al crear módulos, endpoints, migraciones, modelos, Actions, DTOs, repositorios, integraciones, estados, eventos, jobs, consultas, pruebas o al decidir entre Repository, Strategy, State, Factory, Adapter, Observer, Pipeline, Value Object, Domain Service, Domain Event y CQRS ligero, evitando sobrearquitectura.
user-invocable: true
---

# Laravel Clean Architecture

Trabaja sobre la solicitud actual o sobre: **$ARGUMENTS**.

## Objetivo

Construye código Laravel limpio, cohesivo, comprobable y mantenible. Usa patrones para resolver problemas reales, nunca para decorar el proyecto. Conserva las convenciones existentes mientras no comprometan consistencia, seguridad o mantenibilidad.

## 1. Inspección obligatoria antes de modificar

Antes de proponer o escribir código:

1. Lee `composer.json` para conocer la versión real de PHP, Laravel y paquetes instalados.
2. Revisa la estructura actual, `AGENTS.md`, rutas, modelos, migraciones, providers, tests y convenciones del módulo afectado.
3. Identifica el dominio, el caso de uso, las invariantes, las transacciones y los riesgos de concurrencia.
4. No inventes tablas, campos, estados, permisos, paquetes ni APIs que el proyecto no tenga. Señala los supuestos indispensables.
5. Prefiere cambios pequeños y compatibles. No reestructures todo el proyecto para resolver una tarea local.

## 2. Arquitectura por defecto

Usa un **monolito modular** organizado por contexto o funcionalidad. Aplica **DDD pragmático**, no DDD ceremonial.

Dirección de dependencias:

```text
Presentation -> Application -> Domain
                         ^
Infrastructure ---------|
```

- `Presentation`: HTTP, CLI, Resources y Requests.
- `Application`: casos de uso, DTOs, Queries y coordinación transaccional.
- `Domain`: reglas, estados, Value Objects, contratos y eventos del negocio.
- `Infrastructure`: Eloquent, proveedores externos, cache, mensajería y adaptadores.

Estructura sugerida cuando el proyecto aún no tenga una convención mejor:

```text
app/Modules/<Module>/
├── Application/
│   ├── Actions/
│   ├── DTOs/
│   ├── Queries/
│   └── Jobs/
├── Domain/
│   ├── Models/
│   ├── Enums/
│   ├── ValueObjects/
│   ├── Services/
│   ├── Contracts/
│   ├── Events/
│   └── Exceptions/
├── Infrastructure/
│   ├── Persistence/
│   └── Integrations/
└── Presentation/Http/
    ├── Controllers/
    ├── Requests/
    └── Resources/
```

En un enfoque pragmático, se permite Eloquent en `Domain/Models` si el proyecto ya trabaja así. No construyas entidades puras y mapeadores solo por formalidad.

## 3. Flujo de una operación

```text
Route -> FormRequest -> Controller -> DTO -> Action/UseCase
      -> Domain/Repository/Adapter -> Event -> Listener/Job -> Resource
```

Reglas:

- El Controller coordina; no contiene negocio.
- El FormRequest valida formato y autorización de entrada.
- El DTO transporta datos tipados; no pases `Request` al dominio.
- El Action representa un caso de uso y controla la transacción cuando corresponda.
- Las reglas deben vivir cerca del dominio que protegen.
- El Resource transforma la salida HTTP.

## 4. Patrones que debes evaluar

Consulta `references/PATTERN-CATALOG.md` para la guía completa. Aplica estas prioridades:

### Patrones principales

- **Action / Use Case**: una clase por operación de negocio significativa.
- **DTO**: datos tipados al cruzar límites entre Presentation y Application.
- **State**: ciclo de vida y transiciones válidas. Empieza con Enum; usa clases State cuando cambie el comportamiento por estado.
- **Strategy**: variantes intercambiables de un algoritmo o comportamiento.
- **Factory**: creación compleja o dependiente de una variante.
- **Adapter**: aislar SDKs, APIs y proveedores externos detrás de contratos propios.
- **Observer**: preferir Domain Events + Listeners explícitos. Eloquent Observers solo para automatismos técnicos simples.
- **Repository**: solo cuando abstraiga persistencia compleja, agregados o múltiples fuentes. No envuelvas CRUD de Eloquent sin valor.

### Patrones de dominio y aplicación

- **Domain Service**: regla de negocio que no pertenece naturalmente a una entidad.
- **Value Object**: concepto inmutable con validación propia, como Money, BallNumber o DateRange.
- **Domain Event**: hecho relevante que ya ocurrió, nombrado en pasado.
- **Query Object**: lectura compleja o reutilizable.
- **Pipeline / Chain of Responsibility**: proceso secuencial con pasos independientes y componibles.
- **Specification**: reglas combinables y reutilizables para elegibilidad o validación compleja.
- **CQRS ligero**: separar Actions que escriben de Queries que leen; no crear buses o almacenes separados sin necesidad.

### Patrones de confiabilidad

- **Idempotency**: webhooks, jobs, pagos, sorteos, claims y comandos repetibles.
- **Transactional Outbox**: cuando sea inaceptable confirmar la DB y perder el evento externo.
- **Retry con backoff**: fallos transitorios, nunca errores de validación.
- **Circuit Breaker**: proveedor externo inestable o costoso.
- **Optimistic/Pessimistic Locking**: concurrencia sobre recursos compartidos.

## 5. Matriz rápida de decisión

```text
¿Es una operación de negocio?                  -> Action
¿Cruza una frontera con datos tipados?         -> DTO
¿Hay variantes intercambiables?                -> Strategy
¿La creación cambia por variante?              -> Factory
¿El comportamiento depende del estado?         -> State
¿Es un proveedor o SDK externo?                -> Adapter
¿Algo ya ocurrió y otros reaccionan?           -> Domain Event + Listener
¿La persistencia es compleja o reemplazable?   -> Repository
¿Es una consulta compleja/reutilizable?         -> Query Object
¿Es una regla sin dueño natural?                -> Domain Service
¿Es un concepto inmutable con invariantes?      -> Value Object
¿Hay pasos secuenciales reemplazables?          -> Pipeline
¿Puede repetirse sin duplicar efectos?          -> Idempotency
```

No fuerces un patrón cuando una clase o método simple sea más claro.

## 6. Reglas de implementación Laravel

- Usa `declare(strict_types=1);` cuando el proyecto lo adopte.
- Usa tipos de parámetros y retorno, Enums respaldados y DTOs `readonly`.
- Prefiere inyección por constructor y contratos propios para infraestructura externa.
- No uses `env()` fuera de archivos `config/*`.
- No uses `request()`, `auth()` ni objetos HTTP dentro del dominio.
- Evita Helpers globales con negocio y clases `*Service` genéricas.
- Evita Facades dentro de lógica de dominio; se permiten en bordes de Application/Infrastructure cuando simplifiquen Laravel.
- No llames APIs externas dentro de transacciones largas.
- Protege invariantes también en PostgreSQL mediante FK, `unique`, `check`, índices y `not null`.
- Usa `DB::transaction()` para unidades atómicas y `lockForUpdate()` solo cuando el riesgo de carrera sea real.
- Emite eventos dependientes de datos confirmados después del commit.
- Los Jobs deben ser idempotentes, reintentables y delegar el negocio a Actions.
- Los WebSockets notifican; la base de datos sigue siendo la fuente oficial.
- Usa cantidades monetarias en unidad mínima o un Value Object Money; nunca `float`.

## 7. Reglas específicas para Repository

Usa Eloquent directamente desde Actions o Queries para CRUD sencillo.

Crea Repository cuando exista al menos una de estas condiciones:

- reconstrucción de un agregado desde varias tablas;
- consultas de persistencia complejas repetidas en varios casos de uso;
- más de una fuente de datos;
- necesidad real de sustituir la persistencia;
- contrato del dominio que no debe depender de Eloquent;
- bloqueo o guardado coordinado que merece una abstracción semántica.

Un Repository debe expresar intención de negocio:

```php
interface BingoGameRepository
{
    public function getRunningForUpdate(string $gameId): BingoGame;
    public function save(BingoGame $game): void;
}
```

Evita interfaces genéricas `all/find/create/update/delete` que solo duplican Eloquent.

## 8. Eventos y Observer

Usa eventos explícitos:

```text
BingoGameStarted
BallDrawn
BingoClaimSubmitted
BingoWinnerDeclared
PaymentApproved
```

Un evento describe un hecho pasado. Los listeners pueden transmitir, auditar, notificar o despachar Jobs.

No escondas reglas críticas en Eloquent Observers. Resérvalos para UUIDs, normalización o automatismos técnicos previsibles. Una operación obligatoria para completar el caso de uso debe estar en el Action o en la misma transacción, no depender de un listener eventual.

## 9. Estado y Strategy

- Modela estados como Enum y centraliza las transiciones.
- Migra a clases State solo cuando cada estado tenga comportamientos sustancialmente distintos.
- Usa Strategy para algoritmos realmente sustituibles, no para crear una clase por cada `if`.
- Resuelve Strategies mediante una Factory/Resolver explícita; no mediante condicionales dispersos.

## 10. Pruebas obligatorias

Para cada cambio relevante:

- **Unit**: Value Objects, Strategies, State transitions, Specifications y Domain Services puros.
- **Feature**: endpoint completo, autorización, validación, Action y persistencia.
- **Integration**: PostgreSQL, Redis, colas, eventos e integraciones adaptadas.

Incluye casos felices, límites, errores, duplicados, concurrencia e idempotencia cuando correspondan. Usa las herramientas ya instaladas: Pest o PHPUnit. No agregues paquetes sin justificarlo.

## 11. Calidad y verificación

Respeta las herramientas presentes en el proyecto. Cuando existan, ejecuta o recomienda:

```bash
php artisan test
vendor/bin/pint --test
vendor/bin/phpstan analyse
```

No declares éxito sin revisar resultados. Si no puedes ejecutar algo, indícalo claramente.

## 12. Formato de respuesta de Codex

Antes de implementar, comunica brevemente:

1. Caso de uso e invariantes detectadas.
2. Patrones que aplicarás y por qué.
3. Patrones descartados por innecesarios.

Después de implementar, informa:

1. Archivos creados o modificados.
2. Decisiones arquitectónicas importantes.
3. Pruebas realizadas y resultado.
4. Riesgos o pendientes reales.

No presentes todos los patrones como obligatorios. El objetivo es obtener el diseño mínimo que mantenga claras las reglas del negocio.

## 13. Antipatrones prohibidos

- Fat Controllers.
- God Services o `GeneralService`.
- Repository genérico para cada modelo.
- Arrays mágicos para operaciones importantes.
- Requests dentro de Actions o Domain Services.
- Reglas críticas únicamente en frontend.
- Estados como strings libres sin Enum o validación.
- Observers con lógica crítica oculta.
- Eventos usados para pasos obligatorios dentro de la misma transacción.
- Jobs con toda la lógica del negocio.
- APIs externas dentro de transacciones extensas.
- Microservicios, Event Sourcing o CQRS completo sin necesidad demostrable.

## Referencias de esta Skill

- `references/PATTERN-CATALOG.md`: clasificación, uso, señales y errores de cada patrón.
- `references/MODULE-TEMPLATE.md`: plantilla de módulo y flujo de código.
- `references/REVIEW-CHECKLIST.md`: lista para revisar código Laravel.
