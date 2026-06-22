<?php

declare(strict_types=1);

namespace App\Modules\Commerce\Presentation\Http\Controllers\Admin;

use App\Modules\Commerce\Application\Queries\GetAdminPaymentDetailQuery;
use App\Modules\Commerce\Domain\Models\Payment;
use App\Modules\Commerce\Presentation\Http\Resources\Admin\AdminPaymentDetailResource;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

final class ShowAdminPaymentController
{
    public function __invoke(
        Payment $payment,
        GetAdminPaymentDetailQuery $query,
    ): AdminPaymentDetailResource {
        $hydrated = $query->find($payment->getKey());

        if ($hydrated === null) {
            throw new NotFoundHttpException('Payment not found.');
        }

        return new AdminPaymentDetailResource($hydrated);
    }
}
