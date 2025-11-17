<?php
/*
Plugin Name: LensDigital SEO
Description: Internal SEO analysis tool listing posts with titles, meta, headings, and links.
Version: 1.0
Author: Lens Digital
*/

if (!defined('ABSPATH')) exit;

// Add menu under Tools
add_action('admin_menu', function() {
    add_management_page('LensDigital SEO', 'LensDigital SEO', 'manage_options', 'lensdigital-seo', 'lensdigital_seo_page');
});

// Enqueue CSS
add_action('admin_enqueue_scripts', function($hook) {
    if($hook !== 'tools_page_lensdigital-seo') return;
    wp_enqueue_style('lensdigital-seo-style', plugin_dir_url(__FILE__).'assets/style.css');
});

// --- Functions ---

// Meta extraction for multiple SEO plugins
function lensdigital_get_meta($post_id) {
    $meta = [
        'title' => '',
        'description' => '',
    ];

    // Yoast
    if(class_exists('WPSEO_Meta')) {
        $meta['title'] = WPSEO_Meta::get_value('title', $post_id) ?: get_the_title($post_id);
        $meta['description'] = WPSEO_Meta::get_value('metadesc', $post_id);
    }
    // RankMath
    elseif(function_exists('rank_math_get_post_meta')) {
        $meta['title'] = rank_math_get_post_meta($post_id, 'title') ?: get_the_title($post_id);
        $meta['description'] = rank_math_get_post_meta($post_id, 'description');
    }
    // SlimSEO
    elseif(function_exists('slim_seo')) {
        $meta['title'] = get_post_meta($post_id, '_slim_seo_title', true) ?: get_the_title($post_id);
        $meta['description'] = get_post_meta($post_id, '_slim_seo_description', true);
    }
    // All In One SEO
    elseif(class_exists('All_in_One_SEO_Pack')) {
        $meta['title'] = get_post_meta($post_id, '_aioseop_title', true) ?: get_the_title($post_id);
        $meta['description'] = get_post_meta($post_id, '_aioseop_description', true);
    }
    else {
        $meta['title'] = get_the_title($post_id);
    }

    return $meta;
}

// Headings Extraction (including Elementor)
function lensdigital_get_headings($post) {
    $headings = [];

    // Standard content
    if(preg_match_all('/<(h[1-6])>(.*?)<\/\1>/i', $post->post_content, $matches, PREG_SET_ORDER)) {
        foreach($matches as $m) {
            $level = intval(substr($m[1],1));
            $indent = str_repeat('&nbsp;&nbsp;&nbsp;', $level - 1);
            $headings[] = $indent . '<strong>' . strtoupper($m[1]) . '</strong>: ' . wp_strip_all_tags($m[2]);
        }
    }

    // Elementor content
    $elementor_data = get_post_meta($post->ID, '_elementor_data', true);
    if($elementor_data) {
        $data = json_decode($elementor_data, true);
        if($data && is_array($data)) {
            $extract_headings = function($elements) use (&$extract_headings, &$headings) {
                foreach($elements as $el) {
                    if(isset($el['elType'], $el['settings'])) {
                        if($el['elType'] === 'widget' && isset($el['settings']['title'])) {
                            $tag = $el['settings']['title_size'] ?? 'h2';
                            $level = intval(substr($tag,1));
                            $indent = str_repeat('&nbsp;&nbsp;&nbsp;', $level - 1);
                            $headings[] = $indent . '<strong>' . strtoupper($tag) . '</strong>: ' . wp_strip_all_tags($el['settings']['title']);
                        }
                        if(!empty($el['elements'])) $extract_headings($el['elements']);
                    }
                }
            };
            $extract_headings($data);
        }
    }

    return $headings;
}

// Internal links
function lensdigital_get_links($post) {
    $content = $post->post_content;
    $matches = [];
    preg_match_all('/<a[^>]+href=["\']([^"\']+)["\']/i', $content, $matches);
    return $matches[1] ?? [];
}

// --- Main Page ---
function lensdigital_seo_page() {
    $posts = get_posts(['numberposts' => -1, 'post_type' => 'post']);
    echo '<div class="wrap"><h1>LensDigital SEO Analysis</h1>';
    echo '<table class="lensdigital-seo-table">';
    echo '<thead><tr><th>Post</th><th>Page Title</th><th>Meta Title</th><th>Meta Description</th><th>Headings</th><th>Links Out</th><th>Links In</th></tr></thead>';
    echo '<tbody>';

    // Build reverse lookup for inbound links
    $all_links = [];
    foreach($posts as $p) {
        $all_links[$p->ID] = lensdigital_get_links($p);
    }

    foreach($posts as $index => $post) {
        $meta = lensdigital_get_meta($post->ID);
        $headings = lensdigital_get_headings($post);
        $out_links = lensdigital_get_links($post);

        // inbound links
        $in_links = [];
        foreach($all_links as $pid => $links) {
            if($pid == $post->ID) continue;
            foreach($links as $l) {
                if(get_permalink($post->ID) == $l) $in_links[] = get_the_title($pid);
            }
        }

        echo '<tr>';
        echo '<td valign="top">'.esc_html($post->post_title).'</td>';
        echo '<td valign="top">'.esc_html(get_the_title($post->ID)).'</td>';
        echo '<td valign="top">'.esc_html($meta['title']).'</td>';
        echo '<td valign="top">'.esc_html($meta['description']).'</td>';
        echo '<td valign="top">'.implode('<br>', $headings).'</td>';
        echo '<td valign="top">'.implode('<br>', $out_links).'</td>';
        echo '<td valign="top">'.implode('<br>', $in_links).'</td>';
        echo '</tr>';
    }

    echo '</tbody></table></div>';
}