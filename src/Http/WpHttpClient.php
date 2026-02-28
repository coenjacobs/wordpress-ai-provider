<?php

declare(strict_types=1);

namespace CoenJacobs\WordPressAiProvider\Http;

use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;
use Nyholm\Psr7\Response;

/**
 * Minimal PSR-18 HTTP client wrapping WordPress's wp_remote_request().
 *
 * Uses unscoped PSR interfaces provided by the AI Experiments plugin.
 * This class is only loaded when AI Experiments is installed.
 */
class WpHttpClient implements ClientInterface
{
    /**
     * Send an HTTP request via wp_remote_request() and return a PSR-7 response.
     */
    public function sendRequest(RequestInterface $request): ResponseInterface
    {
        $args = [
            'method'  => $request->getMethod(),
            'headers' => [],
            'timeout' => 60,
        ];

        foreach ($request->getHeaders() as $name => $values) {
            $args['headers'][$name] = implode(', ', $values);
        }

        $body = (string) $request->getBody();
        if ($body !== '') {
            $args['body'] = $body;
        }

        $wpResponse = wp_remote_request((string) $request->getUri(), $args);

        if (is_wp_error($wpResponse)) {
            throw new NetworkException(
                'HTTP request failed: ' . $wpResponse->get_error_message(),
                $request
            );
        }

        $statusCode = (int) wp_remote_retrieve_response_code($wpResponse);
        $responseHeaders = wp_remote_retrieve_headers($wpResponse);
        $responseBody = wp_remote_retrieve_body($wpResponse);

        $headers = [];
        if ($responseHeaders instanceof \WpOrg\Requests\Utility\CaseInsensitiveDictionary) {
            foreach ($responseHeaders as $name => $value) {
                $headers[$name] = is_array($value) ? $value : [$value];
            }
        }

        return new Response($statusCode, $headers, $responseBody);
    }
}
