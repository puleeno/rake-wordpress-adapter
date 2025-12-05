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
                'timeout' => 30,
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

        // Pattern to match product detail URLs: /products_detail/xxx.html
        if (preg_match_all('/href=["\']([^"\']*products_detail\/[^"\']*\.html)["\']/i', $html, $matches)) {
            foreach ($matches[1] as $url) {
                // Convert relative URLs to absolute
                if (strpos($url, 'http') !== 0) {
                    $parsedBase = parse_url($baseUrl);
                    $base = $parsedBase['scheme'] . '://' . $parsedBase['host'];
                    $url = $base . $url;
                }
                $productUrls[] = $url;
            }
        }

        return array_unique($productUrls);
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

        $createdCount = 0;
        $parentUrlHash = hash('sha256', $parentUrl);

        foreach ($childUrls as $childUrl) {
            $childUrlHash = hash('sha256', $childUrl);

            // Check if reference already exists
            $exists = $wpdb->get_var($wpdb->prepare(
                "SELECT id FROM {$tableName} WHERE parent_url_hash = %s AND child_url_hash = %s",
                $parentUrlHash,
                $childUrlHash
            ));

            if ($exists) {
                continue; // Skip if already exists
            }

            // Insert new reference
            $result = $wpdb->insert(
                $tableName,
                [
                    'parent_url' => $parentUrl,
                    'parent_url_hash' => $parentUrlHash,
                    'child_url' => $childUrl,
                    'child_url_hash' => $childUrlHash,
                    'relationship_type' => 'category_product',
                    'created_at' => current_time('mysql'),
                ],
                ['%s', '%s', '%s', '%s', '%s', '%s']
            );

            if ($result !== false) {
                $createdCount++;
            }
        }

        return $createdCount;
    }
}
