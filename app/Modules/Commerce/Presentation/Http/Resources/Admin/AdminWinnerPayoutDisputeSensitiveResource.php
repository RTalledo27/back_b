<?php

declare(strict_types=1);

namespace App\Modules\Commerce\Presentation\Http\Resources\Admin;

use Illuminate\Http\Request;

final class AdminWinnerPayoutDisputeSensitiveResource extends AdminWinnerPayoutDisputeResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return array_merge(parent::toArray($request), [
            'description' => $this->resource->description_encrypted,
        ]);
    }
}
