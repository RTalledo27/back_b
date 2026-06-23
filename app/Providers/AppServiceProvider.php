<?php

namespace App\Providers;

use App\Modules\Commerce\Domain\Models\Order;
use App\Modules\Commerce\Domain\Models\Payment;
use App\Modules\Commerce\Infrastructure\GameLifecycle\CommerceGameStartReadinessChecker;
use App\Modules\Commerce\Presentation\Http\Policies\OrderPolicy;
use App\Modules\Commerce\Presentation\Http\Policies\PaymentPolicy;
use App\Modules\RepeatNumberBingo\Application\Contracts\DrawNumberStrategy;
use App\Modules\RepeatNumberBingo\Application\Contracts\GameStartReadinessChecker;
use App\Modules\RepeatNumberBingo\Application\Contracts\PublicGameUpdatesPublisher;
use App\Modules\RepeatNumberBingo\Domain\Models\Game;
use App\Modules\RepeatNumberBingo\Domain\Services\EngineTickCommandIdGenerator;
use App\Modules\RepeatNumberBingo\Infrastructure\Broadcasting\LaravelPublicGameUpdatesPublisher;
use App\Modules\RepeatNumberBingo\Infrastructure\Randomness\CryptographicallySecureDrawNumberStrategy;
use App\Modules\RepeatNumberBingo\Presentation\Http\Policies\GamePolicy;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->bind(DrawNumberStrategy::class, CryptographicallySecureDrawNumberStrategy::class);
        $this->app->bind(GameStartReadinessChecker::class, CommerceGameStartReadinessChecker::class);
        $this->app->bind(PublicGameUpdatesPublisher::class, LaravelPublicGameUpdatesPublisher::class);
        $this->app->singleton(EngineTickCommandIdGenerator::class, fn () => new EngineTickCommandIdGenerator(
            (string) config('engine.draw_command_namespace'),
        ));
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        Gate::policy(Game::class, GamePolicy::class);
        Gate::policy(Order::class, OrderPolicy::class);
        Gate::policy(Payment::class, PaymentPolicy::class);
    }
}
