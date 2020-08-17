<?php
namespace Puleeno\Rake\WordPress\Traits;

use Puleeno\Rake\WordPress\SeoImporter;
use Ramphor\Rake\Facades\Logger;

trait WordPressProcessor
{
    protected $appendPostCategories = false;
    protected $appendPostTags = false;

    /**
     * Check the post data is exists with post title and original ID
     *
     * @param string $postTitle
     * @param mixed $originalId
     * @param string $postType
     * @return int The WordPress post ID if it already exists.
     */
    public function checkIsExists($postTitle, $originalId = null, $postType = 'post')
    {
        global $wpdb;
        $sql = empty($originalId)
            ? $wpdb->prepare(
                "SELECT p.ID FROM {$wpdb->posts} p
                WHERE p.post_title=%s
                    AND p.post_type=%s
                    AND p.post_status=%s",
                $postTitle,
                $postType,
                'publish'
            )
            : $wpdb->prepare(
                "SELECT p.ID FROM {$wpdb->posts} p INNER JOIN {$wpdb->postmeta} pm ON p.ID = pm.post_id
                WHERE p.post_title=%s
                    AND p.post_type=%s
                    AND p.post_status=%s
                    AND post_status=%s
                    AND pm.meta_key=%s
                    AND pm.meta_value=%s",
                $postTitle,
                $postType,
                'publish',
                '_original_id',
                $originalId
            );

        $exists = $wpdb->get_var($sql);
        Logger::debug(sprintf('Check %s has title "%s" is exists: %s', $postType, $postTitle, $exists));

        return (int)$exists;
    }

    public function convertPostStatus($postStatus)
    {
        if (!in_array($postStatus, ['publish', 'draft', 'pending'])) {
            $postStatus = apply_filters(
                'rake_default_post_status',
                'publish',
                $postStatus,
                $this->feedItem
            );
        }
        return $postStatus;
    }

    public function getAuthor()
    {
        $author   = $this->feedItem->getMeta('author', null);
        $authorId = apply_filters('rake_pre_get_feed_author', null);
        if (!is_null($authorId)) {
            return $authorId;
        }

        $authorId = 1;
        if (!is_null($author)) {
            Logger::warning('The author import is not implemented');
        }
        return apply_filters('rake_get_feed_author', $authorId);
    }

    public function importPost($postContent = null)
    {
        if (is_null($postContent)) {
            $postContent = (string)$this->feedItem->content;
        } else {
            $postContent = (string)$postContent;
            $this->feedItem->setProperty(
                'content',
                $postContent
            );
        }
        $originalId       = $this->feedItem->getMeta('original_id', null);
        $this->importedId = $this->checkIsExists(
            $this->feedItem->title,
            $originalId,
            'post'
        );
        if ($this->importedId > 0) {
            return $this->importedId;
        }

        $postStatus = $this->convertPostStatus($this->feedItem->getMeta('post_status', 'publish'));
        $postArr    = array(
            'post_type'    => 'post',
            'post_title'   => $this->feedItem->title,
            'post_content' => $this->cleanupContentBeforeImport($postContent),
            'post_status'  => $postStatus,
            'post_author'  => $this->getAuthor(),
        );
        Logger::debug('Insert new post ' . $postArr['post_title'], $postArr);
        $this->importedId = wp_insert_post($postArr, $wpError);

        if ($this->importedId > 0) {
            update_post_meta(
                $this->importedId,
                '_original_id',
                $originalId
            );
            return $this->importedId;
        }
        return $wpError;
    }

    public function importPage($pageContent = null)
    {
        if (is_null($pageContent)) {
            $pageContent = (string)$this->feedItem->content;
        } else {
            $pageContent = (string)$pageContent;
            $this->feedItem->setProperty(
                'content',
                $pageContent
            );
        }

        $originalId       = $this->feedItem->getMeta('original_id', null);
        $this->importedId = $this->checkIsExists(
            $this->feedItem->title,
            $originalId,
            'post'
        );
        if ($this->importedId > 0) {
            update_post_meta(
                $this->importedId,
                '_original_id',
                $originalId
            );
            return $this->importedId;
        }

        $postStatus = $this->convertPostStatus($this->feedItem->getMeta('post_status', 'publish'));
        $postArr    = array(
            'post_type'    => 'page',
            'post_title'   => $this->feedItem->title,
            'post_content' => $this->cleanupContentBeforeImport($pageContent),
            'post_status'  => $postStatus,
            'post_author'  => $this->getAuthor(),
        );

        Logger::debug('Insert new page ' . $postArr['post_title'], $postArr);
        $this->importedId = wp_insert_post($postArr, $wpError);
        if ($this->importedId > 0) {
            return $this->importedId;
        }
        return $wpError;
    }

    public function importPostCategories($categories, $isNested = false, $postId = null)
    {
        if (is_null($postId)) {
            if (empty($this->importedId)) {
                Logger::warning('The post ID is not set value. Please set it before import categories', (array)$this->feedItem);
                return;
            }
            $postId = $this->importedId;
        }

        $termIds  = [];
        $parentId = 0;
        if (is_array($categories)) {
            // Remove the categories with empty names;
            $categories = array_filter($categories);

            foreach ($categories as $category) {
                $category = trim($category);
                $term     = term_exists($category, 'category', $parentId);
                if (!is_null($term)) {
                    $termId    = (int)$term['term_id'];
                    $termIds[] = $termId;
                    if ($isNested) {
                        if ($parentId > 0) {
                            if ($parentId > 0) {
                                wp_update_term($termId, 'category', [
                                    'parent' => $parentId
                                ]);
                            }
                        }
                        $parentId = $termId;
                    }
                    continue;
                }

                $categoryArgs = [];
                if ($isNested && $parentId > 0) {
                    $categoryArgs['parent'] = $parentId;
                }

                Logger::debug(sprintf('Insert new post category: "%s"', $category), $categoryArgs);
                $term = wp_insert_term($category, 'category', $categoryArgs);
                if (is_wp_error($term)) {
                    Logger::warning($term->get_error_message(), $categoryArgs);
                    continue;
                }

                $termId    = (int)$term['term_id'];
                $termIds[] = $termId;
                if ($isNested) {
                    $parentId = $termId;
                }
            }
        }

        return wp_set_post_categories($postId, $termIds, $this->appendPostCategories);
    }

    public function importPostTags($tags, $postId = null)
    {
        if (is_null($postId)) {
            if (empty($this->importedId)) {
                Logger::warning('The post ID is not set value. Please set it before import categories', (array)$this->feedItem);
                return;
            }
            $postId = $this->importedId;
        }
        return wp_set_post_tags($postId, $tags, $this->appendPostTags);
    }

    public function importSeo($postId = null)
    {
        if (is_null($postId)) {
            if (empty($this->importedId)) {
                Logger::warning('Need post ID to import SEO metadata');
                return;
            }
            $postId = $this->importedId;
        }
        $importer = SeoImporter::instance();

        $seoTitle = $this->feedItem->getMeta('seo_title', null);
        if (!empty($seoTitle)) {
            $importer->importTitle($postId, $seoTitle);
        }
        $seoDescription = $this->feedItem->getMeta('seo_description', null);
        if (!empty($seoDescription)) {
            $importer->importDescription($postId, $seoDescription);
        }
    }
}
