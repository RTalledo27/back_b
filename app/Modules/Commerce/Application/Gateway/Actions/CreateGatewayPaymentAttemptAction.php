<?php

declare(strict_types=1);

namespace App\Modules\Commerce\Application\Gateway\Actions;

use App\Modules\Commerce\Application\Gateway\GatewayPaymentAttemptRequest;
use App\Modules\Commerce\Application\Gateway\GatewayPaymentAttemptResponse;
use App\Modules\Commerce\Application\Gateway\GatewayPaymentNotPayableException;
use App\Modules\Commerce\Application\Gateway\PaymentGatewayAttemptData;
use App\Modules\Commerce\Application\Gateway\PaymentGatewayCreateAttemptData;
use App\Modules\Commerce\Application\Gateway\PaymentGatewayException;
use App\Modules\Commerce\Application\Gateway\PaymentGatewayProviderRegistry;
use App\Modules\Commerce\Application\Gateway\RecordPaymentGatewayAttemptAction;
use App\Modules\Commerce\Domain\Enums\OrderStatus;
use App\Modules\Commerce\Domain\Enums\PaymentStatus;
use App\Modules\Commerce\Domain\Models\Order;
use App\Modules\Commerce\Domain\Models\Payment;
use App\Modules\Commerce\Infrastructure\Gateway\Models\PaymentGatewayAttempt;
use Illuminate\Support\Facades\DB;

final class CreateGatewayPaymentAttemptAction
{
    public function __construct(
        private readonly PaymentGatewayProviderRegistry $providers,
        private readonly RecordPaymentGatewayAttemptAction $recordAttempt,
    ) {}

    public function execute(GatewayPaymentAttemptRequest $request): GatewayPaymentAttemptResponse
    {
        return DB::transaction(function () use ($request): GatewayPaymentAttemptResponse {
            /** @var Order $order */
            $order = Order::query()
                ->whereKey($request->orderId)
                ->lockForUpdate()
                ->firstOrFail();

            /** @var Payment $payment */
            $payment = Payment::query()
                ->whereKey($request->paymentId)
                ->lockForUpdate()
                ->firstOrFail();

            $environment = (string) config('payment_gateways.environment', 'sandbox');
            $existing = PaymentGatewayAttempt::query()
                ->where('idempotency_key_hash', $request->idempotencyKeyHash)
                ->lockForUpdate()
                ->first();

            if ($existing !== null) {
                $this->assertReplayMatches($existing, $request, $order, $payment, $environment);

                return GatewayPaymentAttemptResponse::fromModel($existing);
            }

            $provider = $this->providers->get($request->provider);

            $this->assertPayable($request, $order, $payment);

            $providerResult = $provider->createAttempt(new PaymentGatewayCreateAttemptData(
                orderId: $order->id,
                paymentId: $payment->id,
                amountCents: $payment->amount_cents,
                currency: $payment->currency,
                idempotencyKeyHash: $request->idempotencyKeyHash,
                requestFingerprint: $request->requestFingerprint,
                expiresAt: $request->expiresAt,
            ));

            $attempt = $this->recordAttempt->execute(new PaymentGatewayAttemptData(
                orderId: $order->id,
                paymentId: $payment->id,
                amountCents: $providerResult->amountCents,
                currency: $providerResult->currency,
                provider: $providerResult->provider,
                environment: $environment,
                idempotencyKeyHash: $request->idempotencyKeyHash,
                requestFingerprint: $request->requestFingerprint,
                status: $providerResult->status->value,
                providerAttemptId: $providerResult->providerAttemptId,
                checkoutUrl: $providerResult->checkoutUrl,
                expiresAt: $providerResult->expiresAt,
            ));

            return GatewayPaymentAttemptResponse::fromModel($attempt);
        });
    }

    private function assertPayable(
        GatewayPaymentAttemptRequest $request,
        Order $order,
        Payment $payment,
    ): void {
        if (
            $order->user_id !== $request->userId
            || $payment->order_id !== $order->id
            || $order->status !== OrderStatus::Pending
            || $payment->status !== PaymentStatus::Pending
            || ($order->expires_at !== null && $order->expires_at->isPast())
        ) {
            throw new GatewayPaymentNotPayableException;
        }
    }

    private function assertReplayMatches(
        PaymentGatewayAttempt $existing,
        GatewayPaymentAttemptRequest $request,
        Order $order,
        Payment $payment,
        string $environment,
    ): void {
        $sameExpiry = $existing->expires_at === null
            ? $request->expiresAt === null
            : $request->expiresAt !== null && $existing->expires_at->equalTo($request->expiresAt);

        if (
            ! hash_equals($existing->request_fingerprint, $request->requestFingerprint)
            || $existing->order_id !== $order->id
            || $existing->payment_id !== $payment->id
            || $existing->provider !== $request->provider
            || $existing->environment !== $environment
            || $existing->amount_cents !== $payment->amount_cents
            || $existing->currency !== strtoupper($payment->currency)
            || ! $sameExpiry
        ) {
            throw PaymentGatewayException::idempotencyConflict();
        }
    }
}
