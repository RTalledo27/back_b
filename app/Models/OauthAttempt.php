<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\OauthAttemptPurpose;
use Database\Factories\OauthAttemptFactory;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * @property string $id
 * @property string $provider
 * @property string $purpose
 * @property ?int $initiated_by_user_id
 * @property string $state_hash
 * @property ?string $exchange_code_hash
 * @property ?int $user_id
 * @property Carbon $expires_at
 * @property ?Carbon $consumed_at
 */
class OauthAttempt extends Model
{
    /** @use HasFactory<OauthAttemptFactory> */
    use HasFactory, HasUuids;

    /** @var list<string> */
    protected $fillable = [
        'provider',
        'purpose',
        'initiated_by_user_id',
        'state_hash',
        'exchange_code_hash',
        'user_id',
        'expires_at',
        'consumed_at',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'expires_at' => 'datetime',
            'consumed_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** @return BelongsTo<User, $this> */
    public function initiatedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'initiated_by_user_id');
    }

    public function isForLogin(): bool
    {
        return $this->purpose === OauthAttemptPurpose::Login->value;
    }

    public function isForLink(): bool
    {
        return $this->purpose === OauthAttemptPurpose::Link->value;
    }

    public function isExpired(): bool
    {
        return $this->expires_at->isPast();
    }

    public function isConsumed(): bool
    {
        return $this->consumed_at !== null;
    }

    public function hasExchangeCode(): bool
    {
        return $this->exchange_code_hash !== null;
    }

    public function canBeExchanged(): bool
    {
        return $this->hasExchangeCode()
            && ! $this->isConsumed()
            && ! $this->isExpired()
            && $this->user_id !== null;
    }
}
