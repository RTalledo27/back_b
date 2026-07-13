<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * @property string $id
 * @property string $outbox_event_id
 * @property string $event_type
 * @property int $recipient_user_id
 * @property string $channel
 * @property string $deduplication_key
 * @property string $status
 * @property Carbon|null $queued_at
 * @property Carbon|null $sent_at
 * @property Carbon|null $failed_at
 * @property int $attempts
 * @property string|null $last_error
 * @property Carbon $created_at
 * @property Carbon|null $updated_at
 */
class NotificationDelivery extends Model
{
    use HasUuids;

    protected $table = 'notification_deliveries';

    public $incrementing = false;

    protected $keyType = 'string';

    public const STATUS_PENDING = 'pending';

    public const STATUS_QUEUED = 'queued';

    public const STATUS_SENT = 'sent';

    public const STATUS_FAILED = 'failed';

    public const CHANNEL_MAIL = 'mail';

    /** Pending records younger than this many seconds are treated as fresh (zone ambigua). */
    public const PENDING_FRESH_SECONDS = 300;

    public const MAX_ATTEMPTS = 3;

    protected $fillable = [
        'id',
        'outbox_event_id',
        'event_type',
        'recipient_user_id',
        'channel',
        'deduplication_key',
        'status',
        'queued_at',
        'sent_at',
        'failed_at',
        'attempts',
        'last_error',
        'created_at',
        'updated_at',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'recipient_user_id' => 'integer',
            'attempts' => 'integer',
            'queued_at' => 'datetime',
            'sent_at' => 'datetime',
            'failed_at' => 'datetime',
            'created_at' => 'datetime',
            'updated_at' => 'datetime',
        ];
    }

    /**
     * Idempotent claim via INSERT ... ON CONFLICT DO NOTHING.
     *
     * Returns the existing row when deduplication_key already exists,
     * or the newly inserted row. No unique-violation exception is raised.
     */
    public static function claim(
        string $outboxEventId,
        string $eventType,
        int $recipientUserId,
        string $channel,
    ): self {
        return self::claimForHandler($outboxEventId, $eventType, $recipientUserId, $channel)[0];
    }

    /**
     * Same as claim() but also returns whether the row was just created.
     *
     * Use in handlers to skip isPendingFresh() on the initial (non-retry) execution.
     * If $wasJustCreated is true, the record did not exist before this call —
     * no zone ambigua risk, so proceed with processing.
     *
     * @return array{0: self, 1: bool}
     */
    public static function claimForHandler(
        string $outboxEventId,
        string $eventType,
        int $recipientUserId,
        string $channel,
    ): array {
        $key = "{$outboxEventId}:{$recipientUserId}:{$channel}";
        $now = now();
        $id = (string) Str::uuid7();

        DB::statement(<<<'SQL'
            INSERT INTO notification_deliveries
                (id, outbox_event_id, event_type, recipient_user_id, channel,
                 deduplication_key, status, attempts, created_at, updated_at)
            VALUES
                (?, ?, ?, ?, ?, ?, 'pending', 0, ?, ?)
            ON CONFLICT (deduplication_key) DO NOTHING
        SQL, [$id, $outboxEventId, $eventType, $recipientUserId, $channel, $key, $now, $now]);

        $delivery = self::where('deduplication_key', $key)->firstOrFail();
        $wasJustCreated = $delivery->id === $id;

        return [$delivery, $wasJustCreated];
    }

    /** True when status is queued or sent — do not re-send. */
    public function isFinalOrQueued(): bool
    {
        return in_array($this->status, [self::STATUS_QUEUED, self::STATUS_SENT], true);
    }

    /** True when status is pending and the record is recent (zone ambigua after crash). */
    public function isPendingFresh(): bool
    {
        if ($this->status !== self::STATUS_PENDING) {
            return false;
        }

        $reference = $this->updated_at ?? $this->created_at;

        return $reference !== null
            && $reference->diffInSeconds(now()) < self::PENDING_FRESH_SECONDS;
    }

    /** True when pending and stale enough to retry, within attempt budget. */
    public function isRetryablePending(): bool
    {
        if ($this->status !== self::STATUS_PENDING) {
            return false;
        }

        $reference = $this->updated_at ?? $this->created_at;
        $stale = $reference === null
            || $reference->diffInSeconds(now()) >= self::PENDING_FRESH_SECONDS;

        return $stale && $this->attempts < self::MAX_ATTEMPTS;
    }

    /** True when failed and still within attempt budget. */
    public function isRetryableFailed(): bool
    {
        return $this->status === self::STATUS_FAILED
            && $this->attempts < self::MAX_ATTEMPTS;
    }

    public function markQueued(): void
    {
        $now = now();
        $this->status = self::STATUS_QUEUED;
        $this->queued_at = $now;
        $this->updated_at = $now;
        $this->save();
    }

    public function markSent(): void
    {
        $now = now();
        $this->status = self::STATUS_SENT;
        $this->sent_at = $now;
        $this->updated_at = $now;
        $this->save();
    }

    public function markFailed(string $reason): void
    {
        $now = now();
        $this->status = self::STATUS_FAILED;
        $this->failed_at = $now;
        $this->last_error = mb_substr($reason, 0, 1000);
        $this->attempts = $this->attempts + 1;
        $this->updated_at = $now;
        $this->save();
    }
}
