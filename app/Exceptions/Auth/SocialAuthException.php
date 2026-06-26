<?php

declare(strict_types=1);

namespace App\Exceptions\Auth;

use RuntimeException;

final class SocialAuthException extends RuntimeException
{
    public function __construct(
        public readonly string $errorCode,
        string $message,
        public readonly int $httpStatus = 422,
    ) {
        parent::__construct($message);
    }

    public static function invalidProvider(string $provider): self
    {
        return new self(
            'invalid_provider',
            "The provider \"{$provider}\" is not supported.",
            422,
        );
    }

    public static function missingConfiguration(string $provider): self
    {
        return new self(
            'provider_not_configured',
            "The provider \"{$provider}\" is not configured.",
            503,
        );
    }

    public static function invalidState(): self
    {
        return new self('invalid_state', 'The OAuth state is invalid or was not found.', 422);
    }

    public static function expiredState(): self
    {
        return new self('expired_state', 'The OAuth state has expired.', 422);
    }

    public static function callbackAlreadyProcessed(): self
    {
        return new self('callback_already_processed', 'This OAuth callback has already been processed.', 422);
    }

    public static function exchangeCodeNotFound(): self
    {
        return new self('exchange_code_not_found', 'The exchange code was not found.', 422);
    }

    public static function exchangeCodeExpired(): self
    {
        return new self('exchange_code_expired', 'The exchange code has expired.', 422);
    }

    public static function exchangeCodeConsumed(): self
    {
        return new self('exchange_code_consumed', 'The exchange code has already been used.', 422);
    }

    public static function incorrectPassword(): self
    {
        return new self('invalid_current_password', 'The current password is incorrect.', 422);
    }

    public static function reauthenticationRequired(): self
    {
        return new self(
            'reauthentication_required',
            'Recent social authentication is required to perform this action.',
            422,
        );
    }

    public static function lastAuthenticationMethod(): self
    {
        return new self(
            'last_authentication_method',
            'Cannot unlink the last remaining authentication method.',
            422,
        );
    }

    public static function notLinked(string $provider): self
    {
        return new self(
            'not_linked',
            "The provider \"{$provider}\" is not linked to this account.",
            422,
        );
    }
}
