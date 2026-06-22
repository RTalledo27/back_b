<?php

declare(strict_types=1);

namespace App\Modules\Commerce\Presentation\Http\Requests\Player;

use App\Modules\Commerce\Domain\Models\Order;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;

final class CancelOrderRequest extends FormRequest
{
    public function authorize(): bool
    {
        /** @var Order|null $order */
        $order = $this->route('order');

        return $order !== null && Gate::allows('cancel', $order);
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [];
    }
}
