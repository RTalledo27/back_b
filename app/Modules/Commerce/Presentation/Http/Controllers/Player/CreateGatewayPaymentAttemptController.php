<?php

declare(strict_types=1);

namespace App\Modules\Commerce\Presentation\Http\Controllers\Player;

use App\Modules\Commerce\Application\Gateway\Actions\CreateGatewayPaymentAttemptAction;
use App\Modules\Commerce\Domain\Models\Order;
use App\Modules\Commerce\Presentation\Http\Exceptions\GatewayHttpException;
use App\Modules\Commerce\Presentation\Http\Requests\Player\CreateGatewayPaymentAttemptRequest;
use App\Modules\Commerce\Presentation\Http\Resources\Player\GatewayPaymentAttemptResource;
use Illuminate\Support\Facades\Gate;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Throwable;

final class CreateGatewayPaymentAttemptController
{
    public function __invoke(
        CreateGatewayPaymentAttemptRequest $request,
        Order $order,
        CreateGatewayPaymentAttemptAction $action,
    ): Response {
        if (! Gate::allows('createGatewayAttempt', $order)) {
            throw new NotFoundHttpException('Order not found.');
        }

        $order->loadMissing('payment');

        if ($order->payment === null) {
            throw new NotFoundHttpException('Order not found.');
        }

        try {
            $result = $action->execute($request->toGatewayRequest($order, $order->payment));
        } catch (Throwable $exception) {
            throw GatewayHttpException::from($exception);
        }

        return (new GatewayPaymentAttemptResource($result))
            ->response()
            ->setStatusCode(Response::HTTP_CREATED);
    }
}
