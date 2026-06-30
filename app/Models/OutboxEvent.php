<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * @property string $id
 * @property string $event_type
 * @property string $aggregate_type
 * @property string|null $aggregate_id
 * @property string|null $deduplication_key
 * @property array<string, mixed> $payload
 * @property Carbon $available_at
 * @property Carbon|null $processed_at
 * @property Carbon|null $failed_at
 * @property int $attempts
 * @property string|null $last_error
 * @property Carbon|null $locked_at
 * @property string|null $locked_by
 * @property Carbon|null $next_attempt_at
 * @property int $max_attempts
 * @property Carbon $created_at
 */
class OutboxEvent extends Model
{
    protected $table = 'outbox_events';

    public $incrementing = false;

    protected $keyType = 'string';

    /**
     * No updated_at column — outbox rows are append-on-create, then
     * mutated only in processing fields (processed_at, failed_at, etc.).
     */
    public const UPDATED_AT = null;

    /**
     * created_at is managed explicitly in the recorder; let Eloquent
     * handle reads only.
     */
    public $timestamps = false;

    protected $guarded = [];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'payload' => 'array',
            'available_at' => 'datetime',
            'processed_at' => 'datetime',
            'failed_at' => 'datetime',
            'locked_at' => 'datetime',
            'next_attempt_at' => 'datetime',
            'created_at' => 'datetime',
            'attempts' => 'integer',
            'max_attempts' => 'integer',
        ];
    }
}
