<?php
namespace Puleeno\Rake\WordPress;

use Psr\Http\Message\StreamInterface;
use Ramphor\Rake\Resource;
use Ramphor\Rake\Facades\Client;
use Ramphor\Rake\Facades\Resources;
use Ramphor\Rake\Facades\Document;

use function media_handle_sideload;

trait ToothTrait
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
        if (pl_validate_extension($extension)) {
            return $fileName;
        }

        $mime = mime_content_type($realFile);
        $newExtension = pl_convert_mime_type_to_extension($mime);
        if (empty($extension)) {
            return sprintf('%s%s', $fileName, $newExtension);
        }

        return str_replace($extension, ltrim($newExtension, '.'), $fileName);
    }

    public function downloadResource(Resource $resource): Resource
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
                $file_array = array(
                    'name' => $this->generateFileName($resource->guid, $meta['uri']),
                    'tmp_name' => $meta['uri']
                );
                $postId = '0';
                $newGuid = media_handle_sideload($file_array, $postId);
                if (is_wp_error($newGuid)) {
                    // Will logging later
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
            // Will logging later
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
        $document = Document::load($post->post_content);
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

    public function updateCoverImage(Resource $resource, $attachmentId)
    {
        return update_post_meta(
            $resource->newGuid,
            '_thumbnail_id',
            $attachmentId
        );
    }

    public function getPostType($type)
    {
        return $type;
    }

    public function updateGallaryImage(Resource $resource, $attachmentId)
    {
        $postId   = $resource->newGuid;
        $postType = $this->getPostType($resource->newType);

        if ($postType === 'product') {
            $postThumbnailId = get_post_thumbnail_id($postId);
            if ($postThumbnailId === $attachmentId) {
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
