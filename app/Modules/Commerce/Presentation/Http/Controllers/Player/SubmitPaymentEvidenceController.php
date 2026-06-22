<?php

declare(strict_types=1);

namespace App\Modules\Commerce\Presentation\Http\Controllers\Player;

use App\Modules\Commerce\Application\DTOs\SubmitPaymentEvidenceData;
use App\Modules\Commerce\Application\Support\SubmitPaymentEvidenceOrchestrator;
use App\Modules\Commerce\Domain\Models\Order;
use App\Modules\Commerce\Presentation\Http\Requests\Player\SubmitPaymentEvidenceRequest;
use App\Modules\Commerce\Presentation\Http\Resources\SubmitPaymentEvidenceResource;
use Symfony\Component\HttpFoundation\Response;

final class SubmitPaymentEvidenceController
{
    public function __invoke(
        SubmitPaymentEvidenceRequest $request,
        Order $order,
        SubmitPaymentEvidenceOrchestrator $orchestrator,
    ): Response {
        $data = new SubmitPaymentEvidenceData(
            orderId: $order->getKey(),
            userId: (int) $request->user()?->getKey(),
        );

        $result = $orchestrator->handle(
            data: $data,
            uploadedFile: $request->uploadedFile(),
            idempotencyKey: (string) $request->header('Idempotency-Key'),
            requestMethod: $request->method(),
            requestPath: $request->path(),
        );

        return (new SubmitPaymentEvidenceResource($result))
            ->response()
            ->setStatusCode(Response::HTTP_OK);
    }
}
