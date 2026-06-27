<?php

declare(strict_types=1);

namespace App\Modules\Commerce\Domain\Models;

use App\Models\User;
use App\Modules\Shared\Domain\Exceptions\ImmutableModelException;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * Append-only evidence document attached to a winner payout.
 *
 * @property string $id
 * @property string $payout_id
 * @property string $disk
 * @property string $path
 * @property string $original_filename
 * @property string $mime_type
 * @property int $size_bytes
 * @property string $sha256
 * @property int $uploaded_by
 * @property Carbon $created_at
 */
class WinnerPayoutDocument extends Model
{
    use HasUuids;

    protected $table = 'winner_payout_documents';

    protected $guarded = [];

    public const UPDATED_AT = null;

    protected static function booted(): void
    {
        static::updating(function (): void {
            throw ImmutableModelException::forModel(self::class, 'updated');
        });

        static::deleting(function (): void {
            throw ImmutableModelException::forModel(self::class, 'deleted');
        });
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'size_bytes' => 'integer',
            'created_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<WinnerPayout, $this>
     */
    public function payout(): BelongsTo
    {
        return $this->belongsTo(WinnerPayout::class, 'payout_id');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function uploadedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'uploaded_by');
    }
}
