<?php

declare(strict_types=1);

namespace App\Modules\Commerce\Infrastructure\Idempotency;

use App\Models\User;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * Eloquent representation of an idempotency_keys row. The atomic claim
 * SQL lives in IdempotentCommandExecutor — this model is a thin
 * persistence layer used for reads and small writes.
 *
 * @property string $id
 * @property int $user_id
 * @property string $request_method
 * @property string $request_path
 * @property string $key
 * @property string $payload_sha256
 * @property ?array<string,mixed> $result_payload
 * @property Carbon $locked_at
 * @property ?Carbon $completed_at
 * @property Carbon $expires_at
 */
class IdempotencyKey extends Model
{
    use HasUuids;

    protected $table = 'idempotency_keys';

    public $timestamps = false;

    protected $guarded = [];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'result_payload' => 'array',
            'locked_at' => 'datetime',
            'completed_at' => 'datetime',
            'expires_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
