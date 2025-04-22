<?php
/*
Plugin Name: WP Auto Commenter
Description: Automatically posts a public comment on post/page visit (with timestamp and IP). Allows configurable number per configurable minutes per IP per post. No user interaction.
Version: 1.1.0
Author: Aravinddwara
*/

if ( ! defined( 'ABSPATH' ) ) exit;

// 1. Register settings and admin menu
add_action('admin_menu', function() {
    add_options_page('Auto Visitor Comments Settings', 'Auto Visitor Comments', 'manage_options', 'avc-settings', 'avc_settings_page');
});

add_action('admin_init', function() {
    register_setting('avc_options', 'avc_comment_template');
    register_setting('avc_options', 'avc_show_ip');
    register_setting('avc_options', 'avc_show_time');
    register_setting('avc_options', 'avc_max_per_window');
    register_setting('avc_options', 'avc_window_minutes');
});

function avc_settings_page() {
    ?>
    <div class="wrap"><h1>Auto Visitor Comments Settings</h1>
    <form method="post" action="options.php">
        <?php settings_fields('avc_options'); do_settings_sections('avc_options'); ?>
        <table class="form-table">
            <tr valign="top">
                <th scope="row">Comment template</th>
                <td>
                    <input type="text" name="avc_comment_template" value="<?php echo esc_attr(get_option('avc_comment_template', 'Visited on {time}{ip}')); ?>" style="width: 400px;" />
                    <br />
                    <small>Available placeholders: <code>{ip}</code>, <code>{time}</code>. Leave blank to use default.</small>
                </td>
            </tr>
            <tr valign="top">
                <th scope="row">Show IP?</th>
                <td><input type="checkbox" name="avc_show_ip" value="1" <?php checked(1, get_option('avc_show_ip', 1)); ?> /></td>
            </tr>
            <tr valign="top">
                <th scope="row">Show timestamp?</th>
                <td><input type="checkbox" name="avc_show_time" value="1" <?php checked(1, get_option('avc_show_time', 1)); ?> /></td>
            </tr>
            <tr valign="top">
                <th scope="row">Max comments per window (per IP)</th>
                <td><input type="number" min="1" name="avc_max_per_window" value="<?php echo esc_attr(get_option('avc_max_per_window', 3)); ?>" /></td>
            </tr>
            <tr valign="top">
                <th scope="row">Time window (minutes)</th>
                <td><input type="number" min="1" name="avc_window_minutes" value="<?php echo esc_attr(get_option('avc_window_minutes', 5)); ?>" /></td>
            </tr>
        </table>
        <?php submit_button(); ?>
    </form></div>
    <?php
}

// 2. Per-post/page enable/disable meta box
add_action('add_meta_boxes', function() {
    add_meta_box('avc_enable_box', 'Auto Visitor Comments', 'avc_meta_box', ['post','page'], 'side');
});

function avc_meta_box($post) {
    $val = get_post_meta($post->ID, '_avc_enable', true);
    echo '<label><input type="checkbox" name="avc_enable" value="1" '.checked($val, '1', false).' /> Enable auto-comments for this content</label>';
}

add_action('save_post', function($post_id) {
    // Check autosave or permissions if needed
    if (defined('DOING_AUTOSAVE') && DOING_AUTOSAVE) return;
    if (!current_user_can('edit_post', $post_id)) return;

    if (isset($_POST['avc_enable']) && $_POST['avc_enable'] === '1') {
        update_post_meta($post_id, '_avc_enable', '1');
    } else {
        update_post_meta($post_id, '_avc_enable', '0');
    }
});

// 3. Main logic (updated to use settings)
function avc_maybe_autocomment() {
    if (is_singular()) {
        global $post;
        $post_id = $post->ID;
        // Only run if enabled for this post
        $enabled = get_post_meta($post_id, '_avc_enable', true);
        if ($enabled !== '1') return;

        if (empty($_SERVER['REMOTE_ADDR'])) return; // Safety check
        $ip = $_SERVER['REMOTE_ADDR'];
        $now = current_time('timestamp');
        $max = (int) get_option('avc_max_per_window', 3);
        if ($max < 1) $max = 3;
        $mins = (int) get_option('avc_window_minutes', 5);
        if ($mins < 1) $mins = 5;

        // Check recent auto-comments by this IP on this post
        $args = array(
            'post_id' => $post_id,
            'author_ip' => $ip,
            'date_query' => array(
                'after' => gmdate('Y-m-d H:i:s', $now - $mins*60),
                'inclusive' => true
            ),
            'type' => 'comment',
            'meta_query' => array(
                array(
                    'key' => 'avc_auto',
                    'value' => '1',
                    'compare' => '='
                )
            )
        );
        $recent = get_comments($args);
        if (count($recent) < $max) {
            $ip_show = get_option('avc_show_ip', 1) ? (' from IP '.esc_html($ip)) : '';
            $time_show = get_option('avc_show_time', 1) ? date('Y-m-d H:i:s', $now) : '';
            $tpl = get_option('avc_comment_template', 'Visited on {time}{ip}');
            if (trim($tpl) === '') {
                $tpl = 'Visited on {time}{ip}';
            }
            $comment_content = str_replace(
                ['{time}', '{ip}'],
                [$time_show, $ip_show],
                $tpl
            );

            // Sanitize comment content to avoid issues
            $comment_content = wp_kses_post($comment_content);

            $commentarr = array(
                'comment_post_ID' => $post_id,
                'comment_author' => 'Auto Visitor',
                'comment_author_email' => '',
                'comment_author_url' => '',
                'comment_content' => $comment_content,
                'comment_type' => '',
                'comment_parent' => 0,
                'user_id' => 0,
                'comment_approved' => 1,
                'comment_agent' => 'auto-visitor-comments-plugin',
                'comment_author_IP' => $ip,
            );
            $cid = wp_insert_comment($commentarr);
            if ($cid) {
                add_comment_meta($cid, 'avc_auto', '1');
            }
        }
    }
}
add_action('template_redirect', 'avc_maybe_autocomment');
