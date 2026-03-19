<?php

declare(strict_types=1);

namespace CoenJacobs\WordPressAiProvider\Models\AnthropicCompatible;

use WordPress\AiClient\Messages\DTO\Message;
use WordPress\AiClient\Messages\DTO\MessagePart;
use WordPress\AiClient\Messages\DTO\ModelMessage;
use WordPress\AiClient\Providers\ApiBasedImplementation\AbstractApiBasedModel;
use WordPress\AiClient\Providers\Http\DTO\Request;
use WordPress\AiClient\Providers\Http\DTO\Response;
use WordPress\AiClient\Providers\Http\Enums\HttpMethodEnum;
use WordPress\AiClient\Providers\Http\Util\ResponseUtil;
use WordPress\AiClient\Providers\Models\TextGeneration\Contracts\TextGenerationModelInterface;
use WordPress\AiClient\Results\DTO\Candidate;
use WordPress\AiClient\Results\DTO\GenerativeAiResult;
use WordPress\AiClient\Results\DTO\TokenUsage;
use WordPress\AiClient\Results\Enums\FinishReasonEnum;

/**
 * Text generation model using the Anthropic Messages API format.
 *
 * Subclasses must implement createRequest() to provide the correct URL.
 */
abstract class TextGenerationModel extends AbstractApiBasedModel implements TextGenerationModelInterface
{
    public function generateTextResult(array $prompt): GenerativeAiResult
    {
        $params = $this->prepareGenerateTextParams($prompt);

        $request = $this->createRequest(
            HttpMethodEnum::POST(),
            'messages',
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
        $messages = [];

        foreach ($prompt as $message) {
            $role = $message->getRole()->isUser() ? 'user' : 'assistant';
            $content = [];

            foreach ($message->getParts() as $part) {
                $partData = $this->getMessagePartData($part);
                if ($partData !== null) {
                    $content[] = $partData;
                }
            }

            if (empty($content)) {
                continue;
            }

            $messages[] = [
                'role' => $role,
                'content' => $content,
            ];
        }

        $params = [
            'model' => $this->metadata()->getId(),
            'messages' => $messages,
            'max_tokens' => $config->getMaxTokens() ?? 4096,
        ];

        $systemInstruction = $config->getSystemInstruction();
        if ($systemInstruction !== null) {
            $params['system'] = $systemInstruction;
        }

        $temperature = $config->getTemperature();
        if ($temperature !== null) {
            $params['temperature'] = $temperature;
        }

        $topP = $config->getTopP();
        if ($topP !== null) {
            $params['top_p'] = $topP;
        }

        $topK = $config->getTopK();
        if ($topK !== null) {
            $params['top_k'] = $topK;
        }

        $stopSequences = $config->getStopSequences();
        if ($stopSequences !== null) {
            $params['stop_sequences'] = $stopSequences;
        }

        return $params;
    }

    /**
     * Parse the Anthropic Messages API response into a GenerativeAiResult.
     */
    protected function parseResponseToGenerativeAiResult(Response $response): GenerativeAiResult
    {
        $data = $response->getData();

        $candidates = [];
        $text = '';
        foreach ($data['content'] ?? [] as $block) {
            if (($block['type'] ?? '') === 'text') {
                $text .= $block['text'] ?? '';
            }
        }

        $finishReason = $this->mapFinishReason($data['stop_reason'] ?? 'end_turn');

        $candidates[] = new Candidate(
            new ModelMessage([new MessagePart($text)]),
            $finishReason,
        );

        $usage = $data['usage'] ?? [];
        $inputTokens = ($usage['input_tokens'] ?? 0)
            + ($usage['cache_creation_input_tokens'] ?? 0)
            + ($usage['cache_read_input_tokens'] ?? 0);
        $outputTokens = $usage['output_tokens'] ?? 0;

        return new GenerativeAiResult(
            $data['id'] ?? '',
            $candidates,
            new TokenUsage(
                $inputTokens,
                $outputTokens,
                $inputTokens + $outputTokens,
            ),
            $this->providerMetadata(),
            $this->metadata(),
        );
    }

    /**
     * Convert a single message part to the Anthropic content block format.
     *
     * @return array<string, mixed>|null
     */
    protected function getMessagePartData(MessagePart $part): ?array
    {
        if ($part->getType()->isText()) {
            return ['type' => 'text', 'text' => $part->getText()];
        }

        if ($part->getType()->isFile()) {
            $file = $part->getFile();
            if ($file !== null && $file->isImage() && $file->isInline()) {
                return [
                    'type' => 'image',
                    'source' => [
                        'type' => 'base64',
                        'media_type' => $file->getMimeType(),
                        'data' => $file->getBase64Data(),
                    ],
                ];
            }
        }

        return null;
    }

    private function mapFinishReason(string $reason): FinishReasonEnum
    {
        switch ($reason) {
            case 'end_turn':
            case 'stop_sequence':
                return FinishReasonEnum::stop();
            case 'max_tokens':
                return FinishReasonEnum::length();
            case 'tool_use':
                return FinishReasonEnum::toolCalls();
            case 'refusal':
                return FinishReasonEnum::contentFilter();
            default:
                return FinishReasonEnum::stop();
        }
    }
}
