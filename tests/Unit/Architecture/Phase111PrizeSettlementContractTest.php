<?php

declare(strict_types=1);

namespace Tests\Unit\Architecture;

use App\Modules\Shared\Infrastructure\Outbox\OutboxEventDispatcher;
use Illuminate\Support\Facades\Route;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

final class Phase111PrizeSettlementContractTest extends TestCase
{
    private const DOCUMENTATION = __DIR__.'/../../../docs/phase-11.md';

    #[Test]
    public function phase_11_documentation_exists_and_declares_the_public_contract(): void
    {
        $documentation = $this->documentation();

        foreach ([
            'Fase 11 — Prize settlement, payout audit y transparencia',
            'registered != approved',
            'approved != executed',
            'executed != received',
            'paid != confirmed_by_winner',
            'created_by != approved_by',
            'winner_payout_events',
            'financially_closed',
            'game_draws',
            'integridad del historial',
            'Imparcialidad',
            'server_seed_commitment',
            'No se afirma exactly-once',
        ] as $requiredTerm) {
            self::assertStringContainsString($requiredTerm, $documentation);
        }
    }

    #[Test]
    public function future_phase_eleven_routes_are_not_registered(): void
    {
        $futureFragments = [
            '/audit',
            '/winnings',
            '/winner-payouts',
            '/claim',
            '/confirm-receipt',
            '/dispute',
            '/mark-processing',
            '/mark-paid',
            '/reconcile',
            '/resolve-dispute',
        ];

        foreach (Route::getRoutes() as $route) {
            $uri = '/'.$route->uri();

            foreach ($futureFragments as $fragment) {
                self::assertStringNotContainsString($fragment, $uri);
            }
        }
    }

    #[Test]
    public function phase_eleven_does_not_add_persistence_or_migration_contracts(): void
    {
        $migrationSource = implode("\n", $this->phpFiles([base_path('database/migrations')]));

        foreach ([
            'prize_fund',
            'winner_claim',
            'winner_payout_events',
            'payout_receipt',
        ] as $forbiddenTerm) {
            self::assertStringNotContainsString($forbiddenTerm, $migrationSource);
        }
    }

    #[Test]
    public function outbox_dispatcher_keeps_the_existing_five_event_types(): void
    {
        $source = file_get_contents((new \ReflectionClass(OutboxEventDispatcher::class))->getFileName());

        self::assertNotFalse($source);

        preg_match_all("/'([a-z][a-z0-9_]*)'\\s*=>/", $source, $matches);

        self::assertSame([
            'payment_approved',
            'payment_rejected',
            'order_refunded',
            'winner_payout_registered',
            'game_winner_declared',
        ], $matches[1]);

        foreach ([
            'winner_claim_submitted',
            'winner_payout_paid',
            'game_financially_closed',
        ] as $forbiddenEventType) {
            self::assertStringNotContainsString($forbiddenEventType, $source);
        }
    }

    #[Test]
    public function no_phase_eleven_payout_provider_or_outgoing_gateway_exists(): void
    {
        $source = implode("\n", $this->phpFiles([
            base_path('app/Modules/Commerce/Application'),
            base_path('app/Modules/Commerce/Infrastructure'),
        ]));

        foreach ([
            'PayoutProvider',
            'GuzzleHttp',
            'curl_exec',
            'stream_socket_client',
            'Http::',
        ] as $forbiddenTerm) {
            self::assertStringNotContainsString($forbiddenTerm, $source);
        }
    }

    #[Test]
    public function game_engine_has_no_phase_eleven_settlement_contract(): void
    {
        $source = implode("\n", $this->phpFiles([
            base_path('app/Modules/RepeatNumberBingo'),
        ]));

        foreach ([
            'prize_funded',
            'pending_claim',
            'winner_payout_events',
            'financially_closed',
            'server_seed_commitment',
        ] as $forbiddenTerm) {
            self::assertStringNotContainsString($forbiddenTerm, $source);
        }
    }

    #[Test]
    public function documentation_defines_private_evidence_and_public_receipt_boundaries(): void
    {
        $documentation = $this->documentation();

        foreach ([
            'storage privado',
            'hash SHA-256',
            'proof_digest',
            'winner_alias',
            'No debe permitir reconstruir cuentas',
            'no publicar nombre completo',
            'DNI',
            'CCI',
            'comprobante completo',
        ] as $requiredTerm) {
            self::assertStringContainsString($requiredTerm, $documentation);
        }
    }

    #[Test]
    public function documentation_defines_dual_control_reconciliation_and_disputes(): void
    {
        $documentation = $this->documentation();

        foreach ([
            'maker/admin',
            'checker/admin diferente',
            'created_by != approved_by',
            'winner-payout.approve',
            'payout_reconciled',
            'winner_dispute_opened',
            'winner_dispute_resolved',
            'discrepancias',
            'financially_closed',
            'No se implementan estos permisos',
        ] as $requiredTerm) {
            self::assertStringContainsString($requiredTerm, $documentation);
        }
    }

    #[Test]
    public function documentation_explicitly_keeps_automatic_payout_and_commit_reveal_future(): void
    {
        $documentation = $this->documentation();

        self::assertStringContainsString('No agrega lógica productiva', $documentation);
        self::assertStringContainsString('No existe payout automático implementado', $documentation);
        self::assertStringContainsString('No existe seed pública ni commit-reveal implementado', $documentation);
        self::assertStringContainsString('No se afirma exactly-once', $documentation);
    }

    private function documentation(): string
    {
        self::assertFileExists(self::DOCUMENTATION);

        $documentation = file_get_contents(self::DOCUMENTATION);

        self::assertNotFalse($documentation);

        return $documentation;
    }

    /**
     * @param  array<int, string>  $directories
     * @return array<int, string>
     */
    private function phpFiles(array $directories): array
    {
        $files = [];

        foreach ($directories as $directory) {
            if (! is_dir($directory)) {
                continue;
            }

            $iterator = new \RecursiveIteratorIterator(
                new \RecursiveDirectoryIterator($directory)
            );

            foreach ($iterator as $file) {
                if ($file->isFile() && $file->getExtension() === 'php') {
                    $files[] = $file->getPathname();
                }
            }
        }

        sort($files);

        return $files;
    }
}
