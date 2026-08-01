<?php

declare(strict_types=1);

namespace App\Modules\Commerce\Presentation\Http\Resources\Admin;

use Illuminate\Http\Request;

final class AdminWinnerPayoutSensitiveResource extends AdminWinnerPayoutResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return parent::toArray($request);
    }
}
