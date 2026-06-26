<?php

declare(strict_types=1);

namespace App\DTOs\Auth;

use App\Enums\SocialAuthOutcome;

final readonly class SocialCallbackResult
{
    public function __construct(
        public SocialAuthOutcome $outcome,
        /** Plain exchange code — present only when outcome->isSuccess(). Never persisted. */
        public ?string $plainCode,
    ) {}
}
