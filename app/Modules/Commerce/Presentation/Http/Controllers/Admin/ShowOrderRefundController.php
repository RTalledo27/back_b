<?php

declare(strict_types=1);

namespace App\Modules\Commerce\Presentation\Http\Controllers\Admin;

use App\Modules\Commerce\Application\DTOs\RefundOrderResult;
use App\Modules\Commerce\Domain\Exceptions\RefundNotFound;
use App\Modules\Commerce\Domain\Models\Order;
use App\Modules\Commerce\Domain\Models\OrderItem;
use App\Modules\Commerce\Domain\Models\PurchaseAllocation;
use App\Modules\Commerce\Domain\Models\Refund;
use App\Modules\Commerce\Presentation\Http\Resources\Admin\RefundResource;
use App\Modules\RepeatNumberBingo\Domain\Models\GameNumber;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

final class ShowOrderRefundController
{
    public function __invoke(Request $request, Order $order): Response
    {
        /** @var ?Refund $refund */
        $refund = Refund::query()->where('order_id', $order->getKey())->first();

        if ($refund === null) {
            throw RefundNotFound::forOrder((string) $order->getKey());
        }

        $allocations = PurchaseAllocation::query()
            ->whereIn(
                'order_item_id',
                OrderItem::query()->where('order_id', $order->getKey())->pluck('id'),
            )
            ->get();

        $entryIds = $allocations->pluck('game_entry_id')->sort()->values()->all();

        $gameNumberIds = $allocations->map(function (PurchaseAllocation $alloc): string {
            return (string) OrderItem::query()->whereKey($alloc->order_item_id)->value('game_number_id');
        })->sort()->values()->all();

        $numbers = GameNumber::query()
            ->whereIn('id', $gameNumberIds)
            ->orderBy('number')
            ->pluck('number')
            ->map(fn ($n): int => (int) $n)
            ->values()
            ->all();

        $result = new RefundOrderResult(
            refundId: $refund->id,
            orderId: $refund->order_id,
            paymentId: $refund->payment_id,
            gameId: $order->game_id,
            buyerUserId: $order->user_id,
            actorUserId: $refund->processed_by_user_id,
            refundedCents: $refund->amount_cents,
            currency: $refund->currency,
            reason: $refund->reason,
            processedAt: $refund->processed_at->toIso8601String(),
            createdAt: $refund->created_at->toIso8601String(),
            gameEntryIds: array_values($entryIds),
            gameNumberIds: array_values($gameNumberIds),
            numbers: $numbers,
            wasAlreadyRefunded: true,
        );

        return (new RefundResource($result))
            ->response()
            ->setStatusCode(Response::HTTP_OK);
    }
}
