# Plantilla de módulo Laravel

Adapta esta estructura a las convenciones existentes; no la impongas si el proyecto ya tiene una organización coherente.

```text
app/Modules/Bingo/
├── Application/
│   ├── Actions/
│   │   ├── CreateBingoGameAction.php
│   │   ├── StartBingoGameAction.php
│   │   └── DrawNextBallAction.php
│   ├── DTOs/
│   │   └── CreateBingoGameData.php
│   ├── Queries/
│   │   └── GetBingoGameStateQuery.php
│   └── Jobs/
│       └── DrawNextBallJob.php
├── Domain/
│   ├── Models/
│   │   ├── BingoGame.php
│   │   └── BingoCard.php
│   ├── Enums/
│   │   └── BingoGameStatus.php
│   ├── ValueObjects/
│   │   └── BallNumber.php
│   ├── Services/
│   │   └── BingoPatternValidator.php
│   ├── Contracts/
│   │   ├── BallDrawStrategy.php
│   │   └── BingoGameRepository.php
│   ├── Events/
│   │   └── BallDrawn.php
│   └── Exceptions/
│       └── InvalidGameTransition.php
├── Infrastructure/
│   ├── Persistence/
│   │   └── EloquentBingoGameRepository.php
│   └── Integrations/
└── Presentation/Http/
    ├── Controllers/
    │   └── DrawNextBallController.php
    ├── Requests/
    │   └── CreateBingoGameRequest.php
    └── Resources/
        └── BingoGameResource.php
```

## Ejemplo de DTO

```php
<?php

declare(strict_types=1);

final readonly class CreateBingoGameData
{
    public function __construct(
        public string $name,
        public int $totalBalls,
        public int $drawIntervalSeconds,
    ) {}
}
```

## Ejemplo de Action

```php
<?php

declare(strict_types=1);

final class StartBingoGameAction
{
    public function execute(string $gameId): BingoGame
    {
        return DB::transaction(function () use ($gameId): BingoGame {
            $game = BingoGame::query()
                ->whereKey($gameId)
                ->lockForUpdate()
                ->firstOrFail();

            $game->start();
            $game->save();

            BingoGameStarted::dispatch($game->id);

            return $game->refresh();
        });
    }
}
```

## Ejemplo de Controller delgado

```php
<?php

declare(strict_types=1);

final class StartBingoGameController
{
    public function __invoke(
        BingoGame $game,
        StartBingoGameAction $action,
    ): BingoGameResource {
        Gate::authorize('start', $game);

        return new BingoGameResource(
            $action->execute($game->getKey()),
        );
    }
}
```

## Ejemplo de Strategy + Resolver

```php
interface BallDrawStrategy
{
    public function draw(BingoGame $game): BallNumber;
}

final class BallDrawStrategyResolver
{
    public function __construct(
        private SecureRandomDrawStrategy $secure,
        private ManualDrawStrategy $manual,
    ) {}

    public function resolve(BingoGame $game): BallDrawStrategy
    {
        return match ($game->draw_mode) {
            DrawMode::SecureRandom => $this->secure,
            DrawMode::Manual => $this->manual,
        };
    }
}
```

## Reglas de transacción

Mantén dentro de la transacción únicamente:

- lecturas que necesitan consistencia;
- validación de invariantes dependientes de DB;
- escritura de la unidad atómica;
- registro de outbox cuando aplique.

Ejecuta fuera de la transacción:

- HTTP a terceros;
- envío de correo o WhatsApp;
- generación pesada de documentos;
- broadcasting no crítico.
