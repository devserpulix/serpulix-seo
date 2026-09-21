<?php
/**
 * GitHub Releases updater for Serpulix SEO.
 *
 * Feeds WordPress's native plugin update transient. SEO sync, schema, and the
 * REST API are untouched. The GitHub token is read from options and sent only
 * in server-side requests to api.github.com.
 */

if (!defined('ABSPATH')) {
    exit;
}

class Serpulix_SEO_GitHub_Updater {

    const CACHE_KEY = 'serpulix_seo_github_release';
    const CACHE_TTL = 43200;
    const ERROR_TTL = 900;
    const RATE_LIMIT_TTL = 3600;
    const API_TIMEOUT = 15;
    const DOWNLOAD_TIMEOUT = 300;
    const MAX_ZIP_BYTES = 52428800;
    const MAX_BODY_BYTES = 50000;

    private $plugin_file;
    private $plugin_slug;
    private $release_memo = null;

    public function __construct() {
        $this->plugin_file = plugin_basename(SERPULIX_SEO_PLUGIN_FILE);
        $this->plugin_slug = dirname($this->plugin_file);
        if ($this->plugin_slug === '.' || $this->plugin_slug === '') {
            $this->plugin_slug = 'serpulix-seo';
        }
    }

    public function init() {
        $this->ensure_token_option();

        add_filter('pre_set_site_transient_update_plugins', array($this, 'inject_update'));
        add_filter('plugins_api', array($this, 'plugins_api'), 10, 3);
        add_filter('upgrader_pre_download', array($this, 'pre_download'), 10, 4);
        add_filter('upgrader_source_selection', array($this, 'fix_source_directory'), 10, 4);
        add_action('admin_init', array($this, 'register_settings'));
        add_action('serpulix_seo_settings_github', array($this, 'render_settings_section'));

        $options = array(
            'serpulix_seo_github_updates_enabled',
            'serpulix_seo_github_owner',
            'serpulix_seo_github_repo',
            'serpulix_seo_github_visibility',
            'serpulix_seo_github_token',
            'serpulix_seo_github_prerelease',
        );
        foreach ($options as $option) {
            add_action('update_option_' . $option, array($this, 'flush_update_cache'));
            add_action('add_option_' . $option, array($this, 'flush_update_cache'));
        }
    }

    /**
     * Register GitHub settings on the existing Serpulix SEO options group.
     */
    public function register_settings() {
        $args = array('capability' => 'manage_options');

        register_setting('seobot_sync_settings', 'serpulix_seo_github_updates_enabled', array_merge($args, array(
            'type' => 'string',
            'sanitize_callback' => array($this, 'sanitize_checkbox'),
            'default' => '0',
        )));
        register_setting('seobot_sync_settings', 'serpulix_seo_github_owner', array_merge($args, array(
            'type' => 'string',
            'sanitize_callback' => array($this, 'sanitize_owner'),
            'default' => '',
        )));
        register_setting('seobot_sync_settings', 'serpulix_seo_github_repo', array_merge($args, array(
            'type' => 'string',
            'sanitize_callback' => array($this, 'sanitize_repo'),
            'default' => '',
        )));
        register_setting('seobot_sync_settings', 'serpulix_seo_github_visibility', array_merge($args, array(
            'type' => 'string',
            'sanitize_callback' => array($this, 'sanitize_visibility'),
            'default' => 'public',
        )));
        register_setting('seobot_sync_settings', 'serpulix_seo_github_token', array_merge($args, array(
            'type' => 'string',
            'sanitize_callback' => array($this, 'sanitize_token'),
            'default' => '',
        )));
        register_setting('seobot_sync_settings', 'serpulix_seo_github_prerelease', array_merge($args, array(
            'type' => 'string',
            'sanitize_callback' => array($this, 'sanitize_checkbox'),
            'default' => '0',
        )));
    }

    public function sanitize_checkbox($value) {
        return ($value === '1' || $value === 1) ? '1' : '0';
    }

    public function sanitize_owner($value) {
        $value = is_string($value) ? trim(sanitize_text_field($value)) : '';
        if ($value === '') {
            return '';
        }
        if (!preg_match('/^[A-Za-z0-9](?:[A-Za-z0-9-]{0,37}[A-Za-z0-9])?$/', $value)) {
            add_settings_error(
                'serpulix_seo_github_owner',
                'invalid_owner',
                __('The GitHub owner must be a valid GitHub username. The saved owner was left unchanged.', 'serpulix-seo')
            );
            return $this->owner();
        }
        return $value;
    }

    public function sanitize_repo($value) {
        $value = is_string($value) ? trim(sanitize_text_field($value)) : '';
        if ($value === '') {
            return '';
        }
        if (!preg_match('/^[A-Za-z0-9._-]{1,100}$/', $value) || strpos($value, '..') !== false) {
            add_settings_error(
                'serpulix_seo_github_repo',
                'invalid_repo',
                __('The GitHub repository name is not valid. The saved repository was left unchanged.', 'serpulix-seo')
            );
            return $this->repo();
        }
        return $value;
    }

    public function sanitize_visibility($value) {
        return ($value === 'private') ? 'private' : 'public';
    }

    /**
     * Keep a saved token when the password field is submitted empty.
     * A non-empty value replaces it. The remove checkbox clears it.
     */
    public function sanitize_token($value) {
        $value = is_string($value) ? trim($value) : '';
        if ($value === '') {
            if (!empty($_POST['serpulix_seo_github_token_clear'])) {
                return '';
            }
            return $this->token();
        }

        $value = sanitize_text_field($value);
        if (!preg_match('/^[A-Za-z0-9_\-]{20,255}$/', $value)) {
            add_settings_error(
                'serpulix_seo_github_token',
                'invalid_token',
                __('The GitHub token format looks invalid. The saved token was left unchanged.', 'serpulix-seo')
            );
            return $this->token();
        }
        return $value;
    }

    public function flush_update_cache() {
        $this->release_memo = null;
        delete_transient(self::CACHE_KEY);
        delete_site_transient('update_plugins');
    }

    /**
     * GitHub fields inside the existing Serpulix SEO settings form.
     */
    public function render_settings_section() {
        if (!current_user_can('manage_options')) {
            return;
        }

        settings_errors();

        $enabled = get_option('serpulix_seo_github_updates_enabled', '0') === '1';
        $owner = $this->owner();
        $repo = $this->repo();
        $visibility = $this->visibility();
        $prerelease = get_option('serpulix_seo_github_prerelease', '0') === '1';
        $has_token = $this->token() !== '';

        if ($enabled && $visibility === 'private' && !$has_token) {
            echo '<div class="notice notice-warning"><p>';
            echo esc_html__('GitHub updates are enabled for a private repository, but no token is saved. WordPress will not download an update until you add a token.', 'serpulix-seo');
            echo '</p></div>';
        }

        $cached = get_transient(self::CACHE_KEY);
        if ($enabled && is_array($cached) && empty($cached['ok']) && !empty($cached['error'])) {
            echo '<div class="notice notice-warning"><p>';
            echo esc_html($this->error_message($cached['error']));
            echo '</p></div>';
        }

        $repo_url = '';
        if ($owner !== '' && $repo !== '') {
            $repo_url = 'https://github.com/' . $owner . '/' . $repo;
        }
        ?>
        <h2><?php esc_html_e('GitHub Update Settings', 'serpulix-seo'); ?></h2>
        <p class="description">
            <?php esc_html_e('WordPress checks the latest GitHub Release and shows the normal plugin update when that release is newer than the installed version. Drafts are ignored. Prereleases are ignored unless you enable them below.', 'serpulix-seo'); ?>
        </p>
        <table class="form-table" role="presentation">
            <tr>
                <th scope="row"><?php esc_html_e('Enable GitHub Updates', 'serpulix-seo'); ?></th>
                <td>
                    <label>
                        <input type="checkbox" name="serpulix_seo_github_updates_enabled" value="1" <?php checked($enabled); ?> />
                        <?php esc_html_e('Yes, check GitHub for new releases', 'serpulix-seo'); ?>
                    </label>
                </td>
            </tr>
            <tr>
                <th scope="row"><label for="serpulix_seo_github_owner"><?php esc_html_e('GitHub Owner', 'serpulix-seo'); ?></label></th>
                <td>
                    <input type="text" id="serpulix_seo_github_owner" name="serpulix_seo_github_owner" value="<?php echo esc_attr($owner); ?>" class="regular-text" placeholder="YOUR_GITHUB_USERNAME" autocomplete="off" />
                    <p class="description"><?php esc_html_e('The GitHub user or organization that owns the repository.', 'serpulix-seo'); ?></p>
                </td>
            </tr>
            <tr>
                <th scope="row"><label for="serpulix_seo_github_repo"><?php esc_html_e('GitHub Repository', 'serpulix-seo'); ?></label></th>
                <td>
                    <input type="text" id="serpulix_seo_github_repo" name="serpulix_seo_github_repo" value="<?php echo esc_attr($repo); ?>" class="regular-text" placeholder="serpulix-seo" autocomplete="off" />
                    <?php if ($repo_url !== '') : ?>
                        <p class="description">
                            <?php esc_html_e('Repository URL:', 'serpulix-seo'); ?>
                            <a href="<?php echo esc_url($repo_url); ?>"><?php echo esc_html($repo_url); ?></a>
                        </p>
                    <?php endif; ?>
                </td>
            </tr>
            <tr>
                <th scope="row"><?php esc_html_e('Repository Visibility', 'serpulix-seo'); ?></th>
                <td>
                    <label>
                        <input type="radio" name="serpulix_seo_github_visibility" value="public" <?php checked($visibility, 'public'); ?> />
                        <?php esc_html_e('Public', 'serpulix-seo'); ?>
                    </label>
                    <br />
                    <label>
                        <input type="radio" name="serpulix_seo_github_visibility" value="private" <?php checked($visibility, 'private'); ?> />
                        <?php esc_html_e('Private', 'serpulix-seo'); ?>
                    </label>
                    <p class="description"><?php esc_html_e('Public repositories can be checked without a token. Private repositories need a personal access token with access to read releases.', 'serpulix-seo'); ?></p>
                </td>
            </tr>
            <tr>
                <th scope="row"><label for="serpulix_seo_github_token"><?php esc_html_e('GitHub Token', 'serpulix-seo'); ?></label></th>
                <td>
                    <input type="password" id="serpulix_seo_github_token" name="serpulix_seo_github_token" value="" class="regular-text" autocomplete="new-password" placeholder="<?php echo esc_attr($has_token ? __('A token is saved', 'serpulix-seo') : __('No token saved', 'serpulix-seo')); ?>" />
                    <p class="description">
                        <?php esc_html_e('Leave blank to keep the saved token. The token is stored in WordPress options and is never shown again.', 'serpulix-seo'); ?>
                    </p>
                    <?php if ($has_token) : ?>
                        <label>
                            <input type="checkbox" name="serpulix_seo_github_token_clear" value="1" />
                            <?php esc_html_e('Remove saved token', 'serpulix-seo'); ?>
                        </label>
                    <?php endif; ?>
                </td>
            </tr>
            <tr>
                <th scope="row"><?php esc_html_e('Prereleases', 'serpulix-seo'); ?></th>
                <td>
                    <label>
                        <input type="checkbox" name="serpulix_seo_github_prerelease" value="1" <?php checked($prerelease); ?> />
                        <?php esc_html_e('Include prereleases', 'serpulix-seo'); ?>
                    </label>
                </td>
            </tr>
        </table>
        <?php
    }

    /**
     * Add this plugin to the native update transient when GitHub is newer.
     *
     * @param object $transient
     * @return object
     */
    public function inject_update($transient) {
        if (!is_object($transient)) {
            return $transient;
        }
        if (empty($transient->checked) || !isset($transient->checked[$this->plugin_file])) {
            return $transient;
        }
        if (!$this->updates_enabled()) {
            return $transient;
        }

        $release = $this->get_release();
        if (!is_array($release) || empty($release['ok']) || empty($release['version']) || empty($release['package'])) {
            return $transient;
        }

        $remote = $release['version'];
        if (!is_string($remote) || !$this->is_valid_version($remote)) {
            return $transient;
        }

        $item = $this->build_update_object($release, $remote);

        if (!version_compare($remote, SERPULIX_SEO_VERSION, '>')) {
            if (!isset($transient->no_update) || !is_array($transient->no_update)) {
                $transient->no_update = array();
            }
            $item->new_version = SERPULIX_SEO_VERSION;
            $item->package = '';
            $transient->no_update[$this->plugin_file] = $item;
            if (isset($transient->response[$this->plugin_file])) {
                unset($transient->response[$this->plugin_file]);
            }
            return $transient;
        }

        if (!isset($transient->response) || !is_array($transient->response)) {
            $transient->response = array();
        }
        $transient->response[$this->plugin_file] = $item;
        if (isset($transient->no_update[$this->plugin_file])) {
            unset($transient->no_update[$this->plugin_file]);
        }
        return $transient;
    }

    /**
     * Details modal for "View version x.x.x details".
     *
     * @param mixed  $result
     * @param string $action
     * @param mixed  $args
     * @return mixed
     */
    public function plugins_api($result, $action, $args) {
        if ($action !== 'plugin_information') {
            return $result;
        }

        $slug = '';
        if (is_object($args) && isset($args->slug)) {
            $slug = (string) $args->slug;
        } elseif (is_array($args) && isset($args['slug'])) {
            $slug = (string) $args['slug'];
        }
        if ($slug !== $this->plugin_slug) {
            return $result;
        }
        if (!$this->updates_enabled()) {
            return $result;
        }

        $release = $this->get_release();
        if (!is_array($release) || empty($release['ok'])) {
            $code = (is_array($release) && !empty($release['error'])) ? $release['error'] : 'unreachable';
            return new WP_Error('serpulix_seo_github_unavailable', $this->error_message($code));
        }

        $header = $this->header_data();
        $package = isset($release['package']) ? $release['package'] : '';
        $homepage = !empty($release['html_url']) ? $release['html_url'] : $header['PluginURI'];

        $info = new stdClass();
        $info->name = $header['Name'];
        $info->slug = $this->plugin_slug;
        $info->version = $release['version'];
        $info->author = '<a href="' . esc_url($header['AuthorURI']) . '">' . esc_html($header['Author']) . '</a>';
        $info->homepage = $homepage;
        $info->requires = $header['RequiresWP'];
        $info->tested = '7.0';
        $info->requires_php = $header['RequiresPHP'];
        $info->download_link = $package;
        $info->trunk = $package;
        $info->last_updated = isset($release['published_at']) ? $release['published_at'] : '';
        $info->sections = array(
            'description' => '<p>' . esc_html($header['Description']) . '</p>',
            'changelog' => $this->format_release_notes($release),
        );
        $info->icons = array();
        $info->banners = array();
        return $info;
    }

    /**
     * Download a private release on the server. Public packages stay with WordPress.
     *
     * @param mixed  $reply
     * @param string $package
     * @param mixed  $upgrader
     * @param array  $hook_extra
     * @return mixed
     */
    public function pre_download($reply, $package, $upgrader, $hook_extra = array()) {
        if (false !== $reply && null !== $reply) {
            return $reply;
        }
        if (!is_string($package) || $package === '') {
            return $reply;
        }
        if (is_array($hook_extra) && !empty($hook_extra['plugin']) && $hook_extra['plugin'] !== $this->plugin_file) {
            return $reply;
        }
        if ($this->visibility() !== 'private') {
            return $reply;
        }
        if (!$this->is_our_package($package)) {
            return $reply;
        }
        return $this->download_private_package($package);
    }

    /**
     * Keep the installed directory name so WordPress reloads serpulix-seo/serpulix-seo.php.
     *
     * @param string $source
     * @param string $remote_source
     * @param mixed  $upgrader
     * @param array  $hook_extra
     * @return string|WP_Error
     */
    public function fix_source_directory($source, $remote_source, $upgrader, $hook_extra = array()) {
        if (is_wp_error($source)) {
            return $source;
        }
        if (!is_array($hook_extra) || empty($hook_extra['plugin']) || $hook_extra['plugin'] !== $this->plugin_file) {
            return $source;
        }
        if (!is_string($source) || $source === '' || !is_string($remote_source) || $remote_source === '') {
            return $source;
        }

        global $wp_filesystem;
        if (!is_object($wp_filesystem)) {
            return new WP_Error(
                'serpulix_seo_github_package',
                __('Could not prepare the update directory.', 'serpulix-seo')
            );
        }

        $main_file = basename(SERPULIX_SEO_PLUGIN_FILE);
        $source = trailingslashit($source);

        if (!$wp_filesystem->exists($source . $main_file)) {
            $nested = $this->find_nested_main($wp_filesystem, $source, $main_file);
            if ($nested === '') {
                return new WP_Error(
                    'serpulix_seo_github_package',
                    __('The update archive does not contain the Serpulix SEO plugin file.', 'serpulix-seo')
                );
            }
            $source = trailingslashit($nested);
        }

        // WordPress installs into wp-content/plugins/{basename of this directory}.
        if (basename(untrailingslashit($source)) === $this->plugin_slug) {
            return $source;
        }

        $desired = trailingslashit($remote_source) . $this->plugin_slug . '/';
        if (untrailingslashit($source) === untrailingslashit($remote_source)) {
            return $this->move_flat_archive_into_directory($wp_filesystem, $source, $desired);
        }

        if ($wp_filesystem->move($source, $desired, true)) {
            return trailingslashit($desired);
        }

        return new WP_Error(
            'serpulix_seo_github_package',
            __('Could not rename the update directory to the installed plugin folder.', 'serpulix-seo')
        );
    }

    /**
     * Store the token so WordPress does not autoload it.
     * Boolean false is correct on WordPress 6.6+. Older releases only honor the string "no".
     */
    private function ensure_token_option() {
        if (get_option('serpulix_seo_github_token', null) !== null) {
            return;
        }
        global $wp_version;
        $autoload = (isset($wp_version) && version_compare($wp_version, '6.6', '>=')) ? false : 'no';
        add_option('serpulix_seo_github_token', '', '', $autoload);
    }

    private function updates_enabled() {
        if (get_option('serpulix_seo_github_updates_enabled', '0') !== '1') {
            return false;
        }
        return $this->owner() !== '' && $this->repo() !== '';
    }

    private function owner() {
        $value = get_option('serpulix_seo_github_owner', '');
        if (!is_string($value) || !preg_match('/^[A-Za-z0-9](?:[A-Za-z0-9-]{0,37}[A-Za-z0-9])?$/', $value)) {
            return '';
        }
        return $value;
    }

    private function repo() {
        $value = get_option('serpulix_seo_github_repo', '');
        if (!is_string($value) || !preg_match('/^[A-Za-z0-9._-]{1,100}$/', $value) || strpos($value, '..') !== false) {
            return '';
        }
        return $value;
    }

    private function visibility() {
        return get_option('serpulix_seo_github_visibility', 'public') === 'private' ? 'private' : 'public';
    }

    private function token() {
        $value = get_option('serpulix_seo_github_token', '');
        if (!is_string($value)) {
            return '';
        }
        $value = trim($value);
        if ($value === '' || !preg_match('/^[A-Za-z0-9_\-]{20,255}$/', $value)) {
            return '';
        }
        return $value;
    }

    private function prereleases_enabled() {
        return get_option('serpulix_seo_github_prerelease', '0') === '1';
    }

    private function get_release() {
        if (is_array($this->release_memo)) {
            return $this->release_memo;
        }

        $cached = get_transient(self::CACHE_KEY);
        if (is_array($cached) && array_key_exists('ok', $cached)) {
            $this->release_memo = $cached;
            return $cached;
        }

        $this->release_memo = $this->fetch_release();
        return $this->release_memo;
    }

    private function fetch_release() {
        $owner = $this->owner();
        $repo = $this->repo();
        if ($owner === '' || $repo === '') {
            return $this->cache_failure('not_configured', self::ERROR_TTL);
        }
        if ($this->visibility() === 'private' && $this->token() === '') {
            return $this->cache_failure('token_required', self::ERROR_TTL);
        }

        if ($this->prereleases_enabled()) {
            $path = '/repos/' . rawurlencode($owner) . '/' . rawurlencode($repo) . '/releases?per_page=20';
        } else {
            $path = '/repos/' . rawurlencode($owner) . '/' . rawurlencode($repo) . '/releases/latest';
        }
        $url = 'https://api.github.com' . $path;
        if (!$this->is_allowed_api_url($url, $owner, $repo)) {
            return $this->cache_failure('bad_url', self::ERROR_TTL);
        }

        $response = wp_safe_remote_get($url, array(
            'headers' => $this->api_headers($this->token()),
            'timeout' => self::API_TIMEOUT,
        ));

        if (is_wp_error($response)) {
            return $this->cache_failure('unreachable', self::ERROR_TTL);
        }

        $code = (int) wp_remote_retrieve_response_code($response);
        if ($code === 403 || $code === 429) {
            $ttl = $this->retry_after_seconds($response);
            if ($ttl < self::RATE_LIMIT_TTL) {
                $ttl = self::RATE_LIMIT_TTL;
            }
            return $this->cache_failure('rate_limit', $ttl);
        }
        if ($code !== 200) {
            return $this->cache_failure('http_error', self::ERROR_TTL);
        }

        $data = json_decode(wp_remote_retrieve_body($response), true);
        if (!is_array($data)) {
            return $this->cache_failure('bad_response', self::ERROR_TTL);
        }

        $release = $this->prereleases_enabled() ? $this->pick_release($data) : $data;
        if (!is_array($release) || empty($release['tag_name']) || !is_string($release['tag_name'])) {
            return $this->cache_failure('no_release', self::ERROR_TTL);
        }
        if (!empty($release['draft'])) {
            return $this->cache_failure('draft', self::ERROR_TTL);
        }
        if (!$this->prereleases_enabled() && !empty($release['prerelease'])) {
            return $this->cache_failure('prerelease', self::ERROR_TTL);
        }

        $tag = $release['tag_name'];
        $version = $this->normalize_version($tag);
        if ($version === '') {
            return $this->cache_failure('bad_version', self::ERROR_TTL);
        }

        $package = $this->select_package($release, $owner, $repo, $tag);
        if ($package === '') {
            return $this->cache_failure('no_package', self::ERROR_TTL);
        }

        $payload = array(
            'ok' => true,
            'tag' => $this->limit_text($tag, 100),
            'version' => $version,
            'name' => $this->limit_text(isset($release['name']) ? $release['name'] : $tag, 200),
            'body' => $this->limit_text(isset($release['body']) ? $release['body'] : '', self::MAX_BODY_BYTES),
            'published_at' => $this->format_date(isset($release['published_at']) ? $release['published_at'] : ''),
            'html_url' => $this->validate_repo_url(isset($release['html_url']) ? $release['html_url'] : '', $owner, $repo),
            'package' => $package,
            'prerelease' => !empty($release['prerelease']),
        );

        set_transient(self::CACHE_KEY, $payload, self::CACHE_TTL);
        return $payload;
    }

    private function pick_release($releases) {
        if (!is_array($releases) || $this->is_assoc($releases)) {
            return null;
        }
        foreach ($releases as $release) {
            if (!is_array($release) || !empty($release['draft'])) {
                continue;
            }
            return $release;
        }
        return null;
    }

    private function select_package($release, $owner, $repo, $tag) {
        $assets = isset($release['assets']) && is_array($release['assets']) ? $release['assets'] : array();
        $preferred = array('serpulix-seo.zip');
        if ($this->plugin_slug !== 'serpulix-seo') {
            $preferred[] = $this->plugin_slug . '.zip';
        }

        $chosen = null;
        $fallback = null;
        foreach ($assets as $asset) {
            if (!is_array($asset) || empty($asset['name']) || !is_string($asset['name'])) {
                continue;
            }
            if (!preg_match('/\.zip$/i', $asset['name'])) {
                continue;
            }
            if (in_array(strtolower($asset['name']), array_map('strtolower', $preferred), true)) {
                $chosen = $asset;
                break;
            }
            if ($fallback === null) {
                $fallback = $asset;
            }
        }
        if ($chosen === null) {
            $chosen = $fallback;
        }

        $private = $this->visibility() === 'private';
        if (is_array($chosen)) {
            if ($private) {
                $id = isset($chosen['id']) ? (int) $chosen['id'] : 0;
                if ($id > 0) {
                    $url = 'https://api.github.com/repos/' . rawurlencode($owner) . '/' . rawurlencode($repo) . '/releases/assets/' . $id;
                    if ($this->is_allowed_api_url($url, $owner, $repo)) {
                        return $url;
                    }
                }
            } else {
                $browser = isset($chosen['browser_download_url']) ? $chosen['browser_download_url'] : '';
                $valid = $this->validate_asset_url($browser, $owner, $repo);
                if ($valid !== '') {
                    return $valid;
                }
            }
        }

        if ($private) {
            $url = 'https://api.github.com/repos/' . rawurlencode($owner) . '/' . rawurlencode($repo) . '/zipball/' . rawurlencode($tag);
            return $this->is_allowed_api_url($url, $owner, $repo) ? $url : '';
        }

        $zipball = isset($release['zipball_url']) ? $release['zipball_url'] : '';
        return $this->validate_zipball_url($zipball, $owner, $repo);
    }

    private function download_private_package($package) {
        if (!$this->is_our_package($package)) {
            return new WP_Error(
                'serpulix_seo_github_url',
                __('The update package URL is not a valid GitHub URL for this plugin.', 'serpulix-seo')
            );
        }

        $token = $this->token();
        if ($token === '') {
            return new WP_Error(
                'serpulix_seo_github_token',
                $this->error_message('token_required')
            );
        }

        if (!function_exists('wp_tempnam')) {
            require_once ABSPATH . 'wp-admin/includes/file.php';
        }

        $url = $package;
        $send_token = true;
        for ($hop = 0; $hop < 5; $hop++) {
            if (!$this->is_allowed_download_host($url)) {
                return new WP_Error(
                    'serpulix_seo_github_redirect',
                    __('GitHub returned an unexpected download location.', 'serpulix-seo')
                );
            }

            $headers = array(
                'User-Agent' => $this->user_agent(),
            );
            if ($send_token && $this->url_host($url) === 'api.github.com') {
                $headers['Authorization'] = 'Bearer ' . $token;
                $headers['Accept'] = 'application/octet-stream';
            }

            $tmp = wp_tempnam('serpulix-seo-update.zip');
            if (!$tmp) {
                return new WP_Error(
                    'serpulix_seo_github_temp',
                    __('Could not create a temporary file for the update.', 'serpulix-seo')
                );
            }

            $response = wp_safe_remote_get($url, array(
                'headers' => $headers,
                'timeout' => self::DOWNLOAD_TIMEOUT,
                'redirection' => 0,
                'stream' => true,
                'filename' => $tmp,
            ));

            if (is_wp_error($response)) {
                $this->delete_file($tmp);
                return new WP_Error(
                    'serpulix_seo_github_download',
                    $this->error_message('unreachable')
                );
            }

            $code = (int) wp_remote_retrieve_response_code($response);
            if ($code === 301 || $code === 302 || $code === 307 || $code === 308) {
                $this->delete_file($tmp);
                $location = wp_remote_retrieve_header($response, 'location');
                if (is_array($location)) {
                    $location = end($location);
                }
                $next = $this->resolve_redirect($url, is_string($location) ? $location : '');
                if ($next === '' || !$this->is_allowed_download_host($next)) {
                    return new WP_Error(
                        'serpulix_seo_github_redirect',
                        __('GitHub returned an unexpected download location.', 'serpulix-seo')
                    );
                }
                $url = $next;
                $send_token = ($this->url_host($url) === 'api.github.com');
                continue;
            }

            if ($code !== 200) {
                $this->delete_file($tmp);
                return new WP_Error(
                    'serpulix_seo_github_download',
                    sprintf(
                        /* translators: %d: HTTP status code */
                        __('GitHub returned HTTP %d while downloading the update. The installed plugin was not changed.', 'serpulix-seo'),
                        $code
                    )
                );
            }

            $valid = $this->validate_zip_file($tmp);
            if (is_wp_error($valid)) {
                $this->delete_file($tmp);
                return $valid;
            }
            return $tmp;
        }

        return new WP_Error(
            'serpulix_seo_github_redirect',
            __('Too many redirects while downloading the update. The installed plugin was not changed.', 'serpulix-seo')
        );
    }

    private function validate_zip_file($path) {
        $size = @filesize($path);
        if ($size === false || $size < 4 || $size > self::MAX_ZIP_BYTES) {
            return new WP_Error(
                'serpulix_seo_github_zip',
                __('The downloaded update is not a valid ZIP archive. The installed plugin was not changed.', 'serpulix-seo')
            );
        }
        $handle = @fopen($path, 'rb');
        if (!$handle) {
            return new WP_Error(
                'serpulix_seo_github_zip',
                __('The downloaded update could not be read. The installed plugin was not changed.', 'serpulix-seo')
            );
        }
        $magic = fread($handle, 2);
        fclose($handle);
        if ($magic !== 'PK') {
            return new WP_Error(
                'serpulix_seo_github_zip',
                __('The downloaded update is not a valid ZIP archive. The installed plugin was not changed.', 'serpulix-seo')
            );
        }
        return true;
    }

    private function build_update_object($release, $version) {
        $header = $this->header_data();
        $homepage = !empty($release['html_url']) ? $release['html_url'] : $header['PluginURI'];
        return (object) array(
            'id' => $this->plugin_file,
            'slug' => $this->plugin_slug,
            'plugin' => $this->plugin_file,
            'new_version' => $version,
            'url' => $homepage,
            'package' => $release['package'],
            'icons' => array(),
            'banners' => array(),
            'tested' => '7.0',
            'requires' => $header['RequiresWP'],
            'requires_php' => $header['RequiresPHP'],
        );
    }

    private function header_data() {
        $defaults = array(
            'Name' => 'Serpulix SEO',
            'PluginURI' => 'https://serpulix.com',
            'Description' => 'Sync and publish SEO content from Serpulix and deploy schema.org structured data to your site.',
            'Author' => 'Serpulix',
            'AuthorURI' => 'https://serpulix.com',
            'RequiresWP' => '5.8',
            'RequiresPHP' => '7.4',
        );
        if (!function_exists('get_plugin_data')) {
            $plugin_file = ABSPATH . 'wp-admin/includes/plugin.php';
            if (file_exists($plugin_file)) {
                require_once $plugin_file;
            }
        }
        if (!function_exists('get_plugin_data')) {
            return $defaults;
        }
        $data = get_plugin_data(SERPULIX_SEO_PLUGIN_FILE, false, false);
        if (!is_array($data)) {
            return $defaults;
        }
        foreach ($defaults as $key => $value) {
            if (empty($data[$key]) || !is_string($data[$key])) {
                $data[$key] = $value;
            }
        }
        return $data;
    }

    private function format_release_notes($release) {
        $name = isset($release['name']) && is_string($release['name']) && $release['name'] !== ''
            ? $release['name']
            : (isset($release['tag']) ? $release['tag'] : '');
        $tag = isset($release['tag']) ? $release['tag'] : '';
        $date = isset($release['published_at']) && is_string($release['published_at']) && $release['published_at'] !== ''
            ? $release['published_at']
            : __('unknown date', 'serpulix-seo');
        $body = isset($release['body']) ? $release['body'] : '';

        $html = '<p><strong>' . esc_html($name) . '</strong></p>';
        $html .= '<p>' . esc_html(sprintf(
            /* translators: 1: release tag, 2: release date */
            __('Version %1$s. Released %2$s.', 'serpulix-seo'),
            $tag,
            $date
        )) . '</p>';
        $html .= $this->markdown_to_html($body);

        return wp_kses($html, $this->release_notes_allowed_html());
    }

    private function markdown_to_html($text) {
        $text = is_string($text) ? $text : '';
        if (strlen($text) > self::MAX_BODY_BYTES) {
            $text = substr($text, 0, self::MAX_BODY_BYTES);
        }
        $text = str_replace(array("\r\n", "\r"), "\n", $text);
        $escaped = esc_html($text);
        $blocks = array();

        $escaped = preg_replace_callback('/```[^\n]*\n(.*?)```/s', function ($matches) use (&$blocks) {
            $code = isset($matches[1]) ? $matches[1] : '';
            $key = '%%SERPULIXCODE' . count($blocks) . '%%';
            $blocks[$key] = '<pre><code>' . $code . '</code></pre>';
            return $key;
        }, $escaped);
        if (!is_string($escaped)) {
            $escaped = esc_html($text);
        }
        $replaced = preg_replace('/`([^`\n]+)`/', '<code>$1</code>', $escaped);
        if (is_string($replaced)) {
            $escaped = $replaced;
        }
        $replaced = preg_replace('/^### (.+)$/m', '<h4>$1</h4>', $escaped);
        if (is_string($replaced)) {
            $escaped = $replaced;
        }
        $replaced = preg_replace('/^## (.+)$/m', '<h3>$1</h3>', $escaped);
        if (is_string($replaced)) {
            $escaped = $replaced;
        }
        $replaced = preg_replace('/^# (.+)$/m', '<h2>$1</h2>', $escaped);
        if (is_string($replaced)) {
            $escaped = $replaced;
        }
        $replaced = preg_replace('/\*\*(.+?)\*\*/s', '<strong>$1</strong>', $escaped);
        if (is_string($replaced)) {
            $escaped = $replaced;
        }
        $replaced = preg_replace_callback('/\[([^\]]+)\]\((https:\/\/[^)\s]+)\)/', function ($matches) {
            $url = esc_url($matches[2]);
            if ($url === '') {
                return $matches[1];
            }
            return '<a href="' . $url . '" rel="noopener noreferrer" target="_blank">' . $matches[1] . '</a>';
        }, $escaped);
        if (is_string($replaced)) {
            $escaped = $replaced;
        }

        $lines = explode("\n", $escaped);
        $out = '';
        $list = '';
        foreach ($lines as $line) {
            if (preg_match('/^\s*[-*]\s+(.+)$/', $line, $matches)) {
                if ($list !== 'ul') {
                    if ($list === 'ol') {
                        $out .= "</ol>\n";
                    }
                    $out .= "<ul>\n";
                    $list = 'ul';
                }
                $out .= '<li>' . $matches[1] . "</li>\n";
                continue;
            }
            if (preg_match('/^\s*\d+\.\s+(.+)$/', $line, $matches)) {
                if ($list !== 'ol') {
                    if ($list === 'ul') {
                        $out .= "</ul>\n";
                    }
                    $out .= "<ol>\n";
                    $list = 'ol';
                }
                $out .= '<li>' . $matches[1] . "</li>\n";
                continue;
            }
            if ($list === 'ul') {
                $out .= "</ul>\n";
                $list = '';
            } elseif ($list === 'ol') {
                $out .= "</ol>\n";
                $list = '';
            }
            if ($line === '' || preg_match('/^<(h[234]|pre|ul|ol)/', $line)) {
                $out .= $line . "\n";
                continue;
            }
            if (isset($blocks[$line])) {
                $out .= $blocks[$line] . "\n";
                continue;
            }
            $out .= '<p>' . $line . "</p>\n";
        }
        if ($list === 'ul') {
            $out .= "</ul>\n";
        } elseif ($list === 'ol') {
            $out .= "</ol>\n";
        }

        if (!empty($blocks)) {
            $out = str_replace(array_keys($blocks), array_values($blocks), $out);
        }

        $safe = wp_kses($out, $this->release_notes_allowed_html());
        return is_string($safe) ? $safe : '';
    }

    private function release_notes_allowed_html() {
        return array(
            'p' => array(),
            'br' => array(),
            'strong' => array(),
            'em' => array(),
            'ul' => array(),
            'ol' => array(),
            'li' => array(),
            'h2' => array(),
            'h3' => array(),
            'h4' => array(),
            'code' => array(),
            'pre' => array(),
            'a' => array(
                'href' => true,
                'rel' => true,
                'target' => true,
            ),
        );
    }

    private function api_headers($token) {
        $headers = array(
            'Accept' => 'application/vnd.github+json',
            'User-Agent' => $this->user_agent(),
            'X-GitHub-Api-Version' => '2022-11-28',
        );
        if (is_string($token) && $token !== '') {
            $headers['Authorization'] = 'Bearer ' . $token;
        }
        return $headers;
    }

    private function user_agent() {
        return 'Serpulix-SEO/' . SERPULIX_SEO_VERSION;
    }

    private function cache_failure($code, $ttl) {
        $payload = array(
            'ok' => false,
            'error' => $code,
        );
        set_transient(self::CACHE_KEY, $payload, (int) $ttl);
        return $payload;
    }

    private function error_message($code) {
        switch ($code) {
            case 'not_configured':
                return __('GitHub owner and repository are not configured.', 'serpulix-seo');
            case 'token_required':
                return __('A GitHub token is required to check a private repository.', 'serpulix-seo');
            case 'rate_limit':
                return __('GitHub refused the update check. Update checks are paused and will resume later.', 'serpulix-seo');
            case 'no_release':
                return __('No published GitHub release was found.', 'serpulix-seo');
            case 'draft':
                return __('The latest GitHub release is a draft and was ignored.', 'serpulix-seo');
            case 'prerelease':
                return __('The latest GitHub release is a prerelease and was ignored.', 'serpulix-seo');
            case 'bad_version':
                return __('The GitHub release tag is not a valid version.', 'serpulix-seo');
            case 'no_package':
                return __('The GitHub release has no installable ZIP.', 'serpulix-seo');
            case 'bad_url':
                return __('The GitHub API URL is not allowed.', 'serpulix-seo');
            case 'bad_response':
                return __('GitHub returned an unreadable response.', 'serpulix-seo');
            case 'http_error':
                return __('GitHub did not return a release. The installed plugin was not changed.', 'serpulix-seo');
            case 'unreachable':
            default:
                return __('GitHub could not be reached. The installed plugin was not changed.', 'serpulix-seo');
        }
    }

    private function normalize_version($tag) {
        $tag = trim((string) $tag);
        if ($tag === '') {
            return '';
        }
        if (isset($tag[0]) && ($tag[0] === 'v' || $tag[0] === 'V')) {
            $tag = substr($tag, 1);
        }
        if (!$this->is_valid_version($tag)) {
            return '';
        }
        return $tag;
    }

    private function is_valid_version($version) {
        return is_string($version) && preg_match('/^\d+\.\d+(?:\.\d+){0,2}(?:[-+][0-9A-Za-z.-]+)?$/', $version) === 1;
    }

    private function is_allowed_api_url($url, $owner, $repo) {
        $parts = $this->parse_https($url);
        if ($parts === null || $parts['host'] !== 'api.github.com') {
            return false;
        }
        $prefix = '/repos/' . $owner . '/' . $repo . '/';
        return strpos($parts['path'], $prefix) === 0;
    }

    private function is_our_package($url) {
        $owner = $this->owner();
        $repo = $this->repo();
        if ($owner === '' || $repo === '') {
            return false;
        }
        $parts = $this->parse_https($url);
        if ($parts === null) {
            return false;
        }
        if ($parts['host'] === 'api.github.com') {
            $prefix = '/repos/' . $owner . '/' . $repo . '/';
            return strpos($parts['path'], $prefix) === 0;
        }
        if ($parts['host'] === 'github.com') {
            $prefix = '/' . $owner . '/' . $repo . '/releases/download/';
            return strpos($parts['path'], $prefix) === 0;
        }
        if ($parts['host'] === 'codeload.github.com') {
            $prefix = '/' . $owner . '/' . $repo . '/';
            return strpos($parts['path'], $prefix) === 0;
        }
        return false;
    }

    private function validate_asset_url($url, $owner, $repo) {
        $parts = $this->parse_https($url);
        if ($parts === null || $parts['host'] !== 'github.com') {
            return '';
        }
        $prefix = '/' . $owner . '/' . $repo . '/releases/download/';
        if (strpos($parts['path'], $prefix) !== 0) {
            return '';
        }
        return 'https://github.com' . $parts['path'];
    }

    private function validate_zipball_url($url, $owner, $repo) {
        $parts = $this->parse_https($url);
        if ($parts === null) {
            return '';
        }
        if ($parts['host'] === 'api.github.com') {
            $prefix = '/repos/' . $owner . '/' . $repo . '/zipball/';
            if (strpos($parts['path'], $prefix) === 0) {
                return 'https://api.github.com' . $parts['path'];
            }
        }
        if ($parts['host'] === 'codeload.github.com') {
            $prefix = '/' . $owner . '/' . $repo . '/';
            if (strpos($parts['path'], $prefix) === 0) {
                return 'https://codeload.github.com' . $parts['path'];
            }
        }
        return '';
    }

    private function validate_repo_url($url, $owner, $repo) {
        $parts = $this->parse_https($url);
        if ($parts === null || $parts['host'] !== 'github.com') {
            return '';
        }
        $prefix = '/' . $owner . '/' . $repo;
        if (strpos($parts['path'], $prefix) !== 0) {
            return '';
        }
        return 'https://github.com' . $parts['path'];
    }

    private function is_allowed_download_host($url) {
        $parts = $this->parse_https($url);
        if ($parts === null) {
            return false;
        }
        $allowed = array(
            'github.com',
            'api.github.com',
            'codeload.github.com',
            'objects.githubusercontent.com',
            'release-assets.githubusercontent.com',
            'github-releases.githubusercontent.com',
        );
        return in_array($parts['host'], $allowed, true);
    }

    private function parse_https($url) {
        if (!is_string($url) || $url === '') {
            return null;
        }
        $parts = wp_parse_url($url);
        if (!is_array($parts) || empty($parts['scheme']) || empty($parts['host']) || empty($parts['path'])) {
            return null;
        }
        if (strtolower($parts['scheme']) !== 'https') {
            return null;
        }
        if (!empty($parts['user']) || !empty($parts['pass'])) {
            return null;
        }
        return array(
            'host' => strtolower($parts['host']),
            'path' => $parts['path'],
        );
    }

    private function url_host($url) {
        $parts = $this->parse_https($url);
        if ($parts === null) {
            return '';
        }
        return $parts['host'];
    }

    private function resolve_redirect($current, $location) {
        $location = trim((string) $location);
        if ($location === '') {
            return '';
        }
        if (preg_match('#^https://#i', $location)) {
            return $location;
        }
        $parts = wp_parse_url($current);
        if (!is_array($parts) || empty($parts['scheme']) || empty($parts['host'])) {
            return '';
        }
        if (isset($location[0]) && $location[0] === '/') {
            return $parts['scheme'] . '://' . $parts['host'] . $location;
        }
        return '';
    }

    private function retry_after_seconds($response) {
        $header = wp_remote_retrieve_header($response, 'retry-after');
        if (is_array($header)) {
            $header = end($header);
        }
        if (is_numeric($header)) {
            $seconds = (int) $header;
            if ($seconds > 0 && $seconds < 86400) {
                return $seconds;
            }
        }
        return self::RATE_LIMIT_TTL;
    }

    /**
     * A release ZIP with plugin files at the archive root has to be wrapped in
     * the real plugin directory. Renaming the temp root itself would move a
     * directory into its own child.
     */
    private function move_flat_archive_into_directory($wp_filesystem, $source, $desired) {
        if (!$wp_filesystem->is_dir($desired) && !$wp_filesystem->mkdir($desired)) {
            return new WP_Error(
                'serpulix_seo_github_package',
                __('Could not rename the update directory to the installed plugin folder.', 'serpulix-seo')
            );
        }

        $list = $wp_filesystem->dirlist($source);
        if (!is_array($list)) {
            return new WP_Error(
                'serpulix_seo_github_package',
                __('Could not read the update archive.', 'serpulix-seo')
            );
        }

        $skip = $this->plugin_slug;
        foreach ($list as $name => $info) {
            $folder = is_string($name) ? $name : '';
            if ($folder === '' || $folder === '.' || $folder === '..' || $folder === $skip) {
                continue;
            }
            $from = trailingslashit($source) . $folder;
            $to = trailingslashit($desired) . $folder;
            if (!$wp_filesystem->move($from, $to, true)) {
                return new WP_Error(
                    'serpulix_seo_github_package',
                    __('Could not rename the update directory to the installed plugin folder.', 'serpulix-seo')
                );
            }
        }

        return trailingslashit($desired);
    }

    private function find_nested_main($wp_filesystem, $source, $main_file) {
        $list = $wp_filesystem->dirlist($source);
        if (!is_array($list)) {
            return '';
        }
        $found = '';
        foreach ($list as $name => $info) {
            if (!is_array($info) || empty($info['type']) || $info['type'] !== 'd') {
                continue;
            }
            $folder = is_string($name) ? $name : '';
            if ($folder === '' || $folder === '.' || $folder === '..') {
                continue;
            }
            $candidate = trailingslashit($source) . $folder . '/';
            if (!$wp_filesystem->exists($candidate . $main_file)) {
                continue;
            }
            if ($found !== '') {
                return '';
            }
            $found = $candidate;
        }
        return $found;
    }

    private function format_date($value) {
        if (!is_string($value) || $value === '') {
            return '';
        }
        $timestamp = strtotime($value);
        if ($timestamp === false) {
            return '';
        }
        return gmdate('Y-m-d', $timestamp);
    }

    private function limit_text($value, $max) {
        if (!is_string($value)) {
            return '';
        }
        if (strlen($value) > $max) {
            return substr($value, 0, $max);
        }
        return $value;
    }

    private function is_assoc($array) {
        if (!is_array($array) || $array === array()) {
            return false;
        }
        return array_keys($array) !== range(0, count($array) - 1);
    }

    private function delete_file($path) {
        if (is_string($path) && $path !== '' && file_exists($path)) {
            @unlink($path);
        }
    }
}
