<?php

declare(strict_types=1);

namespace CoenJacobs\WordPressAiProvider\Admin;

use CoenJacobs\WordPressAiProvider\Provider\ProviderConfig;

/**
 * Base admin settings page with form, info card, and refresh button.
 */
abstract class AbstractSettingsPage
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

    public function registerMenu(): void
    {
        add_options_page(
            $this->config->getPageTitle(),
            $this->config->getMenuTitle(),
            'manage_options',
            $this->config->getPageSlug(),
            [$this, 'renderPage']
        );
    }

    public function renderPage(): void
    {
        if (!current_user_can('manage_options')) {
            return;
        }

        settings_errors($this->config->getPageSlug());

        echo '<div class="wrap">';
        echo '<h1>' . esc_html($this->config->getPageTitle()) . '</h1>';
        echo '<form method="post" action="options.php">';

        settings_fields($this->config->getOptionGroup());
        do_settings_sections($this->config->getPageSlug());
        submit_button('Save Settings');

        echo '</form>';

        $this->renderInfoCard();

        echo '</div>';
    }

    protected function renderInfoCard(): void
    {
        $refresh_url = wp_nonce_url(
            add_query_arg(
                [
                    'page' => $this->config->getPageSlug(),
                    $this->config->getRefreshQueryParam() => '1',
                ],
                admin_url('options-general.php')
            ),
            $this->config->getRefreshNonceAction()
        );

        echo '<div class="card" style="max-width: 600px; margin-top: 20px;">';
        echo '<h2>' . esc_html($this->config->getInfoCardTitle()) . '</h2>';
        echo '<p>' . esc_html($this->config->getInfoCardDescription()) . '</p>';
        echo '<p>';
        echo '<a href="' . esc_url($refresh_url) . '" class="button">Refresh Model List</a> ';
        echo '<a href="' . esc_url($this->config->getWebsiteUrl()) . '" class="button" target="_blank"'
            . ' rel="noopener noreferrer">' . esc_html($this->config->getWebsiteLinkText()) . '</a>';
        echo '</p>';
        echo '</div>';
    }
}
