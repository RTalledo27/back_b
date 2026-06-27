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
use App\Services\Auth\SocialiteProviderAdapter;
use App\Services\Auth\SocialProviderAdapter;
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;
use Illuminate\Support\Str;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->bind(SocialProviderAdapter::class, SocialiteProviderAdapter::class);
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

        $this->configureAuthRateLimiters();
        $this->configurePasswordResetUrl();
    }

    private function configurePasswordResetUrl(): void
    {
        ResetPassword::createUrlUsing(function (mixed $notifiable, string $token): string {
            $base = config('auth.password_reset_frontend_url')
                ?: (config('app.url').'/reset-password');

            return $base
                .'?token='.rawurlencode($token)
                .'&email='.rawurlencode((string) $notifiable->getEmailForPasswordReset());
        });
    }

    private function configureAuthRateLimiters(): void
    {
        RateLimiter::for('auth.forgot-password', function (Request $request): Limit {
            return Limit::perMinute(5)
                ->by($this->authThrottleKey($request, 'auth.forgot-password'))
                ->response(fn (Request $request, array $headers) => response()->json([
                    'message' => 'Too many authentication attempts.',
                    'error' => 'too_many_requests',
                ], 429, $headers));
        });

        RateLimiter::for('auth.reset-password', function (Request $request): Limit {
            return Limit::perMinute(5)
                ->by('auth.reset-password:'.$request->ip())
                ->response(fn (Request $request, array $headers) => response()->json([
                    'message' => 'Too many authentication attempts.',
                    'error' => 'too_many_requests',
                ], 429, $headers));
        });

        RateLimiter::for('auth.register', function (Request $request): Limit {
            return Limit::perMinute(5)
                ->by($this->authThrottleKey($request, 'register'))
                ->response(fn (Request $request, array $headers) => response()->json([
                    'message' => 'Too many authentication attempts.',
                    'error' => 'too_many_requests',
                ], 429, $headers));
        });

        RateLimiter::for('auth.login', function (Request $request): Limit {
            return Limit::perMinute(5)
                ->by($this->authThrottleKey($request, 'login'))
                ->response(fn (Request $request, array $headers) => response()->json([
                    'message' => 'Too many authentication attempts.',
                    'error' => 'too_many_requests',
                ], 429, $headers));
        });

        RateLimiter::for('auth.activate', function (Request $request): Limit {
            $tokenKey = is_string($request->input('token'))
                ? hash_hmac('sha256', (string) $request->input('token'), 'rate-limit')
                : 'missing-token';

            return Limit::perMinute(10)
                ->by('auth.activate:'.$request->ip().':'.$tokenKey)
                ->response(fn (Request $request, array $headers) => response()->json([
                    'message' => 'Too many authentication attempts.',
                    'error' => 'too_many_requests',
                ], 429, $headers));
        });

        RateLimiter::for('admin.create-player', function (Request $request): Limit {
            $userId = $request->user()?->id ?? 'anonymous';

            return Limit::perMinute(20)
                ->by('admin.create-player:'.$userId)
                ->response(fn (Request $request, array $headers) => response()->json([
                    'message' => 'Too many authentication attempts.',
                    'error' => 'too_many_requests',
                ], 429, $headers));
        });

        RateLimiter::for('auth.social.redirect', function (Request $request): Limit {
            $provider = $request->route('provider') ?? 'unknown';

            return Limit::perMinute(20)
                ->by('auth.social.redirect:'.$request->ip().':'.$provider)
                ->response(fn (Request $request, array $headers) => response()->json([
                    'message' => 'Too many authentication attempts.',
                    'error' => 'too_many_requests',
                ], 429, $headers));
        });

        RateLimiter::for('auth.social.callback', function (Request $request): Limit {
            $provider = $request->route('provider') ?? 'unknown';

            return Limit::perMinute(20)
                ->by('auth.social.callback:'.$request->ip().':'.$provider)
                ->response(fn (Request $request, array $headers) => response()->json([
                    'message' => 'Too many authentication attempts.',
                    'error' => 'too_many_requests',
                ], 429, $headers));
        });

        RateLimiter::for('auth.social.exchange', function (Request $request): Limit {
            return Limit::perMinute(20)
                ->by('auth.social.exchange:'.$request->ip())
                ->response(fn (Request $request, array $headers) => response()->json([
                    'message' => 'Too many authentication attempts.',
                    'error' => 'too_many_requests',
                ], 429, $headers));
        });

        RateLimiter::for('auth.social.link.redirect', function (Request $request): Limit {
            $provider = $request->route('provider') ?? 'unknown';
            $userId = $request->user()?->id ?? 'anonymous';

            return Limit::perMinute(20)
                ->by('auth.social.link.redirect:'.$userId.':'.$provider)
                ->response(fn (Request $request, array $headers) => response()->json([
                    'message' => 'Too many authentication attempts.',
                    'error' => 'too_many_requests',
                ], 429, $headers));
        });

        RateLimiter::for('auth.social.link.callback', function (Request $request): Limit {
            $provider = $request->route('provider') ?? 'unknown';

            return Limit::perMinute(20)
                ->by('auth.social.link.callback:'.$request->ip().':'.$provider)
                ->response(fn (Request $request, array $headers) => response()->json([
                    'message' => 'Too many authentication attempts.',
                    'error' => 'too_many_requests',
                ], 429, $headers));
        });

        RateLimiter::for('auth.social.unlink', function (Request $request): Limit {
            $provider = $request->route('provider') ?? 'unknown';
            $userId = $request->user()?->id ?? 'anonymous';

            return Limit::perMinute(10)
                ->by('auth.social.unlink:'.$userId.':'.$provider)
                ->response(fn (Request $request, array $headers) => response()->json([
                    'message' => 'Too many authentication attempts.',
                    'error' => 'too_many_requests',
                ], 429, $headers));
        });
    }

    private function authThrottleKey(Request $request, string $prefix): string
    {
        $email = is_string($request->input('email'))
            ? Str::of((string) $request->input('email'))->trim()->lower()->toString()
            : 'missing-email';

        return $prefix.':'.$request->ip().':'.$email;
    }
}
