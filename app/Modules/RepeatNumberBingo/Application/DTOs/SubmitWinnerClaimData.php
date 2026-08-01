<?php

declare(strict_types=1);

namespace App\Modules\RepeatNumberBingo\Application\DTOs;

final readonly class SubmitWinnerClaimData
{
    /**
     * @param  list<WinnerIdentityDocumentData>  $documents
     */
    public function __construct(
        public string $winnerId,
        public int $userId,
        public string $legalName,
        public string $documentType,
        public string $documentNumber,
        public bool $acceptedPrizeTerms,
        public bool $consentedIdentityProcessing,
        public array $documents,
        public ?string $idempotencyKeyHash = null,
        public ?string $requestFingerprint = null,
    ) {}
}
