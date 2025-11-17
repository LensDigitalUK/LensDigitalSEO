<?php
/*
Plugin Name: LensDigital SEO
Description: Internal SEO analysis tool for posts, meta, headings, and links.
Version: 1.0
Author: Lens Digital
*/

if ( ! defined( 'ABSPATH' ) ) exit;

class LensDigital_SEO {

    public function __construct() {
        add_action( 'admin_menu', [ $this, 'add_admin_menu' ] );
        add_action( 'admin_enqueue_scripts', [ $this, 'enqueue_assets' ] );
    }

    public function add_admin_menu() {
        add_management_page(
            'LensDigital SEO',
            'LensDigital SEO',
            'manage_options',
            'lensdigital-seo',
            [ $this, 'render_page' ]
        );
    }

    public function enqueue_assets($hook) {
        if ( $hook !== 'tools_page_lensdigital-seo' ) return;
        wp_enqueue_style( 'lensdigital-seo-style', plugin_dir_url(__FILE__) . 'assets/css/style.css' );
    }

    public function render_page() {
        $posts = get_posts([ 'numberposts' => -1, 'post_type' => 'post' ]);
        usort($posts, fn($a, $b) => strcmp($a->post_title, $b->post_title));
        echo '<div class="wrap"><h1>LensDigital SEO Analysis</h1><table class="lensdigital-seo-table"><thead><tr>';
        echo '<th>Post</th><th>Meta Title</th><th>Meta Description</th><th>H1</th><th>Headings</th><th>Links Out</th></tr></thead><tbody>';

        foreach($posts as $post) {
            $meta_title = $this->get_meta_title($post);
            $meta_desc = $this->get_meta_description($post);
            $h1 = $this->get_h1($post);
            $headings = $this->get_headings($post);
            $links_out = $this->get_links_out($post);

            echo '<tr>';
            echo '<td style="vertical-align: top;"><a href="' . get_permalink($post) . '" target="_blank">' . esc_html($post->post_title) . '</a></td>';
            echo '<td style="vertical-align: top;">' . esc_html($meta_title) . '</td>';
            echo '<td style="vertical-align: top;">' . esc_html($meta_desc) . '</td>';
            echo '<td style="vertical-align: top;">' . esc_html($h1) . '</td>';
            echo '<td style="vertical-align: top;">' . $headings . '</td>';
            echo '<td style="vertical-align: top;">' . $links_out . '</td>';
            echo '</tr>';
        }

        echo '</tbody></table></div>';
    }

    private function get_meta_title($post) {
        $title = '';
        // Yoast
        if (defined('WPSEO_VERSION')) {
            $title = get_post_meta($post->ID, '_yoast_wpseo_title', true);
        }
        // RankMath
        if (defined('RANK_MATH_VERSION') && empty($title)) {
            $title = get_post_meta($post->ID, 'rank_math_title', true);
        }
        // SlimSEO
        if (defined('SLIM_SEO_VERSION') && empty($title)) {
            $title = get_post_meta($post->ID, '_slim_seo_title', true);
        }
        // All in One SEO
        if (defined('AIOSEO_VERSION') && empty($title)) {
            $title = get_post_meta($post->ID, '_aioseo_title', true);
        }
        if (empty($title)) $title = get_the_title($post);
        return $title;
    }

    private function get_meta_description($post) {
        $desc = '';
        if (defined('WPSEO_VERSION')) {
            $desc = get_post_meta($post->ID, '_yoast_wpseo_metadesc', true);
        }
        if (defined('RANK_MATH_VERSION') && empty($desc)) {
            $desc = get_post_meta($post->ID, 'rank_math_description', true);
        }
        if (defined('SLIM_SEO_VERSION') && empty($desc)) {
            $desc = get_post_meta($post->ID, '_slim_seo_description', true);
        }
        if (defined('AIOSEO_VERSION') && empty($desc)) {
            $desc = get_post_meta($post->ID, '_aioseo_description', true);
        }
        if (empty($desc)) $desc = get_the_excerpt($post);
        return $desc;
    }

    private function get_h1($post) {
        // Try Elementor first
        $elementor_data = get_post_meta($post->ID, '_elementor_data', true);
        if ($elementor_data) {
            $elements = json_decode($elementor_data, true);
            $h1 = $this->find_h1_elementor($elements);
            if ($h1) return $h1;
        }
        // Fallback: search content for first <h1>
        if (preg_match('/<h1.*?>(.*?)<\/h1>/is', $post->post_content, $matches)) {
            return strip_tags($matches[1]);
        }
        return 'H1 missing';
    }

    private function find_h1_elementor($elements) {
        foreach($elements as $el) {
            if (isset($el['widgetType']) && $el['widgetType'] === 'heading') {
                $settings = $el['settings'] ?? [];
                if (($settings['title'] ?? '') && ($settings['size'] ?? '') === 'h1') {
                    return $settings['title'];
                }
            }
            if (!empty($el['elements'])) {
                $found = $this->find_h1_elementor($el['elements']);
                if ($found) return $found;
            }
        }
        return null;
    }

    private function get_headings($post) {
        $headings = '';
        if (preg_match_all('/<h([1-6]).*?>(.*?)<\/h\1>/is', $post->post_content, $matches, PREG_SET_ORDER)) {
            foreach($matches as $match) {
                $level = intval($match[1]);
                $text = strip_tags($match[2]);
                $indent = str_repeat('&nbsp;&nbsp;', $level - 1);
                $headings .= $indent . '<strong>H' . $level . '</strong>: ' . $text . '<br>';
            }
        }
        return $headings;
    }

    private function get_links_out($post) {
        $links = '';
        if (preg_match_all('/<a .*?href=["\'](.*?)["\'].*?>(.*?)<\/a>/is', $post->post_content, $matches, PREG_SET_ORDER)) {
            foreach($matches as $match) {
                $url = esc_url($match[1]);
                $text = strip_tags($match[2]);
                $links .= '<a href="' . $url . '" target="_blank">' . esc_html($text) . '</a><br>';
            }
        }
        return $links;
    }

}

new LensDigital_SEO();
