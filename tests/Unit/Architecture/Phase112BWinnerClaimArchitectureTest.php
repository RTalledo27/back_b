<?php

declare(strict_types=1);

namespace Tests\Unit\Architecture;

use App\Modules\Commerce\Domain\Models\WinnerPayout;
use App\Modules\RepeatNumberBingo\Domain\Enums\WinnerClaimEventType;
use App\Modules\RepeatNumberBingo\Domain\Enums\WinnerClaimStatus;
use App\Modules\RepeatNumberBingo\Domain\Models\GamePrizeFunding;
use App\Modules\RepeatNumberBingo\Domain\Models\GameWinner;
use App\Modules\RepeatNumberBingo\Domain\Models\WinnerClaim;
use App\Modules\RepeatNumberBingo\Domain\Models\WinnerClaimEvent;
use App\Modules\RepeatNumberBingo\Domain\Models\WinnerIdentityDocument;
use App\Modules\RepeatNumberBingo\Domain\Models\WinnerIdentityProfile;
use Illuminate\Routing\Router;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

final class Phase112BWinnerClaimArchitectureTest extends TestCase
{
    #[Test]
    public function claim_aggregate_and_status_contract_exist(): void
    {
        self::assertSame('winner_claims', (new WinnerClaim)->getTable());
        self::assertSame('winner_identity_profiles', (new WinnerIdentityProfile)->getTable());
        self::assertSame('winner_identity_documents', (new WinnerIdentityDocument)->getTable());
        self::assertSame('winner_claim_events', (new WinnerClaimEvent)->getTable());
        self::assertSame([
            'pending_claim',
            'identity_pending',
            'verified',
            'rejected',
            'expired',
        ], array_column(WinnerClaimStatus::cases(), 'value'));
        self::assertSame([
            'claim_created',
            'claim_submitted',
            'identity_verified',
            'identity_rejected',
            'claim_expired',
            'legacy_claim_initialized',
        ], array_column(WinnerClaimEventType::cases(), 'value'));
        self::assertSame(WinnerClaim::class, (new GameWinner)->claim()->getRelated()::class);
    }

    #[Test]
    public function encrypted_identity_and_append_only_contracts_are_present(): void
    {
        self::assertSame('encrypted', (new WinnerIdentityProfile)->getCasts()['legal_name_encrypted']);
        self::assertSame('encrypted', (new WinnerIdentityProfile)->getCasts()['document_number_encrypted']);

        foreach ([WinnerIdentityDocument::class, WinnerClaimEvent::class] as $modelClass) {
            $source = (string) file_get_contents((new \ReflectionClass($modelClass))->getFileName());
            self::assertStringContainsString('UPDATED_AT = null', $source);
            self::assertStringContainsString('ImmutableModelException', $source);
        }
    }

    #[Test]
    public function winner_declaration_creates_claim_and_scheduler_expires_it(): void
    {
        $drawSource = (string) file_get_contents(base_path(
            'app/Modules/RepeatNumberBingo/Application/Actions/DrawGameNumberAction.php'
        ));
        $scheduleSource = (string) file_get_contents(base_path('routes/console.php'));

        self::assertStringContainsString('CreateWinnerClaimAction', $drawSource);
        self::assertStringContainsString('executeWithinTransaction($winner->id', $drawSource);
        self::assertStringContainsString('ExpireWinnerClaimsJob', $scheduleSource);
        self::assertSame('30', (string) config('winner_claim.ttl_days'));
    }

    #[Test]
    public function claim_routes_are_owned_and_no_public_claim_route_exists(): void
    {
        /** @var Router $router */
        $router = app('router');
        $claimRoutes = collect($router->getRoutes()->getRoutes())
            ->filter(static fn ($route): bool => str_contains($route->uri(), 'winner-claims')
                || str_contains($route->uri(), 'me/winnings'));

        self::assertCount(8, $claimRoutes);

        foreach ($claimRoutes as $route) {
            self::assertTrue(
                in_array('auth:sanctum', $route->middleware(), true),
                "Claim route {$route->uri()} must require Sanctum.",
            );
        }

        self::assertSame(1, $claimRoutes->filter(static fn ($route): bool => $route->uri() === 'api/v1/me/winnings/{winner}/claim')->count());
        self::assertSame(0, $claimRoutes->filter(static fn ($route): bool => str_contains($route->uri(), 'public'))->count());
    }

    #[Test]
    public function claim_module_does_not_add_payout_banking_kyc_outbox_or_notification_contracts(): void
    {
        $files = $this->winnerClaimSourceFiles();
        $source = mb_strtolower(implode("\n", array_map(
            static fn (string $file): string => (string) file_get_contents($file),
            $files,
        )));

        foreach (['winnerpayout', 'winner_payout', 'bank_account', 'cci', 'yape', 'plin', 'reniec', 'kyc', 'outbox', 'notification'] as $forbidden) {
            self::assertStringNotContainsString($forbidden, $source);
        }
    }

    /** @return list<string> */
    private function winnerClaimSourceFiles(): array
    {
        $files = [base_path('config/winner_claim.php')];

        foreach ([
            base_path('app/Modules/RepeatNumberBingo'),
            base_path('app/Modules/Commerce'),
        ] as $directory) {
            if (! is_dir($directory)) {
                continue;
            }

            $iterator = new \RecursiveIteratorIterator(
                new \RecursiveDirectoryIterator($directory, \FilesystemIterator::SKIP_DOTS),
            );

            foreach ($iterator as $file) {
                if (! $file->isFile() || $file->getExtension() !== 'php') {
                    continue;
                }

                if (preg_match('/Winner(?:Claim|Identity).*\.php$/', $file->getFilename()) === 1) {
                    $files[] = $file->getPathname();
                }
            }
        }

        return $files;
    }

    #[Test]
    public function resources_do_not_expose_encrypted_values_or_storage_paths(): void
    {
        $playerResource = (string) file_get_contents(base_path(
            'app/Modules/RepeatNumberBingo/Presentation/Http/Resources/Player/PlayerWinnerClaimResource.php'
        ));
        $adminResource = (string) file_get_contents(base_path(
            'app/Modules/RepeatNumberBingo/Presentation/Http/Resources/Admin/AdminWinnerClaimSensitiveResource.php'
        ));

        foreach (['legal_name_encrypted', 'document_number_encrypted', "'path'", "'disk'", "'sha256'"] as $forbidden) {
            self::assertStringNotContainsString($forbidden, $playerResource);
        }
        self::assertStringNotContainsString("'path'", $adminResource);
        self::assertStringNotContainsString("'disk'", $adminResource);
        self::assertStringNotContainsString("'sha256'", $adminResource);
    }

    #[Test]
    public function payout_and_funding_models_remain_separate(): void
    {
        self::assertNotSame(
            (new WinnerClaim)->getTable(),
            (new WinnerPayout)->getTable(),
        );
        self::assertNotSame(
            (new WinnerClaim)->getTable(),
            (new GamePrizeFunding)->getTable(),
        );
    }
}
