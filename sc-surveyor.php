<?php
/*
Plugin Name: Shotcode Surveyor - Shortcode Usage Viewer
Description: View all shortcodes used across your site.
Version: 2.1
Author: https://github.com/codelyfe/Shortcode-Surveyor
*/

add_action('admin_menu', function () {
    add_menu_page('Shortcode Viewer', 'Shortcode Viewer', 'manage_options', 'shortcode-usage-viewer', 'suv_admin_page');
});

//add_action('admin_enqueue_scripts', function () {
//    wp_enqueue_style('suv-css', plugin_dir_url(__FILE__) . 'suv.css');
//    wp_enqueue_script('suv-js', plugin_dir_url(__FILE__) . 'suv.js', ['jquery'], null, true);
//});

function suv_admin_page() {
    echo '<div class="wrap"><h1>Shotcode Surveyor - Shortcode Usage Viewer</h1>';
    echo '<label><input type="checkbox" id="toggle-params" /> Show Parameters</label>';
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
                if (!empty($info['params'][0])) {
                    echo '<ul class="sc-params" style="display:none;">';
                    foreach ($info['params'][0] as $key => $val) {
                        echo "<li>$key = $val</li>";
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
