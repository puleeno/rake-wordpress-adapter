<?php
namespace Puleeno\Rake\WordPress;

use PHPHtmlParser\Dom;
use PHPHtmlParser\Dom\TextNode;
use Ramphor\Rake\Abstracts\Processor;

abstract class WordPressContentProcessor extends Processor
{
    protected $tocPlugins = [
        'easy_table_of_content' => '#toc_container',
    ];

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
                    $imageUrl = $parent->getAttribute('href');
                    if (!preg_match('/\.\w{2,}$/', $imageUrl)) {
                        $imageUrl = $this->convertWordPressImageSizes($image->getAttribute('src'));
                    } elseif (empty($attributes['link'])) {
                        $attributes['link'] = 'file';
                    }
                } else {
                    $imageUrl = $this->convertWordPressImageSizes($image->getAttribute('src'));
                }

                // Check image is not deleted else remove it in gallery
                if (!$this->checkImageIsFound($imageUrl)) {
                    continue;
                }

                $images[]        = $imageUrl;
                $gallaryImages[] = $imageUrl;
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
                    $attributes_text .= sprintf(' %s="%s"', $attribute, $value);
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

    public function convertTableOfContent()
    {
        $document = new Dom();
        $document->load($this->feedItem->content);

        foreach ($this->tocPlugins as $tocRule) {
            foreach ($document->find($tocRule) as $toc_container) {
                $toc_container->delete();
            }
        }
        $this->feedItem->setProperty('content', $document->innerHtml);
    }
}
