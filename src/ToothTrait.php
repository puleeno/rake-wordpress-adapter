<?php
namespace Puleeno\Rake\WordPress;

use Psr\Http\Message\StreamInterface;
use Ramphor\Rake\Resource;
use Ramphor\Rake\Facades\Client;
use Ramphor\Rake\Facades\Resources;

use function download_url;
use function media_handle_sideload;

trait ToothTrait
{
    protected $resourceType = 'attachment';

    protected function requireWordPressSupports()
    {
        if (!function_exists('download_url')) {
            require_once ABSPATH . 'wp-admin/includes/file.php';
        }

        if (!function_exists('media_handle_sideload')) {
            require_once ABSPATH . "wp-admin" . '/includes/image.php';
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
            if ($stream instanceof StreamInterface && $stream->isWritable()) {
                $tempFile = tmpfile();
                $meta     = stream_get_meta_data($tempFile);
                fwrite($tempFile, $stream);
            } else {
                throw new \Exception("data is not file or is not writeable");
            }
            $hashFile       = Resources::generateHash($meta['uri'], $resource->type);
            $existsResource = Resources::getFromHash($hashFile);
            $newGuid        = null;
            $newType        = $this->resolveNewResourceType($resource);
            if (is_null($existsResource)) {
                $file_array = array(
                    'name' => $this->generateFileName($resource->guid, $meta['uri']),
                    'tmp_name' => $meta['uri']
                );
                $post_id = '0';
                $newGuid = media_handle_sideload($file_array, $post_id);
                if (is_wp_error($newGuid)) {
                    // Will logging later
                    return $resource;
                }
                $resource->saveHash($hashFile);
            } else {
                $newGuid = $existsResource->newGuid;
                $newType = $existsResource->type;
            }

            $resource->setNewType($newType);
            $resource->setNewGuid($newGuid);
            $resource->imported();

            // Close temporary handle
            @fclose($tempFile);
        } catch (Exception $e) {
            // Will logging later
        }

        return $resource;
    }
}
