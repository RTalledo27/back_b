<?php

declare(strict_types=1);

namespace App\Modules\Commerce\Presentation\Http\Controllers\Admin;

use App\Modules\Commerce\Application\Queries\ListAdminPaymentsQuery;
use App\Modules\Commerce\Presentation\Http\Resources\Admin\AdminPaymentListResource;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

final class ListAdminPaymentsController
{
    public function __invoke(Request $request, ListAdminPaymentsQuery $query): AnonymousResourceCollection
    {
        $status = $request->query('status');
        $status = is_string($status) ? $status : null;

        return AdminPaymentListResource::collection($query->paginate($status));
    }
}
