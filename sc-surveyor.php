<?php
/*
Plugin Name: Shotcode Surveyor - Shortcode Usage Viewer
Description: View all shortcodes used across your site.
Version: 2.3
Author: https://github.com/codelyfe/Shortcode-Surveyor
*/

add_action('admin_menu', function () {
    add_menu_page('Shortcode Viewer', 'Shortcode Viewer', 'manage_options', 'shortcode-usage-viewer', 'suv_admin_page');
});

add_action('wp_ajax_suv_update_param', function () {
    $post_id = intval($_POST['post_id']);
    $old = sanitize_text_field($_POST['old']);
    $key = sanitize_text_field($_POST['key']);
    $val = sanitize_text_field($_POST['val']);

    $post = get_post($post_id);
    if (!$post) wp_send_json_error('Post not found');

    $content = $post->post_content;
    $pattern = '/(\[' . preg_quote($old) . '[^\]]*\b' . preg_quote($key) . '=")[^"]*(")/';
    $new_content = preg_replace($pattern, '${1}' . $val . '${2}', $content, 1);

    if ($new_content === null) wp_send_json_error('Regex error');

    $result = wp_update_post(['ID' => $post_id, 'post_content' => $new_content]);
    if (is_wp_error($result)) {
        wp_send_json_error('Update failed');
    }

    wp_send_json_success('Updated');
});

add_action('wp_ajax_suv_update_shortcode_name', function () {
    $post_id = intval($_POST['post_id']);
    $old = sanitize_text_field($_POST['old']);
    $new = sanitize_text_field($_POST['new']);

    $post = get_post($post_id);
    if (!$post) wp_send_json_error('Post not found');

    $pattern = '/\[' . preg_quote($old, '/') . '\b/';
    $new_content = preg_replace($pattern, '[' . $new, $post->post_content, 1);

    if ($new_content === null) wp_send_json_error('Regex error');

    $result = wp_update_post(['ID' => $post_id, 'post_content' => $new_content]);
    if (is_wp_error($result)) {
        wp_send_json_error('Update failed');
    }

    wp_send_json_success('Shortcode name updated');
});

add_action('admin_footer', function () {
    ?>
    <script>
    jQuery(document).on('click', '.suv-save-param', function () {
        var $row = jQuery(this).closest('li');
        var post_id = $row.data('post');
        var old = $row.data('shortcode');
        var key = $row.find('input.param-key').val();
        var val = $row.find('input.param-val').val();

        jQuery.post(ajaxurl, {
            action: 'suv_update_param',
            post_id: post_id,
            old: old,
            key: key,
            val: val
        }, function (res) {
            console.log(res);
            if (res.success) {
                alert('Saved!');
            } else {
                alert('Error: ' + res.data);
            }
        });
    });

    jQuery(document).on('click', '.suv-save-name', function () {
        var $row = jQuery(this).closest('li');
        var post_id = $row.data('post');
        var old = $row.data('shortcode');
        var newname = $row.find('input.shortcode-new').val();

        jQuery.post(ajaxurl, {
            action: 'suv_update_shortcode_name',
            post_id: post_id,
            old: old,
            new: newname
        }, function (res) {
            console.log(res);
            if (res.success) {
                alert('Shortcode name updated!');
            } else {
                alert('Error: ' + res.data);
            }
        });
    });
    </script>
    <?php
});

function suv_admin_page() {
    echo '<div class="wrap"><h1>Shotcode Surveyor - Shortcode Usage Viewer</h1>';
    echo '<label><input type="checkbox" id="toggle-params" /> Show Parameters</label>';
    echo '<script>var ajaxurl = "' . admin_url('admin-ajax.php') . '";</script>';
    suv_tabs();
    echo '</div><script>jQuery(function($){$("#toggle-params").on("change",function(){$(".sc-params").toggle(this.checked);}).change();});</script>';
}

function suv_tabs() {
    $tabs = ['usage' => 'Shortcode Usage'];
    echo '<h2 class="nav-tab-wrapper">';
    foreach ($tabs as $key => $label) {
        $active = (!isset($_GET['tab']) && $key === 'usage') || (isset($_GET['tab']) && $_GET['tab'] === $key);
        echo '<a class="nav-tab ' . ($active ? 'nav-tab-active' : '') . '" href="?page=shortcode-usage-viewer&tab=' . $key . '">' . $label . '</a>';
    }
    echo '</h2><div style="margin-top: 20px;">';
    $active_tab = $_GET['tab'] ?? 'usage';
    call_user_func("suv_tab_$active_tab");
    echo '</div>';
}

function get_all_shortcodes() {
    global $shortcode_tags;
    return array_keys($shortcode_tags);
}

function get_all_posts() {
    return get_posts(['post_type' => 'any', 'numberposts' => -1, 'post_status' => 'any']);
}

function suv_tab_usage() {
    $shortcodes = get_all_shortcodes();
    $posts = get_all_posts();
    $results = [];

    foreach ($shortcodes as $sc) {
        $results[$sc] = ['count' => 0, 'posts' => []];
    }

    foreach ($posts as $post) {
        foreach ($shortcodes as $sc) {
            $pattern = get_shortcode_regex([$sc]);
            if (preg_match_all("/$pattern/", $post->post_content, $matches)) {
                $count = count($matches[0]);
                $results[$sc]['count'] += $count;
                $results[$sc]['posts'][] = [
                    'id' => $post->ID,
                    'title' => get_the_title($post),
                    'type' => get_post_type($post),
                    'modified' => get_the_modified_date('', $post),
                    'count' => $count,
                    'params' => array_unique(array_map(function ($shortcode) {
                        preg_match_all('/(\w+)="([^"]+)"/', $shortcode, $paramMatches);
                        return array_combine($paramMatches[1], $paramMatches[2]);
                    }, $matches[0]))
                ];
            }
        }
    }

    echo '<table class="widefat"><thead><tr><th>Shortcode</th><th>Total</th><th>Pages</th></tr></thead><tbody>';
    foreach ($results as $sc => $data) {
        if ($data['count']) {
            echo "<tr><td><strong>[$sc]</strong></td><td>{$data['count']}</td><td><ul>";
            foreach ($data['posts'] as $info) {
                $edit = get_edit_post_link($info['id']);
                $title = esc_html($info['title']);
                $type = $info['type'];
                $modified = $info['modified'];
                echo "<li><a href='$edit' target='_blank'>$title</a> ($type) – {$info['count']} time(s), last modified: $modified";
                echo "<ul><li data-post='{$info['id']}' data-shortcode='$sc'>
                        Rename shortcode:
                        <input class='shortcode-new' value='" . esc_attr($sc) . "' />
                        <button class='suv-save-name button button-small'>Rename</button>
                      </li></ul>";
                if (!empty($info['params'][0])) {
                    echo '<ul class="sc-params" style="display:none;">';
                    foreach ($info['params'][0] as $key => $val) {
                        echo "<li data-post='{$info['id']}' data-shortcode='$sc'>
                                <input class='param-key' value='" . esc_attr($key) . "' />
                                = 
                                <input class='param-val' value='" . esc_attr($val) . "' />
                                <button class='suv-save-param button button-small'>Save</button>
                              </li>";
                    }
                    echo '</ul>';
                }
                echo "</li>";
            }
            echo '</ul></td></tr>';
        }
    }
    echo '</tbody></table>';
}

