<?php

declare(strict_types=1);

namespace Tests\Unit\Architecture;

use App\Modules\RepeatNumberBingo\Application\Actions\CancelGameAction;
use App\Modules\RepeatNumberBingo\Application\Actions\CreateGameAction;
use App\Modules\RepeatNumberBingo\Application\Actions\PublishGameAction;
use App\Modules\RepeatNumberBingo\Application\Actions\RecordGamePrizeFundingAction;
use App\Modules\RepeatNumberBingo\Application\Actions\ReleaseGamePrizeFundingAction;
use App\Modules\RepeatNumberBingo\Application\Actions\ReserveGamePrizeFundingAction;
use App\Modules\RepeatNumberBingo\Application\Actions\StartGameAction;
use App\Modules\RepeatNumberBingo\Domain\Models\GamePrizeFunding;
use App\Modules\RepeatNumberBingo\Domain\Models\GamePrizeFundingDocument;
use App\Modules\RepeatNumberBingo\Domain\Models\GamePrizeFundingEvent;
use App\Modules\Shared\Infrastructure\Outbox\OutboxEventDispatcher;
use Illuminate\Support\Facades\Route;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

final class Phase112APrizeFundingArchitectureTest extends TestCase
{
    #[Test]
    public function funding_aggregate_and_append_only_records_exist(): void
    {
        foreach ([
            GamePrizeFunding::class,
            GamePrizeFundingDocument::class,
            GamePrizeFundingEvent::class,
        ] as $class) {
            self::assertTrue(class_exists($class));
        }

        self::assertSame('game_prize_fundings', (new GamePrizeFunding)->getTable());
        self::assertSame('game_prize_funding_documents', (new GamePrizeFundingDocument)->getTable());
        self::assertSame('game_prize_funding_events', (new GamePrizeFundingEvent)->getTable());
        self::assertNull(GamePrizeFundingDocument::UPDATED_AT);
        self::assertNull(GamePrizeFundingEvent::UPDATED_AT);
    }

    #[Test]
    public function lifecycle_actions_are_explicitly_integrated(): void
    {
        self::assertStringContainsString('GamePrizeFunding', $this->source(CreateGameAction::class));
        self::assertStringContainsString('GamePrizeFunding', $this->source(PublishGameAction::class));
        self::assertStringContainsString('ReserveGamePrizeFundingAction', $this->source(StartGameAction::class));
        self::assertStringContainsString('ReleaseGamePrizeFundingAction', $this->source(CancelGameAction::class));

        foreach ([
            ReserveGamePrizeFundingAction::class,
            ReleaseGamePrizeFundingAction::class,
            RecordGamePrizeFundingAction::class,
        ] as $class) {
            self::assertTrue(class_exists($class));
        }
    }

    #[Test]
    public function funding_migrations_have_the_separate_schema_and_no_claim_schema(): void
    {
        $migrationFiles = glob(base_path('database/migrations/*game_prize_funding*.php'));

        self::assertCount(3, $migrationFiles);

        $source = implode("\n", array_map(
            static fn (string $path): string => (string) file_get_contents($path),
            $migrationFiles,
        ));

        self::assertStringContainsString("unique('game_id')", $source);
        self::assertStringContainsString('amount_cents > 0', $source);
        self::assertStringContainsString('legacy_unverified', $source);
        self::assertStringContainsString('safe_metadata', $source);
        self::assertStringNotContainsString('winner_claim', $source);
        self::assertStringNotContainsString('bank_account', $source);
        self::assertStringNotContainsString('yape', mb_strtolower($source));
        self::assertStringNotContainsString('plin', mb_strtolower($source));
    }

    #[Test]
    public function funding_documents_use_private_storage_and_safe_resource_fields(): void
    {
        $filesystem = file_get_contents(base_path('config/filesystems.php'));
        $commerce = file_get_contents(base_path('config/commerce.php'));
        $resource = file_get_contents(base_path(
            'app/Modules/RepeatNumberBingo/Presentation/Http/Resources/Admin/AdminGamePrizeFundingResource.php'
        ));

        self::assertNotFalse($filesystem);
        self::assertNotFalse($commerce);
        self::assertNotFalse($resource);
        self::assertStringContainsString("'visibility' => 'private'", $filesystem);
        self::assertStringContainsString('COMMERCE_PRIZE_FUNDING_MAX_SIZE_KB', $commerce);
        self::assertStringNotContainsString("'path'", $resource);
        self::assertStringNotContainsString("'sha256'", $resource);
        self::assertStringNotContainsString('WinnerPayout', $resource);
    }

    #[Test]
    public function only_admin_read_and_write_routes_exist_for_funding(): void
    {
        $fundingRoutes = [];

        foreach (Route::getRoutes() as $route) {
            if (str_contains($route->uri(), 'prize-funding')) {
                $fundingRoutes[] = $route;
            }
        }

        self::assertCount(2, $fundingRoutes);
        self::assertSame(['GET', 'HEAD'], $fundingRoutes[0]->methods());
        self::assertSame(['POST'], $fundingRoutes[1]->methods());

        foreach (Route::getRoutes() as $route) {
            if (str_contains($route->uri(), 'prize-funding')) {
                self::assertStringContainsString('/admin/', '/'.$route->uri());
            }
        }
    }

    #[Test]
    public function funding_does_not_add_outbox_notifications_or_external_payouts(): void
    {
        $fundingSource = implode("\n", $this->phpFiles(base_path('app/Modules/RepeatNumberBingo')));
        $dispatcherSource = file_get_contents((new \ReflectionClass(OutboxEventDispatcher::class))->getFileName());
        $payoutSource = file_get_contents(base_path(
            'app/Modules/Commerce/Application/Actions/ProcessWinnerPayoutAction.php'
        ));

        self::assertNotFalse($dispatcherSource);
        self::assertNotFalse($payoutSource);

        foreach (['Notification::', 'OutboxEvent', 'PayoutProvider', 'Yape', 'Plin'] as $forbiddenTerm) {
            self::assertStringNotContainsString($forbiddenTerm, $fundingSource);
        }

        self::assertStringNotContainsString('GamePrizeFunding', $payoutSource);
        self::assertStringContainsString('winner_payout_registered', $dispatcherSource);
        self::assertStringNotContainsString('winner_claim_submitted', $dispatcherSource);
    }

    #[Test]
    public function phase_11_documentation_describes_11_2b_and_does_not_promise_exactly_once(): void
    {
        $documentation = file_get_contents(base_path('docs/phase-11.md'));

        self::assertNotFalse($documentation);
        self::assertStringContainsString('Fase 11.2A', $documentation);
        self::assertStringContainsString('Fase 11.2B', $documentation);
        self::assertStringContainsString('legacy_unverified', $documentation);
        self::assertStringContainsString('No se afirma exactly-once', $documentation);
        self::assertStringContainsString('Alcance implementado de 11.2B', $documentation);
    }

    private function source(string $class): string
    {
        $file = (new \ReflectionClass($class))->getFileName();

        self::assertNotFalse($file);

        $source = file_get_contents($file);

        self::assertNotFalse($source);

        return $source;
    }

    /** @return list<string> */
    private function phpFiles(string $directory): array
    {
        $files = [];
        $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($directory));

        foreach ($iterator as $file) {
            if ($file->isFile() && $file->getExtension() === 'php') {
                $files[] = $file->getPathname();
            }
        }

        return $files;
    }
}
