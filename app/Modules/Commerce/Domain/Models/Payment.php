<?php

declare(strict_types=1);

namespace App\Modules\Commerce\Domain\Models;

use App\Models\User;
use App\Modules\Commerce\Domain\Enums\PaymentMethod;
use App\Modules\Commerce\Domain\Enums\PaymentStatus;
use App\Modules\Commerce\Domain\Exceptions\InvalidPaymentTransition;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * @property string $id
 * @property string $order_id
 * @property int $amount_cents
 * @property string $currency
 * @property PaymentMethod $method
 * @property PaymentStatus $status
 * @property ?Carbon $submitted_at
 * @property ?int $reviewed_by
 * @property ?Carbon $reviewed_at
 * @property ?string $rejection_reason
 */
class Payment extends Model
{
    use HasUuids;

    protected $table = 'payments';

    protected $guarded = [];

    /**
     * @var array<string, mixed>
     */
    protected $attributes = [
        'status' => 'pending',
        'method' => 'manual',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'amount_cents' => 'integer',
            'method' => PaymentMethod::class,
            'status' => PaymentStatus::class,
            'submitted_at' => 'datetime',
            'reviewed_at' => 'datetime',
        ];
    }

    public function transitionTo(PaymentStatus $next): void
    {
        if (! $this->status->canTransitionTo($next)) {
            throw InvalidPaymentTransition::from($this->status, $next);
        }

        $this->status = $next;
    }

    /**
     * @return BelongsTo<Order, $this>
     */
    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function reviewer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reviewed_by');
    }

    /**
     * @return HasMany<PaymentDocument, $this>
     */
    public function documents(): HasMany
    {
        return $this->hasMany(PaymentDocument::class);
    }
}
