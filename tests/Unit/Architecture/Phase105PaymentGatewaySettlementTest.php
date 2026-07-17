<?php

declare(strict_types=1);

namespace Tests\Unit\Architecture;

use PHPUnit\Framework\TestCase;

final class Phase105PaymentGatewaySettlementTest extends TestCase
{
    private const APP = __DIR__.'/../../../app';

    private const SETTLEMENT_ACTION = self::APP.'/Modules/Commerce/Application/Gateway/Actions/SettleGatewayPaidTransactionAction.php';

    private const TRANSITION_ACTION = self::APP.'/Modules/Commerce/Application/Actions/ApplyApprovedPaymentTransitionAction.php';

    private const DOCS = __DIR__.'/../../../docs/phase-10.md';

    public function test_settlement_action_is_internal_and_validates_paid_states(): void
    {
        $source = $this->read(self::SETTLEMENT_ACTION);

        $this->assertStringContainsString('paid', $source);
        $this->assertStringContainsString('captured', $source);
        $this->assertStringContainsString('applied_at', $source);
        $this->assertStringContainsString('lockForUpdate', $source);
        $this->assertStringNotContainsString('Http::', $source);
        $this->assertStringNotContainsString('ApprovePaymentAction', $source);
        $this->assertStringNotContainsString('Notification::', $source);
        $this->assertStringNotContainsString('Mail::', $source);
        $this->assertStringNotContainsString('recordOutbox', $source);
    }

    public function test_commercial_transition_has_the_single_outbox_path_and_canonical_locks(): void
    {
        $source = $this->read(self::TRANSITION_ACTION);

        foreach ([
            'Game::query()',
            'Order::query()',
            'Payment::query()',
            'OrderItem::query()',
            'NumberReservation::query()',
            'GameNumber::query()',
        ] as $lockSource) {
            $this->assertStringContainsString($lockSource, $source);
        }

        $this->assertStringContainsString('RecordOutboxEventAction', $source);
        $this->assertStringContainsString("eventType: 'payment_approved'", $source);
        $this->assertStringNotContainsString('notify(', $source);
        $this->assertStringNotContainsString('Mail::', $source);
        $this->assertStringNotContainsString('Notification::', $source);
    }

    public function test_no_public_gateway_surface_or_real_provider_is_present(): void
    {
        $routes = $this->read(__DIR__.'/../../../routes/api.php');
        $providerDirectory = self::APP.'/Modules/Commerce/Infrastructure/Gateway';

        $this->assertDoesNotMatchRegularExpression('/(checkout|webhooks\/payments\/(?:stripe|culqi|niubiz)|gateway\/webhooks)/i', $routes);
        $this->assertDirectoryExists($providerDirectory);
        $this->assertFileDoesNotExist($providerDirectory.'/CulqiPaymentGatewayProvider.php');
        $this->assertFileDoesNotExist($providerDirectory.'/NiubizPaymentGatewayProvider.php');
        $this->assertFileDoesNotExist($providerDirectory.'/StripePaymentGatewayProvider.php');
    }

    public function test_phase_10_documentation_closes_the_paid_path_without_exactly_once_claims(): void
    {
        $docs = $this->read(self::DOCS);

        foreach (['Fase 10.5', 'applied_at', 'payment_approved', 'Game -> Order -> Payment', 'Fase 10.6'] as $term) {
            $this->assertStringContainsString($term, $docs);
        }

        $this->assertStringContainsString('no se afirma exactly-once', strtolower($docs));
        $this->assertStringContainsString('no implementa', strtolower($docs));
    }

    private function read(string $path): string
    {
        $this->assertFileExists($path);

        return (string) file_get_contents($path);
    }
}
