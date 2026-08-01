<?php

declare(strict_types=1);

namespace App\Modules\RepeatNumberBingo\Domain\Models;

use App\Modules\Shared\Domain\Exceptions\ImmutableModelException;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

final class WinnerIdentityDocument extends Model
{
    use HasUuids;

    protected $table = 'winner_identity_documents';

    protected $guarded = [];

    public const UPDATED_AT = null;

    protected static function booted(): void
    {
        self::updating(function (): void {
            throw ImmutableModelException::forModel(self::class, 'updated');
        });

        self::deleting(function (): void {
            throw ImmutableModelException::forModel(self::class, 'deleted');
        });
    }

    protected function casts(): array
    {
        return [
            'size_bytes' => 'integer',
            'created_at' => 'datetime',
        ];
    }

    public function winnerClaim(): BelongsTo
    {
        return $this->belongsTo(WinnerClaim::class);
    }
}
