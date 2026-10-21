<?php
/**
 * Clearing what would keep serving a page as it was before Serpulix changed it.
 * Shared by Schema (class-seobot-sync.php) and Technical SEO (class-seobot-tech-seo.php),
 * so both clear the same caches the same way.
 *
 * 4.9.2: Technical SEO used to clear only WP Rocket, LiteSpeed, W3 Total Cache and WP Super
 * Cache, site-wide. On a SiteGround site a new title stayed hidden behind the host's page
 * cache, so visitors and Google kept the old one and Serpulix could not confirm the fix.
 */
class Serpulix_Cache {

    /**
     * Clear one page: WordPress's own cache for the post, then the page cache of whichever
     * caching plugin is installed. Only that one page is purged, never the whole site.
     * Each call is guarded, so a plugin that is not there is simply skipped.
     * $hook is fired last, for a cache this plugin does not know (a host's own, a CDN).
     * Returns the names of the caches that were purged.
     */
    public static function purge_page($post_id, $url, $hook = 'serpulix_page_changed') {
        $purged = array();
        $post_id = (int) $post_id;
        $url = is_string($url) ? $url : '';

        if ($post_id) {
            clean_post_cache($post_id);
            $purged[] = 'post_cache';
        }

        // WP Rocket
        if ($post_id && function_exists('rocket_clean_post')) {
            rocket_clean_post($post_id);
            $purged[] = 'wp_rocket';
        } elseif (!$post_id && function_exists('rocket_clean_home')) {
            rocket_clean_home();
            $purged[] = 'wp_rocket';
        }
        // LiteSpeed Cache
        if ($post_id && has_action('litespeed_purge_post')) {
            do_action('litespeed_purge_post', $post_id);
            $purged[] = 'litespeed';
        } elseif ($url && has_action('litespeed_purge_url')) {
            do_action('litespeed_purge_url', $url);
            $purged[] = 'litespeed';
        }
        // W3 Total Cache
        if ($post_id && function_exists('w3tc_flush_post')) {
            w3tc_flush_post($post_id);
            $purged[] = 'w3tc';
        } elseif ($url && function_exists('w3tc_flush_url')) {
            w3tc_flush_url($url);
            $purged[] = 'w3tc';
        }
        // WP Super Cache
        if ($post_id && function_exists('wp_cache_post_change')) {
            wp_cache_post_change($post_id);
            $purged[] = 'wp_super_cache';
        }
        // WP Fastest Cache
        if ($post_id && function_exists('wpfc_clear_post_cache_by_id')) {
            wpfc_clear_post_cache_by_id($post_id);
            $purged[] = 'wp_fastest_cache';
        }
        // SiteGround Optimizer
        if ($url && function_exists('sg_cachepress_purge_cache')) {
            sg_cachepress_purge_cache($url);
            $purged[] = 'sg_optimizer';
        }
        // WP-Optimize
        if ($post_id && class_exists('WPO_Page_Cache') && method_exists('WPO_Page_Cache', 'delete_single_post_cache')) {
            WPO_Page_Cache::delete_single_post_cache($post_id);
            $purged[] = 'wp_optimize';
        }
        // Cache Enabler
        if ($post_id && has_action('cache_enabler_clear_page_cache_by_post')) {
            do_action('cache_enabler_clear_page_cache_by_post', $post_id);
            $purged[] = 'cache_enabler';
        }
        do_action($hook, $post_id, $url);

        return $purged;
    }

    /**
     * Clear the whole site, for a change that is not one page's. What /cache/purge did before
     * 4.9.2, plus SiteGround (called without an address, it clears the site's whole cache).
     */
    public static function purge_site() {
        $purged = array();
        if (function_exists('rocket_clean_domain')) {
            rocket_clean_domain();
            $purged[] = 'wp_rocket';
        }
        if (class_exists('LiteSpeed_Cache_API') && method_exists('LiteSpeed_Cache_API', 'purge_all')) {
            LiteSpeed_Cache_API::purge_all();
            $purged[] = 'litespeed';
        }
        if (function_exists('w3tc_flush_all')) {
            w3tc_flush_all();
            $purged[] = 'w3tc';
        }
        if (function_exists('wp_cache_clear_cache')) {
            wp_cache_clear_cache();
            $purged[] = 'wp_super_cache';
        }
        if (function_exists('sg_cachepress_purge_cache')) {
            sg_cachepress_purge_cache();
            $purged[] = 'sg_optimizer';
        }
        if (function_exists('wp_cache_flush')) {
            wp_cache_flush();
            $purged[] = 'object_cache';
        }
        return $purged;
    }
}
