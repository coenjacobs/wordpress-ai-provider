<?php

declare(strict_types=1);

namespace CoenJacobs\WordPressAiProvider\Provider;

/**
 * Value object holding all provider-specific configuration strings.
 *
 * Each plugin creates one of these and passes it to the shared abstract classes.
 */
class ProviderConfig
{
    private string $providerId;
    private string $providerName;
    private string $enabledModelsOption;
    private string $modelsTransientKey;
    private string $errorTransientKey;
    private string $refreshQueryParam;
    private string $refreshNonceAction;
    private string $pageSlug;
    private string $optionGroup;
    private string $sectionId;
    private string $sectionTitle;
    private string $sectionDescriptionHtml;
    private string $pageTitle;
    private string $menuTitle;
    private string $infoCardTitle;
    private string $infoCardDescription;
    private string $websiteUrl;
    private string $websiteLinkText;

    /**
     * @param array<string, string> $config
     */
    public function __construct(array $config)
    {
        $this->providerId = $config['providerId'];
        $this->providerName = $config['providerName'];
        $this->enabledModelsOption = $config['enabledModelsOption'];
        $this->modelsTransientKey = $config['modelsTransientKey'];
        $this->errorTransientKey = $config['errorTransientKey'];
        $this->refreshQueryParam = $config['refreshQueryParam'];
        $this->refreshNonceAction = $config['refreshNonceAction'];
        $this->pageSlug = $config['pageSlug'];
        $this->optionGroup = $config['optionGroup'];
        $this->sectionId = $config['sectionId'];
        $this->sectionTitle = $config['sectionTitle'];
        $this->sectionDescriptionHtml = $config['sectionDescriptionHtml'];
        $this->pageTitle = $config['pageTitle'];
        $this->menuTitle = $config['menuTitle'];
        $this->infoCardTitle = $config['infoCardTitle'];
        $this->infoCardDescription = $config['infoCardDescription'];
        $this->websiteUrl = $config['websiteUrl'];
        $this->websiteLinkText = $config['websiteLinkText'];
    }

    public function getProviderId(): string
    {
        return $this->providerId;
    }

    public function getProviderName(): string
    {
        return $this->providerName;
    }

    public function getEnabledModelsOption(): string
    {
        return $this->enabledModelsOption;
    }

    public function getModelsTransientKey(): string
    {
        return $this->modelsTransientKey;
    }

    public function getErrorTransientKey(): string
    {
        return $this->errorTransientKey;
    }

    public function getRefreshQueryParam(): string
    {
        return $this->refreshQueryParam;
    }

    public function getRefreshNonceAction(): string
    {
        return $this->refreshNonceAction;
    }

    public function getPageSlug(): string
    {
        return $this->pageSlug;
    }

    public function getOptionGroup(): string
    {
        return $this->optionGroup;
    }

    public function getSectionId(): string
    {
        return $this->sectionId;
    }

    public function getSectionTitle(): string
    {
        return $this->sectionTitle;
    }

    public function getSectionDescriptionHtml(): string
    {
        return $this->sectionDescriptionHtml;
    }

    public function getPageTitle(): string
    {
        return $this->pageTitle;
    }

    public function getMenuTitle(): string
    {
        return $this->menuTitle;
    }

    public function getInfoCardTitle(): string
    {
        return $this->infoCardTitle;
    }

    public function getInfoCardDescription(): string
    {
        return $this->infoCardDescription;
    }

    public function getWebsiteUrl(): string
    {
        return $this->websiteUrl;
    }

    public function getWebsiteLinkText(): string
    {
        return $this->websiteLinkText;
    }
}
