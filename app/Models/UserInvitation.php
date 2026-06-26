<?php

declare(strict_types=1);

namespace App\Models;

use Database\Factories\UserInvitationFactory;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * @property string $id
 * @property int $user_id
 * @property ?int $invited_by_user_id
 * @property string $token_hash
 * @property Carbon $expires_at
 * @property ?Carbon $consumed_at
 * @property ?Carbon $revoked_at
 */
class UserInvitation extends Model
{
    /** @use HasFactory<UserInvitationFactory> */
    use HasFactory, HasUuids;

    /**
     * @var list<string>
     */
    protected $fillable = [
        'user_id',
        'invited_by_user_id',
        'token_hash',
        'expires_at',
        'consumed_at',
        'revoked_at',
    ];

    /**
     * @var list<string>
     */
    protected $hidden = [
        'token_hash',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'expires_at' => 'datetime',
            'consumed_at' => 'datetime',
            'revoked_at' => 'datetime',
        ];
    }

    public function isConsumed(): bool
    {
        return $this->consumed_at !== null;
    }

    public function isRevoked(): bool
    {
        return $this->revoked_at !== null;
    }

    public function isExpired(?Carbon $at = null): bool
    {
        return $this->expires_at->lte($at ?? now());
    }

    public function isActive(): bool
    {
        return ! $this->isConsumed() && ! $this->isRevoked();
    }

    public function isValidForActivation(?Carbon $at = null): bool
    {
        return $this->isActive() && ! $this->isExpired($at);
    }

    public function canBeConsumed(?Carbon $at = null): bool
    {
        return $this->isValidForActivation($at);
    }

    public function canBeRevoked(): bool
    {
        return ! $this->isConsumed() && ! $this->isRevoked();
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function invitedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'invited_by_user_id');
    }
}
