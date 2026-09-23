<?php
/**
 * Plugin Name: Serpulix SEO
 * Plugin URI: https://serpulix.com
 * Description: Sync and publish SEO content from Serpulix and deploy schema.org structured data to your site.
 * Version: 4.7.0
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
define('SERPULIX_SEO_VERSION', '4.7.0');
define('SERPULIX_SEO_PLUGIN_DIR', plugin_dir_path(__FILE__));
define('SERPULIX_SEO_PLUGIN_URL', plugin_dir_url(__FILE__));
define('SERPULIX_SEO_PLUGIN_FILE', __FILE__);

// Include required files (simplified - only v2)
require_once SERPULIX_SEO_PLUGIN_DIR . 'includes/class-seobot-api.php';
require_once SERPULIX_SEO_PLUGIN_DIR . 'includes/class-seobot-cpt.php';
require_once SERPULIX_SEO_PLUGIN_DIR . 'includes/class-seobot-sync-handler-v2.php';
require_once SERPULIX_SEO_PLUGIN_DIR . 'includes/class-seobot-images.php';
require_once SERPULIX_SEO_PLUGIN_DIR . 'includes/class-seobot-tech-seo.php';
require_once SERPULIX_SEO_PLUGIN_DIR . 'includes/class-seobot-sync.php';
require_once SERPULIX_SEO_PLUGIN_DIR . 'includes/class-serpulix-github-updater.php';

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

function serpulix_seo_inject_schema() {
    if (!is_singular() && !is_front_page()) {
        return;
    }

    // Two independent channels:
    //  - seobot_schema       : Content Studio (single JSON object/string)
    //  - _serpulix_schema    : Schema Analyzer apply (JSON-encoded ARRAY of objects)
    //  - serpulix_schema_home: Schema Analyzer apply for a blog-index front page
    $blocks = array();

    if (is_singular()) {
        $post_id = get_the_ID();
        $legacy = get_post_meta($post_id, 'seobot_schema', true);
        if (!empty($legacy)) {
            $blocks[] = $legacy;
        }
        $serpulix = get_post_meta($post_id, '_serpulix_schema', true);
        if (!empty($serpulix)) {
            $blocks[] = $serpulix;
        }
    }
    if (is_front_page()) {
        $home_opt = get_option('serpulix_schema_home');
        if (!empty($home_opt)) {
            $blocks[] = $home_opt;
        }
    }

    if (empty($blocks)) {
        return;
    }

    echo "\n<!-- Serpulix SEO Schema.org Markup -->\n";
    foreach ($blocks as $schema) {
        // Decode; _serpulix_schema / serpulix_schema_home are arrays of JSON-LD objects.
        $decoded = is_string($schema) ? json_decode($schema, true) : $schema;
        if (empty($decoded)) {
            continue;
        }
        // Array-of-objects (sequential keys) vs a single object.
        $items = (is_array($decoded) && array_key_exists(0, $decoded)) ? $decoded : array($decoded);
        foreach ($items as $item) {
            if (empty($item)) {
                continue;
            }
            echo '<script type="application/ld+json">';
            // JSON_HEX_TAG|JSON_HEX_AMP hex-encode < > & so a field value containing
            // "</script>" can't break out of the script block (stored XSS). Slashes stay
            // unescaped for clean URLs.
            echo wp_json_encode($item, JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP);
            echo "</script>\n";
        }
    }
    echo "<!-- /Serpulix SEO Schema -->\n";
}
