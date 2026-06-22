<?php

declare(strict_types=1);

namespace App\Modules\Commerce\Presentation\Http\Controllers\Admin;

use App\Modules\Commerce\Application\Actions\ApprovePaymentAction;
use App\Modules\Commerce\Application\DTOs\ApprovePaymentData;
use App\Modules\Commerce\Application\DTOs\ApprovePaymentResult;
use App\Modules\Commerce\Application\Support\IdempotencyContext;
use App\Modules\Commerce\Domain\Events\PaymentApproved;
use App\Modules\Commerce\Domain\Models\Payment;
use App\Modules\Commerce\Infrastructure\Idempotency\IdempotentCommandExecutor;
use App\Modules\Commerce\Presentation\Http\Requests\Admin\ApprovePaymentRequest;
use App\Modules\Commerce\Presentation\Http\Resources\PaymentApprovedResource;
use App\Modules\RepeatNumberBingo\Domain\Events\GameNumbersSold;
use Symfony\Component\HttpFoundation\Response;

final class ApprovePaymentController
{
    public function __invoke(
        ApprovePaymentRequest $request,
        Payment $payment,
        ApprovePaymentAction $action,
        IdempotentCommandExecutor $executor,
    ): Response {
        $user = $request->user();

        $data = new ApprovePaymentData(
            paymentId: $payment->getKey(),
            reviewerUserId: (int) $user?->getKey(),
            notes: $request->notes(),
        );

        $context = IdempotencyContext::make(
            userId: (int) $user?->getKey(),
            method: $request->method(),
            path: $request->path(),
            key: (string) $request->header('Idempotency-Key'),
            payloadComponents: [
                'payment_id' => $payment->getKey(),
                'notes' => $data->notes,
            ],
        );

        /** @var ApprovePaymentResult $result */
        $result = $executor->execute(
            context: $context,
            command: fn (): ApprovePaymentResult => $action->executeWithinTransaction($data),
            hydrate: fn (array $payload): ApprovePaymentResult => ApprovePaymentResult::fromArray($payload),
            afterCommit: function (ApprovePaymentResult $r): void {
                if (! $r->wasTransitionApplied) {
                    return;
                }

                PaymentApproved::dispatch(
                    $r->paymentId,
                    $r->orderId,
                    $r->gameId,
                    $r->buyerUserId,
                    $r->reviewerUserId,
                    $r->gameEntryIds,
                );
                GameNumbersSold::dispatch(
                    $r->gameId,
                    $r->buyerUserId,
                    $r->gameNumberIds,
                    $r->numbers,
                    $r->gameEntryIds,
                );
            },
        );

        return (new PaymentApprovedResource($result))
            ->response()
            ->setStatusCode(Response::HTTP_OK);
    }
}
