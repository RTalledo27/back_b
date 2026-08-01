<?php

declare(strict_types=1);

namespace App\Modules\Commerce\Application\Support;

use App\Modules\Commerce\Application\DTOs\WinnerPayoutDestinationData;
use App\Modules\Commerce\Domain\Enums\WinnerPayoutDestinationMethod;
use Illuminate\Support\Arr;
use Illuminate\Validation\ValidationException;

final class WinnerPayoutDestinationFactory
{
    /** @param array<string, mixed> $input */
    public function make(array $input): WinnerPayoutDestinationData
    {
        $method = (string) ($input['method'] ?? '');
        $allowed = array_map(static fn (WinnerPayoutDestinationMethod $case): string => $case->value, WinnerPayoutDestinationMethod::cases());

        if (! in_array($method, $allowed, true)) {
            throw ValidationException::withMessages(['destination.method' => 'The destination method is invalid.']);
        }

        $payload = Arr::except($input, ['method']);
        $allowedKeys = match ($method) {
            WinnerPayoutDestinationMethod::BankTransfer->value => ['account', 'account_number', 'cci', 'holder_name', 'bank_name'],
            WinnerPayoutDestinationMethod::Yape->value, WinnerPayoutDestinationMethod::Plin->value => ['phone', 'identifier', 'holder_name'],
            WinnerPayoutDestinationMethod::Cash->value => ['reference', 'location'],
            WinnerPayoutDestinationMethod::Other->value => ['category', 'reference'],
        };

        foreach ($payload as $key => $value) {
            if (! in_array((string) $key, $allowedKeys, true)) {
                throw ValidationException::withMessages(['destination' => 'The destination contains an unexpected field.']);
            }
            if (preg_match('/password|secret|token|pin|cvv|credential|key/i', (string) $key) === 1) {
                throw ValidationException::withMessages(['destination' => 'Sensitive credentials are not accepted.']);
            }
            if (! is_scalar($value) && $value !== null) {
                throw ValidationException::withMessages(['destination' => 'Destination values must be scalar.']);
            }
            $payload[$key] = $value === null ? null : trim((string) $value);
        }

        $primary = match ($method) {
            WinnerPayoutDestinationMethod::BankTransfer->value => $payload['account'] ?? $payload['account_number'] ?? $payload['cci'] ?? null,
            WinnerPayoutDestinationMethod::Yape->value, WinnerPayoutDestinationMethod::Plin->value => $payload['phone'] ?? $payload['identifier'] ?? null,
            WinnerPayoutDestinationMethod::Cash->value => $payload['reference'] ?? null,
            WinnerPayoutDestinationMethod::Other->value => $payload['category'] ?? null,
        };

        if (! is_string($primary) || $primary === '') {
            throw ValidationException::withMessages(['destination' => 'The destination is incomplete.']);
        }

        $masked = match ($method) {
            WinnerPayoutDestinationMethod::Other->value => 'other:'.$this->mask($primary),
            default => $this->mask($primary),
        };

        ksort($payload);

        return new WinnerPayoutDestinationData($method, $payload, $masked);
    }

    private function mask(string $value): string
    {
        $value = trim($value);

        return strlen($value) <= 4 ? '****' : '****'.substr($value, -4);
    }
}
