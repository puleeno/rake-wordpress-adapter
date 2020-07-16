<?php
namespace Puleeno\Rake\WordPress;

use Ramphor\Rake\Resource;

trait ToothTrait
{
    protected $resoureType = 'media';

    protected function downloadFileFromUrl($url)
    {
        return download_url();
    }

    protected function resolveNewResourceType(Resource $resource)
    {
        return $this->resourceType;
    }

    public function downloadResource(Resource $resource): Resource
    {
        $tempFile   = $this->downloadFileFromUrl($resource->guid);
        $file_array = array(
            'name' => basename($resource->guid),
            'tmp_name' => $tmp
        );

        if (is_wp_error($tmp)) {
            @unlink($file_array[ 'tmp_name' ]);
            return $tmp;
        }
        $post_id = '0';
        $id      = media_handle_sideload($file_array, $post_id);

        if (is_wp_error($id)) {
            return $resource;
        }

        $resource->setNewType(
            $this->resolveNewResourceType()
        );
        $resource->setNewGuid($id);
        $resource->imported();

        return $resource;
    }
}
