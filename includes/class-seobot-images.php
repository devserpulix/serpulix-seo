<?php
/**
 * Alt-tag inventory and writes for serpulix/v1.
 *
 * List is one row per image on a published post or page (featured image and
 * <img> in content), never the media library on its own. A write updates
 * `_wp_attachment_image_alt` and the matching <img> in post content — meta
 * alone does not show on the page.
 *
 * HTML rules stay in lockstep with src/lib/alt-tags/wp-html.ts (the fixture
 * test). Do not rename seobot_api_* options. Requires PHP 7.4.
 */
class Serpulix_SEO_Images {

    const PER_PAGE = 20;
    const ALT_HARD_CHARS = 1000;
    const IMG_PATTERN = "/<img\\b(?:[^>\"']|\"[^\"]*\"|'[^']*')*>/i";

    public function handle_list($request) {
        $since = $request->get_param('since');
        $cursor = $request->get_param('cursor');
        $page_urls_raw = $request->get_param('page_urls');

        $after_id = 0;
        if ($cursor !== null && $cursor !== '') {
            if (!is_numeric($cursor)) {
                return new WP_REST_Response(array('success' => false, 'error' => 'Invalid cursor'), 400);
            }
            $after_id = (int) $cursor;
        }

        $targeted = false;
        $page_ids = array();
        if ($page_urls_raw !== null && $page_urls_raw !== '') {
            $urls = $this->parse_page_urls($page_urls_raw);
            if ($urls === null) {
                return new WP_REST_Response(array('success' => false, 'error' => 'Invalid page_urls'), 400);
            }
            if (count($urls) > 50) {
                return new WP_REST_Response(array('success' => false, 'error' => 'page_urls is limited to 50'), 400);
            }
            $targeted = true;
            foreach ($urls as $url) {
                $id = $this->url_to_post($url);
                if ($id) {
                    $page_ids[] = $id;
                }
            }
            $page_ids = array_values(array_unique($page_ids));
        }

        if (is_string($since) && $since !== '' && strtotime($since) === false) {
            return new WP_REST_Response(array('success' => false, 'error' => 'Invalid since'), 400);
        }

        $args = array(
            'post_type' => array('post', 'page'),
            'post_status' => 'publish',
            'orderby' => 'ID',
            'order' => 'ASC',
            'ignore_sticky_posts' => true,
            'no_found_rows' => true,
        );

        if ($targeted) {
            // Empty post__in is treated as "no filter" by WP and would list everything.
            $args['post__in'] = count($page_ids) ? $page_ids : array(0);
            $args['posts_per_page'] = count($page_ids) ? count($page_ids) : 1;
            $args['orderby'] = 'post__in';
        } else {
            $args['posts_per_page'] = self::PER_PAGE;
        }

        if (is_string($since) && $since !== '') {
            $args['date_query'] = array(array(
                'column' => 'post_modified_gmt',
                'after' => $since,
                'inclusive' => true,
            ));
        }

        $filter = null;
        if (!$targeted && $after_id > 0) {
            $filter = function ($where) use ($after_id) {
                global $wpdb;
                $where .= $wpdb->prepare(" AND {$wpdb->posts}.ID > %d", $after_id);
                return $where;
            };
            add_filter('posts_where', $filter);
        }

        $query = new WP_Query($args);
        if ($filter) {
            remove_filter('posts_where', $filter);
        }

        $items = array();
        foreach ($query->posts as $post) {
            $items = array_merge($items, $this->items_for_post($post));
        }

        $next = null;
        if (!$targeted && count($query->posts) === self::PER_PAGE) {
            $last = $query->posts[count($query->posts) - 1];
            $next = (string) $last->ID;
        }

        return new WP_REST_Response(array(
            'success' => true,
            'items' => $items,
            'next_cursor' => $next,
        ), 200);
    }

    public function handle_write_alt($request) {
        return $this->apply_alt($request);
    }

    public function handle_revert_alt($request) {
        return $this->apply_alt($request);
    }

    /**
     * Same mutation for write and revert. Revert is a write of the alt the
     * previous response returned. When page_url / post_id is sent and does not
     * resolve, attachment meta still updates but other posts are not touched.
     */
    private function apply_alt($request) {
        $attachment_id = (int) $request->get_param('id');
        $params = $request->get_json_params();
        if (!is_array($params)) {
            $params = array();
        }

        $attachment = get_post($attachment_id);
        if (!$attachment || $attachment->post_type !== 'attachment') {
            return new WP_REST_Response(array('success' => false, 'error' => 'Attachment not found'), 404);
        }
        if (!$this->is_image_attachment($attachment_id)) {
            return new WP_REST_Response(array('success' => false, 'error' => 'Attachment is not an image'), 400);
        }
        if (!array_key_exists('alt_present', $params)) {
            return new WP_REST_Response(array('success' => false, 'error' => 'Missing alt_present'), 400);
        }
        if (array_key_exists('alt', $params) && !is_string($params['alt'])) {
            return new WP_REST_Response(array('success' => false, 'error' => 'alt must be a string'), 400);
        }

        $alt = isset($params['alt']) ? $params['alt'] : '';
        $alt_present = (bool) $params['alt_present'];
        $length = function_exists('mb_strlen') ? mb_strlen($alt) : strlen($alt);
        if ($length > self::ALT_HARD_CHARS) {
            return new WP_REST_Response(array('success' => false, 'error' => 'Alt text exceeds 1000 characters'), 400);
        }

        $requested_post = 0;
        $targeted = false;
        if (!empty($params['post_id'])) {
            $targeted = true;
            $requested_post = (int) $params['post_id'];
            if (!get_post($requested_post)) {
                $requested_post = 0;
            }
        } elseif (!empty($params['page_url']) && is_string($params['page_url'])) {
            $targeted = true;
            $requested_post = $this->url_to_post($params['page_url']);
        }
        $page_unresolved = $targeted && !$requested_post;

        $file_url = wp_get_attachment_url($attachment_id);
        $path = is_string($file_url) ? parse_url($file_url, PHP_URL_PATH) : '';
        $file_name = is_string($path) && $path !== '' ? wp_basename($path) : '';

        $previous_present = metadata_exists('post', $attachment_id, '_wp_attachment_image_alt');
        $previous_alt = $previous_present ? (string) get_post_meta($attachment_id, '_wp_attachment_image_alt', true) : null;

        $posts = array();
        if (!$page_unresolved) {
            $posts = $requested_post
                ? array(get_post($requested_post))
                : $this->posts_referencing($attachment_id, $file_name);
        }

        $updated = 0;
        foreach ($posts as $post) {
            if (!$post || !isset($post->post_content)) {
                continue;
            }
            $rewritten = self::rewrite_content($post->post_content, $attachment_id, $file_name, $alt, $alt_present);
            if ($rewritten === $post->post_content) {
                continue;
            }
            $result = $this->update_post_content($post->ID, $rewritten);
            if (is_wp_error($result)) {
                return new WP_REST_Response(array(
                    'success' => false,
                    'error' => $result->get_error_message(),
                ), 500);
            }
            $updated++;
        }

        if ($alt_present) {
            update_post_meta($attachment_id, '_wp_attachment_image_alt', $alt);
        } else {
            delete_post_meta($attachment_id, '_wp_attachment_image_alt');
        }

        return new WP_REST_Response(array(
            'success' => true,
            'previous_alt' => $previous_present ? $previous_alt : null,
            'previous_alt_present' => (bool) $previous_present,
            'posts_updated' => $updated,
            'page_unresolved' => $page_unresolved,
        ), 200);
    }

    /**
     * Mirror of applyWpAltWrite in src/lib/alt-tags/wp-html.ts.
     * Script and style blocks are not rewritten. wp-image-{id} must be a whole
     * token (wp-image-420 is not wp-image-42). Src match ignores -300x200 and
     * -scaled. Gutenberg wp:image JSON for that id is updated too.
     */
    public static function rewrite_content($html, $attachment_id, $file_name, $alt, $alt_present) {
        if (!is_string($html) || $html === '') {
            return is_string($html) ? $html : '';
        }

        $blocks = array();
        $masked = preg_replace_callback(
            '/<(script|style)\b[^>]*>[\s\S]*?<\/\1>/i',
            function ($match) use (&$blocks) {
                $token = '%%SERPULIX_HOLD_' . count($blocks) . '%%';
                $blocks[] = $match[0];
                return $token;
            },
            $html
        );
        if (!is_string($masked)) {
            $masked = $html;
        }

        $id = (string) $attachment_id;
        if (preg_match('/^\d+$/', $id)) {
            $masked = preg_replace_callback(
                '/<!--\s*wp:image\b[\s\S]*?-->/',
                function ($match) use ($id, $alt, $alt_present) {
                    return self::rewrite_image_block($match[0], $id, $alt, $alt_present);
                },
                $masked
            );
            if (!is_string($masked)) {
                $masked = $html;
            }
        }

        $rewritten = preg_replace_callback(
            self::IMG_PATTERN,
            function ($match) use ($id, $file_name, $alt, $alt_present) {
                $tag = $match[0];
                if (!self::tag_matches($tag, $id, $file_name)) {
                    return $tag;
                }
                return self::write_img_alt($tag, $alt, $alt_present);
            },
            $masked
        );
        if (!is_string($rewritten)) {
            $rewritten = $masked;
        }

        $restored = preg_replace_callback(
            '/%%SERPULIX_HOLD_(\d+)%%/',
            function ($match) use ($blocks) {
                $index = (int) $match[1];
                return isset($blocks[$index]) ? $blocks[$index] : $match[0];
            },
            $rewritten
        );

        return is_string($restored) ? $restored : $rewritten;
    }

    private function items_for_post($post) {
        $page_url = get_permalink($post);
        if (!$page_url) {
            return array();
        }
        $title = get_the_title($post);
        $content = is_string($post->post_content) ? $post->post_content : '';
        $h1 = $this->first_h1($content);
        if ($h1 === '') {
            $h1 = $title;
        }

        $by_key = array();
        $thumb = (int) get_post_thumbnail_id($post);
        if ($thumb && $this->is_image_attachment($thumb)) {
            $by_key['id:' . $thumb] = $this->attachment_item($thumb, $post, $page_url, $title, $h1, 'featured', null);
        }

        if ($content !== '' && preg_match_all(self::IMG_PATTERN, $content, $tags)) {
            foreach ($tags[0] as $tag) {
                $src = $this->absolute_src(self::read_attr($tag, 'src'));
                if ($src === '') {
                    continue;
                }
                $attachment_id = $this->resolve_attachment_id($tag, $src);
                if ($attachment_id && !$this->is_image_attachment($attachment_id)) {
                    continue;
                }
                $key = $attachment_id ? 'id:' . $attachment_id : 'url:' . $src;
                $context = $this->context_around($content, $tag);

                if (isset($by_key[$key])) {
                    $existing = $by_key[$key];
                    if ($existing['section_heading'] === null && $context['section_heading'] !== null) {
                        $existing['section_heading'] = $context['section_heading'];
                    }
                    if ($existing['caption'] === null && $context['caption'] !== null) {
                        $existing['caption'] = $context['caption'];
                    }
                    if ($existing['adjacent_text'] === null && $context['adjacent_text'] !== null) {
                        $existing['adjacent_text'] = $context['adjacent_text'];
                    }
                    if (!$existing['is_link'] && $context['is_link']) {
                        $existing['is_link'] = true;
                        $existing['link_href'] = $context['link_href'];
                    }
                    $from_tag = self::alt_from_tag($tag);
                    $existing['alt'] = $from_tag['alt'];
                    $existing['alt_present'] = $from_tag['alt_present'];
                    $by_key[$key] = $existing;
                    continue;
                }

                if ($attachment_id) {
                    $item = $this->attachment_item($attachment_id, $post, $page_url, $title, $h1, 'content', $tag);
                } else {
                    $item = $this->external_item($src, $post, $page_url, $title, $h1, $tag);
                }
                $item['section_heading'] = $context['section_heading'];
                $item['caption'] = $context['caption'];
                $item['adjacent_text'] = $context['adjacent_text'];
                $item['is_link'] = $context['is_link'];
                $item['link_href'] = $context['link_href'];
                $by_key[$key] = $item;
            }
        }

        return array_values($by_key);
    }

    private function attachment_item($attachment_id, $post, $page_url, $title, $h1, $context_type, $tag) {
        $file_url = wp_get_attachment_url($attachment_id);
        $path = is_string($file_url) ? parse_url($file_url, PHP_URL_PATH) : '';
        $file_name = is_string($path) && $path !== '' ? wp_basename($path) : '';
        $meta = wp_get_attachment_metadata($attachment_id);
        $attached = get_attached_file($attachment_id);
        $file_size = (is_string($attached) && file_exists($attached)) ? filesize($attached) : null;

        if ($tag) {
            $alt_info = self::alt_from_tag($tag);
        } else {
            $present = metadata_exists('post', $attachment_id, '_wp_attachment_image_alt');
            $alt_info = array(
                'alt' => $present ? (string) get_post_meta($attachment_id, '_wp_attachment_image_alt', true) : null,
                'alt_present' => (bool) $present,
            );
        }

        $width = (is_array($meta) && isset($meta['width'])) ? (int) $meta['width'] : null;
        $height = (is_array($meta) && isset($meta['height'])) ? (int) $meta['height'] : null;

        return array(
            'external_id' => (string) $attachment_id,
            'post_id' => (string) $post->ID,
            'url' => is_string($file_url) ? $file_url : '',
            'file_name' => $file_name,
            'page_url' => $page_url,
            'page_title' => $title,
            'page_h1' => $h1,
            'section_heading' => null,
            'caption' => null,
            'adjacent_text' => null,
            'context_type' => $context_type,
            'alt' => $alt_info['alt'],
            'alt_present' => $alt_info['alt_present'],
            'width' => $width,
            'height' => $height,
            'file_size' => $file_size === false ? null : $file_size,
            'mime_type' => get_post_mime_type($attachment_id) ?: null,
            'is_link' => false,
            'link_href' => null,
            'modified_at' => $this->modified_at($attachment_id),
        );
    }

    private function external_item($src, $post, $page_url, $title, $h1, $tag) {
        $alt_info = self::alt_from_tag($tag);
        $path = parse_url($src, PHP_URL_PATH);
        $file_name = is_string($path) ? wp_basename($path) : '';
        $width = self::read_attr($tag, 'width');
        $height = self::read_attr($tag, 'height');

        return array(
            'external_id' => null,
            'post_id' => (string) $post->ID,
            'url' => $src,
            'file_name' => $file_name,
            'page_url' => $page_url,
            'page_title' => $title,
            'page_h1' => $h1,
            'section_heading' => null,
            'caption' => null,
            'adjacent_text' => null,
            'context_type' => 'content',
            'alt' => $alt_info['alt'],
            'alt_present' => $alt_info['alt_present'],
            'width' => is_numeric($width) ? (int) $width : null,
            'height' => is_numeric($height) ? (int) $height : null,
            'file_size' => null,
            'mime_type' => null,
            'is_link' => false,
            'link_href' => null,
            'modified_at' => $this->modified_at($post->ID),
        );
    }

    private function posts_referencing($attachment_id, $file_name) {
        global $wpdb;
        $ids = $wpdb->get_col($wpdb->prepare(
            "SELECT post_id FROM {$wpdb->postmeta} WHERE meta_key = '_thumbnail_id' AND meta_value = %s LIMIT 100",
            (string) $attachment_id
        ));
        if (!is_array($ids)) {
            $ids = array();
        }

        $like_class = '%' . $wpdb->esc_like('wp-image-' . $attachment_id) . '%';
        $content_ids = $wpdb->get_col($wpdb->prepare(
            "SELECT ID FROM {$wpdb->posts} WHERE post_type IN ('post','page') AND post_status IN ('publish','draft','future','private','pending') AND post_content LIKE %s LIMIT 100",
            $like_class
        ));
        if (is_array($content_ids)) {
            $ids = array_merge($ids, $content_ids);
        }

        if ($file_name !== '') {
            $like_file = '%' . $wpdb->esc_like($file_name) . '%';
            $file_ids = $wpdb->get_col($wpdb->prepare(
                "SELECT ID FROM {$wpdb->posts} WHERE post_type IN ('post','page') AND post_status IN ('publish','draft','future','private','pending') AND post_content LIKE %s LIMIT 100",
                $like_file
            ));
            if (is_array($file_ids)) {
                $ids = array_merge($ids, $file_ids);
            }
        }

        $posts = array();
        foreach (array_unique(array_map('intval', $ids)) as $id) {
            $post = get_post($id);
            if ($post && $post->post_type !== 'attachment') {
                $posts[] = $post;
            }
        }
        return $posts;
    }

    private function update_post_content($post_id, $content) {
        // REST requests have no user, so kses would strip Content Studio markup.
        $restore = (bool) has_filter('content_save_pre', 'wp_filter_post_kses');
        kses_remove_filters();
        $result = wp_update_post(array(
            'ID' => $post_id,
            'post_content' => $content,
        ), true);
        if ($restore) {
            kses_init_filters();
        }
        return $result;
    }

    private function url_to_post($url) {
        $url = esc_url_raw($url);
        if (!$url) {
            return 0;
        }
        $post_id = (int) url_to_postid($url);
        if ($post_id) {
            return $post_id;
        }
        $post_id = (int) url_to_postid(trailingslashit($url));
        if ($post_id) {
            return $post_id;
        }
        $home = untrailingslashit(home_url());
        if (untrailingslashit($url) === $home) {
            return (int) get_option('page_on_front');
        }
        return 0;
    }

    private function parse_page_urls($raw) {
        if (is_array($raw)) {
            $urls = array();
            foreach ($raw as $url) {
                if (!is_string($url) || $url === '') {
                    return null;
                }
                $urls[] = $url;
            }
            return $urls;
        }
        if (!is_string($raw)) {
            return null;
        }
        $decoded = json_decode($raw, true);
        if (!is_array($decoded)) {
            return array($raw);
        }
        $urls = array();
        foreach ($decoded as $url) {
            if (!is_string($url) || $url === '') {
                return null;
            }
            $urls[] = $url;
        }
        return $urls;
    }

    private function resolve_attachment_id($tag, $src) {
        if (preg_match('/(?:^|[\s"\'=])wp-image-(\d+)(?!\d)/', $tag, $match)) {
            $id = (int) $match[1];
            $post = get_post($id);
            if ($post && $post->post_type === 'attachment') {
                return $id;
            }
        }
        if (!$src) {
            return 0;
        }
        $id = (int) attachment_url_to_postid($src);
        if ($id) {
            return $id;
        }
        $stripped = $this->unsized_url($src);
        if ($stripped !== $src) {
            return (int) attachment_url_to_postid($stripped);
        }
        return 0;
    }

    private function unsized_url($src) {
        $parts = explode('?', $src, 2);
        $path = $parts[0];
        $query = isset($parts[1]) ? '?' . $parts[1] : '';
        $prev = '';
        while ($path !== $prev) {
            $prev = $path;
            $next = preg_replace('/-\d+x\d+(\.[A-Za-z0-9]+)$/', '$1', $path);
            if (is_string($next)) {
                $path = $next;
            }
            $next = preg_replace('/-scaled(\.[A-Za-z0-9]+)$/', '$1', $path);
            if (is_string($next)) {
                $path = $next;
            }
        }
        return $path . $query;
    }

    private function absolute_src($src) {
        if (!is_string($src)) {
            return '';
        }
        $src = trim($src);
        if ($src === '' || strpos($src, 'data:') === 0) {
            return '';
        }
        if (preg_match('#^https?://#i', $src)) {
            return $src;
        }
        if (strpos($src, '//') === 0) {
            return (is_ssl() ? 'https:' : 'http:') . $src;
        }
        return home_url($src);
    }

    private function is_image_attachment($id) {
        if (wp_attachment_is_image($id)) {
            return true;
        }
        $mime = get_post_mime_type($id);
        return is_string($mime) && strpos($mime, 'image/') === 0;
    }

    private function first_h1($content) {
        if (preg_match('/<h1\b[^>]*>(.*?)<\/h1>/is', $content, $match)) {
            return trim(wp_strip_all_tags($match[1]));
        }
        return '';
    }

    private function context_around($content, $tag) {
        $empty = array(
            'section_heading' => null,
            'caption' => null,
            'adjacent_text' => null,
            'is_link' => false,
            'link_href' => null,
        );
        $pos = strpos($content, $tag);
        if ($pos === false) {
            return $empty;
        }

        $before = substr($content, 0, $pos);
        if (preg_match_all('/<h([1-6])\b[^>]*>(.*?)<\/h\1>/is', $before, $heads) && !empty($heads[2])) {
            $last = count($heads[2]) - 1;
            $level = (int) $heads[1][$last];
            $text = trim(wp_strip_all_tags($heads[2][$last]));
            if ($text !== '' && $level >= 2) {
                $empty['section_heading'] = $text;
            }
        }

        $after = substr($content, $pos, 800);
        if (preg_match('/<figcaption\b[^>]*>(.*?)<\/figcaption>/is', $after, $cap)) {
            $caption = trim(wp_strip_all_tags($cap[1]));
            if ($caption !== '') {
                $empty['caption'] = $caption;
            }
        }

        $text = trim(wp_strip_all_tags(substr($content, $pos + strlen($tag), 500)));
        if ($text === '') {
            $text = trim(wp_strip_all_tags(substr($before, max(0, strlen($before) - 500))));
        }
        if ($text !== '') {
            if (preg_match('/^.{1,240}?[.!?]/u', $text, $sentence)) {
                $empty['adjacent_text'] = $sentence[0];
            } else {
                $empty['adjacent_text'] = function_exists('mb_substr') ? mb_substr($text, 0, 240) : substr($text, 0, 240);
            }
        }

        $window = substr($before, max(0, strlen($before) - 400));
        if (preg_match('/<a\b[^>]*href=(["\'])([^"\']+)\1[^>]*>\s*$/i', $window, $link)) {
            $empty['is_link'] = true;
            $empty['link_href'] = $link[2];
        }

        return $empty;
    }

    private function modified_at($post_id) {
        $gmt = get_post_field('post_modified_gmt', $post_id);
        if (!is_string($gmt) || $gmt === '') {
            return null;
        }
        $stamp = strtotime($gmt . ' UTC');
        if ($stamp === false) {
            return null;
        }
        return gmdate('c', $stamp);
    }

    private static function rewrite_image_block($full, $attachment_id, $alt, $alt_present) {
        if (!preg_match('/\{[\s\S]*\}/', $full, $json_match)) {
            return $full;
        }
        $data = json_decode($json_match[0], true);
        if (!is_array($data)) {
            return $full;
        }
        if ((string) (isset($data['id']) ? $data['id'] : '') !== (string) $attachment_id) {
            return $full;
        }
        if ($alt_present) {
            $data['alt'] = $alt;
        } else {
            unset($data['alt']);
        }
        $encoded = wp_json_encode($data);
        if (!is_string($encoded)) {
            return $full;
        }
        $encoded = str_replace('--', '\\u002d\\u002d', $encoded);
        return str_replace($json_match[0], $encoded, $full);
    }

    private static function tag_matches($tag, $attachment_id, $file_name) {
        if (preg_match('/^\d+$/', (string) $attachment_id)) {
            $pattern = '/(?:^|[\s"\'=])wp-image-' . preg_quote((string) $attachment_id, '/') . '(?!\d)/';
            if (preg_match($pattern, $tag)) {
                return true;
            }
        }
        $src = self::read_attr($tag, 'src');
        if (!$src || !$file_name) {
            return false;
        }
        return self::src_matches($src, $file_name);
    }

    private static function src_matches($src, $file_name) {
        $left = self::file_stem($src);
        $right = self::file_stem($file_name);
        if (!$left || !$right) {
            return false;
        }
        return $left['stem'] === $right['stem'] && $left['ext'] === $right['ext'];
    }

    private static function file_stem($name) {
        $base = $name;
        $q = strpos($base, '?');
        if ($q !== false) {
            $base = substr($base, 0, $q);
        }
        $hash = strpos($base, '#');
        if ($hash !== false) {
            $base = substr($base, 0, $hash);
        }
        $slash = strrpos($base, '/');
        if ($slash !== false) {
            $base = substr($base, $slash + 1);
        }
        $dot = strrpos($base, '.');
        if ($dot === false || $dot === 0) {
            return null;
        }
        $stem = substr($base, 0, $dot);
        $ext = strtolower(substr($base, $dot));
        $prev = '';
        while ($stem !== $prev) {
            $prev = $stem;
            $next = preg_replace('/-scaled$/i', '', $stem);
            if (!is_string($next)) {
                break;
            }
            $stem = $next;
            $next = preg_replace('/-\d+x\d+$/i', '', $stem);
            if (!is_string($next)) {
                break;
            }
            $stem = $next;
        }
        return array('stem' => strtolower($stem), 'ext' => $ext);
    }

    private static function alt_from_tag($tag) {
        $value = self::read_attr($tag, 'alt');
        if ($value === null && !preg_match('/\balt\s*=/i', $tag)) {
            return array('alt' => null, 'alt_present' => false);
        }
        return array('alt' => $value === null ? '' : $value, 'alt_present' => true);
    }

    private static function read_attr($tag, $name) {
        $quoted = preg_quote($name, '/');
        if (preg_match('/\b' . $quoted . '\s*=\s*"([^"]*)"/i', $tag, $match)) {
            return html_entity_decode($match[1], ENT_QUOTES, 'UTF-8');
        }
        if (preg_match('/\b' . $quoted . "\\s*=\\s*'([^']*)'/i", $tag, $match)) {
            return html_entity_decode($match[1], ENT_QUOTES, 'UTF-8');
        }
        return null;
    }

    private static function write_img_alt($tag, $alt, $alt_present) {
        $stripped = preg_replace('/\s+\balt\s*=\s*(?:"[^"]*"|\'[^\']*\'|[^\s>]+)/i', '', $tag, 1);
        if (!is_string($stripped)) {
            $stripped = $tag;
        }
        if (!$alt_present) {
            return $stripped;
        }
        $attr = ' alt="' . self::escape_attr($alt) . '"';
        if (preg_match('/\s*\/>\s*$/', $stripped)) {
            $next = preg_replace('/\s*\/>\s*$/', $attr . ' />', $stripped, 1);
            return is_string($next) ? $next : $stripped;
        }
        $next = preg_replace('/>\s*$/', $attr . '>', $stripped, 1);
        return is_string($next) ? $next : $stripped;
    }

    private static function escape_attr($value) {
        return str_replace(
            array('&', '"', '<', '>'),
            array('&amp;', '&quot;', '&lt;', '&gt;'),
            $value
        );
    }
}
