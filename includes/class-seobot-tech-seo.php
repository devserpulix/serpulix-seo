<?php
/**
 * Technical SEO write endpoints for Serpulix (titles, descriptions, content, redirects, cache purge).
 * Never changes post_title for a title SEO fix.
 */
class Serpulix_SEO_Tech_SEO {

    public function init() {
        add_action('template_redirect', array($this, 'maybe_redirect'), 1);
        add_action('wp_head', array($this, 'output_serpulix_seo_tags'), 1);
        add_filter('pre_get_document_title', array($this, 'filter_document_title'), 20);
        add_filter('wp_title', array($this, 'filter_wp_title'), 20, 2);
    }

    public function register_routes($ns, $permission) {
        register_rest_route($ns, '/pages', array(
            'methods' => 'GET',
            'callback' => array($this, 'handle_get_page'),
            'permission_callback' => $permission,
        ));

        register_rest_route($ns, '/pages/(?P<id>\d+)/seo', array(
            'methods' => 'POST',
            'callback' => array($this, 'handle_write_seo'),
            'permission_callback' => $permission,
        ));

        register_rest_route($ns, '/pages/(?P<id>\d+)/content', array(
            'methods' => 'POST',
            'callback' => array($this, 'handle_write_content'),
            'permission_callback' => $permission,
        ));

        register_rest_route($ns, '/redirects', array(
            'methods' => 'POST',
            'callback' => array($this, 'handle_redirect'),
            'permission_callback' => $permission,
        ));

        register_rest_route($ns, '/cache/purge', array(
            'methods' => 'POST',
            'callback' => array($this, 'handle_cache_purge'),
            'permission_callback' => $permission,
        ));
    }

    private function resolve_post_id_from_url($url) {
        $url = esc_url_raw($url);
        if (!$url) {
            return 0;
        }

        $post_id = url_to_postid($url);
        if ($post_id) {
            return (int) $post_id;
        }

        $path = wp_parse_url($url, PHP_URL_PATH);
        if ($path) {
            $post_id = url_to_postid(home_url($path));
            if ($post_id) {
                return (int) $post_id;
            }
        }

        // Homepage / site root
        $home = untrailingslashit(home_url());
        $candidate = untrailingslashit($url);
        if ($candidate === $home || $path === '/' || $path === '') {
            $front = (int) get_option('page_on_front');
            if ($front) {
                return $front;
            }
        }

        // 4.10.0: url_to_postid() knows only the rewrite rules WordPress itself builds. A post type
        // served at its own address (Serpulix landing pages, seobot_page, live at the site root) or
        // another plugin's custom type was "not found", so its title and heading fixes all failed.
        // Look the address up by its path across every public post type, and accept a match only
        // when that post's own permalink is the address asked for.
        if ($path && $path !== '/') {
            $types = array_values(array_unique(array_merge(
                array('page', 'post', 'seobot_page'),
                get_post_types(array('public' => true))
            )));
            $want = untrailingslashit($path);
            $found = get_page_by_path(trim($path, '/'), OBJECT, $types);
            if ($found && untrailingslashit((string) wp_parse_url(get_permalink($found), PHP_URL_PATH)) === $want) {
                return (int) $found->ID;
            }
            $slug = basename($want);
            if ($slug) {
                $ids = get_posts(array(
                    'name' => $slug,
                    'post_type' => $types,
                    'post_status' => 'publish',
                    'numberposts' => 5,
                    'fields' => 'ids',
                ));
                foreach ($ids as $id) {
                    if (untrailingslashit((string) wp_parse_url(get_permalink($id), PHP_URL_PATH)) === $want) {
                        return (int) $id;
                    }
                }
            }
        }

        return 0;
    }

    private function detect_seo_plugin() {
        if (defined('WPSEO_VERSION') || class_exists('WPSEO_Meta')) {
            return 'yoast';
        }
        if (defined('RANK_MATH_VERSION') || class_exists('RankMath')) {
            return 'rank_math';
        }
        if (defined('AIOSEO_VERSION') || class_exists('AIOSEO\\Plugin')) {
            return 'aioseo';
        }
        // 4.10.0: SEOPress and The SEO Framework. Before, they read as "no SEO plugin", so Serpulix
        // printed its own title and description next to theirs (two of each on the page).
        if (defined('SEOPRESS_VERSION') || function_exists('seopress_init')) {
            return 'seopress';
        }
        if (defined('THE_SEO_FRAMEWORK_VERSION') || function_exists('the_seo_framework')) {
            return 'tsf';
        }
        return 'serpulix';
    }

    private function read_seo_fields($post_id) {
        $plugin = $this->detect_seo_plugin();
        $title = '';
        $meta = '';
        $canonical = '';
        $robots = '';

        if ($plugin === 'yoast') {
            $title = (string) get_post_meta($post_id, '_yoast_wpseo_title', true);
            $meta = (string) get_post_meta($post_id, '_yoast_wpseo_metadesc', true);
            $canonical = (string) get_post_meta($post_id, '_yoast_wpseo_canonical', true);
            $noindex = (string) get_post_meta($post_id, '_yoast_wpseo_meta-robots-noindex', true);
            $robots = ($noindex === '1') ? 'noindex' : 'index';
        } elseif ($plugin === 'rank_math') {
            $title = (string) get_post_meta($post_id, 'rank_math_title', true);
            $meta = (string) get_post_meta($post_id, 'rank_math_description', true);
            $canonical = (string) get_post_meta($post_id, 'rank_math_canonical_url', true);
            $robots = get_post_meta($post_id, 'rank_math_robots', true);
            if (is_array($robots)) {
                $robots = implode(',', $robots);
            }
            $robots = (string) $robots;
        } elseif ($plugin === 'aioseo') {
            $title = (string) get_post_meta($post_id, '_aioseo_title', true);
            $meta = (string) get_post_meta($post_id, '_aioseo_description', true);
            $canonical = (string) get_post_meta($post_id, '_aioseo_canonical_url', true);
            $robots_meta = get_post_meta($post_id, '_aioseo_robots_meta', true);
            if (is_array($robots_meta)) {
                $parts = array();
                if (!empty($robots_meta['noindex'])) {
                    $parts[] = 'noindex';
                } else {
                    $parts[] = 'index';
                }
                if (!empty($robots_meta['nofollow'])) {
                    $parts[] = 'nofollow';
                }
                $robots = implode(',', $parts);
            } else {
                $robots = (string) $robots_meta;
            }
        } elseif ($plugin === 'seopress') {
            $title = (string) get_post_meta($post_id, '_seopress_titles_title', true);
            $meta = (string) get_post_meta($post_id, '_seopress_titles_desc', true);
            $canonical = (string) get_post_meta($post_id, '_seopress_robots_canonical', true);
            $robots = ((string) get_post_meta($post_id, '_seopress_robots_index', true) === 'yes') ? 'noindex' : 'index';
        } elseif ($plugin === 'tsf') {
            $title = (string) get_post_meta($post_id, '_genesis_title', true);
            $meta = (string) get_post_meta($post_id, '_genesis_description', true);
            $canonical = (string) get_post_meta($post_id, '_genesis_canonical_uri', true);
            $robots = ((string) get_post_meta($post_id, '_genesis_noindex', true) === '1') ? 'noindex' : 'index';
        } else {
            $title = (string) get_post_meta($post_id, '_serpulix_seo_title', true);
            $meta = (string) get_post_meta($post_id, '_serpulix_seo_description', true);
            $canonical = (string) get_post_meta($post_id, '_serpulix_seo_canonical', true);
            $robots = (string) get_post_meta($post_id, '_serpulix_seo_robots', true);
        }

        return array(
            'plugin' => $plugin,
            'title' => $title,
            'meta_description' => $meta,
            'canonical' => $canonical,
            'robots' => $robots,
        );
    }

    private function write_seo_fields($post_id, $fields) {
        $plugin = $this->detect_seo_plugin();
        $previous = $this->read_seo_fields($post_id);

        if (array_key_exists('title', $fields)) {
            $val = sanitize_text_field((string) $fields['title']);
            if ($plugin === 'yoast') {
                update_post_meta($post_id, '_yoast_wpseo_title', $val);
            } elseif ($plugin === 'rank_math') {
                update_post_meta($post_id, 'rank_math_title', $val);
            } elseif ($plugin === 'aioseo') {
                update_post_meta($post_id, '_aioseo_title', $val);
            } elseif ($plugin === 'seopress') {
                update_post_meta($post_id, '_seopress_titles_title', $val);
            } elseif ($plugin === 'tsf') {
                update_post_meta($post_id, '_genesis_title', $val);
            } else {
                update_post_meta($post_id, '_serpulix_seo_title', $val);
            }
        }
        if (array_key_exists('meta_description', $fields)) {
            $val = sanitize_textarea_field((string) $fields['meta_description']);
            if ($plugin === 'yoast') {
                update_post_meta($post_id, '_yoast_wpseo_metadesc', $val);
            } elseif ($plugin === 'rank_math') {
                update_post_meta($post_id, 'rank_math_description', $val);
            } elseif ($plugin === 'aioseo') {
                update_post_meta($post_id, '_aioseo_description', $val);
            } elseif ($plugin === 'seopress') {
                update_post_meta($post_id, '_seopress_titles_desc', $val);
            } elseif ($plugin === 'tsf') {
                update_post_meta($post_id, '_genesis_description', $val);
            } else {
                update_post_meta($post_id, '_serpulix_seo_description', $val);
            }
        }
        if (array_key_exists('canonical', $fields)) {
            $val = esc_url_raw((string) $fields['canonical']);
            if ($plugin === 'yoast') {
                update_post_meta($post_id, '_yoast_wpseo_canonical', $val);
            } elseif ($plugin === 'rank_math') {
                update_post_meta($post_id, 'rank_math_canonical_url', $val);
            } elseif ($plugin === 'aioseo') {
                update_post_meta($post_id, '_aioseo_canonical_url', $val);
            } elseif ($plugin === 'seopress') {
                update_post_meta($post_id, '_seopress_robots_canonical', $val);
            } elseif ($plugin === 'tsf') {
                update_post_meta($post_id, '_genesis_canonical_uri', $val);
            } else {
                update_post_meta($post_id, '_serpulix_seo_canonical', $val);
            }
        }
        if (array_key_exists('robots', $fields)) {
            $val = sanitize_text_field((string) $fields['robots']);
            if ($plugin === 'yoast') {
                $noindex = (stripos($val, 'noindex') !== false) ? '1' : '';
                update_post_meta($post_id, '_yoast_wpseo_meta-robots-noindex', $noindex);
            } elseif ($plugin === 'rank_math') {
                $robots = array_filter(array_map('trim', explode(',', $val)));
                update_post_meta($post_id, 'rank_math_robots', $robots);
            } elseif ($plugin === 'aioseo') {
                $robots_meta = get_post_meta($post_id, '_aioseo_robots_meta', true);
                if (!is_array($robots_meta)) {
                    $robots_meta = array();
                }
                $robots_meta['noindex'] = (stripos($val, 'noindex') !== false) ? '1' : '';
                $robots_meta['nofollow'] = (stripos($val, 'nofollow') !== false) ? '1' : '';
                update_post_meta($post_id, '_aioseo_robots_meta', $robots_meta);
            } elseif ($plugin === 'seopress') {
                update_post_meta($post_id, '_seopress_robots_index', (stripos($val, 'noindex') !== false) ? 'yes' : '');
            } elseif ($plugin === 'tsf') {
                update_post_meta($post_id, '_genesis_noindex', (stripos($val, 'noindex') !== false) ? '1' : '0');
            } else {
                update_post_meta($post_id, '_serpulix_seo_robots', $val);
            }
        }

        return $previous;
    }

    public function handle_get_page($request) {
        $url = $request->get_param('url');
        $post_id = $this->resolve_post_id_from_url($url);
        if (!$post_id) {
            return new WP_REST_Response(array('ok' => false, 'error' => 'Page not found for URL'), 404);
        }
        $post = get_post($post_id);
        if (!$post) {
            return new WP_REST_Response(array('ok' => false, 'error' => 'Post not found'), 404);
        }

        $seo = $this->read_seo_fields($post_id);
        preg_match_all('/<h1[^>]*>(.*?)<\\/h1>/is', $post->post_content, $h1s);

        return new WP_REST_Response(array(
            'ok' => true,
            'resource_id' => (string) $post_id,
            'title' => $seo['title'],
            'meta_description' => $seo['meta_description'],
            'canonical' => $seo['canonical'],
            'robots' => $seo['robots'],
            'h1s' => array_map('wp_strip_all_tags', $h1s[1]),
            'content_html' => $post->post_content,
            'content_hash' => md5($post->post_content),
            'seo_plugin' => $seo['plugin'],
            'permalink' => get_permalink($post_id),
        ), 200);
    }

    public function handle_write_seo($request) {
        $post_id = intval($request['id']);
        $post = get_post($post_id);
        if (!$post) {
            return new WP_REST_Response(array('ok' => false, 'error' => 'Post not found'), 404);
        }
        $params = $request->get_json_params();
        if (!is_array($params)) {
            return new WP_REST_Response(array('ok' => false, 'error' => 'Invalid body'), 400);
        }
        $fields = array();
        foreach (array('title', 'meta_description', 'canonical', 'robots') as $key) {
            if (array_key_exists($key, $params)) {
                $fields[$key] = $params[$key];
            }
        }
        if (empty($fields)) {
            return new WP_REST_Response(array('ok' => false, 'error' => 'No SEO fields to update'), 400);
        }
        $previous = $this->write_seo_fields($post_id, $fields);
        // A host's page cache (SiteGround's above all) kept serving the old title: clear this page now.
        $purged = Serpulix_Cache::purge_page($post_id, get_permalink($post_id), 'serpulix_tech_seo_changed');
        return new WP_REST_Response(array(
            'ok' => true,
            'cache_purged' => $purged,
            'previous' => array(
                'title' => $previous['title'],
                'meta_description' => $previous['meta_description'],
                'canonical' => $previous['canonical'],
                'robots' => $previous['robots'],
            ),
            'seo_plugin' => $previous['plugin'],
        ), 200);
    }

    public function handle_write_content($request) {
        $post_id = intval($request['id']);
        $post = get_post($post_id);
        if (!$post) {
            return new WP_REST_Response(array('ok' => false, 'error' => 'Post not found'), 404);
        }
        $params = $request->get_json_params();
        $content = isset($params['content_html']) ? $params['content_html'] : null;
        $expected = isset($params['expected_hash']) ? $params['expected_hash'] : null;
        if ($content === null) {
            return new WP_REST_Response(array('ok' => false, 'error' => 'content_html required'), 400);
        }
        $current_hash = md5($post->post_content);
        if ($expected && !hash_equals($current_hash, (string) $expected)) {
            return new WP_REST_Response(array(
                'ok' => false,
                'error' => 'Content changed since scan',
                'current_hash' => $current_hash,
            ), 409);
        }
        $previous_hash = $current_hash;
        $result = wp_update_post(array(
            'ID' => $post_id,
            'post_content' => $content,
        ), true);
        if (is_wp_error($result)) {
            return new WP_REST_Response(array('ok' => false, 'error' => $result->get_error_message()), 403);
        }
        $purged = Serpulix_Cache::purge_page($post_id, get_permalink($post_id), 'serpulix_tech_seo_changed');
        return new WP_REST_Response(array(
            'ok' => true,
            'previous_hash' => $previous_hash,
            'content_hash' => md5($content),
            'cache_purged' => $purged,
        ), 200);
    }

    public function handle_redirect($request) {
        $params = $request->get_json_params();
        $from = isset($params['from']) ? esc_url_raw($params['from']) : '';
        $to = isset($params['to']) ? esc_url_raw($params['to']) : '';
        $type = isset($params['type']) ? intval($params['type']) : 301;
        if (!$from || !$to) {
            return new WP_REST_Response(array('ok' => false, 'error' => 'from and to required'), 400);
        }
        if (!in_array($type, array(301, 302, 307, 308), true)) {
            $type = 301;
        }

        if (class_exists('RankMath') && class_exists('\\RankMath\\Redirections\\DB')) {
            try {
                \RankMath\Redirections\DB::add(array(
                    'sources' => array(array('pattern' => $from, 'comparison' => 'exact')),
                    'url_to' => $to,
                    'header_code' => $type,
                    'status' => 'active',
                ));
                Serpulix_Cache::purge_page(0, $from, 'serpulix_tech_seo_changed');
                return new WP_REST_Response(array('ok' => true, 'via' => 'rank_math'), 200);
            } catch (Exception $e) {
                // fall through
            }
        }

        // Serpulix own redirect table (option)
        $redirects = get_option('serpulix_tech_seo_redirects', array());
        if (!is_array($redirects)) {
            $redirects = array();
        }
        $key = $this->normalize_redirect_key($from);
        $redirects[$key] = array('to' => $to, 'type' => $type, 'from' => $from);
        update_option('serpulix_tech_seo_redirects', $redirects, false);
        // The old address may be cached as it was (a 404 page): clear it so the redirect is served.
        Serpulix_Cache::purge_page(0, $from, 'serpulix_tech_seo_changed');

        return new WP_REST_Response(array('ok' => true, 'via' => 'serpulix'), 200);
    }

    /**
     * POST /cache/purge. With a page (`post_id` and/or `url` in the body) only that page is cleared,
     * the same way a write clears it; with neither, the whole site (the pre-4.9.2 behaviour, which
     * missed SiteGround). Serpulix sends the page from 4.9.2 on; older Serpulix sends no body.
     */
    public function handle_cache_purge($request) {
        $params = $request->get_json_params();
        $params = is_array($params) ? $params : array();
        $post_id = isset($params['post_id']) ? intval($params['post_id']) : 0;
        $url = isset($params['url']) ? esc_url_raw($params['url']) : '';
        if ($post_id && !get_post($post_id)) {
            $post_id = 0;
        }
        if (!$post_id && $url) {
            $post_id = url_to_postid($url);
        }
        if ($post_id || $url) {
            if (!$url) {
                $url = get_permalink($post_id);
            }
            $purged = Serpulix_Cache::purge_page($post_id, $url, 'serpulix_tech_seo_changed');
            return new WP_REST_Response(array('ok' => true, 'scope' => 'page', 'post_id' => $post_id, 'purged' => $purged), 200);
        }
        $purged = Serpulix_Cache::purge_site();
        return new WP_REST_Response(array('ok' => true, 'scope' => 'site', 'purged' => $purged), 200);
    }

    /**
     * Apply redirects stored when no Rank Math (or Rank Math add failed).
     */
    public function maybe_redirect() {
        if (is_admin() || (defined('REST_REQUEST') && REST_REQUEST)) {
            return;
        }

        $redirects = get_option('serpulix_tech_seo_redirects', array());
        if (!is_array($redirects) || empty($redirects)) {
            return;
        }

        $request_uri = isset($_SERVER['REQUEST_URI']) ? wp_unslash($_SERVER['REQUEST_URI']) : '';
        if ($request_uri === '') {
            return;
        }

        $current = home_url($request_uri);
        $keys = array(
            $this->normalize_redirect_key($current),
            $this->normalize_redirect_key(home_url(wp_parse_url($request_uri, PHP_URL_PATH) ?: '/')),
        );

        foreach ($keys as $key) {
            if (!isset($redirects[$key]) || !is_array($redirects[$key])) {
                continue;
            }
            $to = isset($redirects[$key]['to']) ? $redirects[$key]['to'] : '';
            $type = isset($redirects[$key]['type']) ? intval($redirects[$key]['type']) : 301;
            if (!$to || !in_array($type, array(301, 302, 307, 308), true)) {
                continue;
            }
            wp_redirect($to, $type);
            exit;
        }
    }

    /**
     * When no Yoast/Rank Math/AIOSEO is active, output Serpulix SEO meta so
     * live verification can see title/description/canonical/robots writes.
     */
    public function output_serpulix_seo_tags() {
        if ($this->detect_seo_plugin() !== 'serpulix') {
            return;
        }
        if (!is_singular() && !is_front_page()) {
            return;
        }

        $post_id = $this->current_post_id();
        if (!$post_id) {
            return;
        }

        $desc = (string) get_post_meta($post_id, '_serpulix_seo_description', true);
        $canonical = (string) get_post_meta($post_id, '_serpulix_seo_canonical', true);
        $robots = (string) get_post_meta($post_id, '_serpulix_seo_robots', true);

        if ($desc !== '') {
            echo '<meta name="description" content="' . esc_attr($desc) . '" />' . "\n";
        }
        if ($canonical !== '') {
            echo '<link rel="canonical" href="' . esc_url($canonical) . '" />' . "\n";
        }
        if ($robots !== '') {
            echo '<meta name="robots" content="' . esc_attr($robots) . '" />' . "\n";
        }
    }

    public function filter_document_title($title) {
        if ($this->detect_seo_plugin() !== 'serpulix') {
            return $title;
        }
        $post_id = $this->current_post_id();
        if (!$post_id) {
            return $title;
        }
        $custom = (string) get_post_meta($post_id, '_serpulix_seo_title', true);
        return $custom !== '' ? $custom : $title;
    }

    public function filter_wp_title($title, $sep = '') {
        unset($sep);
        if ($this->detect_seo_plugin() !== 'serpulix') {
            return $title;
        }
        $post_id = $this->current_post_id();
        if (!$post_id) {
            return $title;
        }
        $custom = (string) get_post_meta($post_id, '_serpulix_seo_title', true);
        return $custom !== '' ? $custom : $title;
    }

    private function current_post_id() {
        if (is_singular()) {
            return (int) get_queried_object_id();
        }
        if (is_front_page()) {
            $front = (int) get_option('page_on_front');
            if ($front) {
                return $front;
            }
        }
        return 0;
    }

    private function normalize_redirect_key($url) {
        $path = wp_parse_url($url, PHP_URL_PATH);
        if ($path === null || $path === false || $path === '') {
            $path = '/';
        }
        return untrailingslashit(strtolower($path));
    }
}
