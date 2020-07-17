<?php
use Ramphor\Rake\Facades\Client;

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

    // Default mine types;
    $mimes  = [];
    $source = 'https://gist.githubusercontent.com/AshHeskes/6038140/raw/file-extension-to-mime-types.json';
    try {
        $response = Client::request('GET', $sourceMineTypes);
        $body     = $response->getBody();
        $mimes    = json_decode($body, true);

        // Caching mine types
        $h = @fopen($cachePath, 'w');
        @fwrite($h, $body);
        @fclose($h);
    } catch (\Exception $e) {
        // Will logging later
    }

    return $mimes;
}

function pl_validate_extension($extension)
{
    $mines = fetch_extension_mime_types();
    if (substr($extension, 0, 1) !== '.') {
        $extension = '.' . $extension;
    }
    return isset($mines[$extension]);
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
