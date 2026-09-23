<?php
/**
 * Main plugin class - Simplified for v2 API only
 */
class Serpulix_SEO_Sync {

    private $api;
    private $cpt;
    private $sync_handler_v2;
    private $images;
    private $tech_seo;

    public function __construct() {
        $this->api = new Serpulix_SEO_API();
        $this->cpt = new Serpulix_SEO_CPT();
        $this->sync_handler_v2 = new Serpulix_SEO_Sync_Handler_V2();
        $this->images = new Serpulix_SEO_Images();
        $this->tech_seo = new Serpulix_SEO_Tech_SEO();
    }

    public function run() {
        // Initialize components
        $this->api->init();
        $this->cpt->init();
        $this->tech_seo->init();

        // Add REST API endpoints
        add_action('rest_api_init', array($this, 'register_rest_routes'));
    }

    public function register_rest_routes() {
        $ns = 'serpulix/v1';

        register_rest_route($ns, '/sync', array(
            'methods' => 'POST',
            'callback' => array($this->sync_handler_v2, 'handle_direct_sync'),
            'permission_callback' => array($this, 'verify_api_key')
        ));

        register_rest_route($ns, '/categories', array(
            'methods' => 'GET',
            'callback' => array($this, 'handle_get_categories'),
            'permission_callback' => array($this, 'verify_api_key')
        ));

        register_rest_route($ns, '/delete', array(
            'methods' => 'POST',
            'callback' => array($this, 'handle_delete_post'),
            'permission_callback' => array($this, 'verify_api_key')
        ));

        register_rest_route($ns, '/status', array(
            'methods' => 'GET',
            'callback' => array($this, 'handle_status_check'),
            'permission_callback' => array($this, 'verify_api_key')
        ));

        register_rest_route($ns, '/post-status', array(
            'methods' => 'GET',
            'callback' => array($this, 'handle_post_status'),
            'permission_callback' => array($this, 'verify_api_key')
        ));

        register_rest_route($ns, '/apply-schema', array(
            'methods' => 'POST',
            'callback' => array($this, 'handle_apply_schema'),
            'permission_callback' => array($this, 'verify_api_key')
        ));

        register_rest_route($ns, '/images', array(
            'methods' => 'GET',
            'callback' => array($this->images, 'handle_list'),
            'permission_callback' => array($this, 'verify_api_key')
        ));

        register_rest_route($ns, '/images/(?P<id>\d+)/alt/revert', array(
            'methods' => 'POST',
            'callback' => array($this->images, 'handle_revert_alt'),
            'permission_callback' => array($this, 'verify_api_key')
        ));

        register_rest_route($ns, '/images/(?P<id>\d+)/alt', array(
            'methods' => 'POST',
            'callback' => array($this->images, 'handle_write_alt'),
            'permission_callback' => array($this, 'verify_api_key')
        ));

        // Technical SEO (titles, meta, content, redirects, cache purge)
        $this->tech_seo->register_routes($ns, array($this, 'verify_api_key'));
    }

    /**
     * Return current WordPress post status (publish | future | draft | ...)
     * for a given post ID. Used by Serpulix to verify scheduled posts went live.
     */
    public function handle_post_status($request) {
        $post_id = intval($request->get_param('post_id'));
        if (!$post_id) {
            return new WP_REST_Response(array(
                'success' => false,
                'error' => 'Missing post_id'
            ), 400);
        }

        $post = get_post($post_id);
        if (!$post) {
            return new WP_REST_Response(array(
                'success' => false,
                'error' => 'Post not found'
            ), 404);
        }

        return new WP_REST_Response(array(
            'success' => true,
            'post_id' => $post_id,
            'post_status' => $post->post_status,
            'post_date' => $post->post_date,
            'post_date_gmt' => $post->post_date_gmt,
            'permalink' => get_permalink($post_id),
        ), 200);
    }

    /**
     * Apply Serpulix Schema Analyzer JSON-LD to a page by URL.
     * Stores in a DEDICATED meta (_serpulix_schema), never touches seobot_schema
     * or other plugins' markup. Full-replace = idempotent. Empty array clears.
     */
    public function handle_apply_schema($request) {
        $params = $request->get_json_params();
        $url = isset($params['url']) ? esc_url_raw($params['url']) : '';
        $schemas = isset($params['schemas']) && is_array($params['schemas']) ? $params['schemas'] : array();

        if (!$url) {
            return new WP_REST_Response(array('success' => false, 'error' => 'Missing url'), 400);
        }

        $post_id = url_to_postid($url);

        // Homepage / site root resolution
        $home = untrailingslashit(home_url());
        $is_home = (untrailingslashit($url) === $home);
        if (!$post_id && $is_home) {
            $front = (int) get_option('page_on_front');
            if ($front) {
                $post_id = $front;
            } else {
                // Blog-index front page: no post to attach to -> store as a site option.
                update_option('serpulix_schema_home', wp_json_encode($schemas));
                return new WP_REST_Response(array('success' => true, 'post_id' => 0, 'applied' => count($schemas), 'target' => 'home_option'), 200);
            }
        }

        if (!$post_id) {
            return new WP_REST_Response(array('success' => false, 'reason' => 'unresolved', 'url' => $url), 200);
        }

        if (count($schemas) > 0) {
            update_post_meta($post_id, '_serpulix_schema', wp_json_encode($schemas));
        } else {
            delete_post_meta($post_id, '_serpulix_schema');
        }

        return new WP_REST_Response(array('success' => true, 'post_id' => $post_id, 'applied' => count($schemas)), 200);
    }

    /**
     * Plugin status / health check endpoint.
     * Used by SEOBot/Serpulix to verify plugin is installed and the API key matches.
     */
    public function handle_status_check($request) {
        return new WP_REST_Response(array(
            'success' => true,
            'plugin' => 'Serpulix SEO',
            'version' => SERPULIX_SEO_VERSION,
            'alt_tags' => true,
            'tech_seo' => true,
        ), 200);
    }

    public function verify_api_key($request) {
        $auth_header = $request->get_header('authorization');

        if (!$auth_header || strpos($auth_header, 'Bearer ') !== 0) {
            return false;
        }

        $api_key = substr($auth_header, 7);
        $configured_key = get_option('seobot_api_key');

        if (!$api_key || !$configured_key) {
            return false;
        }

        return hash_equals($configured_key, $api_key);
    }

    /**
     * Delete a WordPress post/page by SEOBot page ID
     */
    public function handle_delete_post($request) {
        $params = $request->get_json_params();
        $page_id = isset($params['page_id']) ? $params['page_id'] : null;

        if (!$page_id) {
            return new WP_REST_Response(array('success' => false, 'error' => 'Missing page_id'), 400);
        }

        // Find post by seobot page ID meta
        $posts = get_posts(array(
            'post_type' => array('post', 'page', 'seobot_page'),
            'meta_key' => '_seobot_page_id',
            'meta_value' => $page_id,
            'post_status' => 'any',
            'numberposts' => 1,
        ));

        if (empty($posts)) {
            return new WP_REST_Response(array('success' => false, 'error' => 'Post not found'), 404);
        }

        $post_id = $posts[0]->ID;
        $result = wp_trash_post($post_id); // move to trash, not permanent delete

        if ($result) {
            return new WP_REST_Response(array('success' => true, 'deleted_post_id' => $post_id), 200);
        }

        return new WP_REST_Response(array('success' => false, 'error' => 'Failed to delete post'), 500);
    }

    /**
     * Return all WordPress categories
     */
    public function handle_get_categories($request) {
        $categories = get_categories(array(
            'hide_empty' => false,
            'orderby' => 'name',
            'order' => 'ASC',
        ));

        $result = array();
        foreach ($categories as $cat) {
            $result[] = array(
                'id' => $cat->term_id,
                'name' => $cat->name,
                'slug' => $cat->slug,
                'parent' => $cat->parent,
                'count' => $cat->count,
            );
        }

        return new WP_REST_Response($result, 200);
    }

}
