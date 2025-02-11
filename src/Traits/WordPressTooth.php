<?php

namespace Puleeno\Rake\WordPress\Traits;

use Throwable;
use Exception;
use Psr\Http\Message\StreamInterface;
use Psr\Http\Client\RequestExceptionInterface;
use Automattic\WooCommerce\Internal\Admin\CategoryLookup;
use Ramphor\Rake\Resource;
use Ramphor\Rake\Facades\Request;
use Ramphor\Rake\Facades\Logger;
use Ramphor\Rake\Facades\Resources;
use PHPHtmlParser\Dom as Document;

use function media_handle_sideload;

trait WordPressTooth
{
    protected $resourceType                  = 'attachment';
    protected $maxRetryDownloadResourceTimes = 10;
    protected $usePostTitleAsImageFileName   = false;

    public function wordpressBootstrap()
    {
        $activePlugins = get_option('active_plugins', []);
        if (in_array('woocommerce/woocommerce.php', $activePlugins)) {
            Logger::debug('Load Woocommerce functions to import Product data');
            CategoryLookup::define_category_lookup_tables_in_wpdb();
        }
    }

    protected function requireWordPressSupports()
    {
        if (!function_exists('media_handle_sideload')) {
            require_once ABSPATH . 'wp-admin/includes/file.php';
            require_once ABSPATH . 'wp-admin/includes/image.php';
            require_once ABSPATH . 'wp-admin/includes/media.php';
        }
    }

    protected function resolveNewResourceType(Resource $resource)
    {
        return $this->resourceType;
    }

    public function usePostTitleAsImageFileName()
    {
        return $this->usePostTitleAsImageFileName;
    }

    protected function generateFileName($url, $realFile, $postTitle = null)
    {
        $fileName  = basename($url);
        $extension = pathinfo($fileName, PATHINFO_EXTENSION);

        if (strpos($fileName, '%') !== false) {
            $fileName = urldecode($fileName);
        }

        if (!empty($postTitle) && $this->usePostTitleAsImageFileName()) {
            $fileNameWithoutExtension = sanitize_title($postTitle);
        } elseif ($extension !== "") {
            $fileNameWithoutExtension = sanitize_title(str_replace('.' . $extension, '', $fileName));
        } else {
            $fileNameWithoutExtension = sanitize_title($fileName);
        }

        if (pl_validate_extension($extension)) {
            return sprintf('%s.%s', $fileNameWithoutExtension, $extension);
        }

        $mime         = mime_content_type($realFile);
        $newExtension = pl_convert_mime_type_to_extension($mime);

        return sprintf('%s%s', $fileNameWithoutExtension, $newExtension);
    }

    public function downloadResource(Resource &$resource): Resource
    {
        $this->requireWordPressSupports();
        Logger::debug(sprintf('Download the %s resource: %s', $resource->type, $resource->guid));
        $parentResource = null;

        try {
            $tempFile   = tmpfile();
            $response   = Request::sendRequest(
                'GET',
                $resource->guid,
                apply_filters(
                    'rake_wordpress_download_image_request_options',
                    array( 'verify' => false
                    )
                )
            );
            $stream     = $response->getBody();
            if ($stream instanceof StreamInterface && $stream->isWritable()) {
                fwrite($tempFile, $stream);
            } else {
                throw new Exception("data is not file or is not writeable");
            }

            $meta           = stream_get_meta_data($tempFile);
            $hashFile       = Resources::generateHash($meta['uri'], $resource->type);
            $newType        = $this->resolveNewResourceType($resource);
            $newGuid        = null;
            $existsResource = Resources::getFromHash($hashFile, $newType);
            if (is_null($existsResource)) {
                $parentResource = Resources::findParent($resource->id);
                $postId         = is_null($parentResource) ? 0 : (int)$parentResource->newGuid;
                $postTitle      = $this->usePostTitleAsImageFileName() ? get_the_title($postId) : null;

                $file_array = array(
                    'name'     => $this->generateFileName($resource->guid, $meta['uri'], $postTitle),
                    'tmp_name' => $meta['uri']
                );
                $newGuid        = media_handle_sideload($file_array, $postId);

                if (is_wp_error($newGuid)) {
                    // Logging in the catch block
                    throw new Exception($newGuid->get_error_message());
                }
                $resource->saveHash($hashFile, $newType, $newGuid);
                Logger::debug(sprintf(
                    'The image %s with hash %s is downloaded as %s(#%d)',
                    $resource->guid,
                    $hashFile,
                    $newType,
                    $newGuid
                ));
            } else {
                $newGuid = $existsResource->newGuid;
                $newType = $existsResource->newType;

                Logger::info(sprintf(
                    'The image hash "%s" of URL %s is already exists as %s(#%d)',
                    $hashFile,
                    $resource->guid,
                    $existsResource->newType,
                    $existsResource->newGuid
                ));
            }

            $resource->setNewType($newType);
            $resource->setNewGuid($newGuid);
            $resource->imported();
        } catch (Throwable $e) {
            Logger::warning(sprintf("%s\n%s", $e->getMessage(), var_export(array(
                'url' => $resource->guid,
                'from' => $parentResource ? $parentResource->guid : '',
                'mime_type' => isset($meta['uri']) ? mime_content_type($meta['uri']) : 'unknown',
            ), true)), (array)$resource);
            if ($e instanceof RequestExceptionInterface && is_callable([$e, 'getResponse'])) {
                $response = method_exists($e, 'getResponse')
                    ? call_user_func([$e, 'getResponse'])
                    : null;
                if ($response->getStatusCode() < 500) {
                    $resource->skip();
                }
            } elseif ($resource->retry >= $this->maxRetryDownloadResourceTimes) {
                $resource->skip();
            }
        } finally {
            // Close temporary handle
            @fclose($tempFile);
        }

        return $resource;
    }

    public function validateSystemResource($postId, $postType): bool
    {
        $post = get_post($postId);
        if (is_null($post)) {
            return false;
        }

        return $post->post_type == trim($postType);
    }

    public function updatePostResource(Resource $resource)
    {
        return wp_update_post([
            'ID' => $resource->newGuid,
            'post_type' => $resource->newType,
            'post_content' => $resource->content,
        ]);
    }

    protected function getCallbackNameFromResourceType($type)
    {
        switch ($type) {
            case 'content_image':
                return 'updateContentImage';
            case 'cover_image':
                return 'updateCoverImage';
            case 'gallery_image':
                return 'updateGalleryImage';
        }
    }

    public function updateSystemResource(Resource $resource, Resource $parentResource)
    {

        $originCallback = [
            $resource->getTooth(),
            $this->getCallbackNameFromResourceType($resource->type)
        ];
        $callback = is_null($parentResource)
            ? $originCallback
            : apply_filters("rake/system/resource/{$resource->type}/callback", $originCallback, $parentResource, $resource);

        if (is_callable($callback)) {
            return call_user_func(
                $callback,
                $parentResource,
                $resource->newGuid,
                $resource->guid,
                $resource->newType
            );
        }
    }


    public function updateContentImage(Resource $parent, $attachmentId, $oldUrl)
    {
        $dataType = $parent->newType;
        $dataTypeMaps = [
            'post' => 'post',
            'product' => 'post',
            'product_category' => 'taxonomy',
            'page' => 'post',
            'category' => 'taxonomy',
            'tag' => 'taxonomy',
        ];

        $originDataType = apply_filters(
            'crawlflow/data/type',
            array_get($dataTypeMaps, $dataType),
            $dataType,
            $parent
        );

        if ($originDataType === 'post') {
            return $this->updatePostContentOfImage($parent, $attachmentId, $oldUrl);
        }
        if ($originDataType === 'taxonomy') {
            return $this->updateTermContentOfImage($parent, $attachmentId, $oldUrl);
        }
        Logger::warning(sprintf('The data type %s is not supported', $dataType), [$parent]);
    }

    protected function updatePostContentOfImage(Resource $parent, $attachmentId, $oldUrl)
    {
        $document = new Document();
        $postId   = $parent->newGuid;
        $postType = $parent->newType;
        $post     = get_post($postId);
        if (is_null($post)) {
            Logger::warning(sprintf('The post has ID %d is not found', $postId), [
                'post_id' => $parent->newGuid,
                'post_type' => $parent->newType,
            ]);
            return;
        }

        try {
            $document->loadStr($post->post_content);
        } catch (Throwable $e) {
            // Override document content with empty string
            $document->loadStr('');

            Logger::warning($e->getMessage(), $post->to_array());
        }

        $images   = $document->find('img[src=' . $oldUrl . ']');
        foreach ($images as $image) {
            $imageUrl = wp_get_attachment_url($attachmentId);
            if ($imageUrl === false) {
                Logger::warning(sprintf(
                    'Attachment #%d is not exists so this image(%s) will be removed',
                    $attachmentId,
                    $oldUrl
                ));
                $image->delete();
                continue;
            }
            $image->setAttribute('src', $imageUrl);

            Logger::debug(sprintf('The image(%s) is replaced by new URL %s', $oldUrl, $imageUrl));
        }


        $newContent = $document->innerHtml;

        $parent->setContent($newContent);

        $parent->save();

        return wp_update_post([
            'ID' => $postId,
            'post_type' => $postType,
            'post_content' => $newContent,
        ]);
    }


    protected function updateTermContentOfImage(Resource $parent, $attachmentId, $oldUrl)
    {
        $termId = $parent->newGuid;
        $termName = apply_filters(
            'crawlflow/data/taxonomy/type',
            $parent->newType,
            $parent
        );

        $term = get_term($termId, $termName);
        if (is_null($term)) {
            Logger::warning(sprintf('The term has ID %d is not found', $termId), [$parent]);
            return;
        }

        $document = new Document();
        try {
            $document->loadStr($term->description);
        } catch (Throwable $e) {
            // Override document content with empty string
            $document->loadStr('');

            Logger::warning($e->getMessage(), $term->to_array());
        }

        $images   = $document->find('img[src=' . $oldUrl . ']');
        foreach ($images as $image) {
            $imageUrl = wp_get_attachment_url($attachmentId);
            if ($imageUrl === false) {
                Logger::warning(sprintf(
                    'Attachment #%d is not exists so this image(%s) will be removed',
                    $attachmentId,
                    $oldUrl
                ));
                $image->delete();
                continue;
            }
            $image->setAttribute('src', $imageUrl);

            Logger::debug(sprintf('The image(%s) is replaced by new URL %s', $oldUrl, $imageUrl));
        }


        $newContent = $document->innerHtml;

        // update content of resource
        $parent->setContent($newContent);
        $parent->save();

        // update description of term
        return wp_update_term($termId, $termName, [
            'description' => $newContent,
        ]);
    }

    public function updateCoverImage(Resource $postResource, $attachmentId)
    {
        if (post_type_exists($postResource->newType)) {
            do_action('rake/wordpress/cover/post/updated', $postResource->newGuid, $attachmentId);

            return update_post_meta(
                $postResource->newGuid,
                '_thumbnail_id',
                $attachmentId
            );
        } elseif (taxonomy_exists($postResource->newType)) {
            if ($postResource->newType === 'product_cat') {
                return update_term_meta(
                    $postResource->newGuid,
                    'thumbnail_id',
                    $attachmentId
                );
            }
        }
    }

    public function getDataType($resource)
    {
        if (post_type_exists($resource->newType)) {
            return $resource->newType;
        } elseif (taxonomy_exists($resource->newType)) {
            return $resource->newType;
        }
        return $resource->newType;
    }

    public function updateGalleryImage(Resource $postResource, $attachmentId)
    {
        $postId   = $postResource->newGuid;
        $postType = $this->getDataType($postResource);

        if ($postType === 'product') {
            $postThumbnailId = get_post_thumbnail_id($postId);
            if ($postThumbnailId == $attachmentId) {
                Logger::debug(sprintf(
                    'The thumbnail #%d is already exists as feature image so it is skipped add to gallery images',
                    $postThumbnailId
                ));
                return;
            }

            $galleryImages   = explode(',', get_post_meta($postId, '_product_image_gallery', true));

            // Push gallery image to list if not exists.
            if (!in_array($attachmentId, $galleryImages)) {
                $galleryImages[] = $attachmentId;

                Logger::debug(sprintf('The attachment #%d is appended to product gallery', $attachmentId));
                return update_post_meta(
                    $postId,
                    '_product_image_gallery',
                    implode(',', array_unique($galleryImages))
                );
            }
        }
    }
}
