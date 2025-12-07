<?php

namespace RamphorRake\Adapter\Processor;

use Rake\Processor\AbstractProcessor;
use Rake\Contracts\Entities\ParsedDataItemInterface;

/**
 * Collect Resources Processor
 * 
 * Extracts và lưu resources (HTML, images, files, links) từ parsed data
 * với quan hệ cha con. Tất cả resources được lưu vào dpc_rake_data_sources
 * hoặc dpc_rake_resources với parent-child relationships.
 * 
 * Resources trong Rake:
 * - HTML content: Nội dung HTML của trang
 * - Images: Hình ảnh từ trang
 * - Files: Files đính kèm (PDF, documents, etc.)
 * - Links: Internal/external links
 */
class CollectResourcesProcessor extends AbstractProcessor
{
    /**
     * Process data item and collect resources
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
            // Get parent URL (source URL)
            $parentUrl = $item->get('url') ?? $item->get('source_url') ?? '';
            
            if (empty($parentUrl)) {
                return $this->createNullItem('Parent URL is required for resource collection');
            }

            $this->log('Collecting resources', ['parent_url' => $parentUrl]);

            // Collect different types of resources
            $resources = [
                'html' => $this->collectHtmlResources($item, $parentUrl),
                'images' => $this->collectImageResources($item, $parentUrl),
                'files' => $this->collectFileResources($item, $parentUrl),
                'links' => $this->collectLinkResources($item, $parentUrl),
            ];

            // Save resources to database
            $savedCount = $this->saveResources($parentUrl, $resources);

            $this->log('Resources collected', [
                'parent_url' => $parentUrl,
                'html' => count($resources['html']),
                'images' => count($resources['images']),
                'files' => count($resources['files']),
                'links' => count($resources['links']),
                'total_saved' => $savedCount,
            ]);

            // Return item with resource info
            return $item
                ->set('resources_collected', true)
                ->set('resources_count', array_sum(array_map('count', $resources)))
                ->set('resources', $resources);

        } catch (\Exception $e) {
            $this->logError('Processing error', ['error' => $e->getMessage()]);
            return $this->createNullItem('Error: ' . $e->getMessage());
        }
    }

    /**
     * Collect HTML resources
     * 
     * @param ParsedDataItemInterface $item
     * @param string $parentUrl
     * @return array
     */
    private function collectHtmlResources(ParsedDataItemInterface $item, string $parentUrl): array
    {
        $htmlResources = [];

        // Get HTML content from various fields
        $htmlFields = [
            'html',
            'content',
            'description',
            'product_description',
            'category_description',
            'raw_html',
        ];

        foreach ($htmlFields as $field) {
            $html = $item->get($field);
            if (!empty($html) && is_string($html)) {
                $htmlResources[] = [
                    'type' => 'html',
                    'url' => $parentUrl,
                    'content' => $html,
                    'field' => $field,
                ];
            }
        }

        return $htmlResources;
    }

    /**
     * Collect image resources
     * 
     * @param ParsedDataItemInterface $item
     * @param string $parentUrl
     * @return array
     */
    private function collectImageResources(ParsedDataItemInterface $item, string $parentUrl): array
    {
        $imageResources = [];

        // Get images from various fields
        $imageFields = [
            'product_images',
            'images',
            'image_urls',
            'gallery_images',
        ];

        foreach ($imageFields as $field) {
            $images = $item->get($field);
            if (is_array($images)) {
                foreach ($images as $imageUrl) {
                    if (!empty($imageUrl) && filter_var($imageUrl, FILTER_VALIDATE_URL)) {
                        $imageResources[] = [
                            'type' => 'image',
                            'url' => $imageUrl,
                            'parent_url' => $parentUrl,
                        ];
                    }
                }
            } elseif (is_string($images) && !empty($images)) {
                // Try to extract URLs from string (HTML, JSON, etc.)
                $extracted = $this->extractUrlsFromString($images, 'image');
                $imageResources = array_merge($imageResources, $extracted);
            }
        }

        // Also extract from HTML content
        $html = $item->get('html') ?? $item->get('content') ?? $item->get('description') ?? '';
        if (!empty($html)) {
            $extracted = $this->extractImageUrlsFromHtml($html, $parentUrl);
            $imageResources = array_merge($imageResources, $extracted);
        }

        return array_unique($imageResources, SORT_REGULAR);
    }

    /**
     * Collect file resources
     * 
     * @param ParsedDataItemInterface $item
     * @param string $parentUrl
     * @return array
     */
    private function collectFileResources(ParsedDataItemInterface $item, string $parentUrl): array
    {
        $fileResources = [];

        // Get files from various fields
        $fileFields = [
            'files',
            'file_urls',
            'attachments',
            'downloads',
        ];

        foreach ($fileFields as $field) {
            $files = $item->get($field);
            if (is_array($files)) {
                foreach ($files as $fileUrl) {
                    if (!empty($fileUrl) && filter_var($fileUrl, FILTER_VALIDATE_URL)) {
                        $fileResources[] = [
                            'type' => 'file',
                            'url' => $fileUrl,
                            'parent_url' => $parentUrl,
                        ];
                    }
                }
            }
        }

        // Extract from HTML
        $html = $item->get('html') ?? $item->get('content') ?? '';
        if (!empty($html)) {
            $extracted = $this->extractFileUrlsFromHtml($html, $parentUrl);
            $fileResources = array_merge($fileResources, $extracted);
        }

        return array_unique($fileResources, SORT_REGULAR);
    }

    /**
     * Collect link resources
     * 
     * @param ParsedDataItemInterface $item
     * @param string $parentUrl
     * @return array
     */
    private function collectLinkResources(ParsedDataItemInterface $item, string $parentUrl): array
    {
        $linkResources = [];

        // Extract links from HTML
        $html = $item->get('html') ?? $item->get('content') ?? $item->get('description') ?? '';
        if (!empty($html)) {
            $links = $this->extractLinksFromHtml($html, $parentUrl);
            $linkResources = array_merge($linkResources, $links);
        }

        return array_unique($linkResources, SORT_REGULAR);
    }

    /**
     * Extract image URLs from HTML
     * 
     * @param string $html
     * @param string $parentUrl
     * @return array
     */
    private function extractImageUrlsFromHtml(string $html, string $parentUrl): array
    {
        $images = [];
        $parsedBase = parse_url($parentUrl);
        $base = $parsedBase['scheme'] . '://' . $parsedBase['host'];

        // Extract from img src
        if (preg_match_all('/<img[^>]+src=["\']([^"\']+)["\']/i', $html, $matches)) {
            foreach ($matches[1] as $src) {
                if (strpos($src, 'http') !== 0) {
                    $src = $base . '/' . ltrim($src, '/');
                }
                $images[] = [
                    'type' => 'image',
                    'url' => $src,
                    'parent_url' => $parentUrl,
                ];
            }
        }

        return $images;
    }

    /**
     * Extract file URLs from HTML
     * 
     * @param string $html
     * @param string $parentUrl
     * @return array
     */
    private function extractFileUrlsFromHtml(string $html, string $parentUrl): array
    {
        $files = [];
        $parsedBase = parse_url($parentUrl);
        $base = $parsedBase['scheme'] . '://' . $parsedBase['host'];

        // Extract file links (PDF, DOC, ZIP, etc.)
        if (preg_match_all('/<a[^>]+href=["\']([^"\']+\.(pdf|doc|docx|zip|rar|tar|gz))["\']/i', $html, $matches)) {
            foreach ($matches[1] as $url) {
                if (strpos($url, 'http') !== 0) {
                    $url = $base . '/' . ltrim($url, '/');
                }
                $files[] = [
                    'type' => 'file',
                    'url' => $url,
                    'parent_url' => $parentUrl,
                ];
            }
        }

        return $files;
    }

    /**
     * Extract links from HTML
     * 
     * @param string $html
     * @param string $parentUrl
     * @return array
     */
    private function extractLinksFromHtml(string $html, string $parentUrl): array
    {
        $links = [];
        $parsedBase = parse_url($parentUrl);
        $base = $parsedBase['scheme'] . '://' . $parsedBase['host'];

        // Extract all links
        if (preg_match_all('/<a[^>]+href=["\']([^"\']+)["\']/i', $html, $matches)) {
            foreach ($matches[1] as $url) {
                // Skip anchors, javascript, mailto, etc.
                if (preg_match('/^(#|javascript:|mailto:|tel:)/i', $url)) {
                    continue;
                }

                if (strpos($url, 'http') !== 0) {
                    $url = $base . '/' . ltrim($url, '/');
                }

                $links[] = [
                    'type' => 'link',
                    'url' => $url,
                    'parent_url' => $parentUrl,
                ];
            }
        }

        return $links;
    }

    /**
     * Extract URLs from string (HTML, JSON, etc.)
     * 
     * @param string $content
     * @param string $type Resource type
     * @return array
     */
    private function extractUrlsFromString(string $content, string $type): array
    {
        $resources = [];

        // Try to extract URLs using regex
        if (preg_match_all('/https?:\/\/[^\s<>"\'{}]+/i', $content, $matches)) {
            foreach ($matches[0] as $url) {
                $resources[] = [
                    'type' => $type,
                    'url' => $url,
                ];
            }
        }

        return $resources;
    }

    /**
     * Save resources to database
     * 
     * @param string $parentUrl
     * @param array $resources
     * @return int Number of resources saved
     */
    private function saveResources(string $parentUrl, array $resources): int
    {
        global $wpdb;
        $sourcesTable = $wpdb->prefix . 'rake_data_sources';
        $originsTable = $wpdb->prefix . 'rake_data_origins';
        $referencesTable = $wpdb->prefix . 'rake_data_origins_references';

        $savedCount = 0;

        // Get parent origin ID
        $parentOrigin = $wpdb->get_row($wpdb->prepare(
            "SELECT id FROM {$originsTable} WHERE guid = %s",
            $parentUrl
        ), ARRAY_A);

        if (!$parentOrigin) {
            return 0;
        }

        $parentOriginId = (int)$parentOrigin['id'];

        // Process each resource type
        foreach ($resources as $type => $typeResources) {
            foreach ($typeResources as $resource) {
                $resourceUrl = $resource['url'] ?? '';

                if (empty($resourceUrl)) {
                    continue;
                }

                // Find or create child origin
                $childOrigin = $wpdb->get_row($wpdb->prepare(
                    "SELECT id FROM {$originsTable} WHERE guid = %s",
                    $resourceUrl
                ), ARRAY_A);

                if (!$childOrigin) {
                    // Create child origin
                    $wpdb->insert($originsTable, [
                        'source_id' => null,
                        'guid' => $resourceUrl,
                        'raw_data' => '',
                        'fetched_at' => current_time('mysql'),
                        'source_type' => 'processor',
                        'processor_id' => 'collect_resources',
                    ]);
                    $childOriginId = (int)$wpdb->insert_id;
                } else {
                    $childOriginId = (int)$childOrigin['id'];
                }

                // Check if reference already exists
                $existing = $wpdb->get_var($wpdb->prepare(
                    "SELECT id FROM {$referencesTable} WHERE parent_origin_id = %d AND child_origin_id = %d",
                    $parentOriginId,
                    $childOriginId
                ));

                if (!$existing) {
                    $wpdb->insert($referencesTable, [
                        'parent_origin_id' => $parentOriginId,
                        'child_origin_id' => $childOriginId,
                        'relationship_type' => $type,
                        'created_at' => current_time('mysql'),
                    ]);
                    $savedCount++;
                }
            }
        }

        return $savedCount;
    }
}
