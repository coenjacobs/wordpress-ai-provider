<?php

declare(strict_types=1);

namespace CoenJacobs\WordPressAiProvider\Provider;

use WordPress\AiClient\AiClient;
use WordPress\AiClient\Providers\Contracts\ProviderAvailabilityInterface;

/**
 * Provider is configured if the registry has an API key authentication set.
 *
 * The API key may come from environment variable, PHP constant, or the
 * WordPress Connectors database option — all resolved by WordPress core.
 */
class RegistryApiKeyProviderAvailability implements ProviderAvailabilityInterface
{
    private string $providerId;

    public function __construct(string $providerId)
    {
        $this->providerId = $providerId;
    }

    public function isConfigured(): bool
    {
        if (!class_exists(AiClient::class)) {
            return false;
        }

        $registry = AiClient::defaultRegistry();

        if (!$registry->hasProvider($this->providerId)) {
            return false;
        }

        return $registry->getProviderRequestAuthentication($this->providerId) !== null;
    }
}
