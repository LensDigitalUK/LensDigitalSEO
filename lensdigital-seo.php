<?php
/*
Plugin Name: LensDigital SEO
Description: Internal SEO analysis tool for posts — meta, H1 (including Elementor), headings and outbound links.
Version: 2.1
Author: Lens Digital
*/
if ( ! defined( 'ABSPATH' ) ) exit;

// Bootstrap
add_action('admin_menu', 'lensdigital_add_admin_menu');
add_action('admin_enqueue_scripts', 'lensdigital_enqueue_assets');

// Trigger CSV export early
add_action('admin_init', 'lensdigital_handle_csv_export');

function lensdigital_handle_csv_export(){
    if(isset($_POST['lensdigital_export_csv']) && current_user_can('manage_options')){
        lensdigital_export_csv();
    }
}

function lensdigital_add_admin_menu(){
    add_management_page('LensDigital SEO','LensDigital SEO','manage_options','lensdigital-seo','lensdigital_render_page');
}

function lensdigital_enqueue_assets($hook){
    if($hook !== 'tools_page_lensdigital-seo') return;
    wp_enqueue_style('lensdigital-seo-style', plugin_dir_url(__FILE__).'assets/style.css', [], '2.1');
}

function lensdigital_render_page(){
    // Handle export request
    if(isset($_POST['lensdigital_export_csv']) && current_user_can('manage_options')){
        lensdigital_export_csv();
    }

    $posts = get_posts(['numberposts'=>-1,'post_type'=>'post']);
    usort($posts, function($a,$b){ return strcasecmp($a->post_title,$b->post_title); });

    echo '<div class="wrap"><h1>LensDigital SEO — Analysis</h1>';

    // Export form
    echo '<form method="post"><button type="submit" name="lensdigital_export_csv" class="button button-primary">Export CSV</button></form><br>';

    echo '<table class="lensdigital-seo-table">';
    echo '<thead><tr><th>Post</th><th>Meta Title</th><th>Meta Description</th><th>H1</th><th>Headings</th><th>Links Out</th></tr></thead><tbody>';

    foreach($posts as $post){
        $meta_title = lensdigital_get_meta_title($post->ID);
        $meta_desc  = lensdigital_get_meta_description($post->ID);
        $h1 = lensdigital_get_h1($post);
        $headings = lensdigital_get_headings_html($post);
        $links_out = lensdigital_get_links_out_html($post);

        echo '<tr>';
        echo '<td style="vertical-align:top;"><a href="'.esc_url(get_permalink($post)).'" target="_blank">'.esc_html($post->post_title).'</a></td>';
        echo '<td style="vertical-align:top;">'.esc_html($meta_title).'</td>';
        echo '<td style="vertical-align:top;">'.esc_html($meta_desc).'</td>';
        echo '<td style="vertical-align:top;">'.esc_html($h1).'</td>';
        echo '<td style="vertical-align:top;">'.$headings.'</td>';
        echo '<td style="vertical-align:top;">'.$links_out.'</td>';
        echo '</tr>';
    }

    echo '</tbody></table></div>';
}

// --- Meta title (supports SlimSEO) ---
function lensdigital_get_meta_title($post_id){

    // SlimSEO (array)
    $slim = get_post_meta($post_id, 'slim_seo', false);
    if (!empty($slim)) {
       $latest = end($slim);
       if (is_array($latest) && !empty($latest['title'])) {
           return $latest['title'];
       }
    }
    // Fallback
    return get_the_title($post_id);
}

// --- Meta description ---
function lensdigital_get_meta_description($post_id){

    // SlimSEO (array)
	$slim = get_post_meta($post_id, 'slim_seo', false);
	if (!empty($slim)) {
		$latest = end($slim);
		if (is_array($latest) && !empty($latest['description'])) {
			return $latest['description'];
		}
	}

    // Fallback to excerpt
    $excerpt = wp_strip_all_tags(get_the_excerpt($post_id));
    return !empty($excerpt) ? $excerpt : '';
}

// --- H1 extraction (simplified: use post title) ---
function lensdigital_get_h1($post){
    return get_the_title($post->ID);
}

function lensdigital_find_elementor_heading($elements, $target_tag='h1', $check_root_settings=false){
    foreach($elements as $el){
        if(!is_array($el)) continue;
        if(isset($el['widgetType']) && $el['widgetType']==='heading'){
            $settings = $el['settings'] ?? [];
            $size = isset($settings['size']) ? strtolower($settings['size']) : (isset($settings['title_size'])?strtolower($settings['title_size']):'');
            $title = $settings['title'] ?? '';
            if($title && ($size===$target_tag || (!$size && $target_tag==='h1' && !empty($settings['title'])))){
                return $title;
            }
        }
        $children_keys = ['elements','inner','widgets','container','settings'];
        foreach($children_keys as $k){
            if(!empty($el[$k]) && is_array($el[$k])){
                $res = lensdigital_find_elementor_heading($el[$k], $target_tag, $check_root_settings);
                if($res) return $res;
            }
        }
        if($check_root_settings && isset($el['settings']) && is_array($el['settings'])){
            $s = $el['settings'];
            if(isset($s['title']) && isset($s['title_size']) && strtolower($s['title_size'])===$target_tag){
                return $s['title'];
            }
        }
    }
    return null;
}

function lensdigital_get_headings_html($post_or_id) {
    // Normalize $post object
    if (is_numeric($post_or_id)) {
        $post = get_post((int) $post_or_id);
    } elseif (is_object($post_or_id) && isset($post_or_id->ID)) {
        $post = $post_or_id;
    } else {
        return 'None';
    }
    if (!$post) return 'None';

    $headings = [];

    // ---------- 1) Try Elementor sources first ----------
    $elementor_headings = [];
    $elementor_meta_keys = ['_elementor_data', '_elementor_json'];
    foreach ($elementor_meta_keys as $meta_key) {
        $elementor_data = get_post_meta($post->ID, $meta_key, true);
        if ($elementor_data) {
            $arr = is_string($elementor_data) ? json_decode($elementor_data, true) : $elementor_data;
            if (is_array($arr) && function_exists('lensdigital_find_all_elementor_headings')) {
                $found = lensdigital_find_all_elementor_headings($arr);
                if (!empty($found) && is_array($found)) {
                    // collect and continue checking other keys (some sites have multiple)
                    foreach ($found as $f) {
                        $elementor_headings[] = $f;
                    }
                }
            }
        }
    }

    // Normalize Elementor results and fix levels (no H0)
    $normalized_elementor = [];
    if (!empty($elementor_headings)) {
        foreach ($elementor_headings as $f) {
            $title = isset($f['title']) ? trim(wp_strip_all_tags($f['title'])) : '';
            $raw_size = isset($f['size']) ? strtolower($f['size']) : '';
            // try to extract numeric from strings like 'h2' or '2'
            if (preg_match('/h([1-6])/', $raw_size, $m)) {
                $lvl = intval($m[1]);
            } elseif (preg_match('/^([1-6])$/', $raw_size, $m2)) {
                $lvl = intval($m2[1]);
            } else {
                // default when Elementor doesn't specify — use H2
                $lvl = 2;
            }
            if ($title !== '') {
                $normalized_elementor[] = ['size' => 'h' . $lvl, 'title' => $title];
            }
        }
    }

    // If we found any elementor headings, use them (prefer Elementor)
    if (!empty($normalized_elementor)) {
        $headings = array_merge($headings, $normalized_elementor);
    } else {
        // ---------- 2) Fallback: parse the rendered content (more reliable than raw post_content) ----------
        $rendered = apply_filters('the_content', $post->post_content);
        if (preg_match_all('/<h([1-6])[^>]*>(.*?)<\/h\1>/is', $rendered, $matches, PREG_SET_ORDER)) {
            foreach ($matches as $m) {
                $lvl = intval($m[1]);
                $text = trim(wp_strip_all_tags($m[2]));
                if ($text !== '') {
                    $headings[] = ['size' => 'h' . $lvl, 'title' => $text];
                }
            }
        }
    }

    // ---------- 3) Deduplicate (size|title) while preserving first-seen order ----------
    $unique = [];
    $final = [];
    foreach ($headings as $h) {
        $key = (isset($h['size']) ? $h['size'] : '') . '|' . (isset($h['title']) ? $h['title'] : '');
        if ($key === '|' || trim($h['title']) === '') continue;
        if (!isset($unique[$key])) {
            $unique[$key] = true;
            $final[] = $h;
        }
    }
    $headings = $final;

    if (empty($headings)) {
        return 'None';
    }

    // ---------- 4) Ensure there is at least one real H1 if present (do not promote H2 to H1) ----------
    $has_h1 = false;
    foreach ($headings as $h) {
        if (isset($h['size']) && strtolower($h['size']) === 'h1') {
            $has_h1 = true;
            break;
        }
    }
    // Note: we *do not* auto-upgrade H2->H1. If no H1 exists, we still show headings but you can check H1 specifically elsewhere.

    // ---------- 5) Render HTML with hierarchy indentation ----------
    $out = '<ul class="lensdigital-heading-list" style="list-style:none;margin:0;padding:0;">';
    foreach ($headings as $h) {
        $level = isset($h['size']) ? intval(substr($h['size'], 1)) : 2;
        if ($level < 1 || $level > 6) $level = 2; // safety
        $indent_px = max(0, ($level - 1) * 14); // smaller indent than before
        $out .= '<li style="margin-left:' . $indent_px . 'px; margin-bottom:4px;">';
        $out .= '<strong>' . esc_html(strtoupper($h['size'])) . ':</strong> ' . esc_html($h['title']);
        $out .= '</li>';
    }
    $out .= '</ul>';

    // If you want an explicit H1 status flag, you could append it:
    // if (!$has_h1) $out = '<div class="ld-h1-missing" style="color:#c00;font-weight:bold">H1 missing</div>' . $out;

    return $out;
}

function lensdigital_find_all_elementor_headings($elements) {
    $found = [];

    foreach ($elements as $el) {
        // Recurse into child elements
        if (isset($el['elements']) && is_array($el['elements'])) {
            $found = array_merge($found, lensdigital_find_all_elementor_headings($el['elements']));
        }

        if (!isset($el['widgetType']) || $el['widgetType'] === '') {
            continue;
        }

        $widget = strtolower($el['widgetType']);
        $settings = isset($el['settings']) ? $el['settings'] : [];

        /* -----------------------------------------------------
           1. Elementor "Heading" Widget
           ----------------------------------------------------- */
        if ($widget === 'heading') {
            $title = isset($settings['title']) ? wp_strip_all_tags($settings['title']) : '';
            $tag   = isset($settings['header_size']) ? strtolower($settings['header_size']) : 'h2';

            $found[] = [
                'title' => $title,
                'size'  => $tag
            ];
            continue;
        }

        /* -----------------------------------------------------
           2. Elementor "Text Editor" widget (can contain <h1>)
           ----------------------------------------------------- */
        if ($widget === 'text-editor' && !empty($settings['editor'])) {
            if (preg_match_all('/<h([1-6])[^>]*>(.*?)<\/h\1>/is', $settings['editor'], $matches, PREG_SET_ORDER)) {
                foreach ($matches as $m) {
                    $found[] = [
                        'title' => wp_strip_all_tags($m[2]),
                        'size'  => 'h' . $m[1]
                    ];
                }
            }
            continue;
        }

        /* -----------------------------------------------------
           3. Elementor "HTML" widget
           ----------------------------------------------------- */
        if ($widget === 'html' && !empty($settings['html'])) {
            if (preg_match_all('/<h([1-6])[^>]*>(.*?)<\/h\1>/is', $settings['html'], $matches, PREG_SET_ORDER)) {
                foreach ($matches as $m) {
                    $found[] = [
                        'title' => wp_strip_all_tags($m[2]),
                        'size'  => 'h' . $m[1]
                    ];
                }
            }
            continue;
        }

        /* -----------------------------------------------------
           4. Fallback: ANY widget containing <h1> in raw data
           ----------------------------------------------------- */
        $raw = json_encode($el);
        if (preg_match_all('/<h([1-6])[^>]*>(.*?)<\/h\1>/is', $raw, $matches, PREG_SET_ORDER)) {
            foreach ($matches as $m) {
                $found[] = [
                    'title' => wp_strip_all_tags($m[2]),
                    'size'  => 'h' . $m[1]
                ];
            }
        }
    }

    return $found;
}

// --- Links out HTML with clickable text ---
function lensdigital_get_links_out_html($post){
    $html = '';
    if(preg_match_all('/<a[^>]+href=["\']([^"\']+)["\'][^>]*>(.*?)<\/a>/is',$post->post_content,$matches,PREG_SET_ORDER)){
        foreach($matches as $m){
			// Skip if the link contains an image
            if(preg_match('/<img[^>]*>/i', $m[2])) continue;
			
            $url = esc_url_raw($m[1]);
            $text = wp_strip_all_tags($m[2]);
            $html .= '<a href="'.esc_url($url).'" target="_blank">'.esc_html($text ?: $url).'</a><br>';
        }
    }
    return $html !== '' ? $html : 'None';
}

function lensdigital_export_csv(){
    if(!current_user_can('manage_options')) return;

    $posts = get_posts(['numberposts'=>-1,'post_type'=>'post']);
    usort($posts, function($a,$b){ return strcasecmp($a->post_title,$b->post_title); });

    // Send CSV headers
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename=lensdigital-seo.csv');

    $output = fopen('php://output', 'w');
    fputcsv($output, ['Post','Meta Title','Meta Description','H1','Headings','Links Out']);

    foreach($posts as $post){
        $meta_title = lensdigital_get_meta_title($post->ID);
        $meta_desc  = lensdigital_get_meta_description($post->ID);
        $h1 = lensdigital_get_h1($post);
        $headings = strip_tags(lensdigital_get_headings_html($post));
        $links_out = strip_tags(lensdigital_get_links_out_html($post));

        fputcsv($output, [
            get_the_title($post->ID),
            $meta_title,
            $meta_desc,
            $h1,
            $headings,
            $links_out
        ]);
    }

    fclose($output);
    exit;
}
