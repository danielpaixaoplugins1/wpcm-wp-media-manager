<?php
/*
Plugin Name: WPCM WP Media Manager
Plugin URI: https://centralmidia.net.br/wpcm-wp-media-manager
Description: Gerencia mídias no WordPress, convertendo imagens para WebP, redimensionando mídias grandes, aplicando medidas de segurança, renomeando mídias com um formato mais organizado. Define texto alternativo e título da imagem com base no título da postagem ou no nome do site.
Version: 3.2
Author: Daniel Oliveira da Paixao
Author URI: https://centralmidia.net.br/dev
*/

if (!defined('ABSPATH')) exit; // Exit if accessed directly

add_filter('wp_handle_upload_prefilter', 'wpcm_pre_handle_upload');
add_filter('wp_handle_upload', 'converte_para_webp');
add_action('add_attachment', 'wpcm_set_media_data_on_upload');
add_action('save_post', 'wpcm_update_media_on_post_save');
add_action('admin_menu', 'wpcm_add_admin_menu');
add_action('admin_init', 'wpcm_settings_init');

function wpcm_add_admin_menu() {
    add_options_page('WPCM Media Manager Settings', 'WPCM Media Manager', 'manage_options', 'wpcm-media-manager', 'wpcm_options_page');
}

function wpcm_options_page() {
    ?>
    <div class="wrap">
        <h1>WPCM Media Manager Settings</h1>
        <form method="post" action="options.php">
            <?php
            settings_fields('wpcm_options_group');
            do_settings_sections('wpcm-media-manager');
            submit_button();
            ?>
        </form>
    </div>
    <?php
}

function wpcm_settings_init() {
    register_setting('wpcm_options_group', 'wpcm_options');
    add_settings_section('wpcm_general_settings', 'General Settings', 'wpcm_general_settings_section_callback', 'wpcm-media-manager');

    add_settings_field('wpcm_image_size_threshold', 'Image Size Threshold (width in pixels)', 'wpcm_image_size_threshold_render', 'wpcm-media-manager', 'wpcm_general_settings');
    add_settings_field('wpcm_video_size_threshold', 'Video Size Threshold (width in pixels)', 'wpcm_video_size_threshold_render', 'wpcm-media-manager', 'wpcm_general_settings');
}

function wpcm_general_settings_section_callback() {
    echo 'Adjust the settings for the WPCM Media Manager plugin.';
}

function wpcm_image_size_threshold_render() {
    $options = get_option('wpcm_options');
    ?>
    <input type='number' name='wpcm_options[image_size_threshold]' value='<?php echo isset($options['image_size_threshold']) ? esc_attr($options['image_size_threshold']) : ''; ?>' min="0">
    <p>Set the maximum width (in pixels) for images before they are resized. Set to 0 to disable resizing.</p>
    <?php
}

function wpcm_video_size_threshold_render() {
    $options = get_option('wpcm_options');
    ?>
    <input type='number' name='wpcm_options[video_size_threshold]' value='<?php echo isset($options['video_size_threshold']) ? esc_attr($options['video_size_threshold']) : ''; ?>' min="0">
    <p>Set the maximum width (in pixels) for videos before they are resized. Set to 0 to disable resizing.</p>
    <?php
}

function wpcm_pre_handle_upload($file) {
    $time = current_time('timestamp');
    $file_path_info = pathinfo($file['name']);
    $file_type = wp_check_filetype($file['name']);
    
    if (strpos($file_type['type'], 'image') !== false) {
        $prefix = 'img';
    } elseif (strpos($file_type['type'], 'audio') !== false) {
        $prefix = 'audio';
        add_filter('upload_dir', 'wpcm_change_audio_upload_dir');
    } elseif (strpos($file_type['type'], 'video') !== false) {
        $prefix = 'vid';
        add_filter('upload_dir', 'wpcm_change_video_upload_dir');
    } else {
        $prefix = 'file';
    }

    $file_name_prefix = $prefix . date('i', $time) . date('s', $time);
    $file['name'] = $file_name_prefix . '.' . $file_path_info['extension'];

    return $file;
}

function wpcm_change_audio_upload_dir($upload) {
    $upload['subdir'] = '/uploads/audios' . $upload['subdir'];
    $upload['path'] = $upload['basedir'] . $upload['subdir'];
    $upload['url'] = $upload['baseurl'] . $upload['subdir'];
    remove_filter('upload_dir', 'wpcm_change_audio_upload_dir');
    return $upload;
}

function wpcm_change_video_upload_dir($upload) {
    $upload['subdir'] = '/uploads/videos' . $upload['subdir'];
    $upload['path'] = $upload['basedir'] . $upload['subdir'];
    $upload['url'] = $upload['baseurl'] . $upload['subdir'];
    remove_filter('upload_dir', 'wpcm_change_video_upload_dir');
    return $upload;
}

function converte_para_webp($file) {
    if ($file['type'] === 'image/jpeg' || $file['type'] === 'image/png') {
        $options = get_option('wpcm_options');
        $size_threshold = isset($options['image_size_threshold']) ? (int) $options['image_size_threshold'] : 1200;

        $image_path = $file['file'];
        $image = wp_get_image_editor($image_path);
        if (!is_wp_error($image)) {
            $image_size = $image->get_size();
            if ($image_size['width'] > $size_threshold || $image_size['height'] > $size_threshold) {
                $image->resize($size_threshold, $size_threshold, false);
                $image->save($image_path);
            }

            $webp_path = preg_replace('/\.(jpg|jpeg|png)$/', '.webp', $image_path);
            $image->set_quality(90);
            $result = $image->save($webp_path, 'image/webp');

            if (!is_wp_error($result)) {
                $file['file'] = $webp_path;
                $file['url'] = str_replace(wp_basename($file['file']), wp_basename($webp_path), $file['url']);
                $file['type'] = 'image/webp';
                $file['name'] = wp_basename($webp_path);

                unlink($image_path);
            }
        }
    }

    return $file;
}

function wpcm_set_media_data_on_upload($attachment_id) {
    $attachment = get_post($attachment_id);
    $parent_post = get_post($attachment->post_parent);

    // Verifica se o título da postagem está definido e não é "Rascunho automático"
    if ($parent_post && !empty($parent_post->post_title) && strtolower($parent_post->post_title) !== 'rascunho automático') {
        $default_alt_text = $parent_post->post_title;
    } else {
        // Usa o nome do site, removendo TLDs e hifens
        $site_name = preg_replace('/\..+$/', '', get_bloginfo('name')); // Remove TLD
        $default_alt_text = str_replace('-', ' ', $site_name); // Substitui hifens por espaços
    }

    update_post_meta($attachment_id, '_wp_attachment_image_alt', $default_alt_text);
    wp_update_post([
        'ID' => $attachment_id,
        'post_title' => $default_alt_text,
        'post_name' => sanitize_title($default_alt_text)
    ]);
}

function wpcm_update_media_on_post_save($post_id) {
    if (defined('DOING_AUTOSAVE') && DOING_AUTOSAVE) return;
    if (wp_is_post_revision($post_id) || wp_is_post_autosave($post_id)) return;

    $attachments = get_posts([
        'post_type' => 'attachment',
        'posts_per_page' => -1,
        'post_parent' => $post_id,
    ]);

    foreach ($attachments as $attachment) {
        $default_alt_text = get_the_title($post_id) !== 'Rascunho automático' ? get_the_title($post_id) : preg_replace('/\..+$/', '', get_bloginfo('name'));
        $default_alt_text = str_replace('-', ' ', $default_alt_text);

        update_post_meta($attachment->ID, '_wp_attachment_image_alt', $default_alt_text);
        wp_update_post([
            'ID' => $attachment->ID,
            'post_title' => $default_alt_text,
            'post_name' => sanitize_title($default_alt_text)
        ]);
    }
}
