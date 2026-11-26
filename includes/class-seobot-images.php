<?php
/**
 * Alt-tag inventory and writes for serpulix/v1.
 *
 * List is one row per image on a published post or page (featured image and
 * <img> in content), never the media library on its own. A write updates
 * `_wp_attachment_image_alt` and the matching <img> in post content — meta
 * alone does not show on the page.
 *
 * Matching (which <img> is this attachment): the WordPress media id on the tag
 * (`wp-image-N` or `data-attachment-id`) decides when the tag has one, whatever
 * host serves the file. A tag with no media id matches only when it is on this
 * site and its upload path (folder and file, size suffix ignored) is the
 * attachment's own file. Never by file name alone, never on another host.
 *
 * Saving: an alt-only change updates post_content directly (no "last modified"
 * bump, no revision, no unslashing), then clears the post's caches. Meta goes
 * through wp_slash(), because update_post_meta() unslashes its value.
 *
 * HTML rules stay in lockstep with src/lib/alt-tags/wp-html.ts (the fixture
 * test). Do not rename seobot_api_* options. Requires PHP 7.4.
 */
class Serpulix_SEO_Images {

    const PER_PAGE = 20;
    const ALT_HARD_CHARS = 1000;
    const IMG_PATTERN = "/<img\\b(?:[^>\"']|\"[^\"]*\"|'[^']*')*>/i";
    /** Write protocol. 2: compare before write, per-place results, request ids, plan and lookup. */
    const PROTOCOL = 2;
    const JOURNAL_KEY = '_serpulix_alt_requests';
    const JOURNAL_KEEP = 10;
    const IN_PROGRESS_SECONDS = 120;
    /** Pages rendered after a write to check what they print. */
    const VERIFY_MAX = 20;
    const REFERENCING_LIMIT = 200;

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
     * Same handler for write and revert.
     *
     * Protocol 2 (the body carries `places`): every place says which alt Serpulix last saw there,
     * and a place whose live alt is something else (a person changed it) is left alone and reported
     * as "changed". The media library alt is compared the same way. Each place reports what
     * happened to it, and what the rendered page prints afterwards where the plugin can tell.
     * A request id makes the write idempotent: the same id answers with the first result.
     *
     * `mode: lookup` (read a request's result) and `mode: plan` (which posts a write would edit,
     * so Serpulix can lock them first) send no `alt_present`: a plugin older than protocol 2
     * answers them with 400 "Missing alt_present" and writes nothing.
     *
     * Without `places` (an older Serpulix) the write is the earlier one: the alt goes to every
     * published place that holds the image, with the exact matching and the safe save below.
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

        $mode = isset($params['mode']) && is_string($params['mode']) ? $params['mode'] : 'write';
        if ($mode === 'lookup') {
            return $this->lookup_request($attachment_id, $params);
        }
        if ($mode === 'plan') {
            return $this->plan_write($attachment_id, $params);
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

        if (isset($params['places']) && is_array($params['places'])) {
            return $this->write_v2($attachment_id, $params, $alt, $alt_present);
        }
        return $this->write_legacy($attachment_id, $params, $alt, $alt_present);
    }

    /**
     * The earlier write (no `places`): every published place that holds the image gets the alt,
     * or only the post named by page_url / post_id. Kept for a Serpulix that does not send places.
     */
    private function write_legacy($attachment_id, $params, $alt, $alt_present) {
        $target = $this->match_target($attachment_id);

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

        $posts = array();
        if (!$page_unresolved) {
            $posts = $requested_post
                ? array(get_post($requested_post))
                : $this->posts_referencing($attachment_id, $target);
        }

        // Prefer the page <img> alt for the receipt — not attachment meta.
        $previous_from_page = false;
        $previous_present = false;
        $previous_alt = null;
        foreach ($posts as $probe) {
            if (!$probe || !isset($probe->post_content)) {
                continue;
            }
            $lives = self::matching_alts($probe->post_content, $target);
            if (count($lives)) {
                $previous_from_page = true;
                $previous_present = $lives[0]['present'];
                $previous_alt = $lives[0]['alt'];
                break;
            }
        }
        if (!$previous_from_page) {
            $current = $this->media_alt($attachment_id);
            $previous_present = $current['present'];
            $previous_alt = $current['alt'];
        }

        $updated = 0;
        $matched = 0;
        $places = array();
        foreach ($posts as $post) {
            if (!$post || !isset($post->post_content)) {
                continue;
            }
            $lives = self::matching_alts($post->post_content, $target);
            if (count($lives)) {
                $matched++;
                $places[] = array(
                    'post_id' => (string) $post->ID,
                    'page_url' => get_permalink($post) ?: null,
                    'previous_alt' => $lives[0]['present'] ? $lives[0]['alt'] : null,
                    'previous_alt_present' => (bool) $lives[0]['present'],
                );
            }
            $rewritten = self::rewrite_content($post->post_content, $target, $alt, $alt_present);
            if ($rewritten === $post->post_content) {
                continue;
            }
            $result = $this->save_post_content($post->ID, $rewritten);
            if (is_wp_error($result)) {
                return new WP_REST_Response(array(
                    'success' => false,
                    'error' => $result->get_error_message(),
                ), 500);
            }
            $updated++;
            $this->purge($post->ID);
        }

        // Decorative (alt_present true, alt "") stores empty meta — never delete the key.
        $this->write_media_alt($attachment_id, array('alt' => $alt_present ? $alt : null, 'present' => $alt_present));

        return new WP_REST_Response(array(
            'success' => true,
            'protocol' => self::PROTOCOL,
            'previous_alt' => $previous_present ? $previous_alt : null,
            'previous_alt_present' => (bool) $previous_present,
            'posts_updated' => $updated,
            'posts_matched' => $matched,
            'page_unresolved' => $page_unresolved,
            'places' => $places,
        ), 200);
    }

    /** Protocol 2: compare before write, per place, idempotent by request id. */
    private function write_v2($attachment_id, $params, $alt, $alt_present) {
        $request_id = self::clean_request_id($params);
        $carried = array();
        $carried_media = null;
        if ($request_id !== '') {
            $record = $this->journal_get($attachment_id, $request_id);
            if ($record) {
                if (!empty($record['done']) && isset($record['response']) && is_array($record['response'])) {
                    $response = $record['response'];
                    $response['replayed'] = true;
                    return new WP_REST_Response($response, 200);
                }
                if (time() - (int) (isset($record['started_at']) ? $record['started_at'] : 0) < self::IN_PROGRESS_SECONDS) {
                    return new WP_REST_Response(array(
                        'success' => false,
                        'protocol' => self::PROTOCOL,
                        'code' => 'in_progress',
                        'error' => 'An earlier write with this request id is still running',
                    ), 409);
                }
                // The earlier attempt stopped half-way: keep what it wrote, do the rest.
                $carried = isset($record['places']) && is_array($record['places']) ? $record['places'] : array();
                $carried_media = isset($record['media']) && is_array($record['media']) ? $record['media'] : null;
            }
        }

        $target = $this->match_target($attachment_id);
        $mismatch = $this->media_mismatch($params, $target);
        if ($mismatch !== null) {
            return new WP_REST_Response($mismatch, 409);
        }

        $default_target = array('alt' => $alt_present ? $alt : null, 'present' => $alt_present);
        $known = self::alt_list(isset($params['known']) ? $params['known'] : array());
        $others = !empty($params['others']);
        $only = null;
        if (isset($params['only_post_ids']) && is_array($params['only_post_ids'])) {
            $only = array_map('intval', $params['only_post_ids']);
        }

        $journal = array(
            'started_at' => time(),
            'done' => false,
            'places' => $carried,
            'media' => $carried_media,
        );
        if ($request_id !== '') {
            $this->journal_put($attachment_id, $request_id, $journal);
        }

        $resolved = $this->resolve_places($params['places']);
        $listed = $resolved['listed'];
        $results = $resolved['unresolved'];

        $ids = array_keys($listed);
        if ($others) {
            foreach ($this->posts_referencing($attachment_id, $target) as $post) {
                $ids[] = (int) $post->ID;
            }
        }
        $ids = array_values(array_unique(array_map('intval', $ids)));

        $updated = 0;
        $matched = 0;
        $written_posts = array();
        foreach ($ids as $post_id) {
            $is_listed = isset($listed[$post_id]);
            $place_target = ($is_listed && $listed[$post_id]['target'] !== null) ? $listed[$post_id]['target'] : $default_target;
            $page_url = $is_listed ? $listed[$post_id]['page_url'] : '';

            if (isset($carried[(string) $post_id])) {
                $results[] = $carried[(string) $post_id];
                $matched++;
                continue;
            }
            if ($only !== null && !in_array($post_id, $only, true)) {
                if ($is_listed) {
                    $results[] = self::place_row($post_id, $page_url, 'skipped', null, null, 'not_locked', true);
                }
                continue;
            }

            // Read again right before the save: a person may have edited the post since the plan.
            clean_post_cache($post_id);
            $post = get_post($post_id);
            if (!$post) {
                if ($is_listed) {
                    $results[] = self::place_row($post_id, $page_url, 'not_found', null, null, 'page_not_found', true);
                }
                continue;
            }
            $permalink = $post->post_type === 'wp_block' ? null : (get_permalink($post) ?: ($page_url !== '' ? $page_url : null));
            // Drafts, private and scheduled posts are not what visitors see: left alone.
            if ($post->post_type !== 'wp_block' && $post->post_status !== 'publish') {
                if ($is_listed) {
                    $results[] = self::place_row($post_id, $permalink, 'skipped', null, null, 'not_published', true);
                }
                continue;
            }

            $html = is_string($post->post_content) ? $post->post_content : '';
            $allowed = $is_listed ? $listed[$post_id]['expected'] : $known;
            $decision = self::decide_post($html, $target, $place_target, $allowed, $is_listed);

            if ($decision['status'] === 'not_found') {
                if ($is_listed) {
                    $thumb = (int) get_post_thumbnail_id($post);
                    $results[] = $thumb === (int) $attachment_id
                        ? self::place_row($post_id, $permalink, 'via_media', null, null, 'featured', true)
                        : self::place_row($post_id, $permalink, 'not_found', null, null, 'not_in_content', true);
                }
                continue;
            }
            $matched++;
            if ($decision['status'] === 'changed') {
                $results[] = self::place_row($post_id, $permalink, 'changed', $decision['previous'], $decision['live'], null, $is_listed);
                continue;
            }
            if ($decision['status'] === 'unchanged') {
                $results[] = self::place_row($post_id, $permalink, 'unchanged', $decision['previous'], null, null, $is_listed);
                continue;
            }

            $saved = $this->save_post_content($post_id, $decision['html']);
            if (is_wp_error($saved)) {
                $results[] = self::place_row($post_id, $permalink, 'failed', $decision['previous'], null, $saved->get_error_message(), $is_listed);
                continue;
            }
            $updated++;
            $written_posts[] = $post_id;
            $row = self::place_row($post_id, $permalink, 'written', $decision['previous'], null, null, $is_listed);
            $results[] = $row;
            if ($request_id !== '') {
                $journal['places'][(string) $post_id] = $row;
                $this->journal_put($attachment_id, $request_id, $journal);
            }
        }

        $media = null;
        $media_target = $default_target;
        if (isset($params['media']) && is_array($params['media'])) {
            if (isset($params['media']['target']) && is_array($params['media']['target'])) {
                $media_target = self::alt_value($params['media']['target']);
            }
            if ($carried_media !== null && isset($carried_media['status']) && $carried_media['status'] === 'written') {
                $media = $carried_media;
            } else {
                $expected = self::alt_list(isset($params['media']['expected']) ? $params['media']['expected'] : array());
                $current = $this->media_alt($attachment_id);
                $status = self::decide_media($current, $media_target, $expected);
                if ($status === 'write') {
                    $this->write_media_alt($attachment_id, $media_target);
                    $status = 'written';
                }
                $media = array(
                    'kind' => 'media',
                    'status' => $status,
                    'previous_alt' => $current['present'] ? $current['alt'] : null,
                    'previous_alt_present' => (bool) $current['present'],
                    'live_alt' => ($status === 'changed' && $current['present']) ? $current['alt'] : null,
                    'live_alt_present' => $status === 'changed' ? (bool) $current['present'] : null,
                );
                if ($request_id !== '' && $status === 'written') {
                    $journal['media'] = $media;
                    $this->journal_put($attachment_id, $request_id, $journal);
                }
            }
        }

        if ($media !== null && $media['status'] === 'written') {
            foreach ($results as $row) {
                if ($row['status'] === 'via_media' && !empty($row['post_id'])) {
                    $written_posts[] = (int) $row['post_id'];
                }
            }
        }
        foreach (array_slice(array_values(array_unique($written_posts)), 0, 50) as $post_id) {
            $this->purge($post_id);
        }

        // A listed place carries the address Serpulix sent, so Serpulix finds its own row for it.
        foreach ($results as $index => $row) {
            if (!empty($row['post_id']) && isset($listed[(int) $row['post_id']]) && $listed[(int) $row['post_id']]['page_url'] !== '') {
                $results[$index]['sent_url'] = $listed[(int) $row['post_id']]['page_url'];
            } elseif (empty($row['post_id']) && !empty($row['page_url'])) {
                $results[$index]['sent_url'] = $row['page_url'];
            }
        }

        // What the page prints now, where the plugin can render it. A place only the media library
        // feeds (a featured image) is checked against the library alt.
        $checked = 0;
        foreach ($results as $index => $row) {
            if ($checked >= self::VERIFY_MAX) {
                break;
            }
            if (!in_array($row['status'], array('written', 'unchanged', 'via_media'), true) || empty($row['post_id'])) {
                continue;
            }
            $post = get_post((int) $row['post_id']);
            if (!$post || $post->post_type === 'wp_block') {
                continue;
            }
            $checked++;
            $listed_target = isset($listed[(int) $row['post_id']]) ? $listed[(int) $row['post_id']]['target'] : null;
            $expect = $row['status'] === 'via_media' ? $media_target : ($listed_target !== null ? $listed_target : $default_target);
            $printed = self::matching_alts($this->render_page_html($post), $target);
            $results[$index] = array_merge($row, self::printed_summary($printed, $expect));
        }

        $first = null;
        foreach ($results as $row) {
            if (in_array($row['status'], array('written', 'unchanged'), true)) {
                $first = $row;
                break;
            }
        }
        $previous_alt = $first ? $first['previous_alt'] : ($media ? $media['previous_alt'] : null);
        $previous_present = $first ? $first['previous_alt_present'] : ($media ? $media['previous_alt_present'] : false);

        $response = array(
            'success' => true,
            'protocol' => self::PROTOCOL,
            'request_id' => $request_id !== '' ? $request_id : null,
            'replayed' => false,
            'previous_alt' => $previous_alt,
            'previous_alt_present' => (bool) $previous_present,
            'posts_updated' => $updated,
            'posts_matched' => $matched,
            'page_unresolved' => false,
            'places' => array_values($results),
            'media' => $media,
        );
        if ($request_id !== '') {
            $journal['done'] = true;
            $journal['response'] = $response;
            $this->journal_put($attachment_id, $request_id, $journal);
        }
        return new WP_REST_Response($response, 200);
    }

    /** `mode: lookup`: what an earlier write with this request id did, without writing. */
    private function lookup_request($attachment_id, $params) {
        $request_id = self::clean_request_id($params);
        if ($request_id === '') {
            return new WP_REST_Response(array('success' => false, 'protocol' => self::PROTOCOL, 'error' => 'Missing request_id'), 400);
        }
        $record = $this->journal_get($attachment_id, $request_id);
        if (!$record) {
            return new WP_REST_Response(array('success' => true, 'protocol' => self::PROTOCOL, 'mode' => 'lookup', 'found' => false), 200);
        }
        $done = !empty($record['done']);
        $started = (int) (isset($record['started_at']) ? $record['started_at'] : 0);
        return new WP_REST_Response(array(
            'success' => true,
            'protocol' => self::PROTOCOL,
            'mode' => 'lookup',
            'found' => true,
            'done' => $done,
            'running' => !$done && (time() - $started) < self::IN_PROGRESS_SECONDS,
            'response' => ($done && isset($record['response'])) ? $record['response'] : null,
            'places' => (isset($record['places']) && is_array($record['places'])) ? array_values($record['places']) : array(),
            'media' => isset($record['media']) ? $record['media'] : null,
        ), 200);
    }

    /** `mode: plan`: the posts a write would edit (Serpulix locks them first). Writes nothing. */
    private function plan_write($attachment_id, $params) {
        $target = $this->match_target($attachment_id);
        $mismatch = $this->media_mismatch($params, $target);
        if ($mismatch !== null) {
            return new WP_REST_Response($mismatch, 409);
        }
        $resolved = $this->resolve_places(isset($params['places']) && is_array($params['places']) ? $params['places'] : array());
        $posts = array();
        foreach ($resolved['listed'] as $post_id => $place) {
            $posts[$post_id] = array(
                'post_id' => (string) $post_id,
                'page_url' => get_permalink($post_id) ?: ($place['page_url'] !== '' ? $place['page_url'] : null),
                'listed' => true,
            );
        }
        if (!empty($params['others'])) {
            foreach ($this->posts_referencing($attachment_id, $target) as $post) {
                if (!isset($posts[(int) $post->ID])) {
                    $posts[(int) $post->ID] = array(
                        'post_id' => (string) $post->ID,
                        'page_url' => $post->post_type === 'wp_block' ? null : (get_permalink($post) ?: null),
                        'listed' => false,
                    );
                }
            }
        }
        $unresolved = array();
        foreach ($resolved['unresolved'] as $row) {
            $unresolved[] = $row['page_url'];
        }
        return new WP_REST_Response(array(
            'success' => true,
            'protocol' => self::PROTOCOL,
            'mode' => 'plan',
            'posts' => array_values($posts),
            'unresolved' => $unresolved,
        ), 200);
    }

    /** Listed places by post id (expected alts merged), and the ones whose page is not found. */
    private function resolve_places($raw_places) {
        $listed = array();
        $unresolved = array();
        foreach ($raw_places as $raw) {
            if (!is_array($raw)) {
                continue;
            }
            $page_url = isset($raw['page_url']) && is_string($raw['page_url']) ? $raw['page_url'] : '';
            $post_id = !empty($raw['post_id']) ? (int) $raw['post_id'] : 0;
            if ($post_id && !get_post($post_id)) {
                $post_id = 0;
            }
            if (!$post_id && $page_url !== '') {
                $post_id = $this->url_to_post($page_url);
            }
            if (!$post_id) {
                $unresolved[] = self::place_row(0, $page_url !== '' ? $page_url : null, 'not_found', null, null, 'page_not_found', true);
                continue;
            }
            $expected = self::alt_list(isset($raw['expected']) ? $raw['expected'] : array());
            $place_target = isset($raw['target']) && is_array($raw['target']) ? self::alt_value($raw['target']) : null;
            if (isset($listed[$post_id])) {
                $listed[$post_id]['expected'] = array_merge($listed[$post_id]['expected'], $expected);
                continue;
            }
            $listed[$post_id] = array('expected' => $expected, 'target' => $place_target, 'page_url' => $page_url);
        }
        return array('listed' => $listed, 'unresolved' => $unresolved);
    }

    private static function place_row($post_id, $page_url, $status, $previous, $live, $reason, $listed) {
        return array(
            'post_id' => $post_id ? (string) $post_id : null,
            'page_url' => $page_url,
            'kind' => 'page',
            'status' => $status,
            'listed' => (bool) $listed,
            'previous_alt' => ($previous && $previous['present']) ? $previous['alt'] : null,
            'previous_alt_present' => $previous ? (bool) $previous['present'] : false,
            'live_alt' => ($live && $live['present']) ? $live['alt'] : null,
            'live_alt_present' => $live ? (bool) $live['present'] : null,
            'reason' => $reason,
            'prints' => null,
            'printed_alt' => null,
        );
    }

    /** 'new' when every printed copy shows the target, 'other' with the first that does not, 'none'. */
    private static function printed_summary($printed, $target) {
        if (!count($printed)) {
            return array('prints' => 'none', 'printed_alt' => null);
        }
        foreach ($printed as $value) {
            if (!self::alt_equal($value, $target)) {
                return array('prints' => 'other', 'printed_alt' => $value['present'] ? $value['alt'] : null);
            }
        }
        return array('prints' => 'new', 'printed_alt' => null);
    }

    /**
     * One post: write it only when every copy of the image in it still shows an alt Serpulix
     * expected (or already shows the target). A place Serpulix did not list may also be written
     * when its alt is empty. Mirror of decideWpPost in src/lib/alt-tags/wp-html.ts.
     */
    public static function decide_post($html, $target, $place_target, $allowed, $listed) {
        $lives = self::matching_alts($html, $target);
        if (!count($lives)) {
            return array('status' => 'not_found', 'html' => $html, 'previous' => null, 'live' => null);
        }
        $differs = array();
        foreach ($lives as $live) {
            if (!self::alt_same_state($live, $place_target)) {
                $differs[] = $live;
            }
        }
        if (!count($differs)) {
            return array('status' => 'unchanged', 'html' => $html, 'previous' => $lives[0], 'live' => null);
        }
        foreach ($differs as $live) {
            if (self::alt_in($live, $allowed)) {
                continue;
            }
            if (!$listed && self::alt_is_empty($live)) {
                continue;
            }
            return array('status' => 'changed', 'html' => $html, 'previous' => $differs[0], 'live' => $live);
        }
        $rewritten = self::rewrite_content($html, $target, $place_target['alt'], $place_target['present']);
        if ($rewritten === $html) {
            return array('status' => 'unchanged', 'html' => $html, 'previous' => $lives[0], 'live' => null);
        }
        return array('status' => 'written', 'html' => $rewritten, 'previous' => $differs[0], 'live' => null);
    }

    /**
     * The media library alt: "unchanged" when it already shows the target; written when it is
     * empty or one Serpulix expected; otherwise a person wrote it and it is left alone ("changed").
     * Mirror of decideWpMedia in wp-html.ts.
     */
    public static function decide_media($current, $target, $expected) {
        if (self::alt_same_state($current, $target)) {
            return 'unchanged';
        }
        if (self::alt_is_empty($current) || self::alt_in($current, $expected)) {
            return 'write';
        }
        return 'changed';
    }

    private function media_alt($attachment_id) {
        $present = metadata_exists('post', $attachment_id, '_wp_attachment_image_alt');
        return array(
            'alt' => $present ? (string) get_post_meta($attachment_id, '_wp_attachment_image_alt', true) : null,
            'present' => (bool) $present,
        );
    }

    /** update_post_meta() unslashes its value, so it is slashed first: a backslash stays a backslash. */
    private function write_media_alt($attachment_id, $value) {
        if ($value['present']) {
            update_post_meta($attachment_id, '_wp_attachment_image_alt', wp_slash((string) $value['alt']));
        } else {
            delete_post_meta($attachment_id, '_wp_attachment_image_alt');
        }
    }

    /**
     * A stored media id can point at another file after a media re-import. When Serpulix sends
     * the image address it saw, its upload path must still be this attachment's file.
     */
    private function media_mismatch($params, $target) {
        $image_url = isset($params['image_url']) && is_string($params['image_url']) ? $params['image_url'] : '';
        if ($image_url === '' || $target['file_rel'] === '') {
            return null;
        }
        $rel = self::src_upload_rel($image_url, $target, true);
        if ($rel === null || $rel === $target['file_rel']) {
            return null;
        }
        return array(
            'success' => false,
            'protocol' => self::PROTOCOL,
            'code' => 'media_mismatch',
            'error' => 'This media ID now belongs to a different file (' . $target['file_rel'] . '). Scan the page again.',
        );
    }

    private static function clean_request_id($params) {
        $id = isset($params['request_id']) && is_string($params['request_id']) ? $params['request_id'] : '';
        return preg_match('/^[A-Za-z0-9_-]{8,64}$/', $id) ? $id : '';
    }

    private function journal_get($attachment_id, $request_id) {
        $all = get_post_meta($attachment_id, self::JOURNAL_KEY, true);
        return (is_array($all) && isset($all[$request_id]) && is_array($all[$request_id])) ? $all[$request_id] : null;
    }

    /** The last few requests per image, so a retry after a timeout reads the result instead of writing again. */
    private function journal_put($attachment_id, $request_id, $record) {
        $all = get_post_meta($attachment_id, self::JOURNAL_KEY, true);
        if (!is_array($all)) {
            $all = array();
        }
        $all[$request_id] = $record;
        if (count($all) > self::JOURNAL_KEEP) {
            uasort($all, function ($a, $b) {
                return (int) (isset($a['started_at']) ? $a['started_at'] : 0) - (int) (isset($b['started_at']) ? $b['started_at'] : 0);
            });
            $all = array_slice($all, -self::JOURNAL_KEEP, null, true);
        }
        update_post_meta($attachment_id, self::JOURNAL_KEY, wp_slash($all));
    }

    private function purge($post_id) {
        if (class_exists('Serpulix_Cache')) {
            Serpulix_Cache::purge_page($post_id, get_permalink($post_id), 'serpulix_alt_changed');
        } else {
            clean_post_cache($post_id);
        }
    }

    /**
     * v1.5 page list source: registered nav menu items first (menu order),
     * then published pages and posts. URLs only; Serpulix filters and ranks.
     */
    public function handle_menu($request) {
        $limit = (int) $request->get_param('limit');
        if ($limit <= 0 || $limit > 500) {
            $limit = 300;
        }
        $seen = array();
        $items = array();
        $push = function ($url, $source) use (&$seen, &$items, $limit) {
            if (!is_string($url) || $url === '' || count($items) >= $limit) {
                return;
            }
            $key = untrailingslashit(strtolower($url));
            if (isset($seen[$key])) {
                return;
            }
            $seen[$key] = true;
            $items[] = array('url' => $url, 'source' => $source);
        };

        $locations = get_nav_menu_locations();
        if (is_array($locations)) {
            foreach ($locations as $menu_id) {
                $menu_items = wp_get_nav_menu_items($menu_id);
                if (!is_array($menu_items)) {
                    continue;
                }
                foreach ($menu_items as $item) {
                    if (isset($item->url) && $this->is_same_site($item->url)) {
                        $push($item->url, 'menu');
                    }
                }
            }
        }

        $push(home_url('/'), 'menu');

        $query = new WP_Query(array(
            'post_type' => array('page', 'post'),
            'post_status' => 'publish',
            'posts_per_page' => $limit,
            'orderby' => array('menu_order' => 'ASC', 'date' => 'DESC'),
            'no_found_rows' => true,
            'ignore_sticky_posts' => true,
            'fields' => 'ids',
        ));
        foreach ($query->posts as $post_id) {
            $push(get_permalink($post_id), 'content');
        }

        return new WP_REST_Response(array('success' => true, 'items' => $items), 200);
    }

    /**
     * v1.5 page scan: the images a visitor sees in the content area of one page, by URL. Never
     * blocked by a firewall.
     *
     * `html` is the post body as the page prints it (the_content filters, or the Elementor layout
     * for a page built with Elementor), the featured image first, and then, in a
     * `<div class="serpulix-extra-images">` at the end, the images the body does not hold:
     * WooCommerce product gallery images and ACF image, gallery, group, repeater and flexible
     * content fields. Those print the media library alt, as the theme does.
     * `sources` says what was read; `extra_images` lists the appended images (attachment id,
     * source, address) for a scan that wants to treat them on their own.
     */
    public function handle_page_content($request) {
        $url = $request->get_param('url');
        if (!is_string($url) || $url === '') {
            return new WP_REST_Response(array('success' => false, 'error' => 'Missing url'), 400);
        }
        $post_id = $this->url_to_post($url);
        if (!$post_id) {
            return new WP_REST_Response(array('success' => false, 'reason' => 'unresolved', 'page_url' => $url), 200);
        }
        $post = get_post($post_id);
        if (!$post || $post->post_status !== 'publish') {
            return new WP_REST_Response(array('success' => false, 'reason' => 'unresolved', 'page_url' => $url), 200);
        }

        $page = $this->render_page($post);
        $html = $page['html'];
        $h1 = $this->first_h1($html);
        $title = get_the_title($post);

        return new WP_REST_Response(array(
            'success' => true,
            'post_id' => (string) $post_id,
            'page_url' => get_permalink($post),
            'title' => $title,
            'h1' => $h1 !== '' ? $h1 : $title,
            'html' => is_string($html) ? $html : '',
            'modified_at' => $this->modified_at($post_id),
            'sources' => $page['sources'],
            'extra_images' => $page['extra_images'],
        ), 200);
    }

    /** The page's content-area HTML as handle_page_content returns it. */
    private function render_page_html($post) {
        $page = $this->render_page($post);
        return $page['html'];
    }

    private function render_page($post) {
        $sources = array();
        $html = $this->render_body($post, $sources);

        $thumb = (int) get_post_thumbnail_id($post);
        if ($thumb && $this->is_image_attachment($thumb)) {
            $featured = wp_get_attachment_image($thumb, 'full', false, array('class' => 'wp-image-' . $thumb . ' serpulix-featured'));
            if (is_string($featured) && $featured !== '') {
                $html = $featured . "\n" . $html;
                $sources[] = 'featured';
            }
        }

        $extra = array();
        if ($post->post_type === 'product' && function_exists('wc_get_product')) {
            try {
                $product = wc_get_product($post->ID);
                if ($product && method_exists($product, 'get_gallery_image_ids')) {
                    foreach ((array) $product->get_gallery_image_ids() as $id) {
                        $id = (int) $id;
                        if ($id && !isset($extra[$id])) {
                            $extra[$id] = 'woocommerce_gallery';
                        }
                    }
                }
            } catch (\Throwable $e) {
                // A broken shop extension must not stop the scan.
            }
        }
        if (function_exists('get_field_objects')) {
            try {
                $fields = get_field_objects($post->ID);
                if (is_array($fields)) {
                    foreach ($fields as $field) {
                        $this->collect_acf_images($field, $extra, 0);
                    }
                }
            } catch (\Throwable $e) {
                // Same for a broken field group.
            }
        }

        $extra_html = '';
        $extra_images = array();
        foreach ($extra as $id => $source) {
            if (!$this->is_image_attachment($id) || $id === $thumb) {
                continue;
            }
            if (preg_match('/(?:^|[\s"\'])wp-image-' . $id . '(?!\d)/', $html)) {
                continue;
            }
            $img = wp_get_attachment_image($id, 'full', false, array('class' => 'wp-image-' . $id . ' serpulix-extra serpulix-' . str_replace('_', '-', $source)));
            if (!is_string($img) || $img === '') {
                continue;
            }
            $extra_html .= $img . "\n";
            $address = wp_get_attachment_url($id);
            $extra_images[] = array(
                'attachment_id' => (string) $id,
                'source' => $source,
                'url' => is_string($address) ? $address : '',
            );
            if (!in_array($source === 'woocommerce_gallery' ? 'woocommerce_gallery' : 'acf', $sources, true)) {
                $sources[] = $source === 'woocommerce_gallery' ? 'woocommerce_gallery' : 'acf';
            }
        }
        if ($extra_html !== '') {
            $html .= "\n<div class=\"serpulix-extra-images\">\n" . $extra_html . "</div>";
        }

        return array('html' => $html, 'sources' => $sources, 'extra_images' => $extra_images);
    }

    /** The post body as the page prints it: Elementor's layout for an Elementor page, else the_content. */
    private function render_body($post, &$sources) {
        $raw = is_string($post->post_content) ? $post->post_content : '';
        $previous = isset($GLOBALS['post']) ? $GLOBALS['post'] : null;
        $GLOBALS['post'] = $post;
        setup_postdata($post);
        $html = null;
        ob_start();
        try {
            if (class_exists('\Elementor\Plugin') && get_post_meta($post->ID, '_elementor_edit_mode', true) === 'builder') {
                $elementor = \Elementor\Plugin::instance();
                if (isset($elementor->frontend) && method_exists($elementor->frontend, 'get_builder_content_for_display')) {
                    $built = $elementor->frontend->get_builder_content_for_display($post->ID, true);
                    if (is_string($built) && trim($built) !== '') {
                        $html = $built;
                        $sources[] = 'elementor';
                    }
                }
            }
            if ($html === null) {
                $html = apply_filters('the_content', $raw);
                $sources[] = 'the_content';
            }
        } catch (\Throwable $e) {
            $html = $raw;
            $sources[] = 'post_content';
        }
        ob_end_clean();
        wp_reset_postdata();
        $GLOBALS['post'] = $previous;
        return is_string($html) ? $html : $raw;
    }

    /** ACF image and gallery fields, also inside groups, repeaters and flexible content. */
    private function collect_acf_images($field, &$out, $depth) {
        if ($depth > 4 || !is_array($field)) {
            return;
        }
        $type = isset($field['type']) ? $field['type'] : '';
        $value = array_key_exists('value', $field) ? $field['value'] : null;
        if ($type === 'image') {
            $id = $this->acf_image_id($value);
            if ($id && !isset($out[$id])) {
                $out[$id] = 'acf';
            }
            return;
        }
        if ($type === 'gallery') {
            if (is_array($value)) {
                foreach ($value as $item) {
                    $id = $this->acf_image_id($item);
                    if ($id && !isset($out[$id])) {
                        $out[$id] = 'acf';
                    }
                }
            }
            return;
        }
        if (!is_array($value)) {
            return;
        }
        $rows = array();
        if ($type === 'group') {
            $rows = array(array('defs' => isset($field['sub_fields']) ? $field['sub_fields'] : array(), 'row' => $value));
        } elseif ($type === 'repeater') {
            foreach ($value as $row) {
                $rows[] = array('defs' => isset($field['sub_fields']) ? $field['sub_fields'] : array(), 'row' => $row);
            }
        } elseif ($type === 'flexible_content') {
            $layouts = array();
            if (isset($field['layouts']) && is_array($field['layouts'])) {
                foreach ($field['layouts'] as $layout) {
                    if (is_array($layout) && isset($layout['name'])) {
                        $layouts[$layout['name']] = isset($layout['sub_fields']) ? $layout['sub_fields'] : array();
                    }
                }
            }
            foreach ($value as $row) {
                $name = (is_array($row) && isset($row['acf_fc_layout'])) ? $row['acf_fc_layout'] : '';
                $rows[] = array('defs' => isset($layouts[$name]) ? $layouts[$name] : array(), 'row' => $row);
            }
        }
        foreach ($rows as $entry) {
            if (!is_array($entry['row']) || !is_array($entry['defs'])) {
                continue;
            }
            foreach ($entry['defs'] as $def) {
                if (!is_array($def) || !isset($def['name']) || !array_key_exists($def['name'], $entry['row'])) {
                    continue;
                }
                $def['value'] = $entry['row'][$def['name']];
                $this->collect_acf_images($def, $out, $depth + 1);
            }
        }
    }

    private function acf_image_id($value) {
        if (is_numeric($value)) {
            return (int) $value;
        }
        if (is_array($value)) {
            if (isset($value['ID']) && is_numeric($value['ID'])) {
                return (int) $value['ID'];
            }
            if (isset($value['id']) && is_numeric($value['id'])) {
                return (int) $value['id'];
            }
            return 0;
        }
        if (is_string($value) && $value !== '') {
            return (int) attachment_url_to_postid($value);
        }
        return 0;
    }

    /**
     * v1.5 editability: map image URLs to attachment ids. Size (-1024x768),
     * -scaled and -e123 edit suffixes are stripped before the lookup.
     *
     * An image served from a CDN is matched too: by the media id Serpulix read from its tag
     * (`media_ids`: url => id, optional), or by the site's upload path inside the CDN address
     * (Jetpack i0.wp.com/<site>/wp-content/uploads/…, Optimole, a cdn.<site> pull zone).
     */
    public function handle_resolve($request) {
        $params = $request->get_json_params();
        $urls = (is_array($params) && isset($params['urls']) && is_array($params['urls'])) ? $params['urls'] : null;
        if ($urls === null) {
            return new WP_REST_Response(array('success' => false, 'error' => 'Missing urls'), 400);
        }
        if (count($urls) > 200) {
            return new WP_REST_Response(array('success' => false, 'error' => 'urls is limited to 200'), 400);
        }
        $hints = (is_array($params) && isset($params['media_ids']) && is_array($params['media_ids'])) ? $params['media_ids'] : array();
        $target_base = $this->match_target(0);
        $results = array();
        foreach ($urls as $url) {
            if (!is_string($url) || $url === '') {
                continue;
            }
            $src = $this->absolute_src($url);
            $hint = isset($hints[$url]) && is_numeric($hints[$url]) ? (int) $hints[$url] : 0;
            if ($hint && $this->is_image_attachment($hint) && $this->address_fits_attachment($src, $hint)) {
                $results[] = array('url' => $url, 'external_id' => (string) $hint, 'editable' => true, 'reason' => null);
                continue;
            }
            if ($src !== '' && !$this->is_same_site($src)) {
                $rel = self::src_upload_rel($src, $target_base, true);
                if ($rel === null) {
                    $results[] = array('url' => $url, 'external_id' => null, 'editable' => false, 'reason' => 'third_party');
                    continue;
                }
                $src = $this->uploads_base_url() . self::raw_upload_rel($src, $target_base, true);
            }
            if ($src === '') {
                $results[] = array('url' => $url, 'external_id' => null, 'editable' => false, 'reason' => 'third_party');
                continue;
            }
            if (preg_match('#/wp-content/themes/|/wp-includes/#i', $src)) {
                $results[] = array('url' => $url, 'external_id' => null, 'editable' => false, 'reason' => 'theme_asset');
                continue;
            }
            $id = $this->resolve_attachment_id('', $src);
            if ($id && $this->is_image_attachment($id)) {
                $results[] = array('url' => $url, 'external_id' => (string) $id, 'editable' => true, 'reason' => null);
            } else {
                $results[] = array('url' => $url, 'external_id' => null, 'editable' => false, 'reason' => 'no_media_match');
            }
        }
        return new WP_REST_Response(array('success' => true, 'items' => $results), 200);
    }

    /** A media id hint fits when the address carries no upload path, or carries this attachment's. */
    private function address_fits_attachment($src, $attachment_id) {
        $target = $this->match_target($attachment_id);
        $rel = self::src_upload_rel($src, $target, true);
        return $rel === null || $target['file_rel'] === '' || $rel === $target['file_rel'];
    }

    private function is_same_site($url) {
        $host = parse_url($url, PHP_URL_HOST);
        if (!is_string($host) || $host === '') {
            return true;
        }
        return self::bare_host($host) === self::bare_host(parse_url(home_url(), PHP_URL_HOST));
    }

    private static function bare_host($host) {
        return preg_replace('/^www\./i', '', strtolower((string) $host));
    }

    /** What decides whether an <img> is this attachment (see the class comment). */
    private function match_target($attachment_id) {
        $rel = $attachment_id ? $this->attachment_rel($attachment_id) : '';
        $target = array(
            'id' => (string) (int) $attachment_id,
            'file_rel' => $rel !== '' ? self::normalize_rel($rel) : '',
            'raw_rel' => strtolower($rel),
            'home_host' => self::bare_host(parse_url(home_url(), PHP_URL_HOST)),
            'uploads_path' => $this->uploads_path(),
            'owner' => null,
        );
        $base = $this->uploads_base_url();
        $self = (int) $attachment_id;
        // A tag with no media id whose file is another attachment of its own (banner-1200x628.jpg
        // uploaded as a file, not a size of banner.jpg) is that attachment, not this one.
        $target['owner'] = function ($raw_rel) use ($base, $self) {
            $id = (int) attachment_url_to_postid($base . $raw_rel);
            return $id && $id !== $self ? $id : 0;
        };
        return $target;
    }

    private function attachment_rel($attachment_id) {
        $rel = get_post_meta($attachment_id, '_wp_attached_file', true);
        if (is_string($rel) && $rel !== '') {
            $rel = str_replace('\\', '/', $rel);
            $uploads = wp_get_upload_dir();
            $basedir = isset($uploads['basedir']) ? str_replace('\\', '/', (string) $uploads['basedir']) : '';
            if ($basedir !== '' && strpos($rel, $basedir . '/') === 0) {
                $rel = substr($rel, strlen($basedir) + 1);
            }
            return ltrim($rel, '/');
        }
        $url = wp_get_attachment_url($attachment_id);
        if (!is_string($url)) {
            return '';
        }
        $path = (string) parse_url($url, PHP_URL_PATH);
        $base = $this->uploads_path();
        return stripos($path, $base) === 0 ? substr($path, strlen($base)) : '';
    }

    private function uploads_path() {
        $uploads = wp_get_upload_dir();
        $path = isset($uploads['baseurl']) ? parse_url($uploads['baseurl'], PHP_URL_PATH) : '';
        if (!is_string($path) || $path === '') {
            $path = '/wp-content/uploads';
        }
        return rtrim($path, '/') . '/';
    }

    private function uploads_base_url() {
        $uploads = wp_get_upload_dir();
        $base = isset($uploads['baseurl']) && is_string($uploads['baseurl']) ? $uploads['baseurl'] : home_url('/wp-content/uploads');
        return rtrim($base, '/') . '/';
    }

    /**
     * The media id an <img> names: a `wp-image-N` class token, or `data-attachment-id`.
     * '' when it names none.
     */
    public static function tag_media_id($tag) {
        $class = self::read_attr($tag, 'class');
        if (is_string($class) && preg_match('/(?:^|\s)wp-image-(\d+)(?=\s|$)/', $class, $match)) {
            return $match[1];
        }
        $data = self::read_attr($tag, 'data-attachment-id');
        if (is_string($data) && preg_match('/^\d+$/', trim($data))) {
            return trim($data);
        }
        return '';
    }

    /**
     * The upload path an image address points at (folder and file, size suffixes removed,
     * lowercase), or null when it is not under this site's uploads. With $allow_cdn an address on
     * another host still counts when it carries the site's upload path and either names the site
     * (Jetpack, Optimole) or is a subdomain of it (cdn.example.com).
     */
    public static function src_upload_rel($src, $target, $allow_cdn = false) {
        $raw = self::raw_upload_rel($src, $target, $allow_cdn);
        return $raw === null ? null : self::normalize_rel($raw);
    }

    private static function raw_upload_rel($src, $target, $allow_cdn) {
        if (!is_string($src)) {
            return null;
        }
        $src = html_entity_decode(trim($src), ENT_QUOTES, 'UTF-8');
        if ($src === '' || strpos($src, 'data:') === 0) {
            return null;
        }
        if (strpos($src, '//') === 0) {
            $src = 'https:' . $src;
        }
        $host = parse_url($src, PHP_URL_HOST);
        $base = $target['uploads_path'];
        if (is_string($host) && $host !== '' && self::bare_host($host) !== $target['home_host']) {
            if (!$allow_cdn) {
                return null;
            }
            $bare = self::bare_host($host);
            $names_site = $target['home_host'] !== '' && stripos($src, $target['home_host'] . '/') !== false;
            $subdomain = $target['home_host'] !== '' && substr($bare, -strlen('.' . $target['home_host'])) === '.' . $target['home_host'];
            if (!$names_site && !$subdomain) {
                return null;
            }
            $path = (string) parse_url($src, PHP_URL_PATH);
            $pos = stripos($path, $base);
            if ($pos === false) {
                // Optimole and similar put the whole original address in the path.
                $pos = stripos($path, '/wp-content/uploads/');
                if ($pos === false) {
                    return null;
                }
                return ltrim(rawurldecode(substr($path, $pos + strlen('/wp-content/uploads/'))), '/');
            }
            return ltrim(rawurldecode(substr($path, $pos + strlen($base))), '/');
        }
        $path = (string) parse_url($src, PHP_URL_PATH);
        if (stripos($path, $base) !== 0) {
            return null;
        }
        return ltrim(rawurldecode(substr($path, strlen($base))), '/');
    }

    /** Lowercase upload path with WordPress size, -scaled, -rotated and -e123 edit suffixes removed. */
    public static function normalize_rel($rel) {
        $rel = strtolower(ltrim(str_replace('\\', '/', (string) $rel), '/'));
        $cut = strcspn($rel, '?#');
        $rel = substr($rel, 0, $cut);
        $dot = strrpos($rel, '.');
        $slash = strrpos($rel, '/');
        if ($dot === false || ($slash !== false && $dot < $slash)) {
            return $rel;
        }
        $stem = substr($rel, 0, $dot);
        $ext = substr($rel, $dot);
        $prev = null;
        while ($stem !== $prev) {
            $prev = $stem;
            $next = preg_replace('/-\d+x\d+$/', '', $stem);
            $stem = is_string($next) ? $next : $stem;
            $next = preg_replace('/-(?:scaled|rotated)$/', '', $stem);
            $stem = is_string($next) ? $next : $stem;
            $next = preg_replace('/-e\d{8,}$/', '', $stem);
            $stem = is_string($next) ? $next : $stem;
        }
        return $stem . $ext;
    }

    /**
     * Mirror of imgTagMatchesAttachment in src/lib/alt-tags/wp-html.ts. The media id decides when
     * the tag has one; otherwise the tag's file on this site must be the attachment's own file.
     */
    public static function tag_matches($tag, $target) {
        $media_id = self::tag_media_id($tag);
        if ($media_id !== '') {
            return $media_id === (string) $target['id'];
        }
        if ($target['file_rel'] === '') {
            return false;
        }
        $raw = self::raw_upload_rel(self::read_attr($tag, 'src'), $target, false);
        if ($raw === null || self::normalize_rel($raw) !== $target['file_rel']) {
            return false;
        }
        $raw = strtolower($raw);
        if ($raw !== $target['raw_rel'] && isset($target['owner']) && is_callable($target['owner'])) {
            return !call_user_func($target['owner'], $raw);
        }
        return true;
    }

    /**
     * Mirror of applyWpAltWrite in src/lib/alt-tags/wp-html.ts.
     * Script and style blocks are not rewritten. Gutenberg wp:image JSON for that id is updated too.
     */
    public static function rewrite_content($html, $target, $alt, $alt_present) {
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

        $id = (string) $target['id'];
        if (preg_match('/^\d+$/', $id) && $id !== '0') {
            $next = preg_replace_callback(
                '/<!--\s*wp:image\b[\s\S]*?-->/',
                function ($match) use ($id, $alt, $alt_present) {
                    return self::rewrite_image_block($match[0], $id, $alt, $alt_present);
                },
                $masked
            );
            if (is_string($next)) {
                $masked = $next;
            }
        }

        $rewritten = preg_replace_callback(
            self::IMG_PATTERN,
            function ($match) use ($target, $alt, $alt_present) {
                $tag = $match[0];
                if (!self::tag_matches($tag, $target)) {
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

    /** Alt of every <img> in this HTML that is the attachment (script and style blocks do not count). */
    public static function matching_alts($html, $target) {
        if (!is_string($html) || $html === '') {
            return array();
        }
        $masked = preg_replace('/<(script|style)\b[^>]*>[\s\S]*?<\/\1>/i', '', $html);
        if (!is_string($masked)) {
            $masked = $html;
        }
        $out = array();
        if (preg_match_all(self::IMG_PATTERN, $masked, $tags)) {
            foreach ($tags[0] as $tag) {
                if (self::tag_matches($tag, $target)) {
                    $info = self::alt_from_tag($tag);
                    $out[] = array('alt' => $info['alt'], 'present' => $info['alt_present']);
                }
            }
        }
        return $out;
    }

    /** {alt, alt_present} from a request into the internal {alt, present}. */
    private static function alt_value($raw) {
        if (!is_array($raw)) {
            return array('alt' => null, 'present' => false);
        }
        $present = !empty($raw['alt_present']);
        $alt = (isset($raw['alt']) && is_string($raw['alt'])) ? $raw['alt'] : '';
        return array('alt' => $present ? $alt : null, 'present' => $present);
    }

    private static function alt_list($raw) {
        $out = array();
        if (!is_array($raw)) {
            return $out;
        }
        foreach ($raw as $item) {
            if (is_array($item)) {
                $out[] = self::alt_value($item);
            }
        }
        return $out;
    }

    /** No alt and alt="" read the same; spaces are trimmed and runs of white space count as one. */
    private static function alt_norm($value) {
        if (!is_array($value) || empty($value['present'])) {
            return '';
        }
        $text = (string) $value['alt'];
        $squeezed = preg_replace('/\s+/u', ' ', $text);
        if (!is_string($squeezed)) {
            $squeezed = preg_replace('/\s+/', ' ', $text);
        }
        return trim(is_string($squeezed) ? $squeezed : $text);
    }

    public static function alt_equal($a, $b) {
        return self::alt_norm($a) === self::alt_norm($b);
    }

    /**
     * Same state, for a place against its TARGET: besides equal text, "no alt attribute" and
     * alt="" are different (a decorative mark must put alt="" on an img that has none). Lists of
     * expected alts keep using alt_equal, so a page a person left alone stays left alone.
     */
    public static function alt_same_state($a, $b) {
        if (self::alt_norm($a) !== self::alt_norm($b)) {
            return false;
        }
        return self::alt_norm($a) !== '' || !empty($a['present']) === !empty($b['present']);
    }

    public static function alt_is_empty($value) {
        return self::alt_norm($value) === '';
    }

    private static function alt_in($value, $list) {
        foreach ($list as $item) {
            if (self::alt_equal($value, $item)) {
                return true;
            }
        }
        return false;
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

    /**
     * Published posts of every public type (and synced patterns) whose content may hold the
     * attachment, plus the posts that use it as featured image. Drafts, private and scheduled
     * posts are left out: visitors do not see them. The content match is checked tag by tag later.
     */
    private function posts_referencing($attachment_id, $target) {
        global $wpdb;
        $ids = $wpdb->get_col($wpdb->prepare(
            "SELECT post_id FROM {$wpdb->postmeta} WHERE meta_key = '_thumbnail_id' AND meta_value = %s LIMIT " . (int) self::REFERENCING_LIMIT,
            (string) $attachment_id
        ));
        if (!is_array($ids)) {
            $ids = array();
        }

        $types = array_values(array_diff(get_post_types(array('public' => true)), array('attachment')));
        $types[] = 'wp_block';
        $type_list = implode(',', array_map(function ($type) use ($wpdb) {
            return $wpdb->prepare('%s', $type);
        }, $types));
        $status_sql = "((post_status = 'publish') OR (post_type = 'wp_block'))";

        $patterns = array('%' . $wpdb->esc_like('wp-image-' . $attachment_id) . '%');
        $patterns[] = '%' . $wpdb->esc_like('data-attachment-id="' . $attachment_id . '"') . '%';
        if (!empty($target['file_rel'])) {
            // Folder and file without the extension, so sized copies (-1024x768) are found too.
            $rel = $target['file_rel'];
            $dot = strrpos($rel, '.');
            $stem = $dot !== false ? substr($rel, 0, $dot) : $rel;
            $patterns[] = '%' . $wpdb->esc_like($stem) . '%';
        }
        foreach ($patterns as $like) {
            $content_ids = $wpdb->get_col($wpdb->prepare(
                "SELECT ID FROM {$wpdb->posts} WHERE post_type IN ($type_list) AND $status_sql AND post_content LIKE %s LIMIT " . (int) self::REFERENCING_LIMIT,
                $like
            ));
            if (is_array($content_ids)) {
                $ids = array_merge($ids, $content_ids);
            }
        }

        $posts = array();
        foreach (array_unique(array_map('intval', $ids)) as $id) {
            $post = get_post($id);
            if (!$post || $post->post_type === 'attachment') {
                continue;
            }
            if ($post->post_type !== 'wp_block' && $post->post_status !== 'publish') {
                continue;
            }
            $posts[] = $post;
        }
        return $posts;
    }

    /**
     * Save an alt-only change to post_content. A direct update, so WordPress neither bumps "last
     * modified" (sitemaps, feeds) nor unslashes the content: wp_update_post() strips one level of
     * backslashes, which broke block settings (\u003c), JSON-LD and paths. The post's caches are
     * cleared here; the page caches by purge().
     */
    private function save_post_content($post_id, $content) {
        global $wpdb;
        $result = $wpdb->update(
            $wpdb->posts,
            array('post_content' => $content),
            array('ID' => (int) $post_id),
            array('%s'),
            array('%d')
        );
        if ($result === false) {
            return new WP_Error('serpulix_save_failed', $wpdb->last_error ? $wpdb->last_error : 'The post could not be saved');
        }
        clean_post_cache($post_id);
        return true;
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
        $media_id = $tag ? self::tag_media_id($tag) : '';
        if ($media_id !== '') {
            $id = (int) $media_id;
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
            $next = preg_replace('/-e\d{8,}(\.[A-Za-z0-9]+)$/', '$1', $path);
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

    /**
     * Set (or remove) the alt attribute. Built by string, never as a preg replacement: in a
     * replacement "$50" means group 50, so "Coupon for $50 off" lost its price.
     */
    private static function write_img_alt($tag, $alt, $alt_present) {
        $stripped = preg_replace('/\s+\balt\s*=\s*(?:"[^"]*"|\'[^\']*\'|[^\s>]+)/i', '', $tag, 1);
        if (!is_string($stripped)) {
            $stripped = $tag;
        }
        if (!$alt_present) {
            return $stripped;
        }
        $attr = ' alt="' . self::escape_attr($alt) . '"';
        if (preg_match('/\s*\/>\s*$/', $stripped, $end, PREG_OFFSET_CAPTURE)) {
            return substr($stripped, 0, $end[0][1]) . $attr . ' />';
        }
        if (preg_match('/>\s*$/', $stripped, $end, PREG_OFFSET_CAPTURE)) {
            return substr($stripped, 0, $end[0][1]) . $attr . '>';
        }
        return $stripped;
    }

    private static function escape_attr($value) {
        return str_replace(
            array('&', '"', '<', '>'),
            array('&amp;', '&quot;', '&lt;', '&gt;'),
            $value
        );
    }
}
