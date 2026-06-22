<?php

declare(strict_types=1);

namespace App\Modules\Commerce\Domain\Exceptions;

use App\Modules\Shared\Domain\Exceptions\DomainException;

final class EvidenceValidationException extends DomainException
{
    public static function unsupportedMime(string $detectedMime): self
    {
        return new self(
            "Payment evidence has an unsupported MIME type: {$detectedMime}."
        );
    }
}
