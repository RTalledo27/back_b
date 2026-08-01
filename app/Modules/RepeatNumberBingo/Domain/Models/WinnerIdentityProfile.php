<?php

declare(strict_types=1);

namespace App\Modules\RepeatNumberBingo\Domain\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

final class WinnerIdentityProfile extends Model
{
    use HasUuids;

    protected $table = 'winner_identity_profiles';

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'legal_name_encrypted' => 'encrypted',
            'document_number_encrypted' => 'encrypted',
            'accepted_prize_terms_at' => 'datetime',
            'consented_identity_processing_at' => 'datetime',
        ];
    }

    public function winnerClaim(): BelongsTo
    {
        return $this->belongsTo(WinnerClaim::class);
    }
}
