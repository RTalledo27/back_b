<?php

declare(strict_types=1);

namespace App\Modules\Commerce\Application\Gateway\Actions;

use App\Modules\Commerce\Application\Actions\ApplyApprovedPaymentTransitionAction;
use App\Modules\Commerce\Application\DTOs\ApplyApprovedPaymentTransitionData;
use App\Modules\Commerce\Application\Gateway\GatewayPaymentSettlementRequest;
use App\Modules\Commerce\Application\Gateway\GatewayPaymentSettlementResponse;
use App\Modules\Commerce\Application\Gateway\PaymentGatewayException;
use App\Modules\Commerce\Domain\Models\Order;
use App\Modules\Commerce\Domain\Models\Payment;
use App\Modules\Commerce\Infrastructure\Gateway\Models\PaymentGatewayAttempt;
use App\Modules\Commerce\Infrastructure\Gateway\Models\PaymentGatewayTransaction;
use App\Modules\RepeatNumberBingo\Domain\Models\Game;
use Illuminate\Support\Facades\DB;
use LogicException;

final class SettleGatewayPaidTransactionAction
{
    public function __construct(private readonly ApplyApprovedPaymentTransitionAction $transition) {}

    public function execute(GatewayPaymentSettlementRequest $request): GatewayPaymentSettlementResponse
    {
        return DB::transaction(
            fn (): GatewayPaymentSettlementResponse => $this->executeWithinTransaction($request),
        );
    }

    public function executeWithinTransaction(
        GatewayPaymentSettlementRequest $request,
    ): GatewayPaymentSettlementResponse {
        if (DB::transactionLevel() === 0) {
            throw new LogicException(
                'SettleGatewayPaidTransactionAction::executeWithinTransaction requires an active database transaction.'
            );
        }

        /** @var PaymentGatewayTransaction $transactionSnapshot */
        $transactionSnapshot = PaymentGatewayTransaction::query()
            ->whereKey($request->transactionId)
            ->firstOrFail();

        /** @var PaymentGatewayAttempt $attemptSnapshot */
        $attemptSnapshot = PaymentGatewayAttempt::query()
            ->whereKey($transactionSnapshot->payment_gateway_attempt_id)
            ->firstOrFail();

        /** @var Payment $paymentSnapshot */
        $paymentSnapshot = Payment::query()
            ->whereKey($transactionSnapshot->payment_id)
            ->firstOrFail();

        /** @var Order $orderSnapshot */
        $orderSnapshot = Order::query()
            ->whereKey($paymentSnapshot->order_id)
            ->firstOrFail();

        /** @var Game $gameSnapshot */
        $gameSnapshot = Game::query()
            ->whereKey($orderSnapshot->game_id)
            ->firstOrFail();

        $this->assertGatewayTransaction(
            $request,
            $transactionSnapshot,
            $attemptSnapshot,
            $paymentSnapshot,
            $orderSnapshot,
            $gameSnapshot,
        );

        // ApplyApprovedPaymentTransitionAction acquires the canonical
        // commerce lock order: Game -> Order -> Payment -> items ->
        // reservations -> numbers. The gateway rows are immutable during
        // this transition and are locked after the business chain below.
        $transitionResult = $this->transition->executeWithinTransaction(
            new ApplyApprovedPaymentTransitionData(
                paymentId: $paymentSnapshot->id,
                origin: 'gateway',
            ),
        );

        /** @var PaymentGatewayAttempt $attempt */
        $attempt = PaymentGatewayAttempt::query()
            ->whereKey($transactionSnapshot->payment_gateway_attempt_id)
            ->lockForUpdate()
            ->firstOrFail();

        /** @var PaymentGatewayTransaction $transaction */
        $transaction = PaymentGatewayTransaction::query()
            ->whereKey($request->transactionId)
            ->lockForUpdate()
            ->firstOrFail();

        if ($transitionResult->wasTransitionApplied) {
            $otherAppliedTransaction = PaymentGatewayTransaction::query()
                ->where('payment_id', $transaction->payment_id)
                ->where('id', '<>', $transaction->id)
                ->whereNotNull('applied_at')
                ->lockForUpdate()
                ->first();

            if ($otherAppliedTransaction !== null) {
                throw PaymentGatewayException::settlementConflict();
            }

            $transaction->applied_at = now();
            $transaction->save();
        } elseif ($transaction->applied_at === null) {
            throw PaymentGatewayException::settlementConflict();
        }

        return GatewayPaymentSettlementResponse::fromTransition(
            transactionId: $transaction->id,
            result: $transitionResult,
        );
    }

    private function assertGatewayTransaction(
        GatewayPaymentSettlementRequest $request,
        PaymentGatewayTransaction $transaction,
        PaymentGatewayAttempt $attempt,
        Payment $payment,
        Order $order,
        Game $game,
    ): void {
        $expectedEnvironment = (string) config('payment_gateways.environment', 'sandbox');
        $currency = strtoupper($payment->currency);

        if (
            $transaction->provider !== $request->provider
            || $attempt->provider !== $request->provider
            || $attempt->environment !== $expectedEnvironment
            || $transaction->payment_id !== $attempt->payment_id
            || $attempt->payment_id !== $payment->id
            || $attempt->order_id !== $order->id
            || $order->game_id !== $game->id
        ) {
            throw PaymentGatewayException::settlementConflict();
        }

        if (! in_array($transaction->status, ['paid', 'captured'], true)) {
            throw PaymentGatewayException::settlementNotApplicable();
        }

        if (
            $transaction->amount_cents !== $payment->amount_cents
            || $attempt->amount_cents !== $payment->amount_cents
            || $transaction->amount_cents !== $attempt->amount_cents
            || $transaction->currency !== $currency
            || $attempt->currency !== $currency
        ) {
            throw PaymentGatewayException::settlementConflict();
        }
    }
}
