<?php

declare(strict_types=1);

namespace App\Modules\Commerce\Application\Gateway;

interface PaymentGatewayProvider
{
    public function name(): string;

    public function createAttempt(PaymentGatewayCreateAttemptData $data): PaymentGatewayCreateAttemptResult;

    public function confirm(PaymentGatewayConfirmData $data): PaymentGatewayConfirmResult;
}
