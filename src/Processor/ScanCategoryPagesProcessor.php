<?php

namespace RamphorRake\Adapter\Processor;

use Rake\Processor\AbstractProcessor;
use Rake\Contracts\Entities\ParsedDataItemInterface;

/**
 * Scan Category Pages Processor
 * 
 * Scans all pages of a product category to find all products
 * and creates references in dpc_rake_data_origins_references table
 */
class ScanCategoryPagesProcessor extends AbstractProcessor
{
    /**
     * Process data item and scan category pages
     * 
     * @param ParsedDataItemInterface $item Extracted data item
     * @return ParsedDataItemInterface
     */
    public function process(ParsedDataItemInterface $item): ParsedDataItemInterface
    {
        // Skip if already null item
        if ($item->isNull()) {
            return $item;
        }

        try {
            $categoryUrl = $item->get('url') ?? $item->get('category_url') ?? '';
            
            if (empty($categoryUrl)) {
                return $this->createNullItem('Category URL is required');
            }

            $this->log('Scanning category pages', ['category_url' => $categoryUrl]);

            // Get all product URLs from category
            $productUrls = $this->scanCategoryPages($categoryUrl);

            if (empty($productUrls)) {
                $this->log('No products found in category', ['category_url' => $categoryUrl]);
                return $item->set('products_scanned', true)->set('products_count', 0);
            }

            // Create references in database
            $createdCount = $this->createReferences($categoryUrl, $productUrls);

            $this->log('Category pages scanned', [
                'category_url' => $categoryUrl,
                'products_found' => count($productUrls),
                'references_created' => $createdCount
            ]);

            return $item
                ->set('products_scanned', true)
                ->set('products_count', count($productUrls))
                ->set('references_created', $createdCount)
                ->set('product_urls', $productUrls);

        } catch (\Exception $e) {
            $this->logError('Processing error', ['error' => $e->getMessage()]);
            return $this->createNullItem('Error: ' . $e->getMessage());
        }
    }

    /**
     * Scan all pages of a category to find products
     * 
     * @param string $categoryUrl Category URL
     * @return array Array of product URLs
     */
    private function scanCategoryPages(string $categoryUrl): array
    {
        $productUrls = [];
        $page = 1;
        // Threshold settings
        $maxPages = $this->getConfig('max_pages', 100);
        $pageDelay = $this->getConfig('page_delay', 500000); // microseconds
        $timeout = $this->getConfig('timeout', 30); // seconds // Limit to prevent infinite loops

        while ($page <= $maxPages) {
            $pageUrl = $this->buildPageUrl($categoryUrl, $page);
            
            $this->log('Fetching category page', ['page' => $page, 'url' => $pageUrl]);

            $response = wp_remote_get($pageUrl, [
                'timeout' => $timeout,
                'headers' => [
                    'User-Agent' => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36'
                ]
            ]);

            if (is_wp_error($response)) {
                $this->logError('Failed to fetch page', [
                    'page' => $page,
                    'error' => $response->get_error_message()
                ]);
                break;
            }

            $responseCode = wp_remote_retrieve_response_code($response);
            if ($responseCode !== 200) {
                // If 404 or other error, assume no more pages
                if ($responseCode === 404) {
                    break;
                }
                $this->logError('Unexpected response code', ['code' => $responseCode, 'page' => $page]);
                break;
            }

            $html = wp_remote_retrieve_body($response);
            $pageProductUrls = $this->extractProductUrls($html, $categoryUrl);

            if (empty($pageProductUrls)) {
                // No products found on this page, assume no more pages
                break;
            }

            $productUrls = array_merge($productUrls, $pageProductUrls);
            $page++;

            // Apply delay between pages (rate limiting)
            $delay = $this->getConfig("page_delay", 500000); // microseconds
            usleep($delay);
        }

        return array_unique($productUrls);
    }

    /**
     * Build URL for category page
     * 
     * @param string $categoryUrl Base category URL
     * @param int $page Page number
     * @return string
     */
    private function buildPageUrl(string $categoryUrl, int $page): string
    {
        if ($page === 1) {
            return $categoryUrl;
        }

        // Add page parameter (common patterns: ?page=, ?p=, /page/, etc.)
        $separator = strpos($categoryUrl, '?') !== false ? '&' : '?';
        return $categoryUrl . $separator . 'page=' . $page;
    }

    /**
     * Extract product URLs from HTML
     * 
     * @param string $html HTML content
     * @param string $baseUrl Base URL for relative links
     * @return array Array of product URLs
     */
    private function extractProductUrls(string $html, string $baseUrl): array
    {
        $productUrls = [];

        // Get product URL pattern from config (if provided)
        $productPattern = $this->getConfig('product_url_pattern', null);
        
        if ($productPattern) {
            // Use configured pattern
            if (preg_match_all($productPattern, $html, $matches)) {
                foreach ($matches[1] as $url) {
                    $url = $this->normalizeUrl($url, $baseUrl);
                    if ($url) {
                        $productUrls[] = $url;
                    }
                }
            }
        } else {
            // Default: Extract all links from HTML (generic approach)
            // This allows the system to work with any website structure
            if (preg_match_all('/href=["\']([^"\']+)["\']/i', $html, $matches)) {
                foreach ($matches[1] as $url) {
                    // Filter out common non-product URLs
                    if ($this->isProductUrl($url)) {
                        $url = $this->normalizeUrl($url, $baseUrl);
                        if ($url) {
                            $productUrls[] = $url;
                        }
                    }
                }
            }
        }

        return array_unique($productUrls);
    }

    /**
     * Normalize URL (convert relative to absolute)
     * 
     * @param string $url URL to normalize
     * @param string $baseUrl Base URL for relative links
     * @return string|null Normalized URL or null if invalid
     */
    private function normalizeUrl(string $url, string $baseUrl): ?string
    {
        // Skip anchors, javascript, mailto, etc.
        if (preg_match('/^(#|javascript:|mailto:|tel:)/i', $url)) {
            return null;
        }

        // Convert relative URLs to absolute
        if (strpos($url, 'http') !== 0) {
            $parsedBase = parse_url($baseUrl);
            if (!$parsedBase) {
                return null;
            }
            $base = $parsedBase['scheme'] . '://' . $parsedBase['host'];
            
            // Handle relative paths
            if (strpos($url, '/') === 0) {
                // Absolute path
                $url = $base . $url;
            } else {
                // Relative path
                $basePath = dirname($parsedBase['path'] ?? '/');
                $url = $base . $basePath . '/' . $url;
            }
        }

        return $url;
    }

    /**
     * Check if URL is likely a product URL
     * Filters out common non-product URLs
     * 
     * @param string $url URL to check
     * @return bool
     */
    private function isProductUrl(string $url): bool
    {
        // Skip common non-product URLs
        $excludePatterns = [
            '/^#/',
            '/^javascript:/',
            '/^mailto:/',
            '/^tel:/',
            '/\.(jpg|jpeg|png|gif|webp|svg|pdf|zip|doc|docx|xls|xlsx)$/i',
            '/\/category\//i',
            '/\/tag\//i',
            '/\/page\//i',
            '/\/search\//i',
            '/\/cart\//i',
            '/\/checkout\//i',
            '/\/account\//i',
            '/\/login\//i',
            '/\/register\//i',
        ];

        foreach ($excludePatterns as $pattern) {
            if (preg_match($pattern, $url)) {
                return false;
            }
        }

        // Get exclude patterns from config
        $configExcludes = $this->getConfig('exclude_patterns', []);
        foreach ($configExcludes as $pattern) {
            if (preg_match($pattern, $url)) {
                return false;
            }
        }

        // Get include patterns from config (if provided, only include matching URLs)
        $includePatterns = $this->getConfig('include_patterns', []);
        if (!empty($includePatterns)) {
            foreach ($includePatterns as $pattern) {
                if (preg_match($pattern, $url)) {
                    return true;
                }
            }
            return false; // If include patterns are set but none match, exclude
        }

        return true; // Default: include all URLs that don't match exclude patterns
    }

    /**
     * Create references in database
     * 
     * @param string $parentUrl Category URL
     * @param array $childUrls Product URLs
     * @return int Number of references created
     */
    private function createReferences(string $parentUrl, array $childUrls): int
    {
        global $wpdb;
        $tableName = $wpdb->prefix . 'rake_data_origins_references';
        $originsTable = $wpdb->prefix . 'rake_data_origins';

        $createdCount = 0;

        // Get parent origin ID
        $parentOrigin = $wpdb->get_row($wpdb->prepare(
            "SELECT id FROM {$originsTable} WHERE guid = %s",
            $parentUrl
        ), ARRAY_A);

        if (!$parentOrigin) {
            $this->logError('Parent origin not found', ['url' => $parentUrl]);
            return 0;
        }

        $parentOriginId = (int)$parentOrigin['id'];

        foreach ($childUrls as $childUrl) {
            // Get or create child origin ID
            $childOrigin = $wpdb->get_row($wpdb->prepare(
                "SELECT id FROM {$originsTable} WHERE guid = %s",
                $childUrl
            ), ARRAY_A);

            if (!$childOrigin) {
                // Create child origin if not exists
                $wpdb->insert($originsTable, [
                    'source_id' => null,
                    'guid' => $childUrl,
                    'raw_data' => '',
                    'fetched_at' => current_time('mysql'),
                    'created_at' => current_time('mysql'),
                    'updated_at' => current_time('mysql'),
                    'crawled' => 0,
                    'source_type' => 'processor',
                    'processor_id' => 'scan_category_pages',
                ]);
                $childOriginId = (int)$wpdb->insert_id;
            } else {
                $childOriginId = (int)$childOrigin['id'];
            }

            // Check if reference already exists
            $exists = $wpdb->get_var($wpdb->prepare(
                "SELECT id FROM {$tableName} WHERE parent_origin_id = %d AND child_origin_id = %d AND relationship_type = %s",
                $parentOriginId,
                $childOriginId,
                'category_product'
            ));

            if ($exists) {
                continue; // Skip if already exists
            }

            // Insert new reference
            $result = $wpdb->insert(
                $tableName,
                [
                    'parent_origin_id' => $parentOriginId,
                    'child_origin_id' => $childOriginId,
                    'relationship_type' => 'category_product',
                    'created_at' => current_time('mysql'),
                ],
                ['%d', '%d', '%s', '%s']
            );

            if ($result !== false) {
                $createdCount++;
            }
        }

        return $createdCount;
    }
}
