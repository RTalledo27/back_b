<?php

declare(strict_types=1);

namespace Tests\Integration\Shared;

use Illuminate\Foundation\Testing\DatabaseTruncation;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use PDOException;
use Tests\Integration\Support\RawPdoConnection;
use Tests\TestCase;

final class NotificationDeliveryConcurrencyTest extends TestCase
{
    use DatabaseTruncation;

    protected function tearDown(): void
    {
        DB::statement('TRUNCATE TABLE notification_deliveries RESTART IDENTITY CASCADE');

        parent::tearDown();
    }

    public function test_two_workers_serialize_the_same_delivery_claim_and_persist_one_row(): void
    {
        $outboxEventId = (string) Str::uuid7();
        $deduplicationKey = "{$outboxEventId}:1:mail";
        $first = RawPdoConnection::open();
        $second = RawPdoConnection::open();

        $sql = <<<'SQL'
            INSERT INTO notification_deliveries
                (id, outbox_event_id, event_type, recipient_user_id, channel,
                 deduplication_key, status, attempts, created_at, updated_at)
            VALUES (?, ?, 'payment_approved', 1, 'mail', ?, 'pending', 0, NOW(), NOW())
            ON CONFLICT (deduplication_key) DO NOTHING
        SQL;

        try {
            $first->beginTransaction();
            $firstStatement = $first->prepare($sql);
            $firstStatement->execute([(string) Str::uuid7(), $outboxEventId, $deduplicationKey]);
            $this->assertSame(1, $firstStatement->rowCount());

            $second->exec("SET statement_timeout = '500ms'");
            $secondStatement = $second->prepare($sql);

            $blockedOnUniqueClaim = false;
            try {
                $secondStatement->execute([(string) Str::uuid7(), $outboxEventId, $deduplicationKey]);
            } catch (PDOException $exception) {
                $blockedOnUniqueClaim = str_contains($exception->getMessage(), 'canceling statement')
                    || str_contains($exception->getMessage(), '57014');
            }

            $this->assertTrue($blockedOnUniqueClaim, 'The second worker must wait for the first unique claim.');
            $first->commit();

            $second->exec("SET statement_timeout = '5s'");
            $secondStatement = $second->prepare($sql);
            $secondStatement->execute([(string) Str::uuid7(), $outboxEventId, $deduplicationKey]);

            $this->assertSame(0, $secondStatement->rowCount());
            $this->assertSame(1, (int) DB::table('notification_deliveries')->count());
        } finally {
            RawPdoConnection::teardown($first);
            RawPdoConnection::teardown($second);
        }
    }
}
