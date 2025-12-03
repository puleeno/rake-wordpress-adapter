<?php

namespace Puleeno\Rake\WordPress\Http;

use Rake\Contracts\Http\HttpResponseInterface;

/**
 * WordPress HTTP Response
 * Wraps WordPress HTTP API response to implement Rake HttpResponseInterface
 */
class WordPressHttpResponse implements HttpResponseInterface
{
    /**
     * @var array WordPress response array
     */
    private array $response;

    /**
     * Constructor
     *
     * @param array $response WordPress response from wp_remote_request()
     */
    public function __construct(array $response)
    {
        $this->response = $response;
    }

    /**
     * Get HTTP status code
     *
     * @return int
     */
    public function getStatusCode(): int
    {
        return wp_remote_retrieve_response_code($this->response);
    }

    /**
     * Get response body
     *
     * @return string
     */
    public function getBody(): string
    {
        return wp_remote_retrieve_body($this->response);
    }

    /**
     * Get response headers
     *
     * @return array
     */
    public function getHeaders(): array
    {
        $headers = wp_remote_retrieve_headers($this->response);
        
        // Convert WP_HTTP_Requests_Utility_CaseInsensitiveDictionary to array
        if (is_object($headers) && method_exists($headers, 'getAll')) {
            return $headers->getAll();
        }
        
        // Already array
        return is_array($headers) ? $headers : [];
    }

    /**
     * Check if response is successful (2xx status code)
     *
     * @return bool
     */
    public function isSuccessful(): bool
    {
        $code = $this->getStatusCode();
        return $code >= 200 && $code < 300;
    }

    /**
     * Get response body as JSON array
     *
     * @return array
     * @throws \RuntimeException If JSON parsing fails
     */
    public function json(): array
    {
        $body = $this->getBody();
        
        if (empty($body)) {
            return [];
        }

        $decoded = json_decode($body, true);

        if (json_last_error() !== JSON_ERROR_NONE) {
            throw new \RuntimeException(
                'Failed to parse JSON response: ' . json_last_error_msg()
            );
        }

        return $decoded ?? [];
    }

    /**
     * Get header by name
     *
     * @param string $name Header name (case-insensitive)
     * @return string|null
     */
    public function getHeader(string $name): ?string
    {
        $headers = $this->getHeaders();
        
        // Case-insensitive search
        foreach ($headers as $key => $value) {
            if (strcasecmp($key, $name) === 0) {
                return is_array($value) ? implode(', ', $value) : $value;
            }
        }
        
        return null;
    }

    /**
     * Get raw WordPress response
     *
     * @return array
     */
    public function getRawResponse(): array
    {
        return $this->response;
    }
}

