<?php

declare(strict_types=1);

namespace App\Modules\Commerce\Presentation\Http\Controllers\Player;

use App\Modules\Commerce\Application\Gateway\GatewayPaymentAttemptResponse;
use App\Modules\Commerce\Domain\Models\Order;
use App\Modules\Commerce\Infrastructure\Gateway\Models\PaymentGatewayAttempt;
use App\Modules\Commerce\Presentation\Http\Resources\Player\GatewayPaymentAttemptResource;
use Illuminate\Support\Facades\Gate;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

final class ShowGatewayPaymentAttemptController
{
    public function __invoke(Order $order, string $attempt): GatewayPaymentAttemptResource
    {
        if (! Gate::allows('viewGatewayAttempt', $order)) {
            throw new NotFoundHttpException('Order not found.');
        }

        $order->loadMissing('payment');

        $gatewayAttempt = PaymentGatewayAttempt::query()
            ->whereKey($attempt)
            ->where('order_id', $order->getKey())
            ->where('payment_id', $order->payment?->getKey())
            ->first();

        if ($gatewayAttempt === null) {
            throw new NotFoundHttpException('Gateway attempt not found.');
        }

        return new GatewayPaymentAttemptResource(GatewayPaymentAttemptResponse::fromModel($gatewayAttempt));
    }
}
