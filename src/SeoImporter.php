<?php
namespace Puleeno\Rake\WordPress;

class SeoImporter {
    protected static $instance;

    protected $seoPlugins = [];

    public static function instance() {
        if (is_null(self::$instance)) {
            self::$instance = new self();
        }

        return self::$instance;
    }

    private function __construct() {
        $this->detectSeoPlugins();
    }

    public function detectSeoPlugins() {
        $active_plugins = get_option( 'active_plugins' );
        if (in_array('seo-by-rank-math/rank-math.php', $active_plugins)) {
            $this->seoPlugins['rankmath'] = array(
                'metadata' => 'postmeta',
                'fields' => array(
                    'title'=> 'rank_math_title',
                    'description' => 'rank_math_description'
                )
            );
        }
    }


    public function importTitle($postId, $seoTitle) {
        foreach($this->seoPlugins as $seoInfo) {
            if (!isset($seoInfo['metadata']) || !isset($seoInfo['fields'])) {
                continue;
            }
            $fields = $seoInfo['fields'];
            if (empty($fields['title'])) {
                return false;
            }
            if ($seoInfo['metadata'] === 'postmeta') {
                update_post_meta($postId, $fields['title'], $seoTitle );
            }
        }
    }

    public function importDescription($postId, $seoDescription) {
        foreach($this->seoPlugins as $seoInfo) {
            if (!isset($seoInfo['metadata']) || !isset($seoInfo['fields'])) {
                continue;
            }
            $fields = $seoInfo['fields'];
            if (empty($fields['description'])) {
                return false;
            }
            if ($seoInfo['metadata'] === 'postmeta') {
                update_post_meta($postId, $fields['description'], $seoDescription );
            }
        }
    }
}
