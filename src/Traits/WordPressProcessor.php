<?php

namespace Puleeno\Rake\WordPress\Traits;

use Puleeno\Rake\WordPress\SeoImporter;
use Ramphor\Rake\Facades\Logger;
use WP_Term;
use WPSEO_Taxonomy_Meta;
use Yoast\WP\SEO\Integrations\Watchers\Indexable_Term_Watcher;

trait WordPressProcessor
{
    protected $appendPostCategories = false;
    protected $appendPostTags = false;

    /**
     * @var \Ramphor\Rake\DataSource\FeedItem
     */
    protected $feedItem;

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
                    AND pm.meta_key=%s
                    AND pm.meta_value=%s",
                $postTitle,
                $postType,
                'publish',
                '_original_id',
                $originalId
            );

        $exists = $wpdb->get_var($sql);
        Logger::info(sprintf('Check %s has title "%s" is exists: %s', $postType, $postTitle, $exists));

        return (int) $exists;
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
        $author = $this->feedItem->getMeta('author', null);
        $authorId = apply_filters('rake/wp/feed_author/pre', null);
        if (!is_null($authorId)) {
            return $authorId;
        }

        $authorId = 1;
        if (!is_null($author)) {
            Logger::warning('The author import is not implemented');
        }
        return apply_filters('rake/wp/feed_author', $authorId);
    }

    public function importPost($postContent = null, $postType = 'post')
    {
        if (is_null($postContent)) {
            $postContent = (string) $this->feedItem->content;
        } else {
            $postContent = (string) $postContent;
        }

        $postContent = $this->cleanupContentBeforeImport($postContent);

        // compatible with download images feature.
        $this->feedItem->setProperty(
            'content',
            $postContent
        );

        $originalId = $this->feedItem->originalId;
        $this->importedId = $this->checkIsExists(
            $this->feedItem->title,
            $originalId,
            'post'
        );

        // Create the post attributes to import or update
        $postArr = array(
            'post_type' => $postType,
        );

        if ($this->feedItem->slug) {
            $postArr['post_name'] = trim($this->feedItem->slug);
        }
        if ($this->feedItem->publishedAt) {
            $postArr['post_date'] = $this->feedItem->publishedAt;
        } elseif ($this->feedItem->createdAt) {
            $postArr['post_date'] = $this->feedItem->createdAt;
        }
        if ($this->feedItem->updatedAt) {
            $postArr['post_modified'] = $this->feedItem->updatedAt;
        }

        if ($this->importedId > 0) {
            Logger::info(sprintf('Found the %s %d so the process will continue with next step', $postType, $this->importedId));
            $postArr = array(
                'ID' => $this->importedId,
            );

            // Check update process is Ok
            if (
                is_wp_error(wp_update_post(apply_filters(
                    'rampho_rake_update_post_args',
                    $postArr,
                    $this->feedItem,
                    $this, // Current processor
                )))
            ) {
                Logger::warning(sprintf('Update %s #%d - %s is failed', $postType, $this->importId, $this->feedItem->title));
            }
            return $this->importedId;
        }

        $postStatus = $this->convertPostStatus($this->feedItem->getMeta('post_status', 'publish'));
        $postArr = $postArr + array(
            'post_title' => $this->feedItem->title,
            'post_content' => $postContent, // this is cleanup content.
            'post_status' => $postStatus,
            'post_author' => $this->getAuthor(),
        );

        Logger::info('Insert new "' . $postType . ' ' . $postArr['post_title'] . '"');
        Logger::debug('Data:', $postArr);

        $this->importedId = wp_insert_post($postArr);

        if ($this->importedId > 0) {
            update_post_meta(
                $this->importedId,
                '_original_id',
                $originalId
            );
            return $this->importedId;
        }

        if (is_null($this->importedId)) {
            $this->importedId = new \WP_Error(
                'invalid_post_attribute',
                sprintf(__('Your %s attributes include the invalid values', $postType))
            );
        }

        return $this->importedId;
    }

    public function importPage($title = null, $pageContent = null)
    {
        $content = empty($pageContent) ? (string) $this->feedItem->content : $pageContent;
        $pageTitle = empty($title) ? $this->feedItem->title : $title;

        // To compatible with download images feature.
        if (!empty($pageContent)) {
            $this->feedItem->setProperty('content', $content);
        }

        $originalId = $this->feedItem->originalId;
        $this->importedId = $this->checkIsExists(
            $pageTitle,
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
        $postArr = [
            'post_type' => 'page',
            'post_title' => $pageTitle,
            'post_content' => $this->cleanupContentBeforeImport($content),
            'post_status' => $postStatus,
            'post_author' => $this->getAuthor(),
        ];

        // Check existing page by slug or title
        $query_args = [
            'post_type' => 'page',
            'post_status' => 'publish',
            'posts_per_page' => 1,
        ];

        if (!empty($this->feedItem->slug)) {
            $query_args['name'] = $this->feedItem->slug;
        } else {
            $query_args['title'] = $postArr['post_title'];
        }

        $existing_page = get_posts($query_args);

        if (!empty($existing_page)) {
            $postArr['ID'] = $existing_page[0]->ID;
            $this->importedId = wp_update_post($postArr, true);
        } else {
            $this->importedId = wp_insert_post($postArr, true);
        }

        Logger::info(
            (!empty($existing_page) ? 'Update' : 'Insert new') . ' page ' . $postArr['post_title'],
            $postArr
        );

        return $this->importedId;
    }

    protected function checkTermExistsBySlugAndTaxonomy($slug, $taxonomy)
    {
        $term = get_term_by('slug', $slug, $taxonomy);
        if ($term instanceof WP_Term) {
            return $term->term_id;
        }
        return 0;
    }

    protected function checkTermExistsByNameAndTaxonomy($name, $taxonomy)
    {
        $term = get_term_by('name', $name, $taxonomy);
        if ($term instanceof WP_Term) {
            return $term->term_id;
        }
        return 0;
    }

    public function importPostCategory($title = null, $description = null, $slug = null, $shortDescription = null, $taxonomy = 'category')
    {
        $title = !is_null($title) ? trim($title) : trim($this->feedItem->title);
        $slug = !is_null($slug) ? $slug : $this->feedItem->slug;
        $description = !is_null($description) ? $description : $this->feedItem->description;
        $taxonomy = !empty($taxonomy) ? $taxonomy : $this->feedItem->taxonomy;

        $termId = !empty($slug) ? $this->checkTermExistsBySlugAndTaxonomy($slug, $taxonomy) : $this->checkTermExistsByNameAndTaxonomy($title, $taxonomy);
        $args = array(
            'description' => $description,
            'slug' => $slug,
        );
        Logger::info(sprintf('Check term of [%s] is exists: %s', $taxonomy, $termId > 0 ? 'existing with Id #' . $termId : 'Not exists'));

        if ($termId <= 0) {
            Logger::info(sprintf('Create new %s with name is "%s"', $taxonomy, $title));
            $term = wp_insert_term($title, $taxonomy, $args);
            $termId = $term['term_id'];


            if (is_wp_error($term)) {
                Logger::warning($term->get_error_message(), array(
                    'title' => $title,
                    'slug' => $slug,
                    'description' => $description,
                    'taxonomy' => $taxonomy
                ));
                return false;
            }
        } else {
            $updatePostCategoryHandle = apply_filters("rake/wp/{$taxonomy}/existing/update/handle", null, $title, $args);
            if (is_callable($updatePostCategoryHandle)) {
                Logger::info(sprintf('Update [%s] #%d data via callback', $taxonomy, $termId), [
                    $title,
                    $args,
                    $updatePostCategoryHandle
                ]);
                call_user_func_array($updatePostCategoryHandle, [
                    $termId,
                    $title,
                    $taxonomy,
                    $args,
                    $this
                ]);
            } else {
                Logger::info(sprintf('Data of [%s] #%d is skipped updating', $taxonomy, $termId));
            }
        }

        if (!empty($shortDescription)) {
            update_term_meta(
                $termId,
                pl_get_post_category_short_description_meta_name(),
                $shortDescription
            );
        }

        $this->setImportedId($termId);

        return $termId;
    }

    public function importPostCategories($categories, $isNested = false, $postId = null, $taxonomy = 'category')
    {
        if (is_null($postId)) {
            if (empty($this->importedId)) {
                Logger::warning('The post ID is not set value. Please set it before import categories', (array) $this->feedItem);
                return;
            }
            $postId = $this->importedId;
        }

        $termIds = [];
        $parentId = 0;
        if (is_array($categories)) {
            // Remove the categories with empty names;
            $categories = array_filter($categories);

            foreach ($categories as $category) {
                $category = trim($category);
                $term = term_exists($category, $taxonomy, $parentId);
                if (!is_null($term)) {
                    $termId = (int) $term['term_id'];
                    $termIds[] = $termId;
                    if ($isNested) {
                        if ($parentId > 0) {
                            if ($parentId > 0) {
                                wp_update_term($termId, $taxonomy, [
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

                Logger::info(sprintf('Insert new post category: "%s"', $category));
                Logger::debug('Data', $categoryArgs);

                $term = wp_insert_term($category, $taxonomy, $categoryArgs);
                if (is_wp_error($term)) {
                    Logger::warning($term->get_error_message(), $categoryArgs);
                    continue;
                }

                $termId = (int) $term['term_id'];
                $termIds[] = $termId;
                if ($isNested) {
                    $parentId = $termId;
                }
            }
        }

        if ($taxonomy === 'category') {
            return wp_set_post_categories($postId, $termIds, $this->appendPostCategories);
        }

        return wp_set_post_terms($postId, $termIds, $taxonomy, $this->appendPostCategories);
    }

    public function importPostTags($tags, $postId = null)
    {
        if (is_null($postId)) {
            if (empty($this->importedId)) {
                Logger::warning('The post ID is not set value. Please set it before import categories', (array) $this->feedItem);
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

        $seoTitle = $this->feedItem->getMeta('seoTitle', null);
        if (!empty($seoTitle)) {
            $importer->importTitle($postId, $seoTitle);
        }
        $seoDescription = $this->feedItem->getMeta('seoDescription', null);
        if (!empty($seoDescription)) {
            $importer->importDescription($postId, $seoDescription);
        }
    }

    public function importTermSeo($termId = null, $taxonomy = 'category')
    {
        if (is_null($termId)) {
            if (empty($this->importedId)) {
                Logger::warning('Need post ID to import SEO metadata');
                return;
            }
            $termId = $this->importedId;
        }
        $importer = SeoImporter::instance();
        foreach ($importer->getSeoPlugins() as $pluginName => $seoInfo) {
            if ($pluginName !== 'yoast_seo') {
                $importer->importTermSeoData(
                    $seoInfo,
                    $termId,
                    $this->feedItem->getMeta('seoTitle', null),
                    $this->feedItem->getMeta('seoDescription', null)
                );
            } elseif (class_exists('WPSEO_Taxonomy_Meta')) {
                // Cập nhật meta cho term
                $meta = array(
                    'wpseo_title' => $this->feedItem->getMeta('seoTitle', null),
                    'wpseo_desc' => $this->feedItem->getMeta('seoDescription', null),
                    'wpseo_focuskw' => '',
                    'wpseo_noindex' => 'index', // Cho phép index
                    'wpseo_canonical' => get_term_link($termId), // URL canonical
                    'wpseo_opengraph-title' => $this->feedItem->getMeta('ogTitle', $this->feedItem->getMeta('seoTitle', null)), // Thêm Open Graph title
                    'wpseo_opengraph-description' => $this->feedItem->getMeta('ogDescription', $this->feedItem->getMeta('seoDescription', null)), // Thêm Open Graph description
                );

                // Sử dụng API của Yoast SEO để cập nhật meta
                WPSEO_Taxonomy_Meta::set_values($termId, $taxonomy, $meta);

                // Cập nhật lại indexable thông qua API của Yoast SEO
                if (class_exists(Indexable_Term_Watcher::class)) {
                    $container = \YoastSEO()->classes->get(Indexable_Term_Watcher::class);
                    $container->build_indexable($termId);
                }
            }
        }
    }
}
