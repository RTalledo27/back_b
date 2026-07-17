<?php

declare(strict_types=1);

namespace App\Modules\Commerce\Presentation\Http\Exceptions;

use App\Modules\Commerce\Application\Gateway\GatewayPaymentNotPayableException;
use App\Modules\Commerce\Application\Gateway\GatewayWebhookSignatureException;
use App\Modules\Commerce\Application\Gateway\PaymentGatewayException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use RuntimeException;
use Throwable;

final class GatewayHttpException extends RuntimeException
{
    public function __construct(
        public readonly int $status,
        public readonly string $error,
        string $message,
    ) {
        parent::__construct($message);
    }

    public static function from(Throwable $exception): self
    {
        if ($exception instanceof self) {
            return $exception;
        }

        if ($exception instanceof GatewayWebhookSignatureException) {
            return new self(401, 'invalid_webhook_signature', 'Invalid webhook signature.');
        }

        if ($exception instanceof GatewayPaymentNotPayableException) {
            return new self(422, 'gateway_not_payable', 'The order is not payable through the gateway.');
        }

        if ($exception instanceof ModelNotFoundException) {
            return new self(404, 'not_found', 'Resource not found.');
        }

        if ($exception instanceof PaymentGatewayException) {
            return match ($exception->getCode()) {
                1002 => new self(409, 'gateway_idempotency_conflict', 'The gateway request conflicts with a previous request.'),
                1004, 1006 => new self(404, 'gateway_provider_not_found', 'Gateway provider not found.'),
                1005 => new self(400, 'invalid_webhook', 'Invalid webhook request.'),
                1009, 1010 => new self(409, 'gateway_conflict', 'The gateway request conflicts with the current state.'),
                default => new self(500, 'gateway_processing_failed', 'The gateway request could not be processed.'),
            };
        }

        report($exception);

        return new self(500, 'gateway_processing_failed', 'The gateway request could not be processed.');
    }
}
