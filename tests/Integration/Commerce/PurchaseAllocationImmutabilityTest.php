<?php

declare(strict_types=1);

namespace Tests\Integration\Commerce;

use App\Models\User;
use App\Modules\Commerce\Domain\Enums\OrderStatus;
use App\Modules\Commerce\Domain\Enums\PaymentMethod;
use App\Modules\Commerce\Domain\Enums\PaymentStatus;
use App\Modules\Commerce\Domain\Models\Order;
use App\Modules\Commerce\Domain\Models\OrderItem;
use App\Modules\Commerce\Domain\Models\Payment;
use App\Modules\Commerce\Domain\Models\PurchaseAllocation;
use App\Modules\RepeatNumberBingo\Domain\Enums\EntryStatus;
use App\Modules\RepeatNumberBingo\Domain\Enums\GameNumberStatus;
use App\Modules\RepeatNumberBingo\Domain\Enums\GameStatus;
use App\Modules\RepeatNumberBingo\Domain\Models\Game;
use App\Modules\RepeatNumberBingo\Domain\Models\GameEntry;
use App\Modules\RepeatNumberBingo\Domain\Models\GameNumber;
use App\Modules\Shared\Domain\Exceptions\ImmutableModelException;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

final class PurchaseAllocationImmutabilityTest extends TestCase
{
    use LazilyRefreshDatabase;

    private function createAllocation(): PurchaseAllocation
    {
        $user = User::factory()->create();
        $game = Game::create([
            'slug' => 'alloc-'.fake()->unique()->lexify('?????'),
            'name' => 'A',
            'number_min' => 1,
            'number_max' => 10,
            'hits_required' => 5,
            'ticket_price_cents' => 100,
            'prize_cents' => 500,
            'currency' => 'PEN',
            'draw_interval_seconds' => 30,
            'auto_draw_enabled' => true,
            'status' => GameStatus::Draft,
        ]);
        $gameNumber = GameNumber::create([
            'game_id' => $game->id,
            'number' => 1,
            'status' => GameNumberStatus::Sold,
        ]);
        $order = Order::create([
            'user_id' => $user->id,
            'game_id' => $game->id,
            'subtotal_cents' => 100,
            'total_cents' => 100,
            'currency' => 'PEN',
            'status' => OrderStatus::Paid,
        ]);
        $item = OrderItem::create([
            'order_id' => $order->id,
            'game_number_id' => $gameNumber->id,
            'unit_price_cents' => 100,
        ]);
        $payment = Payment::create([
            'order_id' => $order->id,
            'amount_cents' => 100,
            'currency' => 'PEN',
            'method' => PaymentMethod::Manual,
            'status' => PaymentStatus::Approved,
        ]);
        $entry = GameEntry::create([
            'game_id' => $game->id,
            'game_number_id' => $gameNumber->id,
            'user_id' => $user->id,
            'status' => EntryStatus::Confirmed,
            'confirmed_at' => now(),
        ]);

        return PurchaseAllocation::create([
            'order_item_id' => $item->id,
            'game_entry_id' => $entry->id,
            'payment_id' => $payment->id,
        ]);
    }

    public function test_create_is_allowed(): void
    {
        $allocation = $this->createAllocation();

        $this->assertNotNull($allocation->id);
    }

    public function test_update_throws(): void
    {
        $allocation = $this->createAllocation();

        // Force an actually-dirty attribute so the updating event fires.
        // The hook must throw before the SQL update runs.
        $allocation->payment_id = (string) Str::uuid7();

        $this->expectException(ImmutableModelException::class);

        $allocation->save();
    }

    public function test_delete_throws(): void
    {
        $allocation = $this->createAllocation();

        $this->expectException(ImmutableModelException::class);

        $allocation->delete();
    }

    public function test_unique_order_item_id_blocks_duplicate(): void
    {
        $allocation = $this->createAllocation();

        $this->expectException(QueryException::class);

        PurchaseAllocation::create([
            'order_item_id' => $allocation->order_item_id,
            'game_entry_id' => $allocation->game_entry_id,
            'payment_id' => $allocation->payment_id,
        ]);
    }
}
