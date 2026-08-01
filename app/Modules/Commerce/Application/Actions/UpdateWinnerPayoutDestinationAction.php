<?php

declare(strict_types=1);

namespace App\Modules\Commerce\Application\Actions;

use App\Modules\Commerce\Application\DTOs\UpdateWinnerPayoutDestinationData;
use App\Modules\Commerce\Application\DTOs\WinnerPayoutCommandResult;
use App\Modules\Commerce\Application\Support\WinnerPayoutWorkflow;
use App\Modules\Commerce\Domain\Enums\WinnerPayoutEventType;
use App\Modules\Commerce\Domain\Enums\WinnerPayoutStatus;
use App\Modules\Commerce\Domain\Exceptions\WinnerPayoutWorkflowException;
use App\Modules\Commerce\Domain\Models\WinnerPayoutDestination;

final class UpdateWinnerPayoutDestinationAction
{
    public function __construct(private readonly WinnerPayoutWorkflow $workflow) {}

    public function executeWithinTransaction(UpdateWinnerPayoutDestinationData $data): WinnerPayoutCommandResult
    {
        $this->workflow->assertTransaction();
        $payout = $this->workflow->lockPayout($data->payoutId);

        if ($payout->status !== WinnerPayoutStatus::Draft) {
            throw WinnerPayoutWorkflowException::notEligible('destination_update_requires_draft');
        }
        if ((int) $payout->created_by_user_id !== $data->actorUserId) {
            throw WinnerPayoutWorkflowException::actorSeparation();
        }

        $version = ((int) WinnerPayoutDestination::query()->where('winner_payout_id', $payout->id)->max('version')) + 1;
        $destination = WinnerPayoutDestination::create([
            'winner_payout_id' => $payout->id,
            'version' => $version,
            'method' => $data->destination->method,
            'destination_payload_encrypted' => $data->destination->payload,
            'destination_masked' => $data->destination->masked,
            'created_by_user_id' => $data->actorUserId,
            'created_at' => now(),
        ]);

        $payout->current_destination_id = $destination->id;
        $payout->updated_at = now();
        $payout->save();
        $this->workflow->recordEvent($payout, WinnerPayoutEventType::DestinationAdded, WinnerPayoutStatus::Draft->value, WinnerPayoutStatus::Draft->value, $data->actorUserId, 'admin', null, ['version' => $version, 'method' => $data->destination->method]);

        return $this->workflow->result($payout);
    }
}
