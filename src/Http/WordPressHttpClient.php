<?php

namespace Puleeno\Rake\WordPress\Http;

use Rake\Contracts\Http\HttpClientInterface;
use Rake\Contracts\Http\HttpResponseInterface;
use Puleeno\Rake\WordPress\Http\WordPressHttpResponse;

/**
 * WordPress HTTP Client
 * Implements Rake HttpClientInterface using WordPress HTTP API
 * 
 * This adapter allows Rake framework to make HTTP requests using WordPress functions
 */
class WordPressHttpClient implements HttpClientInterface
{
    /**
     * @var array Default request options
     */
    private array $defaultOptions = [
        'timeout' => 30,
        'user-agent' => 'Rake-WordPress-Adapter/2.0',
        'sslverify' => false,
    ];

    /**
     * Constructor
     * 
     * @param array $defaultOptions Default options for all requests
     */
    public function __construct(array $defaultOptions = [])
    {
        $this->defaultOptions = array_merge($this->defaultOptions, $defaultOptions);
    }

    /**
     * Send a GET request
     *
     * @param string $url URL to request
     * @param array $options Request options
     * @return HttpResponseInterface
     * @throws \RuntimeException If request fails
     */
    public function get(string $url, array $options = []): HttpResponseInterface
    {
        return $this->request('GET', $url, $options);
    }

    /**
     * Send a POST request
     *
     * @param string $url URL to request
     * @param array $options Request options
     * @return HttpResponseInterface
     * @throws \RuntimeException If request fails
     */
    public function post(string $url, array $options = []): HttpResponseInterface
    {
        return $this->request('POST', $url, $options);
    }

    /**
     * Send a generic HTTP request
     *
     * @param string $method HTTP method (GET, POST, PUT, DELETE, etc.)
     * @param string $url URL to request
     * @param array $options Request options
     * @return HttpResponseInterface
     * @throws \RuntimeException If request fails
     */
    public function request(string $method, string $url, array $options = []): HttpResponseInterface
    {
        // Merge with default options
        $options = array_merge($this->defaultOptions, $options);

        // Prepare WordPress request args
        $args = [
            'method' => strtoupper($method),
            'timeout' => $options['timeout'] ?? 30,
            'user-agent' => $options['user-agent'] ?? $options['user_agent'] ?? $this->defaultOptions['user-agent'],
            'sslverify' => $options['sslverify'] ?? $options['verify'] ?? $this->defaultOptions['sslverify'],
            'headers' => $options['headers'] ?? [],
        ];

        // Handle request body
        if (isset($options['json'])) {
            // JSON body
            $args['body'] = json_encode($options['json']);
            $args['headers']['Content-Type'] = 'application/json';
        } elseif (isset($options['form_params'])) {
            // Form data
            $args['body'] = $options['form_params'];
        } elseif (isset($options['body'])) {
            // Raw body
            $args['body'] = $options['body'];
        }

        // Add query parameters
        if (isset($options['query'])) {
            $url = add_query_arg($options['query'], $url);
        }

        // Make WordPress HTTP request
        $response = wp_remote_request($url, $args);

        // Handle WordPress errors
        if (is_wp_error($response)) {
            throw new \RuntimeException(
                'HTTP request failed: ' . $response->get_error_message()
            );
        }

        // Return wrapped response
        return new WordPressHttpResponse($response);
    }

    /**
     * Set default options
     *
     * @param array $options Options to merge with defaults
     * @return self
     */
    public function setDefaultOptions(array $options): self
    {
        $this->defaultOptions = array_merge($this->defaultOptions, $options);
        return $this;
    }

    /**
     * Get default options
     *
     * @return array
     */
    public function getDefaultOptions(): array
    {
        return $this->defaultOptions;
    }
}

