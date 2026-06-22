<?php

declare(strict_types=1);

namespace Tests\Unit\Commerce;

use App\Modules\Commerce\Application\Actions\ExpirePendingOrdersAction;
use App\Modules\Commerce\Application\Jobs\ExpirePendingOrdersJob;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use ReflectionMethod;

/**
 * The Job is a thin wrapper. Asserts: it implements ShouldQueue and
 * ShouldBeUnique, uses uniqueFor = 60 SECONDS, and `handle()` is a single
 * line that delegates to ExpirePendingOrdersAction::execute(). The body
 * is inspected statically because `ExpirePendingOrdersAction` is final
 * (no mock substitution) and we explicitly want the Job to contain zero
 * business logic — anything beyond the delegation call would signal
 * creeping responsibility.
 */
final class ExpirePendingOrdersJobTest extends TestCase
{
    public function test_implements_should_queue_and_should_be_unique(): void
    {
        $reflection = new ReflectionClass(ExpirePendingOrdersJob::class);
        $this->assertTrue($reflection->implementsInterface(ShouldQueue::class));
        $this->assertTrue($reflection->implementsInterface(ShouldBeUnique::class));
    }

    public function test_unique_for_is_sixty_seconds_and_unique_id_is_stable(): void
    {
        $job = new ExpirePendingOrdersJob;

        $this->assertSame(60, $job->uniqueFor);
        $this->assertSame('commerce:expire-pending-orders', $job->uniqueId());
    }

    public function test_handle_signature_depends_on_the_action(): void
    {
        $method = new ReflectionMethod(ExpirePendingOrdersJob::class, 'handle');
        $params = $method->getParameters();

        $this->assertCount(1, $params);
        $type = $params[0]->getType();
        $this->assertNotNull($type);
        $this->assertSame(ExpirePendingOrdersAction::class, (string) $type);
    }

    public function test_handle_body_contains_only_the_delegation_call(): void
    {
        $method = new ReflectionMethod(ExpirePendingOrdersJob::class, 'handle');
        $source = file((string) $method->getFileName());
        $body = implode(
            '',
            array_slice($source, $method->getStartLine() - 1, $method->getEndLine() - $method->getStartLine() + 1),
        );

        $this->assertStringContainsString('$action->execute(', $body);
        $this->assertStringNotContainsString('DB::', $body);
        $this->assertStringNotContainsString('Schema::', $body);
        $this->assertStringNotContainsString('Order::', $body);
        $this->assertStringNotContainsString('Payment::', $body);
    }
}
