<?php

declare(strict_types=1);

namespace App\Modules\Commerce\Presentation\Http\Controllers\Admin;

use App\Modules\Commerce\Application\Queries\ListGameNumbersAdminQuery;
use App\Modules\Commerce\Domain\Models\NumberReservation;
use App\Modules\Commerce\Presentation\Http\Resources\Admin\AdminGameNumberResource;
use App\Modules\RepeatNumberBingo\Domain\Models\Game;
use App\Modules\RepeatNumberBingo\Domain\Models\GameEntry;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

/**
 * Admin grid for a game's numbers, batch-joining active reservations and
 * sold entries. Two SELECTs total regardless of number count — no N+1.
 */
final class ListGameNumbersAdminController
{
    public function __invoke(
        Game $game,
        ListGameNumbersAdminQuery $query,
    ): AnonymousResourceCollection {
        $numbers = $query->forGame($game->getKey());
        $numberIds = $numbers->pluck('id')->all();

        $reservations = NumberReservation::query()
            ->whereIn('game_number_id', $numberIds)
            ->with('order:id,user_id,status,expires_at')
            ->get()
            ->keyBy('game_number_id');

        $entries = GameEntry::query()
            ->whereIn('game_number_id', $numberIds)
            ->with('user:id,name,email')
            ->get()
            ->keyBy('game_number_id');

        $decorated = $numbers->map(function ($gn) use ($reservations, $entries): AdminGameNumberResource {
            $extra = [
                'active_reservation' => $this->reservationExtra($reservations->get($gn->id)),
                'sold_entry' => $this->entryExtra($entries->get($gn->id)),
            ];

            return new AdminGameNumberResource($gn, $extra);
        });

        return AdminGameNumberResource::collection($decorated);
    }

    /**
     * @return ?array<string, mixed>
     */
    private function reservationExtra(?NumberReservation $reservation): ?array
    {
        if ($reservation === null) {
            return null;
        }

        return [
            'id' => $reservation->id,
            'order_id' => $reservation->order_id,
            'user_id' => $reservation->order?->user_id,
            'order_status' => $reservation->order?->status?->value,
            'expires_at' => $reservation->order?->expires_at?->toIso8601String(),
        ];
    }

    /**
     * @return ?array<string, mixed>
     */
    private function entryExtra(?GameEntry $entry): ?array
    {
        if ($entry === null) {
            return null;
        }

        return [
            'id' => $entry->id,
            'user_id' => $entry->user_id,
            'user_name' => $entry->user?->name,
            'status' => $entry->status->value,
            'confirmed_at' => $entry->confirmed_at?->toIso8601String(),
        ];
    }
}
