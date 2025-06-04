<?php
/*
Plugin Name: درون ریزی نظرات از فایل CSV
Description: افزودن قابلیت درون ریزی نظرات از فایل CSV.
Version: 1.1
Author: MHSP :)
*/

if (!defined('ABSPATH')) exit;

add_action('admin_menu', function () {
    add_submenu_page(
        'edit.php?post_type=nazar',
        'درون‌ریزی CSV',
        'درون‌ریزی CSV',
        'manage_options',
        'nazar_csv_import',
        'nazar_csv_import_page_callback'
    );
});

function nazar_csv_import_page_callback() {
    ?>
    <div class="wrap">
        <h1>درون‌ریزی نظرات از فایل CSV</h1>
        <form method="post" enctype="multipart/form-data">
            <input type="file" name="nazar_csv_file" accept=".csv" required>
            <p>
                <label for="row_limit">حداکثر تعداد ردیف برای پردازش (پیش‌فرض 100): </label>
                <input type="number" name="row_limit" id="row_limit" value="100" min="1" max="1000">
                <span>(ردیف های بعدی پردازش نمی شوند)</span>
                <br><em style="color: red;">عدد بالاتر از 100 ممکن است باعث فشار به سرور شود.</em>
            </p>
            <p>
                <label for="image_size_limit">حداکثر حجم تصاویر (مگابایت): </label>
                <input type="number" name="image_size_limit" id="image_size_limit" value="2" min="0.01" max="50">
                <br><em style="color: #666;">تصاویر با حجم بیشتر از این مقدار در نظر گرفته نمی‌شوند.</em>
            </p>
            <p>
                <label for="audio_size_limit">حداکثر حجم فایل‌های صوتی (مگابایت): </label>
                <input type="number" name="audio_size_limit" id="audio_size_limit" value="5" min="0.01" max="50">
                <br><em style="color: #666;">فایل‌های صوتی با حجم بیشتر از این مقدار در نظر گرفته نمی‌شوند.</em>
            </p>
            <input type="submit" name="nazar_upload_submit" class="button button-primary" value="آپلود فایل">
        </form>
    </div>
    <?php

    if (isset($_FILES['nazar_csv_file']) && isset($_POST['nazar_upload_submit'])) {
        $file = $_FILES['nazar_csv_file'];
        $uploaded = wp_handle_upload($file, ['test_form' => false]);
        if (isset($uploaded['url']) && isset($uploaded['file'])) {
            $file_path = $uploaded['file'];
            $row_limit = intval($_POST['row_limit']) ?: 100;
            $image_size_limit = intval($_POST['image_size_limit']) ?: 2;
            $audio_size_limit = intval($_POST['audio_size_limit']) ?: 3;

            $file_info = wp_check_filetype($uploaded['file']);
            if ( $file_info['type'] !== 'text/csv' && $file_info['type'] !== 'application/vnd.ms.excel' ) {
                @unlink($uploaded['file']);
                echo "<p style='color:red;'>خطا: فرمت فایل نامعتبر است. لطفاً یک فایل CSV انتخاب کنید.</p>";
                return;
            }
            
            ?>
            <form method="post">
                <input type="hidden" name="confirmed_csv_file_path" value="<?php echo esc_attr($file_path); ?>">
                <input type="hidden" name="row_limit" value="<?php echo esc_attr($row_limit); ?>">
                <input type="hidden" name="image_size_limit" value="<?php echo esc_attr($image_size_limit); ?>">
                <input type="hidden" name="audio_size_limit" value="<?php echo esc_attr($audio_size_limit); ?>">
                <p>فایل با موفقیت آپلود شد: <code><?php echo basename($file_path); ?></code></p>
                <input type="submit" name="nazar_process_file" class="button button-secondary" value="تایید و آغاز پردازش">
                <input type="submit" name="nazar_cancel_upload" class="button" value="لغو و حذف فایل">
            </form>
            <script>
                const row_limit_input = document.querySelector('#row_limit');
                row_limit_input.value = <?php echo $row_limit; ?>;
                row_limit_input.disabled = true;
                
                const image_size_limit_input = document.querySelector('#image_size_limit');
                image_size_limit_input.value = <?php echo $image_size_limit; ?>;
                image_size_limit_input.disabled = true;

                const audio_size_limit_input = document.querySelector('#audio_size_limit');
                audio_size_limit.value = <?php echo $audio_size_limit; ?>;
                audio_size_limit.disabled = true;
            </script>
            <?php
        } else {
            echo "<p style='color:red;font-weight:bold;'>آپلود فایل با خطا مواجه شد.</p>";
        }
    }
    else if (isset($_POST['nazar_cancel_upload']) && isset($_POST['confirmed_csv_file_path'])) {
        $file_path_to_delete = sanitize_text_field($_POST['confirmed_csv_file_path']);
        if (file_exists($file_path_to_delete)) {
            @unlink($file_path_to_delete);
            echo '<div class="notice notice-success"><p>فایل CSV با موفقیت حذف شد و عملیات لغو گردید.</p></div>';
        } else {
            echo '<div class="notice notice-warning"><p>فایل CSV قبلاً حذف شده بود یا یافت نشد.</p></div>';
        }
    }
    else if (isset($_POST['nazar_process_file']) && isset($_POST['confirmed_csv_file_path'])) {
        include plugin_dir_path(__FILE__) . 'process-csv.php';
    }
}
?>