<?php

declare(strict_types=1);

namespace App\Modules\Commerce\Application\Gateway;

final class PaymentGatewayProviderRegistry
{
    /**
     * @param  array<string, PaymentGatewayProvider>  $providers
     */
    public function __construct(
        private readonly array $providers,
        private readonly string $defaultProvider,
    ) {}

    public function get(string $provider): PaymentGatewayProvider
    {
        $resolved = $this->providers[$provider] ?? null;

        if ($resolved === null) {
            throw PaymentGatewayException::providerNotConfigured($provider);
        }

        return $resolved;
    }

    public function default(): PaymentGatewayProvider
    {
        return $this->get($this->defaultProvider);
    }

    /**
     * @return list<string>
     */
    public function names(): array
    {
        return array_keys($this->providers);
    }
}
