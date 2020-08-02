<?php
namespace Puleeno\Rake\WordPress\Traits;

trait WordPressProcessor
{
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
        return $wpdb->get_var($sql);
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
            $postContent = (string)$postContent;
            $this->feedItem->setProperty(
                'content',
                $postContent
            );
        } else {
            $postContent = (string)$this->feedItem->content;
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
        $this->importedId = wp_insert_post([
            'post_type' => 'post',
            'post_title' => $this->feedItem->title,
            'post_content' => $postContent,
            'post_status' => $postStatus,
            'post_author' => $this->getAuthor(),
        ], $wpError);

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
        if (is_null($postContent)) {
            $postContent = (string)$postContent;
            $this->feedItem->setProperty(
                'content',
                $postContent
            );
        } else {
            $postContent = (string)$this->feedItem->content;
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
        $this->importedId = wp_insert_post([
            'post_type' => 'page',
            'post_title' => $this->feedItem->title,
            'post_content' => $postContent,
            'post_status' => $postStatus,
            'post_author' => $this->getAuthor(),
        ], $wpError);

        if ($this->importedId > 0) {
            return $this->importedId;
        }
        return $wpError;
    }

    public function importPostCategories($categories, $isNested = false)
    {
    }
}
