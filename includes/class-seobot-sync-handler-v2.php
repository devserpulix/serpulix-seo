<?php
/**
 * SEOBot Sync Handler v2
 * Enterprise-grade content synchronization with multi-type support
 *
 * @package Serpulix_SEO_Sync
 * @version 2.8.3
 */

class Serpulix_SEO_Sync_Handler_V2 {
    private $api_settings;
    
    public function __construct() {
        $this->api_settings = get_option('seobot_api_settings', array());
    }
    
    /**
     * Handle direct sync from SEOBot
     *
     * @return void
     */
    public function handle_direct_sync() {
        if (!$this->verify_api_access()) {
            wp_send_json_error('API access denied');
            return;
        }

        $input = json_decode(file_get_contents('php://input'), true);

        if (empty($input['page_id'])) {
            wp_send_json_error('Missing page_id');
            return;
        }

        $content_type = isset($input['content_type']) ? $input['content_type'] : 'page';

        try {
            $page_data = $this->fetch_processed_page($input['page_id']);

            if (!$page_data) {
                wp_send_json_error('Failed to fetch page data');
                return;
            }

            // Override/merge fields from request body
            if (!empty($input['status'])) {
                $page_data['status'] = $input['status'];
            }
            if (!empty($input['content'])) {
                $page_data['content'] = $input['content'];
            }
            if (!empty($input['title'])) {
                $page_data['title'] = $input['title'];
            }
            if (!empty($input['meta_description'])) {
                $page_data['metaDescription'] = $input['meta_description'];
            }
            if (!empty($input['seobot_base_css'])) {
                $page_data['seobot_base_css'] = $input['seobot_base_css'];
            }
            if (!empty($input['seobot_post_css'])) {
                $page_data['seobot_post_css'] = $input['seobot_post_css'];
            }
            if (!empty($input['seobot_js'])) {
                $page_data['seobot_js'] = $input['seobot_js'];
            }
            if (!empty($input['categoryId'])) {
                $page_data['categoryId'] = $input['categoryId'];
            }
            if (!empty($input['removeNoindexCategory'])) {
                $page_data['removeNoindexCategory'] = $input['removeNoindexCategory'];
            }
            if (!empty($input['scheduledAt'])) {
                $page_data['scheduledAt'] = $input['scheduledAt'];
            }
            if (!empty($input['images'])) {
                $page_data['images'] = $input['images'];
            }
            if (!empty($input['featuredImage'])) {
                $page_data['featuredImage'] = $input['featuredImage'];
            }
            if (!empty($input['schemaMarkup'])) {
                $page_data['schemaMarkup'] = $input['schemaMarkup'];
            }

            $post_result = $this->create_or_update_post($page_data, $content_type);

            if ($post_result['success']) {
                wp_send_json_success($post_result);
            } else {
                wp_send_json_error($post_result['error']);
            }

        } catch (Exception $e) {
            wp_send_json_error('Sync failed: ' . $e->getMessage());
        }
    }
    
    /**
     * Fetch the pre-processed draft from the Serpulix Content Studio.
     *
     * @param string $page_id Serpulix draft identifier
     * @return array|false Page data or false on failure
     */
    private function fetch_processed_page($page_id) {
        $api_url = rtrim(get_option('seobot_api_url', ''), '/');
        $api_key = get_option('seobot_api_key', '');

        if (empty($api_url) || empty($api_key)) {
            return false;
        }

        $url = $api_url . '/api/content-studio/drafts/' . rawurlencode($page_id) . '/processed';

        $response = wp_remote_get($url, array(
            'timeout' => 30,
            'headers' => array(
                'Authorization' => 'Bearer ' . $api_key,
                'Content-Type' => 'application/json'
            )
        ));

        if (is_wp_error($response)) {
            return false;
        }

        $body = wp_remote_retrieve_body($response);
        $data = json_decode($body, true);

        if (json_last_error() !== JSON_ERROR_NONE) {
            return false;
        }

        return $data;
    }
    
    /**
     * Create or update WordPress post
     *
     * @param array $page_data Page data from SEOBot
     * @param string $content_type Content type
     * @return array Result with success status
     */
    private function create_or_update_post($page_data, $content_type = 'seo_page') {
        try {
            // Map content type to WordPress post type. Unknown/legacy types fall
            // back to the seobot_page CPT so pre-existing content stays reachable.
            $post_type_map = array(
                'blog_post' => 'post',
                'page'      => 'page'
            );

            $wp_post_type = isset($post_type_map[$content_type]) ? $post_type_map[$content_type] : 'seobot_page';

            // Check for existing post
            $existing_posts = get_posts(array(
                'post_type' => $wp_post_type,
                'meta_key' => '_seobot_page_id',
                'meta_value' => $page_data['id'],
                'post_status' => 'any',
                'numberposts' => 1
            ));

            $post_id = !empty($existing_posts) ? $existing_posts[0]->ID : null;
            $content = $page_data['content'];

            // Upload ALL images to WP Media Library (with deduplication)
            $featured_image_id = null;
            $images_processed = 0;

            if (!empty($page_data['images']) && is_array($page_data['images'])) {
                foreach ($page_data['images'] as $index => $image) {
                    $attachment_id = $this->upload_featured_image($image);
                    if ($attachment_id) {
                        // Replace image URL in content with WP media URL
                        $wp_url = wp_get_attachment_url($attachment_id);
                        if ($wp_url && !empty($image['url'])) {
                            $content = str_replace($image['url'], $wp_url, $content);
                            // Also replace path-only version
                            $path = parse_url($image['url'], PHP_URL_PATH);
                            if ($path) {
                                $content = str_replace($path, $wp_url, $content);
                            }
                        }
                        // First image = featured image
                        if ($index === 0) {
                            $featured_image_id = $attachment_id;
                        }
                        $images_processed++;
                    }
                }
            } elseif (!empty($page_data['featuredImage'])) {
                // Fallback: only featured image (legacy)
                $featured_image_id = $this->upload_featured_image($page_data['featuredImage']);
                if ($featured_image_id) {
                    $content = $this->replace_image_urls_in_content($content, $page_data['featuredImage'], $featured_image_id);
                    $images_processed = 1;
                }
            }

            // scheduledAt in the future → 'future' (WP-cron publishes at time)
            // scheduledAt in the past   → 'publish' with backdated post_date
            // no scheduledAt            → 'publish' (or 'draft' when caller asked)
            $scheduled_time = !empty($page_data['scheduledAt']) ? strtotime($page_data['scheduledAt']) : 0;
            $is_future_scheduled = $scheduled_time > 0 && $scheduled_time > time();

            $post_data_wp = array(
                'post_title' => $page_data['title'],
                'post_content' => $content,
                'post_excerpt' => $page_data['metaDescription'] ?? '',
                'post_status' => $is_future_scheduled
                    ? 'future'
                    : (($page_data['status'] === 'draft') ? 'draft' : 'publish'),
                'post_type' => $wp_post_type,
                'meta_input' => array(
                    '_seobot_page_id' => $page_data['id'],
                    '_seobot_project_id' => $page_data['projectId'] ?? '',
                    '_seobot_sync_version' => SERPULIX_SEO_VERSION,
                    '_seobot_sync_date' => current_time('mysql'),
                    '_seobot_images_processed' => $page_data['imagesProcessed'] ?? 0,
                    '_seobot_meta_description' => $page_data['metaDescription'] ?? '',
                    '_seobot_content_type' => $content_type
                )
            );

            // Category support (posts only)
            $categories = array();
            if ($wp_post_type === 'post' && !empty($page_data['categoryId'])) {
                $cat_id = intval($page_data['categoryId']);
                if ($cat_id > 0) {
                    $categories[] = $cat_id;
                }
            }

            if (!empty($categories)) {
                $post_data_wp['post_category'] = $categories;
            }

            // Stamp post_date for both future scheduling and backdated publish.
            // post_status is already set above based on whether the time is in
            // the future ('future') or now/past ('publish').
            // post_date must be in the blog's local timezone (WP pins PHP to UTC,
            // so date() here would produce UTC wall-clock, shifting the displayed
            // publish time by the blog's offset — and the displayed DAY for
            // backdates near midnight). get_date_from_gmt() converts using the
            // blog's timezone setting.
            if ($scheduled_time > 0) {
                $gmt_date = gmdate('Y-m-d H:i:s', $scheduled_time);
                $post_data_wp['post_date'] = get_date_from_gmt($gmt_date);
                $post_data_wp['post_date_gmt'] = $gmt_date;
            }

            if ($post_id) {
                // Defense-in-depth: an update must never downgrade an already-published
                // post back to draft. The server-side sync logic already avoids sending
                // 'draft' for a live post, but a stale/misconfigured sync should not be
                // able to unpublish a post that is currently live.
                $current_status = get_post_status($post_id);
                if ($current_status === 'publish' && $post_data_wp['post_status'] === 'draft') {
                    $post_data_wp['post_status'] = 'publish';
                }
                $post_data_wp['ID'] = $post_id;
                $result = wp_update_post($post_data_wp, true);
            } else {
                $result = wp_insert_post($post_data_wp, true);
            }

            if (is_wp_error($result)) {
                return array(
                    'success' => false,
                    'error' => $result->get_error_message()
                );
            }
            
            $final_post_id = $post_id ?: $result;

            // Remove noindex category if requested (cleanup for legacy preview-as-published posts)
            if ($wp_post_type === 'post' && !empty($page_data['removeNoindexCategory'])) {
                $noindex_cat = get_category_by_slug('noindex');
                if ($noindex_cat) {
                    wp_remove_object_terms($final_post_id, $noindex_cat->term_id, 'category');
                }
            }

            // Remove Oxygen Builder metadata to prevent conflicts
            $oxygen_meta_keys = array(
                'ct_builder_shortcodes',
                'ct_builder_json',
                'ct_other_template',
                'ct_render_post_using',
                '_ct_builder_shortcodes',
                '_ct_builder_json'
            );

            foreach ($oxygen_meta_keys as $meta_key) {
                delete_post_meta($final_post_id, $meta_key);
            }

            // Set featured image
            if ($featured_image_id) {
                set_post_thumbnail($final_post_id, $featured_image_id);
            }

            // Save schema markup as post meta
            if (!empty($page_data['schemaMarkup'])) {
                update_post_meta($final_post_id, 'seobot_schema', $page_data['schemaMarkup']);
            }

            // Save Content Studio CSS/JS as files
            if (!empty($page_data['seobot_base_css']) || !empty($page_data['seobot_post_css']) || !empty($page_data['seobot_js'])) {
                $upload_dir = wp_upload_dir();
                $seobot_dir = $upload_dir['basedir'] . '/seobot';
                if (!file_exists($seobot_dir)) {
                    wp_mkdir_p($seobot_dir);
                }

                // Base CSS (shared across all CS posts) — always overwrite with latest
                if (!empty($page_data['seobot_base_css'])) {
                    file_put_contents($seobot_dir . '/cs-base.css', $page_data['seobot_base_css']);
                }

                // Per-post CSS (accent colors, overrides)
                if (!empty($page_data['seobot_post_css'])) {
                    file_put_contents($seobot_dir . '/cs-' . $final_post_id . '.css', $page_data['seobot_post_css']);
                }

                if (!empty($page_data['seobot_base_css']) || !empty($page_data['seobot_post_css'])) {
                    update_post_meta($final_post_id, '_seobot_has_cs_styles', '1');
                }

                // Per-post JS
                if (!empty($page_data['seobot_js'])) {
                    file_put_contents($seobot_dir . '/cs-' . $final_post_id . '.js', $page_data['seobot_js']);
                    update_post_meta($final_post_id, '_seobot_has_cs_js', '1');
                }
            }

            // Force only our categories (false = replace, not append)
            if (!empty($categories)) {
                wp_set_post_categories($final_post_id, $categories, false);
            }

            return array(
                'success' => true,
                'post_id' => $final_post_id,
                'wpPostId' => $final_post_id,
                'post_url' => get_permalink($final_post_id),
                'wpPostUrl' => get_permalink($final_post_id),
                'action' => $post_id ? 'updated' : 'created'
            );
            
        } catch (Exception $e) {
            return array(
                'success' => false,
                'error' => 'Post creation failed: ' . $e->getMessage()
            );
        }
    }
    
    /**
     * Verify API access
     *
     * @return bool
     */
    private function verify_api_access() {
        $auth_header = $this->get_authorization_header();

        if (empty($auth_header)) {
            return false;
        }
        
        $expected_key = get_option('seobot_api_key');
        if (empty($expected_key)) {
            return false;
        }
        
        $provided_key = str_replace('Bearer ', '', $auth_header);
        return hash_equals($expected_key, $provided_key);
    }
    
    /**
     * Upload featured image to WordPress Media Library
     *
     * Implements deduplication to prevent duplicate uploads
     *
     * @param array $image_data Image data with url, filename, alt
     * @return int|null Attachment ID or null on failure
     */
    private function upload_featured_image($image_data) {
        if (empty($image_data['url'])) {
            return null;
        }
        
        $image_url = $image_data['url'];
        $filename = $image_data['filename'] ?? 'featured-image.jpg';
        $alt_text = $image_data['alt'] ?? '';
        
        // Check for existing image
        $existing_attachment = $this->find_existing_image($filename, $image_url);
        if ($existing_attachment) {
            return $existing_attachment;
        }
        
        // Download from SEOBot
        $response = wp_remote_get($image_url, array(
            'timeout' => 30,
            'headers' => array(
                'User-Agent' => 'WordPress/SEOBot-Sync'
            )
        ));
        
        if (is_wp_error($response)) {
            return null;
        }
        
        $image_data_binary = wp_remote_retrieve_body($response);
        if (empty($image_data_binary)) {
            return null;
        }
        
        // Upload to WordPress
        $upload = wp_upload_bits($filename, null, $image_data_binary);
        
        if ($upload['error']) {
            return null;
        }
        
        // Create attachment
        $attachment = array(
            'post_mime_type' => wp_check_filetype($filename)['type'],
            'post_title' => sanitize_file_name($filename),
            'post_content' => '',
            'post_status' => 'inherit'
        );
        
        $attachment_id = wp_insert_attachment($attachment, $upload['file']);
        
        if (is_wp_error($attachment_id)) {
            return null;
        }
        
        // Generate metadata
        require_once(ABSPATH . 'wp-admin/includes/image.php');
        $attachment_data = wp_generate_attachment_metadata($attachment_id, $upload['file']);
        wp_update_attachment_metadata($attachment_id, $attachment_data);
        
        // Set alt text
        if ($alt_text) {
            update_post_meta($attachment_id, '_wp_attachment_image_alt', sanitize_text_field($alt_text));
        }
        
        // Store original SEOBot URL for deduplication
        update_post_meta($attachment_id, '_seobot_original_url', $image_url);
        
        return $attachment_id;
    }
    
    /**
     * Replace image URLs in content with WordPress media URLs
     *
     * @param string $content Post content
     * @param array $image_data Original image data
     * @param int $wp_attachment_id WordPress attachment ID
     * @return string Updated content
     */
    private function replace_image_urls_in_content($content, $image_data, $wp_attachment_id) {
        if (empty($image_data['url']) || !$wp_attachment_id) {
            return $content;
        }
        
        $original_url = $image_data['url'];
        $wp_image_url = wp_get_attachment_url($wp_attachment_id);
        
        if (!$wp_image_url) {
            return $content;
        }
        
        // Replace URLs
        $content = str_replace($original_url, $wp_image_url, $content);
        
        $original_path = parse_url($original_url, PHP_URL_PATH);
        if ($original_path) {
            $content = str_replace($original_path, $wp_image_url, $content);
        }
        
        // Add media IDs to Gutenberg blocks
        $content = $this->add_media_id_to_blocks($content, $wp_image_url, $wp_attachment_id);
        $content = $this->update_image_classes($content, $wp_attachment_id, $wp_image_url);
        
        return $content;
    }
    
    /**
     * Add mediaId to Gutenberg blocks for proper WordPress validation
     *
     * @param string $content Post content
     * @param string $original_url Original image URL
     * @param int $media_id WordPress media ID
     * @return string Updated content
     */
    private function add_media_id_to_blocks($content, $original_url, $media_id) {
        $patterns = [
            '/<!-- wp:(core\/)?media-text ({[^}]*}) -->/' => 'media-text',
            '/<!-- wp:(core\/)?image ({[^}]*}) -->/' => 'image'
        ];
        
        foreach ($patterns as $pattern => $block_type_base) {
            $content = preg_replace_callback($pattern, function($matches) use ($media_id, $block_type_base, $original_url) {
                $block_type = $matches[1] ? 'core/' . $block_type_base : $block_type_base;
                $attributes = json_decode($matches[2], true);

                if (json_last_error() !== JSON_ERROR_NONE || !is_array($attributes)) {
                    return $matches[0];
                }

                // Only stamp the attachment ID onto the block that actually points at
                // this URL — otherwise the raw-file's media ID leaks onto every image
                // block in the content, including ones pointing at an overlay version.
                $should_update = false;
                if ($block_type_base === 'media-text'
                    && isset($attributes['mediaUrl'])
                    && $attributes['mediaUrl'] === $original_url) {
                    $attributes['mediaId'] = $media_id;
                    $should_update = true;
                } elseif ($block_type_base === 'image'
                    && isset($attributes['url'])
                    && $attributes['url'] === $original_url) {
                    $attributes['id'] = $media_id;
                    $should_update = true;
                }

                if ($should_update) {
                    return '<!-- wp:' . $block_type . ' ' . wp_json_encode($attributes) . ' -->';
                }

                return $matches[0];
            }, $content);
        }
        
        return $content;
    }
    
    /**
     * Find existing image in Media Library
     *
     * @param string $filename Image filename
     * @param string $image_url Original URL
     * @return int|null Attachment ID or null
     */
    private function find_existing_image($filename, $image_url) {
        // Search by filename
        $args = array(
            'post_type' => 'attachment',
            'post_status' => 'inherit',
            'posts_per_page' => 1,
            'meta_query' => array(
                array(
                    'key' => '_wp_attached_file',
                    'value' => $filename,
                    'compare' => 'LIKE'
                )
            )
        );
        
        $existing_attachments = get_posts($args);
        if (!empty($existing_attachments)) {
            return $existing_attachments[0]->ID;
        }
        
        // Search by original SEOBot URL
        $args = array(
            'post_type' => 'attachment',
            'post_status' => 'inherit', 
            'posts_per_page' => 1,
            'meta_query' => array(
                array(
                    'key' => '_seobot_original_url',
                    'value' => $image_url,
                    'compare' => '='
                )
            )
        );
        
        $existing_attachments = get_posts($args);
        if (!empty($existing_attachments)) {
            return $existing_attachments[0]->ID;
        }
        
        return null;
    }
    
    /**
     * Update image class attributes
     *
     * @param string $content Post content
     * @param int $media_id WordPress media ID
     * @param string $original_url URL the <img> tag's src must match to receive the class
     * @return string Updated content
     */
    private function update_image_classes($content, $media_id, $original_url = '') {
        // The wp-image-{id} class must land ONLY on the <img> tag that is actually this
        // attachment. Previously every <img> with a class attribute got the class, so an
        // unrelated image (e.g. one still pointing at an overlay version) could end up
        // mislabeled as this attachment.
        if (empty($original_url)) {
            return $content;
        }
        $pattern = '/(<img[^>]+src="' . preg_quote($original_url, '/') . '"[^>]*class=")([^"]*)(")/';
        $content = preg_replace_callback($pattern, function($matches) use ($media_id) {
            $existing = trim($matches[2]);
            $wp_class = 'wp-image-' . $media_id;
            if (strpos($existing, $wp_class) !== false) return $matches[0]; // already has it
            return $matches[1] . $existing . ' ' . $wp_class . $matches[3];
        }, $content);
        return $content;
    }

    /**
     * Get Authorization header
     *
     * Compatible with Apache, nginx, and PHP-FPM environments
     *
     * @return string Authorization header value
     */
    private function get_authorization_header() {
        // Try Apache-style first
        if (function_exists('getallheaders')) {
            $headers = getallheaders();
            if (isset($headers['Authorization'])) {
                return $headers['Authorization'];
            }
        }

        // Try nginx/PHP-FPM $_SERVER variables
        if (isset($_SERVER['HTTP_AUTHORIZATION'])) {
            return $_SERVER['HTTP_AUTHORIZATION'];
        }

        // Try redirect headers
        if (isset($_SERVER['REDIRECT_HTTP_AUTHORIZATION'])) {
            return $_SERVER['REDIRECT_HTTP_AUTHORIZATION'];
        }

        return '';
    }
}
