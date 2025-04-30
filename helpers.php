<?php

use Ramphor\Rake\Facades\Request;
use Ramphor\Rake\Facades\Logger;

/**
 * Credit to @AshHeskes(Ash Heskes)
 */
function fetch_extension_mime_types()
{
    $cachePath = dirname(__FILE__) . '/mime-types.json';
    if (file_exists($cachePath)) {
        $strJson = file_get_contents($cachePath);
        $json    = json_decode($strJson, true);
        if (!is_null($json)) {
            return $json;
        }
    }

    // Default mime types;
    $mimes  = [];
    $source = 'https://gist.githubusercontent.com/AshHeskes/6038140/raw/file-extension-to-mime-types.json';
    try {
        $response = Request::sendRequest('GET', $source, array('verify' => false));
        $body     = $response->getBody();
        $mimes    = json_decode($body, true);

        // Caching mime types
        $h = @fopen($cachePath, 'w');
        @fwrite($h, $body);
        @fclose($h);
    } catch (\Exception $e) {
        ob_start();
        debug_print_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS, 2);
        $errorLogs = ob_get_clean();
        Logger::warning(sprintf('%s\n%s', $e->getMessage(), $errorLogs));
    }

    return $mimes;
}

function pl_validate_extension($extension)
{
    $mimes = fetch_extension_mime_types();
    if (substr($extension, 0, 1) !== '.') {
        $extension = '.' . $extension;
    }
    return isset($mimes[$extension]);
}

function pl_convert_mime_type_to_extension($mime)
{
    $mimes     = fetch_extension_mime_types();
    $extension = array_search($mime, $mimes);
    if ($extension !== false) {
        return $extension;
    }
    return '.unknown';
}


function pl_get_post_category_short_description_meta_name()
{
    return apply_filters(
        'rake/post/category/short_description_meta_name',
        'short_description'
    );
}

function dd2($data)
{
    if (is_array($data)) {
        if (isset($data['tooth'])) {
            unset($data['tooth']);
        }
    }
    if (is_object($data)) {
        if (isset($data->tooth)) {
            unset($data->tooth);
        }
    }
    call_user_func(implode('', ['d', 'd']), $data);
}

add_filter('rake/wp/data/taxonomy/type', function ($dataType, $parent = null) {
    if ($dataType === 'product_category') {
        return 'product_cat';
    }
    return $dataType;
}, 10, 2);


function rake_wp_get_wordpress_taxonomy_name($type, $parent = null)
{
    return apply_filters(
        'rake/wp/data/taxonomy/type',
        $type,
        $parent
    );
}


function rake_wp_get_builtin_data_type($dataType, $parent = null)
{
    $dataTypeMaps = apply_filters('rake/wp/data/type/maps', [
        'post' => 'post',
        'product' => 'post',
        'product_category' => 'taxonomy',
        'page' => 'post',
        'category' => 'taxonomy',
        'tag' => 'taxonomy',
        'attachment' => 'post'
    ]);

    $builtInDataType = apply_filters(
        'rake/wp/data/type',
        array_get($dataTypeMaps, $dataType, $dataType),
        $dataType,
        $parent
    );

    return $builtInDataType;
}
