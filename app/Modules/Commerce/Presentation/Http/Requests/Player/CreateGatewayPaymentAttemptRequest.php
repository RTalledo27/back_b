<?php

declare(strict_types=1);

namespace App\Modules\Commerce\Presentation\Http\Requests\Player;

use App\Modules\Commerce\Application\Gateway\GatewayPaymentAttemptRequest;
use App\Modules\Commerce\Domain\Models\Order;
use App\Modules\Commerce\Domain\Models\Payment;
use Illuminate\Foundation\Http\FormRequest;

final class CreateGatewayPaymentAttemptRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'provider' => [
                'required',
                'string',
                'max:60',
            ],
        ];
    }

    public function toGatewayRequest(Order $order, Payment $payment): GatewayPaymentAttemptRequest
    {
        $provider = (string) $this->validated('provider');

        return new GatewayPaymentAttemptRequest(
            userId: (int) $this->user()->getAuthIdentifier(),
            orderId: $order->getKey(),
            paymentId: $payment->getKey(),
            provider: $provider,
            idempotencyKeyHash: hash('sha256', (string) $this->header('Idempotency-Key')),
            requestFingerprint: hash('sha256', implode('|', [
                'gateway-attempt-v1',
                $order->getKey(),
                $payment->getKey(),
                $provider,
            ])),
        );
    }
}
