<?php
namespace Puleeno\Rake\WordPress\Traits;

trait WordPressProcessor
{
    public function importPost($postContent = null)
    {
    }

    public function importPage($pageContent = null)
    {
    }

    public function checkIsExists($postTitle, $originalId)
    {
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
}
