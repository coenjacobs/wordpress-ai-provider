<?php

declare(strict_types=1);

namespace CoenJacobs\WordPressAiProvider\Provider;

use WordPress\AiClient\AiClient;
use CoenJacobs\WordPressAiProvider\Admin\AbstractSettingsPage;

/**
 * Base plugin class that handles provider registration and admin setup.
 *
 * Subclasses must implement the abstract methods to provide provider-specific configuration.
 */
abstract class AbstractProviderPlugin
{
    /** @var static|null */
    protected static $instance = null;

    /**
     * @return static
     */
    public static function instance()
    {
        if (static::$instance === null) {
            static::$instance = new static();
        }

        return static::$instance;
    }

    public function setup(): void
    {
        add_action('init', [$this, 'registerProvider'], 5);

        if (is_admin()) {
            $settingsPage = $this->createSettingsPage();
            add_action('admin_menu', [$settingsPage, 'registerMenu']);

            $settings = $this->createSettings();
            add_action('admin_init', [$settings, 'registerSettings']);
        }
    }

    /**
     * Register the provider with the WordPress AI Client registry.
     */
    public function registerProvider(): void
    {
        if (!class_exists(AiClient::class)) {
            return;
        }

        $providerClass = $this->getProviderClass();
        $registry = AiClient::defaultRegistry();

        if ($registry->hasProvider($providerClass)) {
            return;
        }

        $registry->registerProvider($providerClass);
    }

    /**
     * Return the ProviderConfig for this plugin.
     *
     * @return ProviderConfig
     */
    abstract protected function getConfig(): ProviderConfig;

    /**
     * Return the fully-qualified provider class name (e.g., OpenRouterProvider::class).
     *
     * @return class-string
     */
    abstract protected function getProviderClass(): string;

    /**
     * Create the settings page instance for this plugin.
     *
     * @return AbstractSettingsPage
     */
    abstract protected function createSettingsPage();

    /**
     * Create the settings instance for this plugin.
     *
     * @return AbstractProviderSettings
     */
    abstract protected function createSettings();
}
