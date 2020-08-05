<?php
namespace Puleeno\Rake\WordPress\Content;

use PHPHtmlParser\Dom;
use PHPHtmlParser\Dom\TextNode;
use Ramphor\Rake\Abstracts\Processor;
use Ramphor\Rake\Facades\Logger;

abstract class WordPressProcessor extends Processor
{
    protected $tocPlugins = [
        'easy_table_of_content' => '#toc_container',
    ];

    public function convertHtmlGalleryFromContent()
    {
        $document = new Dom();
        $document->load($this->feedItem->content);
        $gallaryImages  = $this->feedItem->galleryImages;
        $totalGalleries = count($gallaryImages);

        Logger::debug(sprintf('The processor founded %d WordPress gallery', $totalGalleries));
        if ($totalGalleries > 0) {
            foreach ($document->find('div.gallery[id^="gallery"]') as $gallery) {
                $images     = [];
                $attributes = [];
                $classes    = $gallery->getAttribute('class');
                $domImages  = $gallery->find('img');

                Logger::debug('Found %d from the content', count($domImages));
                foreach ($domImages as $image) {
                    $parent = $image->getParent();
                    if ($parent->tag->name() === "a") {
                        $imageUrl = $parent->getAttribute('href');
                        if (!preg_match('/\.\w{2,}$/', $imageUrl)) {
                            $imageUrl = $this->convertImageUrl($image->getAttribute('src'));
                        } elseif (empty($attributes['link'])) {
                            $attributes['link'] = 'file';
                        }
                    } else {
                        $imageUrl = $this->convertImageUrl($image->getAttribute('src'));
                    }

                    // Check image is not deleted else remove it in gallery
                    if (!$this->checkImageIsFound($imageUrl)) {
                        Logger::warning(sprintf('The image has URL %s is not found. It\'s removed in content', $imageUrl), [
                            'processor' => get_class($this),
                            'tooth' => $this->tooth->getId()
                        ]);
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

                Logger::debug(sprintf(
                    'Convert HTML gallery %s to WordPress gallery %s',
                    $gallery->innertHtml,
                    $gallery_shortcode->innerHtml
                ));
                $gallery->getParent()->replaceChild($gallery->id(), $gallery_shortcode);
            }
            $this->feedItem->setProperty('galleryImages', $gallaryImages);
            $this->feedItem->setProperty('content', $document->innerHtml);
        }
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

    /**
     * Convert image URL with sizes generated by WordPress to original URL
     *
     * @param string $imageUrl
     * @link https://developer.wordpress.org/reference/functions/wp_get_attachment_image_src/
     * @return string the orignal WordPress image URL
     */
    public function convertImageUrl($imageUrl)
    {
        $convertedUrl = preg_replace(
            '/\-\d{1,}x\d{1,}(\.\w{2,})$/',
            '$1',
            $imageUrl
        );

        if ($imageUrl !== $convertedUrl) {
            Logger::info(sprintf('The image %s is converted to %s', $imageUrl, $convertedUrl));
        }
        return $convertedUrl;
    }
}
