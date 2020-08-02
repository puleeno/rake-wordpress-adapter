<?php
namespace Puleeno\Rake\WordPress\Content;

use Ramphor\Rake\Abstracts\Processor;

abstract class OpencartProcessor extends Processor
{
    /**
     * Convert cached image URL with sizes generated by OpenCart to original URL
     *
     * @param string $imageUrl
     * @link http://docs.opencart.com/en-gb/system/setting/image/
     * @return string the orignal OpenCart image URL
     */
    public function convertImageUrl($imageUrl)
    {
        if (preg_match('/\/image\/cache/', $imageUrl) && preg_match('/\-\d{1,}x\d{1,}(\.\w{2,})$/')) {
            $convertedUrl = preg_replace([
                '/\/image\/cache/',
                '/\-\d{1,}x\d{1,}(\.\w{2,})$/'
            ], [
                '/image',
                '$1'
            ], $imageUrl);

            if ($imageUrl !== $convertedUrl) {
                Logger::debug('The image %s is converted to %s', $imageUrl, $convertedUrl);
            }
            return $convertedUrl;
        }
        return $imageUrl;
    }
}
