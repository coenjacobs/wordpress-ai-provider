<?php

declare(strict_types=1);

namespace CoenJacobs\WordPressAiProvider\Models\OpenAiCompatible;

use WordPress\AiClient\Common\Exception\InvalidArgumentException;
use WordPress\AiClient\Files\DTO\File;
use WordPress\AiClient\Messages\DTO\Message;
use WordPress\AiClient\Messages\DTO\MessagePart;
use WordPress\AiClient\Messages\Enums\MessageRoleEnum;
use WordPress\AiClient\Providers\ApiBasedImplementation\AbstractApiBasedModel;
use WordPress\AiClient\Providers\Http\DTO\Request;
use WordPress\AiClient\Providers\Http\DTO\Response;
use WordPress\AiClient\Providers\Http\Enums\HttpMethodEnum;
use WordPress\AiClient\Providers\Http\Exception\ResponseException;
use WordPress\AiClient\Providers\Http\Util\ResponseUtil;
use WordPress\AiClient\Providers\Models\ImageGeneration\Contracts\ImageGenerationModelInterface;
use WordPress\AiClient\Results\DTO\Candidate;
use WordPress\AiClient\Results\DTO\GenerativeAiResult;
use WordPress\AiClient\Results\DTO\TokenUsage;
use WordPress\AiClient\Results\Enums\FinishReasonEnum;

/**
 * Base image generation model for providers that route image generation through chat/completions.
 *
 * Unlike the SDK's AbstractOpenAiCompatibleImageGenerationModel (which POSTs to /images/generations),
 * this class POSTs to /chat/completions with modalities: ["image"]. This is required for providers
 * like OpenRouter that route image generation through the chat completions endpoint.
 *
 * Response format: choices[].message.images[].image_url.url (base64 data URIs).
 */
abstract class ImageGenerationModel extends AbstractApiBasedModel implements ImageGenerationModelInterface
{
    /**
     * {@inheritDoc}
     */
    public function generateImageResult(array $prompt): GenerativeAiResult
    {
        $httpTransporter = $this->getHttpTransporter();
        $params = $this->prepareGenerateImageParams($prompt);
        $request = $this->createRequest(
            HttpMethodEnum::POST(),
            'chat/completions',
            ['Content-Type' => 'application/json'],
            $params
        );

        $request = $this->getRequestAuthentication()->authenticateRequest($request);
        $response = $httpTransporter->send($request);
        $this->throwIfNotSuccessful($response);

        return $this->parseResponseToGenerativeAiResult($response);
    }

    /**
     * Prepares the parameters for the chat/completions image generation request.
     *
     * @param list<Message> $prompt The prompt messages.
     * @return array<string, mixed> The request parameters.
     */
    protected function prepareGenerateImageParams(array $prompt): array
    {
        $config = $this->getConfig();

        $params = [
            'model' => $this->metadata()->getId(),
            'messages' => $this->prepareMessagesParam($prompt),
            'modalities' => ['image'],
        ];

        $candidateCount = $config->getCandidateCount();
        if ($candidateCount !== null) {
            $params['n'] = $candidateCount;
        }

        $outputMediaAspectRatio = $config->getOutputMediaAspectRatio();
        if ($outputMediaAspectRatio !== null) {
            $params['image_config'] = ['aspect_ratio' => $outputMediaAspectRatio];
        }

        $customOptions = $config->getCustomOptions();
        foreach ($customOptions as $key => $value) {
            if (isset($params[$key])) {
                throw new InvalidArgumentException(
                    sprintf('The custom option "%s" conflicts with an existing parameter.', $key)
                );
            }
            $params[$key] = $value;
        }

        return $params;
    }

    /**
     * Prepares the messages parameter for the chat/completions request.
     *
     * @param list<Message> $prompt The prompt messages.
     * @return list<array<string, mixed>> The prepared messages.
     */
    protected function prepareMessagesParam(array $prompt): array
    {
        return array_map(function (Message $message): array {
            $role = $message->getRole()->isUser() ? 'user' : 'assistant';
            $content = [];

            foreach ($message->getParts() as $part) {
                $text = $part->getText();
                if ($text !== null) {
                    $content[] = ['type' => 'text', 'text' => $text];
                }
            }

            return ['role' => $role, 'content' => $content];
        }, $prompt);
    }

    /**
     * Parses the chat/completions response into a GenerativeAiResult.
     *
     * OpenRouter returns images in choices[].message.images[].image_url.url as base64 data URIs.
     *
     * @param Response $response The HTTP response.
     * @return GenerativeAiResult The parsed result.
     */
    protected function parseResponseToGenerativeAiResult(Response $response): GenerativeAiResult
    {
        /** @var array<string, mixed> $responseData */
        $responseData = $response->getData();

        if (!isset($responseData['choices']) || !is_array($responseData['choices'])) {
            throw ResponseException::fromMissingData($this->providerMetadata()->getName(), 'choices');
        }

        $candidates = [];
        foreach ($responseData['choices'] as $index => $choiceData) {
            if (!is_array($choiceData) || array_is_list($choiceData)) {
                throw ResponseException::fromInvalidData(
                    $this->providerMetadata()->getName(),
                    "choices[{$index}]",
                    'The value must be an associative array.'
                );
            }
            $candidates[] = $this->parseResponseChoiceToCandidate($choiceData, $index);
        }

        $id = isset($responseData['id']) && is_string($responseData['id']) ? $responseData['id'] : '';

        if (isset($responseData['usage']) && is_array($responseData['usage'])) {
            $usage = $responseData['usage'];
            $tokenUsage = new TokenUsage(
                $usage['prompt_tokens'] ?? 0,
                $usage['completion_tokens'] ?? 0,
                $usage['total_tokens'] ?? 0
            );
        } else {
            $tokenUsage = new TokenUsage(0, 0, 0);
        }

        $providerMetadata = $responseData;
        unset($providerMetadata['id'], $providerMetadata['choices'], $providerMetadata['usage']);

        return new GenerativeAiResult(
            $id,
            $candidates,
            $tokenUsage,
            $this->providerMetadata(),
            $this->metadata(),
            $providerMetadata
        );
    }

    /**
     * Parses a single choice from the response into a Candidate with image File parts.
     *
     * @param array<string, mixed> $choiceData The choice data.
     * @param int $index The choice index.
     * @return Candidate The parsed candidate.
     */
    protected function parseResponseChoiceToCandidate(array $choiceData, int $index): Candidate
    {
        $finishReason = FinishReasonEnum::stop();
        if (isset($choiceData['finish_reason']) && is_string($choiceData['finish_reason'])) {
            switch ($choiceData['finish_reason']) {
                case 'stop':
                    $finishReason = FinishReasonEnum::stop();
                    break;
                case 'length':
                    $finishReason = FinishReasonEnum::length();
                    break;
                case 'content_filter':
                    $finishReason = FinishReasonEnum::contentFilter();
                    break;
            }
        }

        $messageData = $choiceData['message'] ?? [];
        if (!is_array($messageData)) {
            throw ResponseException::fromMissingData(
                $this->providerMetadata()->getName(),
                "choices[{$index}].message"
            );
        }

        $parts = $this->parseImageParts($messageData, $index);

        $message = new Message(MessageRoleEnum::model(), $parts);
        return new Candidate($message, $finishReason);
    }

    /**
     * Extracts image File parts from the message data.
     *
     * Images are returned as base64 data URIs in message.images[].image_url.url.
     *
     * @param array<string, mixed> $messageData The message data from the response.
     * @param int $choiceIndex The choice index for error messages.
     * @return list<MessagePart> The extracted image parts.
     */
    protected function parseImageParts(array $messageData, int $choiceIndex): array
    {
        $parts = [];

        if (!isset($messageData['images']) || !is_array($messageData['images'])) {
            throw ResponseException::fromMissingData(
                $this->providerMetadata()->getName(),
                "choices[{$choiceIndex}].message.images"
            );
        }

        foreach ($messageData['images'] as $imageIndex => $imageData) {
            if (!is_array($imageData)
                || !isset($imageData['image_url']['url'])
                || !is_string($imageData['image_url']['url'])
            ) {
                throw ResponseException::fromInvalidData(
                    $this->providerMetadata()->getName(),
                    "choices[{$choiceIndex}].message.images[{$imageIndex}]",
                    'Expected image_url.url to be a string.'
                );
            }

            $dataUri = $imageData['image_url']['url'];
            $file = new File($dataUri);
            $parts[] = new MessagePart($file);
        }

        return $parts;
    }

    /**
     * Throws an exception if the response is not successful.
     *
     * @param Response $response The HTTP response to check.
     */
    protected function throwIfNotSuccessful(Response $response): void
    {
        ResponseUtil::throwIfNotSuccessful($response);
    }

    /**
     * Creates a request object for the provider's API.
     *
     * @param HttpMethodEnum $method The HTTP method.
     * @param string $path The API endpoint path.
     * @param array<string, string|list<string>> $headers The request headers.
     * @param string|array<string, mixed>|null $data The request data.
     * @return Request The request object.
     */
    abstract protected function createRequest(
        HttpMethodEnum $method,
        string $path,
        array $headers = [],
        $data = null
    ): Request;
}
