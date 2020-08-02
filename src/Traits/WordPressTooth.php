<?php
namespace Puleeno\Rake\WordPress\Traits;

use Psr\Http\Message\StreamInterface;
use Ramphor\Rake\Resource;
use Ramphor\Rake\Facades\Client;
use Ramphor\Rake\Facades\Logger;
use Ramphor\Rake\Facades\Resources;
use PHPHtmlParser\Dom as Document;

use function media_handle_sideload;

trait WordPressTooth
{
    protected $resourceType = 'attachment';

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

    protected function generateFileName($url, $realFile)
    {
        $fileName  = basename($url);
        $extension = pathinfo($fileName, PATHINFO_EXTENSION);
        if ($extension !== "") {
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

        try {
            $response   = Client::request('GET', $resource->guid);
            $stream     = $response->getBody();
            $tempFile   = tmpfile();
            if ($stream instanceof StreamInterface && $stream->isWritable()) {
                fwrite($tempFile, $stream);
            } else {
                throw new \Exception("data is not file or is not writeable");
            }

            $meta           = stream_get_meta_data($tempFile);
            $hashFile       = Resources::generateHash($meta['uri'], $resource->type);
            $newType        = $this->resolveNewResourceType($resource);
            $newGuid        = null;
            $existsResource = Resources::getFromHash($hashFile, $newType);
            if (is_null($existsResource)) {
                $file_array     = array(
                    'name' => $this->generateFileName($resource->guid, $meta['uri']),
                    'tmp_name' => $meta['uri']
                );
                $parentResource = Resources::findParent($resource->id);
                $postId         = is_null($parentResource) ? 0 : (int)$parentResource->newGuid;
                $newGuid        = media_handle_sideload($file_array, $postId);

                if (is_wp_error($newGuid)) {
                    // Logging in the catch block
                    throw new \Exception($newGuid->get_error_message());
                }
                $resource->saveHash($hashFile, $newType, $newGuid);
            } else {
                $newGuid = $existsResource->newGuid;
                $newType = $existsResource->newType;
            }

            $resource->setNewType($newType);
            $resource->setNewGuid($newGuid);
            $resource->imported();
        } catch (Exception $e) {
            Logger::error($e->getMessage(), [
                'resource_id' => $resource->id,
                'guid' => $resource->guid,
                'type' => $resource->type,
            ]);
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

        return $post->post_type = trim($postType);
    }

    public function updatePostResource(Resource $resource)
    {
        return wp_update_post([
            'ID' => $resource->newGuid,
            'post_type' => $resource->newType,
            'post_content' => $resource->content,
        ]);
    }

    public function updateSystemResource(Resource $resource, Resource $parentResource)
    {
        $resourceType = preg_replace_callback(['/[_|-](\w)/', '/^(\w)/', '/\s/'], function ($match) {
            return strtoupper($match[1]);
        }, $resource->type);
        $callback = [$this, 'update' . $resourceType];

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
        $postId   = $parent->newGuid;
        $postType = $parent->newType;
        $post     = get_post($postId);

        $document = new Document();
        $document->load($post->post_content);

        $images   = $document->find('img[src='. $oldUrl.']');
        foreach ($images as $image) {
            $imageUrl = wp_get_attachment_url($attachmentId);
            if ($imageUrl === false) {
                $image->delete();
                continue;
            }
            $image->setAttribute('src', $imageUrl);
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

    public function updateCoverImage(Resource $postResource, $attachmentId)
    {
        return update_post_meta(
            $postResource->newGuid,
            '_thumbnail_id',
            $attachmentId
        );
    }

    public function getPostType($type)
    {
        return $type;
    }

    public function updateGallaryImage(Resource $postResource, $attachmentId)
    {
        $postId   = $postResource->newGuid;
        $postType = $this->getPostType($postResource->newType);

        if ($postType === 'product') {
            $postThumbnailId = get_post_thumbnail_id($postId);
            if ($postThumbnailId == $attachmentId) {
                return;
            }

            $galleryImages   = explode(',', get_post_meta($postId, '_product_image_gallery', true));
            $galleryImages[] = $attachmentId;

            return update_post_meta(
                $postId,
                '_product_image_gallery',
                implode(',', array_unique($galleryImages))
            );
        }
    }
}