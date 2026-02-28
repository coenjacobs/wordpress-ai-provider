<?php

declare(strict_types=1);

namespace CoenJacobs\WordPressAiProvider\Provider;

use CoenJacobs\WordPressAiProvider\ModelDirectory\AbstractModelMetadataDirectory;

/**
 * Base settings class handling API key management, credentials, model list, and refresh.
 *
 * Subclasses can override registerAdditionalSettings() to add extra fields
 * and createModelMetadataDirectory() to provide their specific directory.
 */
abstract class AbstractProviderSettings
{
    public const CREDENTIALS_OPTION = 'wp_ai_client_provider_credentials';

    private ProviderConfig $config;

    public function __construct(ProviderConfig $config)
    {
        $this->config = $config;
    }

    protected function getConfig(): ProviderConfig
    {
        return $this->config;
    }

    /**
     * Check if the API key is configured via environment variable or PHP constant.
     */
    public static function hasEnvApiKeyFor(ProviderConfig $config): bool
    {
        $env = getenv($config->getEnvVarName());
        if (is_string($env) && $env !== '') {
            return true;
        }

        if (defined($config->getConstantName())) {
            $constant = constant($config->getConstantName());
            return is_string($constant) && $constant !== '';
        }

        return false;
    }

    /**
     * Get the active API key (ENV takes precedence over constant, constant over wp_options).
     */
    public static function getActiveApiKeyFor(ProviderConfig $config): string
    {
        $env = getenv($config->getEnvVarName());
        if (is_string($env) && $env !== '') {
            return $env;
        }

        if (defined($config->getConstantName())) {
            $constant = constant($config->getConstantName());
            if (is_string($constant) && $constant !== '') {
                return $constant;
            }
        }

        $credentials = get_option(self::CREDENTIALS_OPTION, []);
        if (is_array($credentials) && isset($credentials[$config->getProviderId()])) {
            $key = $credentials[$config->getProviderId()];
            if (is_string($key)) {
                return $key;
            }
        }

        return '';
    }

    /**
     * Convenience instance method for the active API key.
     */
    public function getActiveApiKey(): string
    {
        return self::getActiveApiKeyFor($this->config);
    }

    public function registerSettings(): void
    {
        $this->handleRefreshModels();

        register_setting($this->config->getOptionGroup(), self::CREDENTIALS_OPTION, [
            'type' => 'object',
            'default' => [],
            'sanitize_callback' => [$this, 'sanitizeCredentials'],
        ]);

        register_setting($this->config->getOptionGroup(), $this->config->getEnabledModelsOption(), [
            'type' => 'array',
            'default' => [],
            'sanitize_callback' => [$this, 'sanitizeEnabledModels'],
        ]);

        add_settings_section(
            $this->config->getSectionId(),
            $this->config->getSectionTitle(),
            [$this, 'renderSectionDescription'],
            $this->config->getPageSlug()
        );

        add_settings_field(
            $this->config->getProviderId() . '_api_key',
            'API Key',
            [$this, 'renderApiKeyField'],
            $this->config->getPageSlug(),
            $this->config->getSectionId()
        );

        $this->registerAdditionalSettings();

        add_settings_field(
            $this->config->getEnabledModelsOption(),
            'Enabled Models',
            [$this, 'renderModelField'],
            $this->config->getPageSlug(),
            $this->config->getSectionId()
        );
    }

    /**
     * Override in subclasses to register additional settings fields (e.g., routing strategy).
     */
    protected function registerAdditionalSettings(): void
    {
        // No additional settings by default.
    }

    public function renderSectionDescription(): void
    {
        echo $this->config->getSectionDescriptionHtml();
    }

    /**
     * Render the API key settings field, showing env-configured key or an input.
     */
    public function renderApiKeyField(): void
    {
        if (self::hasEnvApiKeyFor($this->config)) {
            $key = self::getActiveApiKeyFor($this->config);
            $masked = strlen($key) > 8
                ? substr($key, 0, 3) . str_repeat('*', strlen($key) - 7) . substr($key, -4)
                : str_repeat('*', strlen($key));

            $envVar = $this->config->getEnvVarName();
            $source = getenv($envVar) !== false && getenv($envVar) !== ''
                ? $envVar . ' environment variable'
                : $this->config->getConstantName() . ' constant';

            echo '<p>';
            echo '<span class="dashicons dashicons-yes-alt" style="color: #00a32a;"></span> ';
            echo 'Configured via ' . esc_html($source);
            echo ' (<code>' . esc_html($masked) . '</code>)';
            echo '</p>';

            return;
        }

        $providerId = $this->config->getProviderId();
        $credentials = get_option(self::CREDENTIALS_OPTION, []);
        $value = $credentials[$providerId] ?? '';
        echo '<input type="password" id="' . esc_attr($providerId) . '_api_key"'
            . ' name="' . esc_attr(self::CREDENTIALS_OPTION) . '[' . esc_attr($providerId) . ']"'
            . ' value="' . esc_attr($value) . '" class="regular-text" autocomplete="off" />';
    }

    /**
     * Render the model selection field.
     *
     * Subclasses should override this to provide their specific model rendering
     * (grouped vs flat, with or without filter dropdown, etc.).
     */
    abstract public function renderModelField(): void;

    /**
     * Create the model metadata directory instance for fetching models.
     *
     * @return AbstractModelMetadataDirectory
     */
    abstract protected function createModelMetadataDirectory(): AbstractModelMetadataDirectory;

    /**
     * Fetch available models from the API via the model metadata directory.
     *
     * @return list<array<string, mixed>>
     */
    protected function fetchModels(): array
    {
        $directory = $this->createModelMetadataDirectory();
        return $directory->fetchAllModels();
    }

    /**
     * Enqueue the model selector assets from the shared package.
     *
     * @param string $pluginFile Path to the main plugin file.
     */
    protected function enqueueModelSelectorAssets(string $pluginFile): void
    {
        $pluginData = get_file_data($pluginFile, ['Version' => 'Version']);
        $version = $pluginData['Version'] ?: '0.1.0';

        $packageAssetsDir = dirname(__DIR__, 2) . '/assets';
        $pluginDir = dirname($pluginFile);
        $relativePath = $this->getRelativePath($pluginDir, $packageAssetsDir);

        wp_enqueue_script(
            $this->config->getProviderId() . '-model-selector',
            plugins_url($relativePath . '/model-selector.js', $pluginFile),
            [],
            $version,
            true
        );

        wp_enqueue_style(
            $this->config->getProviderId() . '-model-selector',
            plugins_url($relativePath . '/model-selector.css', $pluginFile),
            [],
            $version
        );
    }

    /**
     * Get the relative path from one directory to another.
     */
    private function getRelativePath(string $from, string $to): string
    {
        $fromParts = explode('/', rtrim((string) realpath($from), '/'));
        $toParts = explode('/', rtrim((string) realpath($to), '/'));

        $commonLength = 0;
        $maxLength = min(count($fromParts), count($toParts));
        while ($commonLength < $maxLength && $fromParts[$commonLength] === $toParts[$commonLength]) {
            $commonLength++;
        }

        $upCount = count($fromParts) - $commonLength;
        $remaining = array_slice($toParts, $commonLength);

        $parts = array_merge(array_fill(0, $upCount, '..'), $remaining);
        return implode('/', $parts);
    }

    /**
     * @param mixed $input
     * @return list<string>
     */
    public function sanitizeEnabledModels($input): array
    {
        if (!is_array($input)) {
            return [];
        }

        return array_values(array_map('sanitize_text_field', $input));
    }

    /**
     * Sanitize the credentials option, merging our key into the shared array.
     *
     * @param array|mixed $input
     * @return array<string, string>
     */
    public function sanitizeCredentials($input): array
    {
        $existing = get_option(self::CREDENTIALS_OPTION, []);
        if (!is_array($existing)) {
            $existing = [];
        }

        if (!is_array($input)) {
            return $existing;
        }

        $providerId = $this->config->getProviderId();
        $new_key = isset($input[$providerId])
            ? trim($input[$providerId])
            : ($existing[$providerId] ?? '');

        $old_key = $existing[$providerId] ?? '';
        if ($new_key !== $old_key) {
            delete_transient($this->config->getModelsTransientKey());
        }

        $existing[$providerId] = $new_key;

        return $existing;
    }

    private function handleRefreshModels(): void
    {
        $param = $this->config->getRefreshQueryParam();

        if (!isset($_GET[$param])) {
            return;
        }

        if (!current_user_can('manage_options')) {
            return;
        }

        if (!check_admin_referer($this->config->getRefreshNonceAction())) {
            return;
        }

        delete_transient($this->config->getModelsTransientKey());

        wp_safe_redirect(admin_url('options-general.php?page=' . $this->config->getPageSlug()));
        exit;
    }
}
