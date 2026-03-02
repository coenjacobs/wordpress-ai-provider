<?php

declare(strict_types=1);

namespace CoenJacobs\WordPressAiProvider\Models\GoogleCompatible;

use WordPress\AiClient\Messages\DTO\Message;
use WordPress\AiClient\Messages\DTO\MessagePart;
use WordPress\AiClient\Messages\DTO\ModelMessage;
use WordPress\AiClient\Providers\ApiBasedImplementation\AbstractApiBasedModel;
use WordPress\AiClient\Providers\Http\DTO\Request;
use WordPress\AiClient\Providers\Http\DTO\Response;
use WordPress\AiClient\Providers\Http\Enums\HttpMethodEnum;
use WordPress\AiClient\Providers\Http\Util\ResponseUtil;
use WordPress\AiClient\Providers\Models\TextGeneration\Contracts\TextGenerationModelInterface;
use Generator;
use RuntimeException;
use WordPress\AiClient\Results\DTO\Candidate;
use WordPress\AiClient\Results\DTO\GenerativeAiResult;
use WordPress\AiClient\Results\DTO\TokenUsage;
use WordPress\AiClient\Results\Enums\FinishReasonEnum;

/**
 * Text generation model using the Google Gemini generateContent API format.
 *
 * Subclasses must implement createRequest() to provide the correct URL.
 */
abstract class TextGenerationModel extends AbstractApiBasedModel implements TextGenerationModelInterface
{
    /**
     * @param Message[] $prompt
     */
    public function streamGenerateTextResult(array $prompt): Generator
    {
        throw new RuntimeException('Streaming is not yet implemented.');
    }

    public function generateTextResult(array $prompt): GenerativeAiResult
    {
        $params = $this->prepareGenerateTextParams($prompt);
        $modelId = $this->metadata()->getId();

        $request = $this->createRequest(
            HttpMethodEnum::POST(),
            'models/' . $modelId . ':generateContent',
            ['Content-Type' => 'application/json'],
            $params
        );

        $request = $this->getRequestAuthentication()->authenticateRequest($request);
        $response = $this->getHttpTransporter()->send($request);
        ResponseUtil::throwIfNotSuccessful($response);

        return $this->parseResponseToGenerativeAiResult($response);
    }

    /**
     * Create an HTTP request for this provider.
     *
     * @param HttpMethodEnum $method
     * @param string $path
     * @param array<string, string> $headers
     * @param array<string, mixed>|null $data
     * @return Request
     */
    abstract protected function createRequest(
        HttpMethodEnum $method,
        string $path,
        array $headers = [],
        $data = null
    ): Request;

    /**
     * @param Message[] $prompt
     * @return array<string, mixed>
     */
    protected function prepareGenerateTextParams(array $prompt): array
    {
        $config = $this->getConfig();
        $contents = [];

        foreach ($prompt as $message) {
            $role = $message->getRole()->isUser() ? 'user' : 'model';
            $parts = [];

            foreach ($message->getParts() as $part) {
                $partData = $this->getMessagePartData($part);
                if ($partData !== null) {
                    $parts[] = $partData;
                }
            }

            if (empty($parts)) {
                continue;
            }

            $contents[] = [
                'role' => $role,
                'parts' => $parts,
            ];
        }

        $params = [
            'contents' => $contents,
        ];

        $systemInstruction = $config->getSystemInstruction();
        if ($systemInstruction !== null) {
            $params['systemInstruction'] = [
                'parts' => [['text' => $systemInstruction]],
            ];
        }

        $generationConfig = [];

        $maxTokens = $config->getMaxTokens();
        if ($maxTokens !== null) {
            $generationConfig['maxOutputTokens'] = $maxTokens;
        }

        $temperature = $config->getTemperature();
        if ($temperature !== null) {
            $generationConfig['temperature'] = $temperature;
        }

        $topP = $config->getTopP();
        if ($topP !== null) {
            $generationConfig['topP'] = $topP;
        }

        $topK = $config->getTopK();
        if ($topK !== null) {
            $generationConfig['topK'] = $topK;
        }

        $stopSequences = $config->getStopSequences();
        if ($stopSequences !== null) {
            $generationConfig['stopSequences'] = $stopSequences;
        }

        if (!empty($generationConfig)) {
            $params['generationConfig'] = $generationConfig;
        }

        return $params;
    }

    /**
     * Parse the Google Gemini generateContent API response into a GenerativeAiResult.
     */
    protected function parseResponseToGenerativeAiResult(Response $response): GenerativeAiResult
    {
        $data = $response->getData();

        $candidates = [];
        foreach ($data['candidates'] ?? [] as $candidate) {
            $text = '';
            foreach ($candidate['content']['parts'] ?? [] as $part) {
                if (isset($part['text'])) {
                    $text .= $part['text'];
                }
            }

            $finishReason = $this->mapFinishReason($candidate['finishReason'] ?? 'STOP');

            $candidates[] = new Candidate(
                new ModelMessage([new MessagePart($text)]),
                $finishReason,
            );
        }

        $usageMetadata = $data['usageMetadata'] ?? [];
        $promptTokens = $usageMetadata['promptTokenCount'] ?? 0;
        $completionTokens = ($usageMetadata['candidatesTokenCount'] ?? 0)
            + ($usageMetadata['thoughtsTokenCount'] ?? 0);

        return new GenerativeAiResult(
            '',
            $candidates,
            new TokenUsage(
                $promptTokens,
                $completionTokens,
                $promptTokens + $completionTokens,
            ),
            $this->providerMetadata(),
            $this->metadata(),
        );
    }

    /**
     * Convert a single message part to the Google Gemini parts format.
     *
     * @return array<string, mixed>|null
     */
    protected function getMessagePartData(MessagePart $part): ?array
    {
        if ($part->getType()->isText()) {
            return ['text' => $part->getText()];
        }

        if ($part->getType()->isFile()) {
            $file = $part->getFile();
            if ($file === null) {
                return null;
            }

            if ($file->isInline()) {
                return [
                    'inlineData' => [
                        'mimeType' => $file->getMimeType(),
                        'data' => $file->getBase64Data(),
                    ],
                ];
            }

            if ($file->isRemote()) {
                return [
                    'fileData' => [
                        'mimeType' => $file->getMimeType(),
                        'fileUri' => $file->getUrl(),
                    ],
                ];
            }
        }

        return null;
    }

    private function mapFinishReason(string $reason): FinishReasonEnum
    {
        switch ($reason) {
            case 'STOP':
                return FinishReasonEnum::stop();
            case 'MAX_TOKENS':
                return FinishReasonEnum::length();
            case 'SAFETY':
            case 'RECITATION':
            case 'BLOCKLIST':
            case 'PROHIBITED_CONTENT':
            case 'SPII':
                return FinishReasonEnum::contentFilter();
            default:
                return FinishReasonEnum::stop();
        }
    }
}
