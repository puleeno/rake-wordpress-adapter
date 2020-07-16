<?php
namespace Puleeno\Rake\WordPress;

use Psr\Http\Message\StreamInterface;
use Ramphor\Rake\Resource;
use Ramphor\Rake\Facades\Client;

use function download_url;
use function media_handle_sideload;

trait ToothTrait
{
    protected $resoureType = 'media';

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

    protected function generateFileName($url)
    {
        return basename($url);
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
                fclose($tempFile);
            } else {
                throw new \Exception("data is not file or is not writeable");
            }

            $file_array = array(
                'name' => $this->generateFileName($resource->guid),
                'tmp_name' => $meta['uri']
            );
            $post_id = '0';
            $id      = media_handle_sideload($file_array, $post_id);
            if (is_wp_error($id)) {
                // Will logging later
                return $resource;
            }

            $resource->setNewType(
                $this->resolveNewResourceType($resource)
            );
            $resource->setNewGuid($id);
            $resource->imported();
        } catch (Exception $e) {
        }

        return $resource;
    }
}
