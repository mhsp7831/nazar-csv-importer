<?php
if (!defined('ABSPATH')) exit;

$file_path = sanitize_text_field($_POST['confirmed_csv_file_path']);
$row_limit = intval($_POST['row_limit']) ?: 100;
$image_size_limit = intval($_POST['image_size_limit']) ?: 2; // Default 2MB
$audio_size_limit = intval($_POST['audio_size_limit']) ?: 5; // Default 5MB

if (!file_exists($file_path)) {
    echo "<p style='color:red;'>فایل یافت نشد.</p>";
    return;
}

$handle = fopen($file_path, 'r');
if (!$handle) {
    echo "<p style='color:red;'>امکان باز کردن فایل وجود ندارد.</p>";
    return;
}

require_once ABSPATH . 'wp-admin/includes/file.php';

$error_rows = [];
$duplicate_rows = [];
$row_index = 0;
$created = 0;

function find_attachment_by_original_source_url($source_url) {
    global $wpdb;
    $attachment_id = $wpdb->get_var(
        $wpdb->prepare(
            "SELECT post_id FROM $wpdb->postmeta WHERE meta_key = '_original_source_url' AND meta_value = %s LIMIT 1",
            $source_url
        )
    );
    if ( $attachment_id ) {
        if ( get_post_status( $attachment_id ) && get_post_status( $attachment_id ) !== 'trash' ) {
            return $attachment_id;
        }
    }
    return null;
}

function get_remote_file_size($url) {
    // Validate URL format
    if (!filter_var($url, FILTER_VALIDATE_URL)) {
        error_log("Invalid URL format in nazar-csv-importer: " . $url);
        return false;
    }

    // Initialize cURL session
    $ch = curl_init($url);
    
    // Set cURL options
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_HEADER, true);
    curl_setopt($ch, CURLOPT_NOBODY, true);
    curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
    curl_setopt($ch, CURLOPT_TIMEOUT, 10); // 10 seconds timeout
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false); // Skip SSL verification
    
    // Execute cURL session
    $response = curl_exec($ch);
    
    // Check for errors
    if (curl_errno($ch)) {
        $error = curl_error($ch);
        error_log("cURL error in nazar-csv-importer for URL {$url}: {$error}");
        curl_close($ch);
        return false;
    }
    
    // Get file size from headers
    $file_size = curl_getinfo($ch, CURLINFO_CONTENT_LENGTH_DOWNLOAD);
    
    // Close cURL session
    curl_close($ch);
    
    if ($file_size === -1) {
        error_log("Could not determine file size for URL in nazar-csv-importer: " . $url);
        return false;
    }
    
    return $file_size;
}

function is_file_size_ok($url, $limit_mb) {
    $size = get_remote_file_size($url);
    if ($size === false) {
        return false;
    }
    $size_mb = $size / (1024 * 1024); // Convert to MB
    return $size_mb <= $limit_mb;
}

while (($data = fgetcsv($handle)) !== false && $row_index < $row_limit) {
    $row_index++;

    $name        = trim($data[0] ?? '');
    $text        = trim($data[1] ?? '');
    $img_url     = trim($data[2] ?? '');
    $audio_url   = trim($data[3] ?? '');
    $tags_string = trim($data[4] ?? '');
    $cats_string = trim($data[5] ?? '');

    $final_img_attachment_id = null;
    $final_audio_attachment_id = null;

    if (empty($text) && empty($img_url) && empty($audio_url) && empty($tags_string) && empty($cats_string)) {
        $error_rows[] = array_merge([$row_index, 'هیچ داده‌ای موجود نیست'], $data);
        continue;
    }

    if (!empty($img_url)) {
        if (!is_file_size_ok($img_url, $image_size_limit)) {
            $error_message = 'حجم تصویر بیشتر از حد مجاز است';
            if (!filter_var($img_url, FILTER_VALIDATE_URL)) {
                $error_message = 'آدرس تصویر نامعتبر است';
            }
            $error_rows[] = array_merge([$row_index, $error_message], $data);
            continue;
        }
        $existing_img_id = find_attachment_by_original_source_url($img_url);
        if ($existing_img_id) {
            $final_img_attachment_id = $existing_img_id;
        } else {
            $img_id_temp = media_sideload_image($img_url, 0, null, 'id');
            if (!is_wp_error($img_id_temp)) {
                $final_img_attachment_id = $img_id_temp;
                update_post_meta($final_img_attachment_id, '_original_source_url', $img_url);
            } else {
                $error_rows[] = array_merge([$row_index, 'دانلود تصویر ناموفق'], $data);
                continue;
            }
        }
    }

    if (!empty($audio_url)) {
        if (!is_file_size_ok($audio_url, $audio_size_limit)) {
            $error_message = 'حجم فایل صوتی بیشتر از حد مجاز است';
            if (!filter_var($audio_url, FILTER_VALIDATE_URL)) {
                $error_message = 'آدرس فایل صوتی نامعتبر است';
            }
            $error_rows[] = array_merge([$row_index, $error_message], $data);
            continue;
        }
        $existing_audio_id = find_attachment_by_original_source_url($audio_url);
        if ($existing_audio_id) {
            $final_audio_attachment_id = $existing_audio_id;
        } else {
            $audio_tmp_path = download_url($audio_url);
            if (is_wp_error($audio_tmp_path)) {
                $error_rows[] = array_merge([$row_index, 'دانلود صوتی ناموفق'], $data);
                continue;
            }

            $file_array = [
                'name'     => basename($audio_url),
                'tmp_name' => $audio_tmp_path
            ];
            $audio_id_temp = media_handle_sideload($file_array, 0);
            if (!is_wp_error($audio_id_temp)) {
                $final_audio_attachment_id = $audio_id_temp;
                update_post_meta($final_audio_attachment_id, '_original_source_url', $audio_url);
            } else {
                $error_rows[] = array_merge([$row_index, 'بارگذاری صوتی ناموفق'], $data);
                @unlink($audio_tmp_path);
                continue;
            }
        }
    }

    if (!empty($text)) {
        $existing = get_posts([
            'post_type' => 'nazar',
            'meta_query' => [
                ['key' => '_nazar_text',  'value' => $text],
            ],
            'posts_per_page' => 1,
            'fields' => 'ids',
        ]);
        if (!empty($existing)) {
            $duplicate_rows[] = array_merge([$row_index, 'تکراری'], $data);
        }
    }

    $post_id = wp_insert_post([
        'post_type'    => 'nazar',
        'post_title'   => $name ?: 'نظر بدون نام',
        'post_content' => $text,
        'post_status'  => 'publish',
    ]);

    if (is_wp_error($post_id)) {
        $error_rows[] = array_merge([$row_index, 'خطا در ایجاد پست'], $data);
        if (!is_null($final_img_attachment_id)) {
             wp_delete_attachment($final_img_attachment_id, true);
        }
        if (!is_null($final_audio_attachment_id)) {
             wp_delete_attachment($final_audio_attachment_id, true);
        }
        continue;
    }

    if (!is_null($final_img_attachment_id)) {
        wp_update_post( ['ID' => $final_img_attachment_id, 'post_parent' => $post_id] );
        update_post_meta($post_id, '_nazar_image', wp_get_attachment_url($final_img_attachment_id));
        update_post_meta($post_id, '_nazar_image_id', $final_img_attachment_id);
    }

    if (!is_null($final_audio_attachment_id)) {
        wp_update_post( ['ID' => $final_audio_attachment_id, 'post_parent' => $post_id] );
        update_post_meta($post_id, '_nazar_audio', wp_get_attachment_url($final_audio_attachment_id));
        update_post_meta($post_id, '_nazar_audio_id', $final_audio_attachment_id);
    }

    update_post_meta($post_id, '_nazar_text', $text);

    if (!empty($tags_string)) {
        $tags_array = explode('/', $tags_string);
        $sanitized_tags = array_map(function($tag) {
            return str_replace(' ', '_', trim($tag));
        }, $tags_array);

        wp_set_object_terms($post_id, $sanitized_tags, 'nazar_tag');
    }

    if (!empty($cats_string)) {
        $cats_array = explode('/', $cats_string);
        $sanitized_cats = array_map('trim', $cats_array);
        wp_set_object_terms($post_id, $sanitized_cats, 'nazar_category');
    }

    $created++;
}

$upload_dir = wp_upload_dir();
$base = trailingslashit($upload_dir['basedir']);
$error_file = $base . 'nazar_error_rows.csv';
$dup_file   = $base . 'nazar_duplicate_rows.csv';

function write_csv_file($filename, $rows) {
    $f = fopen($filename, 'w');
    if ($f === false) {
        error_log('Error opening CSV file for writing: ' . $filename);
        return false;
    }
    fwrite($f, "\xEF\xBB\xBF");
    foreach ($rows as $row) {
        fputcsv($f, $row);
    }
    fclose($f);
    return true;
}

if (!empty($error_rows)) write_csv_file($error_file, $error_rows);
if (!empty($duplicate_rows)) write_csv_file($dup_file, $duplicate_rows); 

echo "<div class='notice notice-success'><p>{$created} نظر با موفقیت ایجاد شد.</p>";
if (!empty($error_rows)) {
    echo "<p>" . count($error_rows) . " ردیف دارای خطا بودند. <a href='" . esc_url($upload_dir['baseurl'] . '/nazar_error_rows.csv') . "' target='_blank'>دانلود CSV خطاها</a></p>";
}
if (!empty($duplicate_rows)) {
    echo "<p>" . count($duplicate_rows) . " ردیف تکراری بودند. <a href='" . esc_url($upload_dir['baseurl'] . '/nazar_duplicate_rows.csv') . "' target='_blank'>دانلود CSV تکراری‌ها</a></p>";
}
echo "</div>";

fclose($handle);

if (file_exists($file_path)) {
    @unlink($file_path);
}
?>
