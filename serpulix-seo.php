<?php
/**
 * Plugin Name: Serpulix SEO
 * Plugin URI: https://serpulix.com
 * Description: Sync and publish SEO content from Serpulix and deploy schema.org structured data to your site.
 * Version: 4.11.1
 * Author: Serpulix
 * Author URI: https://serpulix.com
 * License: GPLv2 or later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain: serpulix-seo
 * Requires at least: 5.8
 * Requires PHP: 7.4
 * Update URI: https://serpulix.com/serpulix-seo
 */

// Prevent direct access
if (!defined('ABSPATH')) {
    exit;
}

// Define plugin constants
// Runtime version. Keep this identical to the Version header above and to
// Stable tag in readme.txt. GitHub releases are compared with this value.
define('SERPULIX_SEO_VERSION', '4.11.1');
define('SERPULIX_SEO_PLUGIN_DIR', plugin_dir_path(__FILE__));
define('SERPULIX_SEO_PLUGIN_URL', plugin_dir_url(__FILE__));
define('SERPULIX_SEO_PLUGIN_FILE', __FILE__);

// Include required files (simplified - only v2)
require_once SERPULIX_SEO_PLUGIN_DIR . 'includes/class-serpulix-cache.php';
require_once SERPULIX_SEO_PLUGIN_DIR . 'includes/class-seobot-api.php';
require_once SERPULIX_SEO_PLUGIN_DIR . 'includes/class-seobot-cpt.php';
require_once SERPULIX_SEO_PLUGIN_DIR . 'includes/class-seobot-sync-handler-v2.php';
require_once SERPULIX_SEO_PLUGIN_DIR . 'includes/class-seobot-images.php';
require_once SERPULIX_SEO_PLUGIN_DIR . 'includes/class-seobot-tech-seo.php';
require_once SERPULIX_SEO_PLUGIN_DIR . 'includes/class-seobot-sync.php';
require_once SERPULIX_SEO_PLUGIN_DIR . 'includes/class-serpulix-github-updater.php';
require_once SERPULIX_SEO_PLUGIN_DIR . 'includes/class-serpulix-indexnow-events.php';

// Activation and deactivation hooks
register_activation_hook(__FILE__, 'serpulix_seo_activate');
register_deactivation_hook(__FILE__, 'serpulix_seo_deactivate');

function serpulix_seo_activate() {
    // Create custom post type
    $cpt = new Serpulix_SEO_CPT();
    $cpt->register_post_type();

    // Seamless migration from the legacy "seobot-sync" plugin. Settings
    // (seobot_api_* options) and the post mapping (_seobot_page_id meta) live under
    // the same keys, so the connection + dashboard control carry over automatically —
    // no reconnect, no re-sync. We only deactivate the old plugin so its wp_footer
    // hook doesn't render duplicate schema alongside this one.
    if (!function_exists('is_plugin_active')) {
        require_once ABSPATH . 'wp-admin/includes/plugin.php';
    }
    if (is_plugin_active('seobot-sync/seobot-sync.php')) {
        deactivate_plugins('seobot-sync/seobot-sync.php');
    }

    // Flush rewrite rules to ensure clean slate
    flush_rewrite_rules();
}

function serpulix_seo_deactivate() {
    // Flush rewrite rules
    flush_rewrite_rules();
}

// Initialize the plugin
function serpulix_seo_init() {
    $plugin = new Serpulix_SEO_Sync();
    $plugin->run();

    Serpulix_SEO_GitHub_Updater::instance()->init();

    $indexnow_events = new Serpulix_SEO_IndexNow_Events();
    $indexnow_events->init();

    // Auto-flush rewrite rules on version change
    serpulix_seo_check_version();
}
add_action('plugins_loaded', 'serpulix_seo_init');

/**
 * Check plugin version and flush rewrite rules if changed
 */
function serpulix_seo_check_version() {
    $current_version = SERPULIX_SEO_VERSION;
    $saved_version = get_option('seobot_sync_version');

    if ($saved_version !== $current_version) {
        // Version changed - flush rewrite rules to clean up
        flush_rewrite_rules();
        update_option('seobot_sync_version', $current_version);

        // Add admin notice
        add_action('admin_notices', function() {
            echo '<div class="notice notice-success is-dismissible">';
            echo '<p><strong>Serpulix SEO:</strong> Plugin updated to version ' . esc_html(SERPULIX_SEO_VERSION) . '.</p>';
            echo '</div>';
        });
    }
}

/**
 * Inject SEOBot schema markup before closing body tag
 *
 * Runs on all singular pages and checks if SEOBot schema exists.
 * Only outputs schema if post has 'seobot_schema' meta field.
 * Compatible with all schema types: FAQPage, HowTo, Article, Product, etc.
 */
add_action('wp_footer', 'serpulix_seo_inject_schema', 100);

/**
 * Enqueue Content Studio CSS (base + per-post) in head
 */
add_action('wp_enqueue_scripts', 'serpulix_seo_enqueue_content_studio_assets');

function serpulix_seo_enqueue_content_studio_assets() {
    if (!is_singular()) return;

    $post_id = get_the_ID();
    $has_styles = get_post_meta($post_id, '_seobot_has_cs_styles', true);
    $has_js = get_post_meta($post_id, '_seobot_has_cs_js', true);

    if (!$has_styles && !$has_js) return;

    $upload_dir = wp_upload_dir();
    $seobot_url = $upload_dir['baseurl'] . '/seobot';
    $seobot_dir = $upload_dir['basedir'] . '/seobot';

    // Base CSS (shared)
    if ($has_styles && file_exists($seobot_dir . '/cs-base.css')) {
        wp_enqueue_style(
            'seobot-cs-base',
            $seobot_url . '/cs-base.css',
            array(),
            filemtime($seobot_dir . '/cs-base.css')
        );
    }

    // Per-post CSS
    if ($has_styles && file_exists($seobot_dir . '/cs-' . $post_id . '.css')) {
        wp_enqueue_style(
            'seobot-cs-post-' . $post_id,
            $seobot_url . '/cs-' . $post_id . '.css',
            array('seobot-cs-base'),
            filemtime($seobot_dir . '/cs-' . $post_id . '.css')
        );
    }

    // Per-post JS
    if ($has_js && file_exists($seobot_dir . '/cs-' . $post_id . '.js')) {
        wp_enqueue_script(
            'seobot-cs-post-' . $post_id,
            $seobot_url . '/cs-' . $post_id . '.js',
            array(),
            filemtime($seobot_dir . '/cs-' . $post_id . '.js'),
            true // in footer
        );
    }
}

/**
 * Decode a stored schema value into a list of JSON-LD objects. Content Studio stores one object (as a JSON
 * string); the Schema Analyzer stores a JSON array of objects.
 */
function serpulix_seo_schema_items($schema) {
    $decoded = is_string($schema) ? json_decode($schema, true) : $schema;
    if (empty($decoded) || !is_array($decoded)) {
        return array();
    }
    // Array-of-objects (sequential keys) vs a single object.
    $items = array_key_exists(0, $decoded) ? $decoded : array($decoded);
    return array_values(array_filter($items, function ($item) {
        return !empty($item) && is_array($item);
    }));
}

/** Does this JSON-LD object declare the given @type (case-insensitive, a list of types included)? */
function serpulix_seo_schema_has_type($item, $type) {
    if (!is_array($item) || !isset($item['@type'])) {
        return false;
    }
    $types = is_array($item['@type']) ? $item['@type'] : array($item['@type']);
    foreach ($types as $t) {
        if (is_string($t) && strtolower(preg_replace('#^https?://schema\.org/#i', '', $t)) === strtolower($type)) {
            return true;
        }
    }
    return false;
}

function serpulix_seo_print_schema_items($items) {
    foreach ($items as $item) {
        echo '<script type="application/ld+json">';
        // JSON_HEX_TAG|JSON_HEX_AMP hex-encode < > & so a field value containing
        // "</script>" can't break out of the script block (stored XSS). Slashes stay
        // unescaped for clean URLs.
        echo wp_json_encode($item, JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP);
        echo "</script>\n";
    }
}

function serpulix_seo_inject_schema() {
    if (!is_singular() && !is_front_page()) {
        return;
    }

    // Two independent channels, printed apart (QA SC-18):
    //  - seobot_schema       : Content Studio (single JSON object/string), under its own comment
    //  - _serpulix_schema    : Schema Analyzer apply (JSON-encoded ARRAY of objects)
    //  - serpulix_schema_home: Schema Analyzer apply for a blog-index front page
    // Before, both were printed after one comment, so the Analyzer took Content Studio's FAQ for its own,
    // adopted it and pushed it again: the page printed the FAQ twice.
    $content_studio = array();
    $analyzer = array();

    if (is_singular()) {
        $post_id = get_the_ID();
        $content_studio = serpulix_seo_schema_items(get_post_meta($post_id, 'seobot_schema', true));
        $analyzer = serpulix_seo_schema_items(get_post_meta($post_id, '_serpulix_schema', true));
    }
    if (is_front_page()) {
        $analyzer = array_merge($analyzer, serpulix_seo_schema_items(get_option('serpulix_schema_home')));
    }

    // One FAQ per page: when Content Studio prints the page's FAQ, an Analyzer FAQPage is not printed beside it.
    // Content Studio's is the one kept up to date with the page's FAQ section.
    $cs_has_faq = false;
    foreach ($content_studio as $item) {
        if (serpulix_seo_schema_has_type($item, 'FAQPage')) {
            $cs_has_faq = true;
        }
    }
    if ($cs_has_faq) {
        $analyzer = array_values(array_filter($analyzer, function ($item) {
            return !serpulix_seo_schema_has_type($item, 'FAQPage');
        }));
    }

    if (!empty($content_studio)) {
        echo "\n<!-- Serpulix Content Studio Schema -->\n";
        serpulix_seo_print_schema_items($content_studio);
        echo "<!-- /Serpulix Content Studio Schema -->\n";
    }
    if (!empty($analyzer)) {
        echo "\n<!-- Serpulix SEO Schema.org Markup -->\n";
        serpulix_seo_print_schema_items($analyzer);
        echo "<!-- /Serpulix SEO Schema -->\n";
    }
}
