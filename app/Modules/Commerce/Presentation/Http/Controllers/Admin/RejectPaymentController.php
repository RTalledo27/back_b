<?php

declare(strict_types=1);

namespace App\Modules\Commerce\Presentation\Http\Controllers\Admin;

use App\Modules\Commerce\Application\Actions\RejectPaymentAction;
use App\Modules\Commerce\Application\DTOs\RejectPaymentData;
use App\Modules\Commerce\Application\DTOs\RejectPaymentResult;
use App\Modules\Commerce\Application\Support\IdempotencyContext;
use App\Modules\Commerce\Domain\Events\PaymentRejected;
use App\Modules\Commerce\Domain\Models\Payment;
use App\Modules\Commerce\Infrastructure\Idempotency\IdempotentCommandExecutor;
use App\Modules\Commerce\Presentation\Http\Requests\Admin\RejectPaymentRequest;
use App\Modules\Commerce\Presentation\Http\Resources\PaymentRejectedResource;
use Symfony\Component\HttpFoundation\Response;

final class RejectPaymentController
{
    public function __invoke(
        RejectPaymentRequest $request,
        Payment $payment,
        RejectPaymentAction $action,
        IdempotentCommandExecutor $executor,
    ): Response {
        $user = $request->user();

        $data = new RejectPaymentData(
            paymentId: $payment->getKey(),
            reviewerUserId: (int) $user?->getKey(),
            reason: $request->reason(),
        );

        $context = IdempotencyContext::make(
            userId: (int) $user?->getKey(),
            method: $request->method(),
            path: $request->path(),
            key: (string) $request->header('Idempotency-Key'),
            payloadComponents: [
                'payment_id' => $payment->getKey(),
                'reason' => $data->reason,
            ],
        );

        /** @var RejectPaymentResult $result */
        $result = $executor->execute(
            context: $context,
            command: fn (): RejectPaymentResult => $action->executeWithinTransaction($data),
            hydrate: fn (array $payload): RejectPaymentResult => RejectPaymentResult::fromArray($payload),
            afterCommit: function (RejectPaymentResult $r): void {
                if (! $r->wasTransitionApplied) {
                    return;
                }

                PaymentRejected::dispatch(
                    $r->paymentId,
                    $r->orderId,
                    $r->gameId,
                    $r->buyerUserId,
                    $r->reviewerUserId,
                    $r->reason,
                    $r->releasedGameNumberIds,
                );
            },
        );

        return (new PaymentRejectedResource($result))
            ->response()
            ->setStatusCode(Response::HTTP_OK);
    }
}
