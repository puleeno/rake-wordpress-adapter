<?php
namespace Puleeno\RakeWordPressAdapter;

use Rake\Contracts\ResourceManagerInterface;

class WordPressResourceManager implements ResourceManagerInterface
{
    /**
     * Download file từ URL và lưu vào thư viện media WordPress.
     *
     * @param string $url
     * @param array $options
     * @return string Đường dẫn file đã lưu hoặc attachment ID
     */
    public function download(string $url, array $options = []): string
    {
        // Tải file về tạm
        $tmp = download_url($url);
        if (is_wp_error($tmp)) {
            throw new \RuntimeException('Download failed: ' . $tmp->get_error_message());
        }
        // Lấy tên file từ URL
        $filename = basename(parse_url($url, PHP_URL_PATH));
        // Chuẩn bị mảng file cho media_handle_sideload
        $file_array = [
            'name'     => $filename,
            'tmp_name' => $tmp,
        ];
        // Gắn file vào media library
        $attachment_id = media_handle_sideload($file_array, 0);
        // Xoá file tạm nếu có lỗi
        if (is_wp_error($attachment_id)) {
            @unlink($tmp);
            throw new \RuntimeException('Media sideload failed: ' . $attachment_id->get_error_message());
        }
        // Trả về attachment ID (hoặc đường dẫn nếu muốn)
        return (string)$attachment_id;
    }
}