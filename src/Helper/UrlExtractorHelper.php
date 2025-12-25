<?php

namespace Puleeno\Rake\WordPress\Helper;

/**
 * URL Extractor Helper
 * 
 * Extracts URLs from HTML content and saves to dpc_rake_data_origins table
 * with duplicate checking and batch processing
 * 
 * Threshold settings:
 * - batch_size: Number of URLs to process in one batch (default: 100)
 * - query_delay: Delay between database queries in milliseconds (default: 10)
 */
class UrlExtractorHelper
{
    /**
     * Extract all URLs from HTML and save to database
     * 
     * @param string $html HTML content
     * @param string $baseUrl Base URL for relative links
     * @param int|null $sourceId Source ID from dpc_rake_data_origins table
     * @param array $options Options including threshold settings
     * @return array Array of extracted URLs
     */
    public static function extractAndSaveUrls(string $html, string $baseUrl, ?int $sourceId = null, array $options = []): array
    {
        $urls = self::extractUrls($html, $baseUrl);
        
        if (empty($urls)) {
            return [];
        }

        $savedUrls = self::saveUrls($urls, $sourceId, $options);
        
        return $savedUrls;
    }

    /**
     * Extract all URLs from HTML
     * 
     * @param string $html HTML content
     * @param string $baseUrl Base URL for relative links
     * @return array Array of unique URLs
     */
    public static function extractUrls(string $html, string $baseUrl): array
    {
        $urls = [];
        $parsedBase = parse_url($baseUrl);
        $base = $parsedBase['scheme'] . '://' . $parsedBase['host'];

        // Extract all href attributes
        if (preg_match_all('/href=["\']([^"\']+)["\']/i', $html, $matches)) {
            foreach ($matches[1] as $url) {
                // Skip javascript:, mailto:, tel:, etc.
                if (preg_match('/^(javascript|mailto|tel|#|data):/i', $url)) {
                    continue;
                }

                // Convert relative URLs to absolute
                if (strpos($url, 'http') !== 0) {
                    if (strpos($url, '/') === 0) {
                        $url = $base . $url;
                    } else {
                        $url = rtrim($baseUrl, '/') . '/' . ltrim($url, '/');
                    }
                }

                // Normalize URL (remove fragments, sort query params)
                $url = self::normalizeUrl($url);

                // Only include URLs from same domain
                if (self::isSameDomain($url, $baseUrl)) {
                    $urls[] = $url;
                }
            }
        }

        return array_unique($urls);
    }

    /**
     * Save URLs to database with duplicate checking and batch processing
     * 
     * @param array $urls Array of URLs
     * @param int|null $sourceId Source ID
     * @param array $options Options including threshold settings
     * @return array Array of saved URLs
     */
    public static function saveUrls(array $urls, ?int $sourceId = null, array $options = []): array
    {
        global $wpdb;
        $tableName = $wpdb->prefix . 'rake_data_origins';

        // Threshold settings
        $batchSize = $options['batch_size'] ?? 100;
        $queryDelay = ($options['query_delay'] ?? 10) * 1000; // Convert to microseconds

        $savedUrls = [];
        $now = current_time('mysql');
        $totalUrls = count($urls);
        $processed = 0;

        // Process URLs in batches
        $batches = array_chunk($urls, $batchSize);

        foreach ($batches as $batchIndex => $batch) {
            foreach ($batch as $url) {
                $urlHash = hash('sha256', $url);

                // Check if URL already exists
                $exists = $wpdb->get_var($wpdb->prepare(
                    "SELECT id FROM {$tableName} WHERE guid = %s",
                    $url
                ));

                if ($exists) {
                    $processed++;
                    continue; // Skip if already exists
                }

                // Insert new URL
                $result = $wpdb->insert(
                    $tableName,
                    [
                        'source_id' => $sourceId,
                        'guid' => $url,
                        'raw_data' => '', // Empty for now, can be filled later
                        'fetched_at' => $now,
                    ],
                    ['%d', '%s', '%s', '%s']
                );

                if ($result !== false) {
                    $savedUrls[] = $url;
                }

                $processed++;

                // Apply delay between queries (rate limiting)
                if ($queryDelay > 0 && $processed < $totalUrls) {
                    usleep($queryDelay);
                }
            }

            // Small delay between batches
            if ($batchIndex < count($batches) - 1 && $queryDelay > 0) {
                usleep($queryDelay * 2);
            }
        }

        return $savedUrls;
    }

    /**
     * Normalize URL
     * 
     * @param string $url
     * @return string
     */
    private static function normalizeUrl(string $url): string
    {
        // Remove fragment
        $url = preg_replace('/#.*$/', '', $url);

        // Parse and rebuild URL to normalize
        $parsed = parse_url($url);
        
        if (!$parsed) {
            return $url;
        }

        $normalized = '';
        if (isset($parsed['scheme'])) {
            $normalized .= $parsed['scheme'] . '://';
        }
        if (isset($parsed['host'])) {
            $normalized .= strtolower($parsed['host']);
        }
        if (isset($parsed['port'])) {
            $normalized .= ':' . $parsed['port'];
        }
        if (isset($parsed['path'])) {
            $normalized .= $parsed['path'];
        }
        if (isset($parsed['query'])) {
            // Sort query parameters
            parse_str($parsed['query'], $params);
            ksort($params);
            $normalized .= '?' . http_build_query($params);
        }

        return $normalized;
    }

    /**
     * Check if URL is from same domain
     * 
     * @param string $url
     * @param string $baseUrl
     * @return bool
     */
    private static function isSameDomain(string $url, string $baseUrl): bool
    {
        $urlHost = parse_url($url, PHP_URL_HOST);
        $baseHost = parse_url($baseUrl, PHP_URL_HOST);

        return $urlHost === $baseHost;
    }
}
