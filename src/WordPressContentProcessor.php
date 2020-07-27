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
            $images     = [];
            $attributes = [];
            $classes    = $gallery->getAttribute('class');

            foreach ($gallery->find('img') as $image) {
                $parent = $image->getParent();
                if ($parent->tag->name() === "a") {
                    $image_url = $parent->getAttribute('href');
                    if (!preg_match('/\.\w{2,}$/', $image_url)) {
                        $image_url = $this->convertWordPressImageSizes($image->getAttribute('src'));
                    } else {
                        $attributes['link'] = 'file';
                    }
                } else {
                    $image_url = $this->convertWordPressImageSizes($image->getAttribute('src'));
                }
                $gallaryImages[]= $image_url;
                $images[] = $image_url;
            }


            if (preg_match('/gallery\-columns\-(\d{1,})/', $classes, $matches) && $matches[1] != 3) {
                $attributes['columns'] = $matches[1];
            }
            if (preg_match('/gallery\-size\-(\w{1,})/', $classes, $matches)) {
                $attributes['size'] = $matches[1];
            }

            $attributes      = apply_filters('rake_wordpress_gallery_attributes', $attributes);
            $new_shortcode   = apply_filters('rake_wordpress_gallery_shortcode', 'gallery');
            $attributes_text = '';

            if (!empty($attributes)) {
                foreach ($attributes as $attribute => $value) {
                    $attributes_text = sprintf(' %s=%s', $attribute, $value);
                }
            }
            $gallery_shortcode = new TextNode(sprintf(
                '[%s%s ids="%s"]',
                $new_shortcode,
                $attributes_text,
                implode(', ', $images),
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
