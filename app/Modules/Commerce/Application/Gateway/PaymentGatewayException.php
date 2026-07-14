<?php

declare(strict_types=1);

namespace App\Modules\Commerce\Application\Gateway;

use RuntimeException;

final class PaymentGatewayException extends RuntimeException
{
    public static function invalidInput(string $message): self
    {
        return new self($message, 1001);
    }

    public static function idempotencyConflict(): self
    {
        return new self('Payment gateway idempotency key was reused with a different request.', 1002);
    }

    public static function providerFailure(string $message): self
    {
        return new self($message, 1003);
    }

    public static function providerNotConfigured(string $provider): self
    {
        return new self("Payment gateway provider [{$provider}] is not configured.", 1006);
    }

    public static function attemptNotFound(string $attemptId): self
    {
        return new self("Payment gateway attempt [{$attemptId}] was not found.", 1004);
    }

    public static function malformedWebhook(string $message): self
    {
        return new self($message, 1005);
    }

    public static function settlementNotApplicable(): self
    {
        return new self('The gateway transaction is not applicable for settlement.', 1009);
    }

    public static function settlementConflict(): self
    {
        return new self('The gateway settlement conflicts with the commercial payment state.', 1010);
    }
}
