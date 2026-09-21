<?php
/**
 * API client for communication with the Serpulix platform.
 *
 * The only outbound call the plugin makes on its own is the connection
 * check against /api/wordpress/verify (settings page + after-save test).
 */
class Serpulix_SEO_API {

    private $api_url;
    private $api_key;

    public function init() {
        $this->api_url = get_option('seobot_api_url', '');
        $this->api_key = get_option('seobot_api_key', '');
    }

    public function is_connected() {
        if (empty($this->api_url) || empty($this->api_key)) {
            return false;
        }

        $response = $this->make_request('/api/wordpress/verify', 'GET');
        return !is_wp_error($response) && isset($response['success']) && $response['success'] === true;
    }

    private function make_request($endpoint, $method = 'GET', $body = null) {
        $url = $this->api_url . $endpoint;

        $args = array(
            'method' => $method,
            'headers' => array(
                'Authorization' => 'Bearer ' . $this->api_key,
                'Content-Type' => 'application/json',
                'X-WordPress-Site' => get_site_url()
            ),
            'timeout' => 30
        );

        if ($body !== null) {
            $args['body'] = wp_json_encode($body);
        }

        $response = wp_remote_request($url, $args);

        if (is_wp_error($response)) {
            return $response;
        }

        $data = json_decode(wp_remote_retrieve_body($response), true);

        if (wp_remote_retrieve_response_code($response) >= 400) {
            return new WP_Error(
                'api_error',
                isset($data['message']) ? $data['message'] : 'API request failed'
            );
        }

        return $data;
    }
}
