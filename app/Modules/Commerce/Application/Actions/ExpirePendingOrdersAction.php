<?php

declare(strict_types=1);

namespace App\Modules\Commerce\Application\Actions;

use App\Modules\Commerce\Application\DTOs\ExpireOrderData;
use App\Modules\Commerce\Application\DTOs\ExpireOrderOutcome;
use App\Modules\Commerce\Domain\Enums\OrderStatus;
use App\Modules\Commerce\Domain\Models\Order;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Batch orchestrator for ExpireOrderAction.
 *
 *  - Selects only orders eligible for expiration (status=pending AND
 *    expires_at NOT NULL AND expires_at <= now()). The query filter
 *    excludes payment_submitted and every other status by construction.
 *  - Processes orders in small chunks; each ExpireOrderAction::execute()
 *    call opens its own DB transaction AND emits the after-commit event
 *    when applicable — this action does NOT redispatch.
 *  - Captures metrics (examined / expired / skipped / failed).
 *  - On any per-order failure, reports the exception with safe context
 *    (order_id, exception class, phase) and continues with the next.
 */
final class ExpirePendingOrdersAction
{
    public const DEFAULT_CHUNK = 100;

    public function __construct(private readonly ExpireOrderAction $expireOrder) {}

    /**
     * @return array{examined: int, expired: int, skipped: int, failed: int}
     */
    public function execute(int $chunkSize = self::DEFAULT_CHUNK): array
    {
        $metrics = ['examined' => 0, 'expired' => 0, 'skipped' => 0, 'failed' => 0];

        Order::query()
            ->where('status', OrderStatus::Pending->value)
            ->whereNotNull('expires_at')
            ->where('expires_at', '<=', now())
            ->orderBy('id')
            ->chunkById($chunkSize, function ($orders) use (&$metrics): void {
                foreach ($orders as $order) {
                    $metrics['examined']++;

                    try {
                        $result = $this->expireOrder->execute(new ExpireOrderData($order->id));
                    } catch (Throwable $exception) {
                        $this->reportBatchFailure($order->id, $exception);
                        $metrics['failed']++;

                        continue;
                    }

                    if ($result->outcome === ExpireOrderOutcome::Expired) {
                        $metrics['expired']++;
                    } else {
                        $metrics['skipped']++;
                    }
                }
            });

        return $metrics;
    }

    /**
     * Make per-order failures visible to observability without leaking
     * personal or sensitive data. Reports the original exception AND
     * writes a structured warning log line with safe context.
     */
    private function reportBatchFailure(string $orderId, Throwable $exception): void
    {
        try {
            report($exception);
        } catch (Throwable) {
            // Reporting must never replace the original failure surface.
        }

        Log::warning('Order expiration failed during batch run.', [
            'phase' => 'commerce_expiration',
            'order_id' => $orderId,
            'exception' => $exception::class,
        ]);
    }
}
