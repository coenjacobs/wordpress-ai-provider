<?php

declare(strict_types=1);

namespace CoenJacobs\WordPressAiProvider\Provider;

use CoenJacobs\WordPressAiProvider\ModelDirectory\AbstractModelMetadataDirectory;

/**
 * Base settings class handling model selection and refresh.
 *
 * API key management is handled by the WordPress Connectors system.
 * Subclasses can override registerAdditionalSettings() to add extra fields
 * and createModelMetadataDirectory() to provide their specific directory.
 */
abstract class AbstractProviderSettings
{
    private ProviderConfig $config;

    public function __construct(ProviderConfig $config)
    {
        $this->config = $config;
    }

    protected function getConfig(): ProviderConfig
    {
        return $this->config;
    }

    public function registerSettings(): void
    {
        $this->handleRefreshModels();

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
     * Enqueue the model selector assets.
     *
     * Assets are expected at the plugin's own assets/ directory, copied there
     * from the shared package during the build process.
     *
     * @param string $pluginFile Path to the main plugin file.
     */
    protected function enqueueModelSelectorAssets(string $pluginFile): void
    {
        $pluginData = get_file_data($pluginFile, ['Version' => 'Version']);
        $version = $pluginData['Version'] ?: '0.1.0';

        wp_enqueue_script(
            $this->config->getProviderId() . '-model-selector',
            plugins_url('assets/model-selector.js', $pluginFile),
            [],
            $version,
            true
        );

        wp_enqueue_style(
            $this->config->getProviderId() . '-model-selector',
            plugins_url('assets/model-selector.css', $pluginFile),
            [],
            $version
        );
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
