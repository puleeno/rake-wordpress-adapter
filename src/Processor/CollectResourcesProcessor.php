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
                    $
