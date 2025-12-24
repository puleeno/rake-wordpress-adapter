<?php

namespace Puleeno\Rake\WordPress\File;

use Rake\Contracts\File\FileDownloaderClientInterface;

/**
 * WordPress File Downloader Client
 * 
 * Sử dụng WordPress wp_remote_get() và wp_remote_head() để download files.
 * Tích hợp sẵn với WordPress HTTP API và filters.
 */
class WordPressFileDownloaderClient implements FileDownloaderClientInterface
{
    /**
     * Default options cho download
     *
     * @var array
     */
    private $defaultOptions = [
        'timeout' => 30,
        'user-agent' => 'WordPress/Rake File Downloader',
        'sslverify' => true,
        'stream' => false,
        'decompress' => true
    ];

    /**
     * Constructor
     *
     * @param array $defaultOptions Override default options
     */
    public function __construct(array $defaultOptions = [])
    {
        if (!empty($defaultOptions)) {
            $this->defaultOptions = array_merge($this->defaultOptions, $defaultOptions);
        }
    }

    /**
     * Download file từ URL và lưu vào đường dẫn chỉ định
     *
     * @param string $url URL của file cần download
     * @param string $destinationPath Đường dẫn lưu file
     * @param array $options Options cho download (timeout, headers, etc.)
     * @return array
     */
    public function downloadFile(string $url, string $destinationPath, array $options = []): array
    {
        // Merge options
        $requestOptions = array_merge($this->defaultOptions, $options);
        $requestOptions['stream'] = true;
        $requestOptions['filename'] = $destinationPath;

        // WordPress wp_remote_get với stream
        $response = wp_remote_get($url, $requestOptions);

        // Check for errors
        if (is_wp_error($response)) {
            return [
                'success' => false,
                'file_path' => null,
                'error' => $response->get_error_message(),
                'file_size' => 0,
                'mime_type' => null
            ];
        }

        // Get response code
        $responseCode = wp_remote_retrieve_response_code($response);
        if ($responseCode !== 200) {
            return [
                'success' => false,
                'file_path' => null,
                'error' => "HTTP {$responseCode}: " . wp_remote_retrieve_response_message($response),
                'file_size' => 0,
                'mime_type' => null
            ];
        }

        // Verify file was created
        if (!file_exists($destinationPath)) {
            return [
                'success' => false,
                'file_path' => null,
                'error' => 'File download completed but file not found at destination',
                'file_size' => 0,
                'mime_type' => null
            ];
        }

        // Get file info
        $fileSize = filesize($destinationPath);
        $mimeType = wp_remote_retrieve_header($response, 'content-type');

        // Calculate checksum if requested (for integrity verification)
        $checksum = null;
        if (!empty($options['calculate_checksum']) || !empty($options['verify_checksum'])) {
            try {
                // Use hash function directly (XXH128 if available)
                if (function_exists('hash') && in_array('xxh128', hash_algos())) {
                    $content = file_get_contents($destinationPath);
                    if ($content !== false) {
                        $checksum = hash('xxh128', $content);
                    }
                }
            } catch (\Exception $e) {
                // Checksum calculation failed, but do not fail the download
                \Rake\Facade\Logger::warning('WordPressFileDownloaderClient: Failed to calculate checksum: ' . $e->getMessage());
            }
        }

        // Verify checksum if provided
        if (!empty($options['expected_checksum']) && $checksum) {
            if ($checksum !== $options['expected_checksum']) {
                @unlink($destinationPath); // Remove corrupted file
                return [
                    'success' => false,
                    'file_path' => null,
                    'error' => 'Checksum verification failed: file may be corrupted',
                    'file_size' => 0,
                    'mime_type' => null,
                    'checksum' => $checksum,
                    'expected_checksum' => $options['expected_checksum']
                ];
            }
        }

        $result = [
            'success' => true,
            'file_path' => $destinationPath,
            'error' => null,
            'file_size' => $fileSize,
            'mime_type' => $mimeType ?: $this->getMimeTypeFromFile($destinationPath)
        ];

        // Include checksum in result if calculated
        if ($checksum) {
            $result['checksum'] = $checksum;
        }

        return $result;
    }

    /**
     * Download file và trả về content (không lưu vào disk)
     *
     * @param string $url URL của file cần download
     * @param array $options Options cho download
     * @return array
     */
    public function downloadContent(string $url, array $options = []): array
    {
        // Merge options
        $requestOptions = array_merge($this->defaultOptions, $options);
        $requestOptions['stream'] = false; // Get body directly

        // WordPress wp_remote_get
        $response = wp_remote_get($url, $requestOptions);

        // Check for errors
        if (is_wp_error($response)) {
            return [
                'success' => false,
                'content' => null,
                'error' => $response->get_error_message(),
                'content_length' => 0,
                'mime_type' => null
            ];
        }

        // Get response code
        $responseCode = wp_remote_retrieve_response_code($response);
        if ($responseCode !== 200) {
            return [
                'success' => false,
                'content' => null,
                'error' => "HTTP {$responseCode}: " . wp_remote_retrieve_response_message($response),
                'content_length' => 0,
                'mime_type' => null
            ];
        }

        // Get content
        $content = wp_remote_retrieve_body($response);
        $contentLength = strlen($content);
        $mimeType = wp_remote_retrieve_header($response, 'content-type');

        return [
            'success' => true,
            'content' => $content,
            'error' => null,
            'content_length' => $contentLength,
            'mime_type' => $mimeType
        ];
    }

    /**
     * Kiểm tra URL có accessible không (HEAD request)
     *
     * @param string $url
     * @return bool
     */
    public function isUrlAccessible(string $url): bool
    {
        $response = wp_remote_head($url, [
            'timeout' => 10,
            'sslverify' => $this->defaultOptions['sslverify']
        ]);

        if (is_wp_error($response)) {
            return false;
        }

        $responseCode = wp_remote_retrieve_response_code($response);
        return $responseCode >= 200 && $responseCode < 400;
    }

    /**
     * Lấy file info mà không download (HEAD request)
     *
     * @param string $url
     * @return array
     */
    public function getFileInfo(string $url): array
    {
        $response = wp_remote_head($url, [
            'timeout' => 10,
            'sslverify' => $this->defaultOptions['sslverify']
        ]);

        if (is_wp_error($response)) {
            return [
                'success' => false,
                'file_size' => null,
                'mime_type' => null,
                'last_modified' => null,
                'error' => $response->get_error_message()
            ];
        }

        $responseCode = wp_remote_retrieve_response_code($response);
        if ($responseCode !== 200) {
            return [
                'success' => false,
                'file_size' => null,
                'mime_type' => null,
                'last_modified' => null,
                'error' => "HTTP {$responseCode}: " . wp_remote_retrieve_response_message($response)
            ];
        }

        $headers = wp_remote_retrieve_headers($response);

        return [
            'success' => true,
            'file_size' => isset($headers['content-length']) ? (int) $headers['content-length'] : null,
            'mime_type' => $headers['content-type'] ?? null,
            'last_modified' => $headers['last-modified'] ?? null,
            'error' => null
        ];
    }

    /**
     * Get MIME type from file
     *
     * @param string $filePath
     * @return string|null
     */
    private function getMimeTypeFromFile(string $filePath): ?string
    {
        if (!file_exists($filePath)) {
            return null;
        }

        // Use WordPress function if available
        if (function_exists('wp_check_filetype')) {
            $fileType = wp_check_filetype($filePath);
            return $fileType['type'] ?? null;
        }

        // Fallback to PHP
        if (function_exists('mime_content_type')) {
            return mime_content_type($filePath) ?: null;
        }

        return null;
    }

    /**
     * Set default option value
     *
     * @param string $key
     * @param mixed $value
     * @return self
     */
    public function setDefaultOption(string $key, $value): self
    {
        $this->defaultOptions[$key] = $value;
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

    /**
     * Download file và sideload vào WordPress media library
     *
     * @param string $url URL của file cần download
     * @param array $options Options bổ sung
     *   - 'post_id' => int: ID của post để attach file (optional)
     *   - 'title' => string: Title cho attachment (optional)
     *   - 'description' => string: Description cho attachment (optional)
     *   - 'alt_text' => string: Alt text cho image (optional)
     * @return array ['success' => bool, 'attachment_id' => int|null, 'url' => string|null, 'error' => string|null, 'attachment' => array|null]
     */
    public function sideloadToMediaLibrary(string $url, array $options = []): array
    {
        // Require WordPress media functions
        if (!function_exists('media_handle_sideload')) {
            require_once ABSPATH . 'wp-admin/includes/media.php';
            require_once ABSPATH . 'wp-admin/includes/file.php';
            require_once ABSPATH . 'wp-admin/includes/image.php';
        }

        // Download file to temp location
        $tempResult = $this->downloadContent($url, [
            'timeout' => $options['timeout'] ?? 30
        ]);

        if (!$tempResult['success']) {
            return [
                'success' => false,
                'attachment_id' => null,
                'url' => null,
                'error' => $tempResult['error'],
                'attachment' => null
            ];
        }

        // Create temp file
        $tempFile = wp_tempnam($url);
        file_put_contents($tempFile, $tempResult['content']);

        // Prepare file array for media_handle_sideload
        $filename = $this->getFilenameFromUrl($url, $options['filename'] ?? null);
        $fileArray = [
            'name' => $filename,
            'tmp_name' => $tempFile,
            'type' => $tempResult['mime_type'] ?? $this->guessMimeType($filename),
            'size' => $tempResult['content_length'] ?? 0,
            'error' => 0
        ];

        // Get post ID to attach (if provided)
        $postId = $options['post_id'] ?? 0;

        // Prepare post data for attachment
        $attachmentData = [];
        if (!empty($options['title'])) {
            $attachmentData['post_title'] = $options['title'];
        }
        if (!empty($options['description'])) {
            $attachmentData['post_content'] = $options['description'];
        }

        // Sideload file
        $attachmentId = media_handle_sideload($fileArray, $postId, null, $attachmentData);

        // Check for errors
        if (is_wp_error($attachmentId)) {
            @unlink($tempFile); // Clean up
            return [
                'success' => false,
                'attachment_id' => null,
                'url' => null,
                'error' => $attachmentId->get_error_message(),
                'attachment' => null
            ];
        }

        // Set alt text for images
        if (!empty($options['alt_text'])) {
            update_post_meta($attachmentId, '_wp_attachment_image_alt', $options['alt_text']);
        }

        // Get attachment URL and metadata
        $attachmentUrl = wp_get_attachment_url($attachmentId);
        $attachmentMeta = wp_get_attachment_metadata($attachmentId);

        return [
            'success' => true,
            'attachment_id' => $attachmentId,
            'url' => $attachmentUrl,
            'error' => null,
            'attachment' => [
                'id' => $attachmentId,
                'url' => $attachmentUrl,
                'title' => get_the_title($attachmentId),
                'mime_type' => get_post_mime_type($attachmentId),
                'metadata' => $attachmentMeta
            ]
        ];
    }

    /**
     * Get filename from URL
     *
     * @param string $url
     * @param string|null $fallback
     * @return string
     */
    private function getFilenameFromUrl(string $url, ?string $fallback = null): string
    {
        // Parse URL
        $parsed = parse_url($url);
        $path = $parsed['path'] ?? '';
        
        // Get basename
        $filename = basename($path);
        
        // Remove query params
        $filename = preg_replace('/\?.*/', '', $filename);
        
        // If empty, use fallback or generate
        if (empty($filename)) {
            if ($fallback) {
                return $fallback;
            }
            return 'file_' . time() . '.jpg'; // Default extension
        }
        
        return $filename;
    }

    /**
     * Guess MIME type from filename
     *
     * @param string $filename
     * @return string
     */
    private function guessMimeType(string $filename): string
    {
        $extension = strtolower(pathinfo($filename, PATHINFO_EXTENSION));
        
        $mimeTypes = [
            'jpg' => 'image/jpeg',
            'jpeg' => 'image/jpeg',
            'png' => 'image/png',
            'gif' => 'image/gif',
            'webp' => 'image/webp',
            'svg' => 'image/svg+xml',
            'pdf' => 'application/pdf',
            'zip' => 'application/zip',
            'mp4' => 'video/mp4',
            'mp3' => 'audio/mpeg',
            'csv' => 'text/csv',
            'json' => 'application/json',
            'xml' => 'application/xml',
        ];
        
        return $mimeTypes[$extension] ?? 'application/octet-stream';
    }

    /**
     * Sideload multiple files vào media library
     *
     * @param array $urls Array of URLs
     * @param array $options Global options (applied to all)
     * @return array
     */
    public function sideloadMultiple(array $urls, array $options = []): array
    {
        $results = [];
        $successCount = 0;
        $failureCount = 0;

        foreach ($urls as $url) {
            $result = $this->sideloadToMediaLibrary($url, $options);
            $results[] = array_merge(['url' => $url], $result);

            if ($result['success']) {
                $successCount++;
            } else {
                $failureCount++;
            }
        }

        return [
            'success' => $failureCount === 0,
            'total' => count($urls),
            'success_count' => $successCount,
            'failure_count' => $failureCount,
            'results' => $results
        ];
    }

}