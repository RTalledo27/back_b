<?php

declare(strict_types=1);

namespace Tests\Unit\Game;

use App\Actions\Auth\ActivatePlayerAction;
use App\Actions\Auth\CompleteSocialExchangeAction;
use App\Actions\Auth\HandleSocialCallbackAction;
use App\Actions\Auth\HandleSocialLinkCallbackAction;
use App\Actions\Auth\RegisterPlayerAction;
use App\Actions\Auth\ResolveSocialIdentityAction;
use App\Actions\Auth\UnlinkSocialAccountAction;
use App\Modules\RepeatNumberBingo\Application\Queries\ListAdminGamesQuery;
use App\Modules\RepeatNumberBingo\Application\Queries\ListPublicGamesQuery;
use App\Modules\RepeatNumberBingo\Domain\Enums\GameStatus;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use ReflectionClass;

/**
 * Phase 5 architectural guard rails.
 *
 * Enforces structural rules that prevent security regressions:
 * single source of truth for game visibility, no raw provider tokens persisted,
 * Socialite/Sanctum out of Domain, adapter fakes in tests/Support only.
 */
final class Phase5ArchitectureTest extends TestCase
{
    // ─── Visibility source-of-truth ───────────────────────────────────────────

    public function test_publicly_visible_returns_exactly_seven_statuses(): void
    {
        $statuses = GameStatus::publiclyVisible();

        $this->assertCount(7, $statuses,
            'publiclyVisible() must list exactly 7 values; update this test only when a new status is intentionally public');

        foreach ($statuses as $status) {
            $this->assertInstanceOf(GameStatus::class, $status);
        }

        $this->assertNotContains(GameStatus::Draft, $statuses, 'Draft must never be publicly visible');
        $this->assertNotContains(GameStatus::Cancelled, $statuses, 'Cancelled must never be publicly visible');
    }

    public function test_list_public_games_query_calls_game_status_publicly_visible(): void
    {
        $source = (string) file_get_contents((new ReflectionClass(ListPublicGamesQuery::class))->getFileName());

        $this->assertStringContainsString('GameStatus::publiclyVisible()', $source,
            'ListPublicGamesQuery must delegate to GameStatus::publiclyVisible() — no local copy');

        // Guard against hard-coded status strings that would create a second source.
        foreach (["'published'", "'sales_open'", "'completed'"] as $literal) {
            $this->assertStringNotContainsString($literal, $source);
        }
    }

    public function test_list_admin_games_query_calls_game_status_publicly_visible(): void
    {
        $source = (string) file_get_contents((new ReflectionClass(ListAdminGamesQuery::class))->getFileName());

        $this->assertStringContainsString('GameStatus::publiclyVisible()', $source,
            'ListAdminGamesQuery must delegate to GameStatus::publiclyVisible() — no local copy');

        $this->assertStringNotContainsString('PUBLIC_STATUSES', $source,
            'ListAdminGamesQuery must not define its own PUBLIC_STATUSES constant');
    }

    // ─── No provider tokens persisted ─────────────────────────────────────────

    public function test_oauth_attempts_migration_does_not_define_provider_token_columns(): void
    {
        $migrations = array_values(array_filter(
            $this->phpFiles($this->basePath('database/migrations')),
            fn (string $f): bool => str_contains($f, 'oauth_attempt'),
        ));

        $this->assertNotEmpty($migrations, 'Migration for oauth_attempts must exist');

        $source = (string) file_get_contents($migrations[0]);

        foreach (['access_token', 'refresh_token', 'id_token', 'raw_payload', 'raw_user'] as $forbidden) {
            $this->assertStringNotContainsString($forbidden, $source,
                "oauth_attempts migration must not contain column '{$forbidden}' — provider tokens are never persisted");
        }
    }

    public function test_oauth_attempts_migration_stores_hashes_not_plain_state_or_code(): void
    {
        $migrations = array_values(array_filter(
            $this->phpFiles($this->basePath('database/migrations')),
            fn (string $f): bool => str_contains($f, 'oauth_attempt'),
        ));

        $this->assertNotEmpty($migrations);

        $source = (string) file_get_contents($migrations[0]);

        $this->assertStringContainsString('state_hash', $source, 'oauth_attempts must have state_hash column');
        $this->assertStringContainsString('exchange_code_hash', $source, 'oauth_attempts must have exchange_code_hash column');

        $this->assertDoesNotMatchRegularExpression("/->string\('state'\)/", $source,
            "oauth_attempts must not have a plain 'state' column — only state_hash");
        $this->assertDoesNotMatchRegularExpression("/->string\('exchange_code'\)/", $source,
            "oauth_attempts must not have a plain 'exchange_code' column — only exchange_code_hash");
    }

    // ─── Socialite not in Domain ──────────────────────────────────────────────

    public function test_socialite_does_not_appear_in_app_models(): void
    {
        foreach ($this->phpFiles($this->basePath('app/Models')) as $file) {
            $source = (string) file_get_contents($file);
            $this->assertStringNotContainsString('Laravel\\Socialite', $source, $file);
        }
    }

    public function test_social_provider_adapter_lives_in_services_not_domain(): void
    {
        $this->assertFileExists($this->basePath('app/Services/Auth/SocialProviderAdapter.php'),
            'SocialProviderAdapter must live in app/Services/Auth/');
    }

    // ─── Auth controllers thin ────────────────────────────────────────────────

    public function test_auth_controllers_do_not_own_transactions_or_multi_step_writes(): void
    {
        // Redirect controllers may call Model::create() for a single-insert write
        // (e.g. OauthAttempt state creation before redirecting) — that is acceptable.
        // What must never appear in a controller is a DB::transaction() or
        // coordinated multi-step write that belongs in an Action.
        foreach ($this->phpFiles($this->basePath('app/Http/Controllers/Auth')) as $file) {
            $source = (string) file_get_contents($file);

            $this->assertStringNotContainsString('DB::transaction', $source, $file);
            $this->assertStringNotContainsString('DB::statement', $source, $file);
            $this->assertStringNotContainsString('->update(', $source, $file);
            $this->assertStringNotContainsString('->delete(', $source, $file);
        }
    }

    public function test_auth_resources_execute_no_queries(): void
    {
        foreach ($this->phpFiles($this->basePath('app/Http/Resources/Auth')) as $file) {
            $source = (string) file_get_contents($file);

            foreach (['::query(', 'DB::', '->load(', '->loadMissing(', '->fresh(', '->refresh('] as $forbidden) {
                $this->assertStringNotContainsString($forbidden, $source, $file);
            }
        }
    }

    // ─── Auth actions own transactions ────────────────────────────────────────

    /**
     * @return iterable<string, array{class-string}>
     */
    public static function transactionalAuthActions(): iterable
    {
        yield 'register' => [RegisterPlayerAction::class];
        yield 'activate' => [ActivatePlayerAction::class];
        yield 'social callback' => [HandleSocialCallbackAction::class];
        yield 'social exchange' => [CompleteSocialExchangeAction::class];
        yield 'social link callback' => [HandleSocialLinkCallbackAction::class];
        yield 'resolve identity' => [ResolveSocialIdentityAction::class];
        yield 'unlink social' => [UnlinkSocialAccountAction::class];
    }

    /**
     * @param  class-string  $action
     */
    #[DataProvider('transactionalAuthActions')]
    public function test_auth_write_actions_control_their_own_transactions(string $action): void
    {
        $source = (string) file_get_contents((new ReflectionClass($action))->getFileName());

        $this->assertStringContainsString('DB::transaction(', $source,
            "{$action} must wrap its writes in DB::transaction()");
    }

    // ─── Social identity: always Player, never auto-link ─────────────────────

    public function test_new_social_users_are_forced_to_player_role(): void
    {
        $source = (string) file_get_contents((new ReflectionClass(ResolveSocialIdentityAction::class))->getFileName());

        $this->assertStringContainsString('UserRole::Player', $source,
            'ResolveSocialIdentityAction must assign UserRole::Player to new social users');

        $this->assertStringContainsString("forceFill(['role' => UserRole::Player])", $source,
            'Role must be set via forceFill to bypass mass-assignment guard');
    }

    public function test_email_match_returns_account_link_required_not_auto_link(): void
    {
        $source = (string) file_get_contents((new ReflectionClass(ResolveSocialIdentityAction::class))->getFileName());

        $this->assertStringContainsString('SocialAuthOutcome::AccountLinkRequired', $source,
            'An email match must surface AccountLinkRequired, not silently link accounts');
    }

    // ─── Fakes only in tests/Support ──────────────────────────────────────────

    public function test_fake_adapters_do_not_exist_in_production_code(): void
    {
        foreach ($this->phpFiles($this->basePath('app')) as $file) {
            $source = (string) file_get_contents($file);

            $this->assertStringNotContainsString('FakeSocialProviderAdapter', $source, $file);
        }
    }

    // ─── Helpers ──────────────────────────────────────────────────────────────

    /**
     * @return list<string>
     */
    private function phpFiles(string $directory): array
    {
        if (! is_dir($directory)) {
            return [];
        }

        $files = [];
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($directory, RecursiveDirectoryIterator::SKIP_DOTS),
        );

        foreach ($iterator as $file) {
            if ($file->isFile() && $file->getExtension() === 'php') {
                $files[] = $file->getPathname();
            }
        }

        sort($files);

        return $files;
    }

    private function basePath(string $path): string
    {
        return dirname(__DIR__, 3).'/'.$path;
    }
}
