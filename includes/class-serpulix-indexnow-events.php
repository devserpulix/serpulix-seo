<?php
/**
 * Reports published post and page changes to Serpulix for IndexNow / Bing submission.
 *
 * Serpulix queues the URLs and applies the site's Client Config (master switch, "Send on WordPress
 * page updates", "Send on deleted pages", exclusions, cooldown), so this class only reports what
 * changed. URLs are collected during the request and sent once, on shutdown, without waiting for
 * the answer: saving a post never waits on Serpulix.
 */
class Serpulix_SEO_IndexNow_Events {

    /** @var array<string, string> url => 'upsert' | 'delete' */
    private $events = array();

    private static $post_types = array('post', 'page');

    public function init() {
        add_action('transition_post_status', array($this, 'on_transition'), 10, 3);
        add_action('wp_trash_post', array($this, 'on_trash'), 10, 1);
        add_action('before_delete_post', array($this, 'on_delete'), 10, 1);
        add_action('shutdown', array($this, 'flush'));
    }

    private function is_tracked($post) {
        if (!$post instanceof WP_Post) {
            return false;
        }
        if (!in_array($post->post_type, self::$post_types, true)) {
            return false;
        }
        if (wp_is_post_revision($post) || wp_is_post_autosave($post)) {
            return false;
        }
        if (!empty($post->post_password)) {
            return false;
        }
        return true;
    }

    /** Pages pushed by Serpulix itself are already reported by Content Studio. */
    private function is_serpulix_request() {
        $uri = isset($_SERVER['REQUEST_URI']) ? (string) $_SERVER['REQUEST_URI'] : '';
        $route = isset($_GET['rest_route']) ? (string) $_GET['rest_route'] : '';
        return strpos($uri, '/serpulix/v1/') !== false || strpos($route, '/serpulix/v1/') === 0;
    }

    private function add($url, $kind) {
        if (!is_string($url) || $url === '' || strpos($url, '?p=') !== false || strpos($url, '?page_id=') !== false) {
            return;
        }
        $this->events[$url] = $kind;
    }

    /** Published URL of a post, even when its status has already moved away from publish. */
    private function published_permalink($post) {
        $clone = clone $post;
        $clone->post_status = 'publish';
        return get_permalink($clone);
    }

    public function on_transition($new_status, $old_status, $post) {
        if (!$this->is_tracked($post) || $this->is_serpulix_request()) {
            return;
        }
        if ($new_status === 'publish') {
            $this->add(get_permalink($post), 'upsert');
            return;
        }
        // Trash renames the slug first; on_trash has already captured the real URL.
        if ($old_status === 'publish' && $new_status !== 'trash') {
            $this->add($this->published_permalink($post), 'delete');
        }
    }

    public function on_trash($post_id) {
        $post = get_post($post_id);
        if (!$this->is_tracked($post) || $post->post_status !== 'publish' || $this->is_serpulix_request()) {
            return;
        }
        $this->add(get_permalink($post), 'delete');
    }

    public function on_delete($post_id) {
        $post = get_post($post_id);
        if (!$this->is_tracked($post) || $post->post_status !== 'publish' || $this->is_serpulix_request()) {
            return;
        }
        $this->add(get_permalink($post), 'delete');
    }

    public function flush() {
        if (empty($this->events)) {
            return;
        }
        $api_url = get_option('seobot_api_url', '');
        $api_key = get_option('seobot_api_key', '');
        if (empty($api_url) || empty($api_key)) {
            return;
        }

        $events = array();
        foreach ($this->events as $url => $kind) {
            $events[] = array('url' => $url, 'kind' => $kind);
        }
        $this->events = array();

        wp_remote_post(rtrim($api_url, '/') . '/api/indexnow/events', array(
            'blocking' => false,
            'timeout' => 3,
            'headers' => array(
                'Authorization' => 'Bearer ' . $api_key,
                'Content-Type' => 'application/json',
                'X-WordPress-Site' => get_site_url(),
            ),
            'body' => wp_json_encode(array(
                'events' => array_slice($events, 0, 200),
                'source' => 'wordpress',
            )),
        ));
    }
}
