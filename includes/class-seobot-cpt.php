<?php
/**
 * Custom Post Type for SEO Pages
 */
class Serpulix_SEO_CPT {

    public function init() {
        add_action('init', array($this, 'register_post_type'));

        add_action('add_meta_boxes', array($this, 'add_meta_boxes'));
        add_action('save_post_seobot_page', array($this, 'save_meta_data'));

        // Custom permalink structure
        add_filter('post_type_link', array($this, 'remove_cpt_slug'), 10, 2);
        add_filter('request', array($this, 'parse_request_for_seobot_pages'));

        // Sitemap integration
        add_filter('wp_sitemaps_post_types', array($this, 'add_to_sitemap'));
        add_filter('wp_sitemaps_posts_pre_url_list', array($this, 'merge_with_page_sitemap'), 10, 3);

        // Add settings submenu
        add_action('admin_menu', array($this, 'add_settings_submenu'));
        add_action('admin_init', array($this, 'register_settings'));
        add_action('update_option_seobot_api_url', array($this, 'test_connection_after_save'));
        add_action('update_option_seobot_api_key', array($this, 'test_connection_after_save'));
    }
    
    public function register_post_type() {
        $labels = array(
            'name' => 'SEO Pages',
            'singular_name' => 'SEO Page',
            'menu_name' => 'Serpulix SEO',
            'add_new' => 'Add New',
            'add_new_item' => 'Add New SEO Page',
            'edit_item' => 'Edit SEO Page',
            'new_item' => 'New SEO Page',
            'view_item' => 'View SEO Page',
            'search_items' => 'Search SEO Pages',
            'not_found' => 'No SEO pages found',
            'not_found_in_trash' => 'No SEO pages found in trash'
        );
        
        $args = array(
            'labels' => $labels,
            'public' => true,
            'publicly_queryable' => true,
            'show_ui' => true,
            'show_in_menu' => true,
            'query_var' => true,
            'rewrite' => array(
                'slug' => '',
                'with_front' => false
            ),
            'capability_type' => 'post',
            'has_archive' => false,
            'hierarchical' => false,
            'menu_position' => 25,
            'menu_icon' => 'dashicons-search',
            'supports' => array('title', 'editor', 'excerpt', 'thumbnail', 'custom-fields'),
            'show_in_rest' => true
        );
        
        register_post_type('seobot_page', $args);
    }
    
    public function add_meta_boxes() {
        add_meta_box(
            'seobot_meta',
            'Serpulix Information',
            array($this, 'render_meta_box'),
            'seobot_page',
            'side',
            'high'
        );
    }
    
    public function render_meta_box($post) {
        wp_nonce_field('seobot_meta_box', 'seobot_meta_box_nonce');
        
        $page_id = get_post_meta($post->ID, '_seobot_page_id', true);
        $project_id = get_post_meta($post->ID, '_seobot_project_id', true);
        $meta_description = get_post_meta($post->ID, '_seobot_meta_description', true);
        $last_sync = get_post_meta($post->ID, '_seobot_last_sync', true);
        ?>
        <p>
            <label>Serpulix Page ID:</label><br>
            <input type="text" name="seobot_page_id" value="<?php echo esc_attr($page_id); ?>" readonly class="widefat" />
        </p>
        <p>
            <label>Project ID:</label><br>
            <input type="text" name="seobot_project_id" value="<?php echo esc_attr($project_id); ?>" readonly class="widefat" />
        </p>
        <p>
            <label>Meta Description:</label><br>
            <textarea name="seobot_meta_description" class="widefat" rows="3"><?php echo esc_textarea($meta_description); ?></textarea>
        </p>
        <p>
            <label>Last Sync:</label><br>
            <input type="text" value="<?php echo esc_attr($last_sync); ?>" readonly class="widefat" />
        </p>
        <?php
    }
    
    public function save_meta_data($post_id) {
        if (!isset($_POST['seobot_meta_box_nonce'])) {
            return;
        }

        if (!wp_verify_nonce($_POST['seobot_meta_box_nonce'], 'seobot_meta_box')) {
            return;
        }

        if (defined('DOING_AUTOSAVE') && DOING_AUTOSAVE) {
            return;
        }

        if (!current_user_can('edit_post', $post_id)) {
            return;
        }

        if (isset($_POST['seobot_meta_description'])) {
            update_post_meta($post_id, '_seobot_meta_description', sanitize_textarea_field($_POST['seobot_meta_description']));
        }
    }

    /**
     * Remove custom post type slug from permalinks
     *
     * @param string $post_link The post's permalink
     * @param WP_Post $post The post object
     * @return string Modified permalink
     */
    public function remove_cpt_slug($post_link, $post) {
        if ($post->post_type !== 'seobot_page' || $post->post_status !== 'publish') {
            return $post_link;
        }

        return str_replace('/seobot_page/', '/', $post_link);
    }

    /**
     * Parse incoming requests to match seobot_page posts without CPT slug
     *
     * @param array $query_vars Query variables
     * @return array Modified query variables
     */
    public function parse_request_for_seobot_pages($query_vars) {
        if (empty($query_vars['name'])) {
            return $query_vars;
        }

        global $wpdb;
        $post_id = $wpdb->get_var(
            $wpdb->prepare(
                "SELECT ID FROM $wpdb->posts WHERE post_name = %s AND post_type = %s AND post_status = 'publish'",
                $query_vars['name'],
                'seobot_page'
            )
        );

        if ($post_id) {
            $query_vars['seobot_page'] = $query_vars['name'];
            $query_vars['post_type'] = 'seobot_page';
        }

        return $query_vars;
    }

    /**
     * Remove seobot_page from generating separate sitemap
     *
     * @param array $post_types Post types for sitemap generation
     * @return array Modified post types array
     */
    public function add_to_sitemap($post_types) {
        unset($post_types['seobot_page']);
        return $post_types;
    }

    /**
     * Merge SEOBot pages into main page sitemap
     *
     * @param array $url_list Current sitemap URLs
     * @param string $post_type Post type being processed
     * @param int $page_num Page number
     * @return array Modified URL list
     */
    public function merge_with_page_sitemap($url_list, $post_type, $page_num) {
        // Only add to page sitemap
        if ($post_type !== 'page') {
            return $url_list;
        }

        // Get all published seobot pages
        $seobot_posts = get_posts(array(
            'post_type' => 'seobot_page',
            'post_status' => 'publish',
            'numberposts' => 2000,
            'orderby' => 'modified',
            'order' => 'DESC'
        ));

        // Add them to page sitemap
        foreach ($seobot_posts as $post) {
            $url_list[] = array(
                'loc' => get_permalink($post->ID),
                'lastmod' => get_post_modified_time('c', false, $post),
            );
        }

        return $url_list;
    }

    /**
     * Add Settings submenu under SEO Pages
     */
    public function add_settings_submenu() {
        add_submenu_page(
            'edit.php?post_type=seobot_page',
            'Serpulix Settings',
            'Settings',
            'manage_options',
            'seobot-settings',
            array($this, 'render_settings')
        );
    }

    /**
     * Register settings
     */
    public function register_settings() {
        // Connection settings
        register_setting('seobot_sync_settings', 'seobot_api_url', array(
            'sanitize_callback' => 'esc_url_raw'
        ));
        register_setting('seobot_sync_settings', 'seobot_api_key', array(
            'sanitize_callback' => 'sanitize_text_field'
        ));
    }

    /**
     * Test connection after save
     */
    public function test_connection_after_save() {
        $api = new Serpulix_SEO_API();
        $api->init();

        if ($api->is_connected()) {
            set_transient('seobot_connection_message', array(
                'type' => 'success',
                'message' => 'Great! Successfully connected to Serpulix. Your settings have been saved.'
            ), 30);
        } else {
            set_transient('seobot_connection_message', array(
                'type' => 'error',
                'message' => 'Settings saved, but unable to connect to Serpulix. Please check your API URL and Key.'
            ), 30);
        }
    }

    /**
     * Render settings page
     */
    public function render_settings() {
        // Check for connection message
        $connection_message = get_transient('seobot_connection_message');
        if ($connection_message) {
            delete_transient('seobot_connection_message');
        }

        ?>
        <div class="wrap">
            <h1>Serpulix SEO Settings</h1>

            <?php if ($connection_message): ?>
                <div class="notice notice-<?php echo $connection_message['type'] === 'success' ? 'success' : 'error'; ?> is-dismissible">
                    <p><strong><?php echo esc_html($connection_message['message']); ?></strong></p>
                </div>
            <?php endif; ?>

            <form method="post" action="options.php">
                <?php settings_fields('seobot_sync_settings'); ?>

                <!-- Connection Status Display -->
                <?php
                $api = new Serpulix_SEO_API();
                $api->init();
                $is_connected = $api->is_connected();
                $api_url = get_option('seobot_api_url');
                $api_key = get_option('seobot_api_key');
                ?>

                <?php if ($api_url && $api_key): ?>
                    <div class="card" style="background: <?php echo $is_connected ? '#d4edda' : '#f8d7da'; ?>; border: 1px solid <?php echo $is_connected ? '#c3e6cb' : '#f5c6cb'; ?>; padding: 15px; margin-bottom: 20px; border-radius: 5px;">
                        <h3 style="margin-top: 0; color: <?php echo $is_connected ? '#155724' : '#721c24'; ?>;">
                            <?php if ($is_connected): ?>
                                ✅ Connected to Serpulix
                            <?php else: ?>
                                ❌ Not Connected to Serpulix
                            <?php endif; ?>
                        </h3>
                        <p style="color: <?php echo $is_connected ? '#155724' : '#721c24'; ?>; margin-bottom: 0;">
                            <?php if ($is_connected): ?>
                                Great! Your WordPress site can communicate with Serpulix. You can now sync content.
                            <?php else: ?>
                                Unable to connect. Please check your API URL and Key below.
                            <?php endif; ?>
                        </p>
                    </div>
                <?php endif; ?>

                <h2>Connection Settings</h2>
                <table class="form-table">
                    <tr>
                        <th scope="row">Serpulix API URL</th>
                        <td>
                            <input type="url" name="seobot_api_url" value="<?php echo esc_attr(get_option('seobot_api_url')); ?>" class="regular-text" />
                            <p class="description">
                                Your Serpulix application URL (e.g., https://seo.serpulix.com)
                            </p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row">API Key (Per-Project)</th>
                        <td>
                            <input type="text" name="seobot_api_key" value="<?php echo esc_attr(get_option('seobot_api_key')); ?>" class="regular-text" />
                            <p class="description">
                                Get this from Serpulix: <strong>Settings → WordPress → Select your project → Generate API Key</strong><br>
                                <strong>Important:</strong> Each project has its own unique API key. This WordPress site will sync content from one project only.
                            </p>
                        </td>
                    </tr>
                </table>

                <?php do_action('serpulix_seo_settings_github'); ?>

                <?php submit_button('Save Settings'); ?>
            </form>
        </div>
        <?php
    }
}