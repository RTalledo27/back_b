<?php

declare(strict_types=1);

namespace App\Modules\RepeatNumberBingo\Application\Actions;

use App\Modules\RepeatNumberBingo\Domain\Enums\WinnerClaimStatus;
use App\Modules\RepeatNumberBingo\Domain\Models\WinnerClaim;
use Illuminate\Support\Facades\Log;
use Throwable;

final class ExpireWinnerClaimsAction
{
    public function __construct(private readonly ExpireWinnerClaimAction $expireClaim) {}

    /** @return array{examined: int, expired: int, skipped: int, failed: int} */
    public function execute(int $chunkSize = 100): array
    {
        $metrics = ['examined' => 0, 'expired' => 0, 'skipped' => 0, 'failed' => 0];

        WinnerClaim::query()
            ->where('status', WinnerClaimStatus::PendingClaim->value)
            ->whereNotNull('expires_at')
            ->where('expires_at', '<=', now())
            ->orderBy('id')
            ->chunkById($chunkSize, function ($claims) use (&$metrics): void {
                foreach ($claims as $claim) {
                    $metrics['examined']++;

                    try {
                        if ($this->expireClaim->execute((string) $claim->id)) {
                            $metrics['expired']++;
                        } else {
                            $metrics['skipped']++;
                        }
                    } catch (Throwable $exception) {
                        report($exception);
                        Log::warning('Winner claim expiration failed.', [
                            'phase' => 'winner_claim_expiration',
                            'claim_id' => (string) $claim->id,
                            'exception' => $exception::class,
                        ]);
                        $metrics['failed']++;
                    }
                }
            });

        return $metrics;
    }
}
