# Checklist de revisión Laravel

## Diseño

- [ ] El módulo y el caso de uso tienen nombres del dominio.
- [ ] Se eligió la solución más simple que protege las reglas.
- [ ] Los patrones usados resuelven variación, acoplamiento o complejidad real.
- [ ] No se agregó Repository genérico sin una necesidad de persistencia.
- [ ] No se agregó una interfaz con una sola implementación sin motivo de frontera o sustitución.

## HTTP y aplicación

- [ ] Controller delgado.
- [ ] FormRequest para validación HTTP.
- [ ] DTO tipado al cruzar hacia Application.
- [ ] Action/Use Case con una intención clara.
- [ ] Resource para respuesta pública estable.
- [ ] Policies/Gates para autorización; reglas del negocio en el dominio.

## Dominio

- [ ] Invariantes centralizadas y probadas.
- [ ] Estados con Enum y transiciones válidas.
- [ ] Value Objects para conceptos con validación propia.
- [ ] Strategy solo cuando existen variantes reales.
- [ ] Factory solo para construcción no trivial.
- [ ] Domain Events nombrados en pasado.
- [ ] Eloquent Observers sin lógica crítica oculta.

## Persistencia y concurrencia

- [ ] FK, unique, check, índices y nullability correctos.
- [ ] Transacción limitada a una unidad atómica.
- [ ] Bloqueo solo en recursos con riesgo real de carrera.
- [ ] No hay llamadas externas dentro de la transacción.
- [ ] Operaciones repetibles son idempotentes.
- [ ] Eventos/Jobs que dependen del commit se ejecutan después del commit.

## Integraciones

- [ ] SDK externo aislado mediante Adapter/Contract.
- [ ] Timeouts, errores, reintentos y rate limits considerados.
- [ ] Webhooks autenticados e idempotentes.
- [ ] Datos externos traducidos a DTOs internos.

## Código

- [ ] Tipos y retornos explícitos.
- [ ] DTOs inmutables cuando aplica.
- [ ] Sin `env()` fuera de config.
- [ ] Sin arrays mágicos para datos críticos.
- [ ] Sin `GeneralService`, Helpers globales o God Classes.
- [ ] Sin consultas N+1.
- [ ] Sin datos sensibles en logs.

## Pruebas

- [ ] Unit tests para reglas puras, states, strategies y value objects.
- [ ] Feature tests para endpoints y casos de uso.
- [ ] Casos de error, límites y autorización.
- [ ] Duplicados, idempotencia y concurrencia cuando corresponde.
- [ ] Tests ejecutados con la base o servicios adecuados.

## Entrega

- [ ] Migraciones reversibles y seguras.
- [ ] Se documentan variables de entorno nuevas.
- [ ] Se listan archivos modificados.
- [ ] Se reportan comandos ejecutados y resultados reales.
- [ ] Se declaran supuestos, riesgos y pendientes sin inventar éxito.
