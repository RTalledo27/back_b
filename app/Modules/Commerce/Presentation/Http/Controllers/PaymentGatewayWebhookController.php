<?php

declare(strict_types=1);

namespace App\Modules\Commerce\Presentation\Http\Controllers;

use App\Modules\Commerce\Application\Gateway\Actions\ProcessGatewayWebhookAction;
use App\Modules\Commerce\Application\Gateway\Actions\RecordGatewayWebhookNotificationAction;
use App\Modules\Commerce\Application\Gateway\ProcessGatewayWebhookData;
use App\Modules\Commerce\Presentation\Http\Exceptions\GatewayHttpException;
use App\Modules\Commerce\Presentation\Http\Requests\PaymentGatewayWebhookRequest;
use Illuminate\Http\JsonResponse;
use Throwable;

final class PaymentGatewayWebhookController
{
    public function __invoke(
        PaymentGatewayWebhookRequest $request,
        string $provider,
        RecordGatewayWebhookNotificationAction $record,
        ProcessGatewayWebhookAction $process,
    ): JsonResponse {
        try {
            $webhook = $record->execute($request->toGatewayRequest($provider));
            $process->execute(new ProcessGatewayWebhookData($webhook->webhookId));
        } catch (Throwable $exception) {
            throw GatewayHttpException::from($exception);
        }

        return response()->json(['received' => true]);
    }
}
