<?php

declare(strict_types=1);

namespace CoenJacobs\WordPressAiProvider\Provider;

use WordPress\AiClient\Providers\Contracts\ProviderAvailabilityInterface;

/**
 * Concrete availability check: provider is configured if an API key is set.
 */
class ApiKeyProviderAvailability implements ProviderAvailabilityInterface
{
    private ProviderConfig $config;

    public function __construct(ProviderConfig $config)
    {
        $this->config = $config;
    }

    public function isConfigured(): bool
    {
        return AbstractProviderSettings::getActiveApiKeyFor($this->config) !== '';
    }
}
