<?php

declare(strict_types=1);

namespace App\Modules\Commerce\Presentation\Http\Resources\Player;

use App\Modules\Commerce\Application\Gateway\GatewayPaymentAttemptResponse;
use App\Modules\Commerce\Infrastructure\Gateway\Models\PaymentGatewayAttempt;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

final class GatewayPaymentAttemptResource extends JsonResource
{
    public static $wrap = 'data';

    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        if ($this->resource instanceof GatewayPaymentAttemptResponse) {
            return [
                'id' => $this->resource->attemptId,
                'provider' => $this->resource->provider,
                'status' => $this->resource->status->value,
                'amount_cents' => $this->resource->amountCents,
                'currency' => $this->resource->currency,
                'checkout_url' => $this->resource->checkoutUrl,
                'expires_at' => $this->resource->expiresAt?->toIso8601String(),
            ];
        }

        /** @var PaymentGatewayAttempt $attempt */
        $attempt = $this->resource;

        return [
            'id' => $attempt->id,
            'provider' => $attempt->provider,
            'status' => $attempt->status,
            'amount_cents' => $attempt->amount_cents,
            'currency' => $attempt->currency,
            'checkout_url' => $attempt->checkout_url,
            'expires_at' => $attempt->expires_at?->toIso8601String(),
        ];
    }
}
