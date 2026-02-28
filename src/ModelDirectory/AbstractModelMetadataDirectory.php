<?php

declare(strict_types=1);

namespace CoenJacobs\WordPressAiProvider\ModelDirectory;

use CoenJacobs\WordPressAiProvider\Provider\ProviderConfig;
use WordPress\AiClient\Common\Exception\InvalidArgumentException;
use WordPress\AiClient\Messages\Enums\ModalityEnum;
use WordPress\AiClient\Providers\Contracts\ModelMetadataDirectoryInterface;
use WordPress\AiClient\Providers\Models\DTO\ModelMetadata;
use WordPress\AiClient\Providers\Models\DTO\SupportedOption;
use WordPress\AiClient\Providers\Models\Enums\CapabilityEnum;
use WordPress\AiClient\Providers\Models\Enums\OptionEnum;

/**
 * Base model metadata directory: fetches via wp_remote_get, caches in transients, filters by enabled models.
 *
 * Subclasses must implement getModelsApiUrl() and parseModelEntry().
 * Subclasses can override detectCapabilities(), buildSupportedOptions(),
 * detectInputModalities(), detectOutputModalities(), and onModelsLoadedFromCache().
 */
abstract class AbstractModelMetadataDirectory implements ModelMetadataDirectoryInterface
{
    /** @var array<string, ModelMetadata>|null Cached model metadata map. */
    private ?array $modelMetadataMap = null;

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
     * @return list<ModelMetadata>
     */
    public function listModelMetadata(): array
    {
        return array_values($this->getModelMetadataMap());
    }

    public function hasModelMetadata(string $modelId): bool
    {
        return isset($this->getModelMetadataMap()[$modelId]);
    }

    public function getModelMetadata(string $modelId): ModelMetadata
    {
        $map = $this->getModelMetadataMap();

        if (!isset($map[$modelId])) {
            throw new InvalidArgumentException(
                sprintf('Model metadata not found for model ID "%s".', $modelId)
            );
        }

        return $map[$modelId];
    }

    /**
     * @return array<string, ModelMetadata>
     */
    private function getModelMetadataMap(): array
    {
        if ($this->modelMetadataMap !== null) {
            return $this->modelMetadataMap;
        }

        $enabledModels = get_option($this->config->getEnabledModelsOption(), []);
        if (!is_array($enabledModels) || empty($enabledModels)) {
            $this->modelMetadataMap = [];
            return $this->modelMetadataMap;
        }

        $allModels = $this->fetchAllModels();
        $this->modelMetadataMap = [];

        foreach ($allModels as $model) {
            $modelId = $model['id'];

            if (!in_array($modelId, $enabledModels, true)) {
                continue;
            }

            $this->modelMetadataMap[$modelId] = new ModelMetadata(
                $modelId,
                $model['name'] ?? $modelId,
                $this->detectCapabilities($model),
                $this->buildSupportedOptions($model),
            );
        }

        return $this->modelMetadataMap;
    }

    /**
     * Fetch all models from the API (with transient cache).
     *
     * @return list<array<string, mixed>>
     */
    public function fetchAllModels(): array
    {
        $cached = get_transient($this->config->getModelsTransientKey());
        if ($cached !== false && is_array($cached)) {
            $this->onModelsLoadedFromCache($cached);
            return $cached;
        }

        $response = wp_remote_get($this->getModelsApiUrl(), [
            'timeout' => 15,
            'headers' => ['Accept' => 'application/json'],
        ]);

        if (is_wp_error($response)) {
            set_transient($this->config->getErrorTransientKey(), $response->get_error_message(), HOUR_IN_SECONDS);
            return [];
        }

        $status = wp_remote_retrieve_response_code($response);
        if ($status !== 200) {
            set_transient(
                $this->config->getErrorTransientKey(),
                sprintf('API returned HTTP %d', $status),
                HOUR_IN_SECONDS
            );
            return [];
        }

        $body = wp_remote_retrieve_body($response);
        $data = json_decode($body, true);
        if (!is_array($data)) {
            return [];
        }

        $modelList = $data['data'] ?? $data;
        if (!is_array($modelList)) {
            return [];
        }

        $models = [];
        foreach ($modelList as $rawModel) {
            if (!isset($rawModel['id']) || !is_string($rawModel['id'])) {
                continue;
            }

            $parsed = $this->parseModelEntry($rawModel);
            if ($parsed !== null) {
                $models[] = $parsed;
            }
        }

        delete_transient($this->config->getErrorTransientKey());
        set_transient($this->config->getModelsTransientKey(), $models, 10 * MINUTE_IN_SECONDS);

        return $models;
    }

    /**
     * Return the full URL to the models API endpoint.
     */
    abstract protected function getModelsApiUrl(): string;

    /**
     * Parse a raw API model entry into a normalized array.
     *
     * Must include at least 'id' and 'name' keys. Can include additional provider-specific keys.
     * Return null to skip the model.
     *
     * @param array<string, mixed> $rawModel
     * @return array<string, mixed>|null
     */
    abstract protected function parseModelEntry(array $rawModel): ?array;

    /**
     * Called when models are loaded from transient cache. Override to rebuild in-memory caches.
     *
     * @param list<array<string, mixed>> $models
     */
    protected function onModelsLoadedFromCache(array $models): void
    {
        // No-op by default.
    }

    /**
     * Detect capabilities for a model. Override to customize.
     *
     * @param array<string, mixed> $modelData
     * @return list<CapabilityEnum>
     */
    protected function detectCapabilities(array $modelData): array
    {
        return [
            CapabilityEnum::textGeneration(),
            CapabilityEnum::chatHistory(),
        ];
    }

    /**
     * Build supported options for a model. Override to customize per-format options.
     *
     * @param array<string, mixed> $modelData
     * @return list<SupportedOption>
     */
    protected function buildSupportedOptions(array $modelData): array
    {
        return [
            new SupportedOption(OptionEnum::systemInstruction()),
            new SupportedOption(OptionEnum::maxTokens()),
            new SupportedOption(OptionEnum::temperature()),
            new SupportedOption(OptionEnum::topP()),
            new SupportedOption(OptionEnum::frequencyPenalty()),
            new SupportedOption(OptionEnum::presencePenalty()),
            new SupportedOption(OptionEnum::stopSequences()),
            new SupportedOption(
                OptionEnum::inputModalities(),
                [$this->detectInputModalities($modelData)]
            ),
            new SupportedOption(
                OptionEnum::outputModalities(),
                [$this->detectOutputModalities($modelData)]
            ),
        ];
    }

    /**
     * Detect input modalities for a model. Override to use API-provided data.
     *
     * @param array<string, mixed> $modelData
     * @return list<ModalityEnum>
     */
    protected function detectInputModalities(array $modelData): array
    {
        return [ModalityEnum::text()];
    }

    /**
     * Detect output modalities for a model. Override to use API-provided data.
     *
     * @param array<string, mixed> $modelData
     * @return list<ModalityEnum>
     */
    protected function detectOutputModalities(array $modelData): array
    {
        return [ModalityEnum::text()];
    }
}
