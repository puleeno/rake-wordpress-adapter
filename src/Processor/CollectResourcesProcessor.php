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
            $savedCount = $this->saveResources($parentUrl, $resources, $item);

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
     * Collect image resources with detailed classification
     * 
     * @param ParsedDataItemInterface $item
     * @param string $parentUrl
     * @return array
     */
    private function collectImageResources(ParsedDataItemInterface $item, string $parentUrl): array
    {
        $imageResources = [];

        // Get images from specific fields with classification
        $imageFields = [
            'cover_image' => 'cover_image',
            'thumbnail' => 'cover_image', 
            'featured_image' => 'cover_image',
            'product_images' => 'product_image',
            'gallery_images' => 'gallery_image',
            'images' => 'content_image',
            'image_urls' => 'content_image',
        ];

        foreach ($imageFields as $field => $classification) {
            $images = $item->get($field);
            if (is_array($images)) {
                foreach ($images as $imageUrl) {
                    if (!empty($imageUrl) && filter_var($imageUrl, FILTER_VALIDATE_URL)) {
                        $imageResources[] = [
                            'type' => 'image',
                            'subtype' => $classification,
                            'url' => $imageUrl,
                            'parent_url' => $parentUrl,
                            'source_field' => $field,
                        ];
                    }
                }
            } elseif (is_string($images) && !empty($images)) {
                // Try to extract URLs from string (HTML, JSON, etc.)
                $extracted = $this->extractUrlsFromString($images, 'image', $classification);
                $imageResources = array_merge($imageResources, $extracted);
            }
        }

        // Extract from HTML content with context
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
     * Extract URLs from string (HTML, JSON, etc.) with classification
     * 
     * @param string $content
     * @param string $type Resource type
     * @param string $subtype Resource subtype (optional)
     * @return array
     */
    private function extractUrlsFromString(string $content, string $type, string $subtype = ''): array
    {
        $resources = [];

        // Try to extract URLs using regex
        if (preg_match_all('/https?:\/\/[^\s<>"\'{}]+/i', $content, $matches)) {
            foreach ($matches[0] as $url) {
                $resource = [
                    'type' => $type,
                    'url' => $url,
                ];
                
                if ($subtype) {
                    $resource['subtype'] = $subtype;
                }
                
                $resources[] = $resource;
            }
        }

        return $resources;
    }

    /**
     * Save resources to database with mime type detection
     * 
     * @param string $parentUrl
     * @param array $resources
     * @param ParsedDataItemInterface $item
     * @return int Number of resources saved
     */
    private function saveResources(string $parentUrl, array $resources, ParsedDataItemInterface $item): int
    {
        global $wpdb;
        $resourcesTable = $wpdb->prefix . 'rake_resources';

        $savedCount = 0;

        // Get parent resource ID (the imported URL)
        $parentResource = $wpdb->get_row($wpdb->prepare(
            "SELECT id FROM {$resourcesTable} WHERE guid = %s",
            $parentUrl
        ), ARRAY_A);

        if (!$parentResource) {
            return 0;
        }

        $parentResourceId = (int)$parentResource['id'];

        // Process each resource type
        foreach ($resources as $type => $typeResources) {
            foreach ($typeResources as $resource) {
                $resourceUrl = $resource['url'] ?? '';

                if (empty($resourceUrl)) {
                    continue;
                }

                // Check if resource already exists
                $existing = $wpdb->get_row($wpdb->prepare(
                    "SELECT * FROM {$resourcesTable} WHERE guid = %s",
                    $resourceUrl
                ), ARRAY_A);

                // Determine data type using mime type detection
                    $dataType = $this->determineDataTypeByUrl($resourceUrl, $resource['type'] ?? $type);
                    $subtype = $resource['subtype'] ?? '';

                if ($existing) {
                    // Update existing resource with new information
                    $this->updateExistingResource($existing, $resource, $type, $subtype, $item, $parentUrl, $parentResourceId);
                    $savedCount++;
                } else {
                    // Create new resource entry
                    $this->createNewResource($resourceUrl, $resource, $type, $subtype, $item, $parentUrl, $parentResourceId);
                    $savedCount++;
                }
            }
        }

        return $savedCount;
    }

    /**
     * Update existing resource with new information
     * 
     * @param array $existing
     * @param array $resource
     * @param string $type
     * @param string $subtype
     * @param ParsedDataItemInterface $item
     * @param string $parentUrl
     * @param int $parentResourceId
     * @return void
     */
    private function updateExistingResource(array $existing, array $resource, string $type, string $subtype, ParsedDataItemInterface $item, string $parentUrl, int $parentResourceId): void
    {
        global $wpdb;
        $resourcesTable = $wpdb->prefix . 'rake_resources';
        
        // Get updated app data type and guid
        $appDataType = $this->getAppDataType($item);
        $appGuid = $this->getAppGuid($item);
        
        // Get existing metadata and update it
        $existingMetadata = json_decode($existing['metadata'] ?? '{}', true) ?: [];
        
        // Update metadata with new information
        $updatedMetadata = array_merge($existingMetadata, [
            'source_url' => $resource['url'] ?? $existing['guid'],
            'parent_url' => $parentUrl,
            'parent_id' => $parentResourceId,
            'resource_type' => $type,
            'resource_subtype' => $subtype,
            'source_field' => $resource['source_field'] ?? '',
            'last_updated_from' => 'collect_resources_processor',
            'duplicate_update' => true
        ]);
        
        // Update the resource record
        $wpdb->update(
            $resourcesTable,
            [
                'parent_id' => $parentResourceId,
                'current_content' => $resource['content'] ?? $existing['current_content'],
                'app_data_type' => $appDataType,
                'app_guid' => $appGuid,
                'metadata' => json_encode($updatedMetadata),
                'updated_at' => current_time('mysql'),
            ],
            ['id' => $existing['id']]
        );
        
        $this->log('Resource updated (duplicate URL)', [
            'resource_id' => $existing['id'],
            'guid' => $existing['guid'],
            'app_data_type' => $appDataType,
            'app_guid' => $appGuid,
        ]);
    }

    /**
     * Create new resource entry
     * 
     * @param string $resourceUrl
     * @param array $resource
     * @param string $type
     * @param string $subtype
     * @param ParsedDataItemInterface $item
     * @param string $parentUrl
     * @param int $parentResourceId
     * @return void
     */
    private function createNewResource(string $resourceUrl, array $resource, string $type, string $subtype, ParsedDataItemInterface $item, string $parentUrl, int $parentResourceId): void
    {
        global $wpdb;
        $resourcesTable = $wpdb->prefix . 'rake_resources';
        
        // Determine data type using mime type detection
        $dataType = $this->determineDataTypeByUrl($resourceUrl, $resource['type'] ?? $type);
        
        // Get app data type and guid from parsed item metadata
        $appDataType = $this->getAppDataType($item);
        $appGuid = $this->getAppGuid($item);
        
        // Create resource entry with parent_id
        $wpdb->insert($resourcesTable, [
            'parent_id' => $parentResourceId,
            'tooth_id' => 0, // Will be set later if needed
            'data_type' => $dataType,
            'guid' => $resourceUrl,
            'current_content' => $resource['content'] ?? '',
            'app_data_type' => $appDataType,
            'app_guid' => $appGuid,
            'import_status' => 'pending',
            'import_retry' => 0,
            'imported_at' => null,
            'metadata' => json_encode([
                'source_url' => $resourceUrl,
                'parent_url' => $parentUrl,
                'parent_id' => $parentResourceId,
                'resource_type' => $type,
                'resource_subtype' => $subtype,
                'source_field' => $resource['source_field'] ?? '',
                'created_from' => 'collect_resources_processor'
            ]),
            'created_at' => current_time('mysql'),
            'updated_at' => current_time('mysql'),
        ]);
        
        $this->log('Resource created (new URL)', [
            'resource_id' => $wpdb->insert_id,
            'guid' => $resourceUrl,
            'app_data_type' => $appDataType,
            'app_guid' => $appGuid,
        ]);
    }

    /**
     * Get app data type from parsed item
     * 
     * @param ParsedDataItemInterface $item
     * @return string
     */
    private function getAppDataType(ParsedDataItemInterface $item): string
    {
        // Check for specific content types in the parsed item
        $postType = $item->get('post_type') ?? '';
        $contentType = $item->get('content_type') ?? '';
        $itemType = $item->get('type') ?? '';
        
        // Determine app data type based on content
        if ($postType === 'product' || $contentType === 'product' || $itemType === 'product') {
            return 'product';
        } elseif ($postType === 'post' || $contentType === 'post') {
            return 'post';
        } elseif ($postType === 'page' || $contentType === 'page') {
            return 'page';
        } elseif ($item->get('product_id')) {
            return 'product';
        } elseif ($item->get('post_id')) {
            return 'post';
        }
        
        // Default to 'content' if no specific type found
        return 'content';
    }

    /**
     * Get app guid from parsed item
     * 
     * @param ParsedDataItemInterface $item
     * @return string
     */
    private function getAppGuid(ParsedDataItemInterface $item): string
    {
        // Try to get ID from various fields
        $id = $item->get('id') ?? 
              $item->get('product_id') ?? 
              $item->get('post_id') ?? 
              $item->get('page_id') ?? 
              $item->get('item_id') ?? 
              $item->get('entity_id') ?? 
              '';
              
        return (string)$id;
    }

    /**
     * Determine data type by URL using mime type detection
     * 
     * @param string $url
     * @param string $defaultType
     * @return string
     */
    private function determineDataTypeByUrl(string $url, string $defaultType = 'url'): string
    {
        // Get file extension
        $path = parse_url($url, PHP_URL_PATH);
        if (!$path) {
            return $defaultType;
        }

        $extension = strtolower(pathinfo($path, PATHINFO_EXTENSION));
        
        // Image extensions
        $imageExtensions = ['jpg', 'jpeg', 'png', 'gif', 'webp', 'svg', 'bmp', 'ico', 'tiff', 'psd'];
        if (in_array($extension, $imageExtensions)) {
            return 'image';
        }

        // Video extensions
        $videoExtensions = ['mp4', 'avi', 'mov', 'wmv', 'flv', 'webm', 'mkv', 'm4v', '3gp'];
        if (in_array($extension, $videoExtensions)) {
            return 'video';
        }

        // Audio extensions
        $audioExtensions = ['mp3', 'wav', 'ogg', 'flac', 'aac', 'm4a', 'wma'];
        if (in_array($extension, $audioExtensions)) {
            return 'audio';
        }

        // Document extensions
        $documentExtensions = ['pdf', 'doc', 'docx', 'xls', 'xlsx', 'ppt', 'pptx', 'txt', 'rtf'];
        if (in_array($extension, $documentExtensions)) {
            return 'document';
        }

        // Archive extensions
        $archiveExtensions = ['zip', 'rar', '7z', 'tar', 'gz', 'bz2'];
        if (in_array($extension, $archiveExtensions)) {
            return 'archive';
        }

        // Default to provided type or url
        return $defaultType === 'url' ? 'url' : $defaultType;
    }
}
