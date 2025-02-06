<?php

namespace Puleeno\Rake\WordPress;

class SeoImporter
{
    protected static $instance;

    protected $seoPlugins = [];

    public static function instance()
    {
        if (is_null(self::$instance)) {
            self::$instance = new self();
        }

        return self::$instance;
    }

    private function __construct()
    {
        $this->detectSeoPlugins();
    }

    public function detectSeoPlugins()
    {
        $active_plugins = get_option('active_plugins');
        if (in_array('seo-by-rank-math/rank-math.php', $active_plugins)) {
            $this->seoPlugins['rankmath'] = array(
                'metadata' => 'postmeta',
                'fields' => array(
                    'title' => 'rank_math_title',
                    'description' => 'rank_math_description'
                ),
                'title_format' => '%s'
            );
        }

        if (in_array('wordpress-seo/wp-seo.php', $active_plugins)) {
            $this->seoPlugins['yoast_seo'] = array(
                'metadata' => 'postmeta',
                'fields' => array(
                    'title' => '_yoast_wpseo_title',
                    'description' => '_yoast_wpseo_metadesc'
                ),
                'title_format' => '%s %%sep%% %%sitename%%'
            );
        }
    }


    public function importTitle($postId, $seoTitle)
    {
        foreach ($this->seoPlugins as $seoInfo) {
            if (!isset($seoInfo['metadata']) || !isset($seoInfo['fields'])) {
                continue;
            }
            $fields = $seoInfo['fields'];
            if (empty($fields['title'])) {
                return false;
            }
            if ($seoInfo['metadata'] === 'postmeta') {
                $format = !empty($seoInfo['title_format']) ? $seoInfo['title_format'] : '%s';
                $format = apply_filters(
                    'import_seo_title_format',
                    str_replace('%%', '%%%%', $format),
                    $seoInfo
                );

                update_post_meta($postId, $fields['title'], sprintf($format, $seoTitle));
            }
        }
    }

    public function importDescription($postId, $seoDescription)
    {
        foreach ($this->seoPlugins as $seoInfo) {
            if (!isset($seoInfo['metadata']) || !isset($seoInfo['fields'])) {
                continue;
            }
            $fields = $seoInfo['fields'];
            if (empty($fields['description'])) {
                return false;
            }
            if ($seoInfo['metadata'] === 'postmeta') {
                update_post_meta($postId, $fields['description'], $seoDescription);
            }
        }
    }

    public function importTermTitle($termId, $seoTitle)
    {
        foreach ($this->seoPlugins as $seoInfo) {
            if (!isset($seoInfo['metadata']) || !isset($seoInfo['fields'])) {
                continue;
            }
            $fields = $seoInfo['fields'];
            if (empty($fields['title'])) {
                return false;
            }
            if ($seoInfo['metadata'] === 'postmeta') {
                update_term_meta($termId, $fields['title'], $seoTitle);
            }
        }
    }

    public function importTermDescription($termId, $seoDescription)
    {
        foreach ($this->seoPlugins as $seoInfo) {
            if (!isset($seoInfo['metadata']) || !isset($seoInfo['fields'])) {
                continue;
            }
            $fields = $seoInfo['fields'];
            if (empty($fields['description'])) {
                return false;
            }
            if ($seoInfo['metadata'] === 'postmeta') {
                update_term_meta($termId, $fields['description'], $seoDescription);
            }
        }
    }
}
