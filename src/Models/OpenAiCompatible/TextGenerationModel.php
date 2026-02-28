<?php

declare(strict_types=1);

namespace CoenJacobs\WordPressAiProvider\Models\OpenAiCompatible;

use WordPress\AiClient\Providers\OpenAiCompatibleImplementation\AbstractOpenAiCompatibleTextGenerationModel;
use WordPress\AiClient\Results\DTO\Candidate;

/**
 * Base OpenAI-compatible text generation model for all provider plugins.
 *
 * Normalizes null finish_reason to 'stop', which many API gateways return
 * for completed responses. Core's abstract requires a string value.
 */
abstract class TextGenerationModel extends AbstractOpenAiCompatibleTextGenerationModel
{
    /**
     * Normalizes null finish_reason to 'stop' before parsing.
     *
     * Some upstream APIs return finish_reason as null for completed responses,
     * which is valid per the OpenAI spec but not handled by the core SDK.
     *
     * @param array $choiceData The choice data from the API response.
     * @param int $index The index of the choice in the choices array.
     */
    protected function parseResponseChoiceToCandidate(array $choiceData, int $index): Candidate
    {
        if (!isset($choiceData['finish_reason']) || !is_string($choiceData['finish_reason'])) {
            $choiceData['finish_reason'] = 'stop';
        }

        return parent::parseResponseChoiceToCandidate($choiceData, $index);
    }
}
