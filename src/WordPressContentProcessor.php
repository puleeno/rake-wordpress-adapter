<?php
namespace Puleeno\Rake\WordPress;

use Ramphor\Rake\Abstracts\Processor;
use PHPHtmlParser\Dom;
use PHPHtmlParser\Dom\TextNode;

abstract class WordPressContentProcessor extends Processor
{
    public function convertHtmlGalleryFromContent()
    {
        $document = new Dom();
        $document->load($this->feedItem->content);
        $gallaryImages = $this->feedItem->galleryImages;

        foreach ($document->find('div.gallery[id^="gallery"]') as $gallery) {
            $images = [];
            foreach ($gallery->find('img') as $image) {
                $parent = $image->getParent();
                if ($parent->tag->name() === "a") {
                    $image_url = $parent->getAttribute('href');
                } else {
                    $image_url = $this->convertWordPressImageSizes($image->getAttribute('src'));
                }
                $gallaryImages[]= $image_url;
                $images[] = $image_url;
            }
            
            $attributes      = apply_filters('rake_wordpress_gallery_attributes', []);
            $new_shortcode   = apply_filters('rake_wordpress_gallery_shortcode', 'gallery');
            $attributes_text = '';

            if (!empty($attributes)) {
                foreach ($attributes as $attribute => $value) {
                    $attributes_text = sprintf(' %s=%s', $attribute, $value);
                }
            }
            $gallery_shortcode = new TextNode(sprintf(
                '[%s ids="%s"]%s',
                $new_shortcode,
                implode(', ', $images),
                $attributes_text
            ));
            $gallery->getParent()->replaceChild($gallery->id(), $gallery_shortcode);
        }
        $this->feedItem->setProperty('galleryImages', $gallaryImages);
        $this->feedItem->setProperty('content', $document->innerHtml);
    }

    protected function convertWordPressImageSizes($wpImageUrl)
    {
        return preg_replace(
            '/\-\d{1,}x\d{1,}(\.\w{2,})$/',
            '$1',
            $wpImageUrl
        );
    }
}
