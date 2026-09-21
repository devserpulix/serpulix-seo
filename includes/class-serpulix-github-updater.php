<?php
/**
 * GitHub Releases updater for Serpulix SEO.
 *
 * Pushes the latest published GitHub Release from
 * https://github.com/devserpulix/serpulix-seo into WordPress's update_plugins
 * transient so Dashboard → Updates and Plugins → Update Now use core's
 * upgrader. Site admins do not configure a GitHub owner or repository. A token,
 * when defined in wp-config.php, is sent only from PHP to api.github.com and is
 * never written into a download URL or the admin HTML.
 */

if (!defined('ABSPATH')) {
    exit;
}

class Serpulix_SEO_GitHub_Updater {

    const SLUG = 'serpulix-seo';
    const CACHE_KEY = 'serpulix_seo_github_release';
    const OPTION_GROUP = 'seobot_sync_settings';
    const DEFAULT_OWNER = 'devserpulix';
    const DEFAULT_REPO = 'serpulix-seo';

    private static $instance = null;

    private $initialized = false;

    private $checking = false;

    public static function instance() {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    public function init() {
        if ($this->initialized) {
            return;
        }
        $this->initialized = true;
        self::$instance = $this;

        add_filter('pre_set_site_transient_update_plugins', array($this, 'filter_update_transient'));
        add_filter('site_transient_update_plugins', array($this, 'filter_update_transient'));
        add_filter('update_plugins_serpulix.com', array($this, 'filter_update_uri'), 10, 4);
        add_filter('plugins_api', array($this, 'filter_plugins_api'), 10, 3);
        add_filter('upgrader_pre_download', array($this, 'filter_pre_download'), 10, 4);
        add_filter('upgrader_source_selection', array($this, 'filter_source_selection'), 10, 4);
        add_action('load-plugins.php', array($this, 'refresh_github_on_plugin_screen'));
        add_action('load-update-core.php', array($this, 'refresh_github_on_plugin_screen'));

        $this->maybe_prime_update_check();
    }

    /**
     * Plugins and Dashboard → Updates do not rebuild WordPress's 12-hour
     * update cache. Refresh the GitHub release here so a newer tag can appear
     * on those screens without waiting for cron.
     */
    public function refresh_github_on_plugin_screen() {
        if (!current_user_can('update_plugins') || !$this->updates_enabled() || !$this->is_configured()) {
            return;
        }

        $cached = $this->read_cache();
        if (is_array($cached) && $this->cache_matches_config($cached) && $this->release_is_usable($cached)
            && self::is_newer_version($cached['version'], SERPULIX_SEO_VERSION)) {
            return;
        }

        $age = (is_array($cached) && isset($cached['stored_at'])) ? time() - (int) $cached['stored_at'] : PHP_INT_MAX;
        if ($age >= 0 && $age < 2 * MINUTE_IN_SECONDS && is_array($cached) && $this->cache_matches_config($cached)) {
            return;
        }

        $this->get_release(true);
    }

    /**
     * After switching to a built-in GitHub source, drop the cached update
     * check once so Plugins and Dashboard → Updates pick up a release without
     * waiting for WordPress's next scheduled check.
     */
    private function maybe_prime_update_check() {
        if (get_option('serpulix_seo_github_source') === 'builtin') {
            return;
        }
        update_option('serpulix_seo_github_source', 'builtin', true);
        $this->bust_update_cache();
    }

    /**
     * @return string[]
     */
    private function watched_options() {
        return array(
            'serpulix_seo_github_updates_enabled',
            'serpulix_seo_github_owner',
            'serpulix_seo_github_repo',
            'serpulix_seo_github_visibility',
            'serpulix_seo_github_token',
            'serpulix_seo_github_include_prerelease',
        );
    }

    public function register_settings() {
        register_setting(self::OPTION_GROUP, 'serpulix_seo_github_updates_enabled', array(
            'type' => 'string',
            'sanitize_callback' => array($this, 'sanitize_updates_enabled'),
            'default' => '0',
            'show_in_rest' => false,
        ));
        register_setting(self::OPTION_GROUP, 'serpulix_seo_github_owner', array(
            'type' => 'string',
            'sanitize_callback' => array($this, 'sanitize_owner_setting'),
            'default' => '',
            'show_in_rest' => false,
        ));
        register_setting(self::OPTION_GROUP, 'serpulix_seo_github_repo', array(
            'type' => 'string',
            'sanitize_callback' => array($this, 'sanitize_repo_setting'),
            'default' => '',
            'show_in_rest' => false,
        ));
        register_setting(self::OPTION_GROUP, 'serpulix_seo_github_visibility', array(
            'type' => 'string',
            'sanitize_callback' => array($this, 'sanitize_visibility_setting'),
            'default' => 'public',
            'show_in_rest' => false,
        ));
        register_setting(self::OPTION_GROUP, 'serpulix_seo_github_token', array(
            'type' => 'string',
            'sanitize_callback' => array($this, 'sanitize_token_setting'),
            'default' => '',
            'show_in_rest' => false,
        ));
        register_setting(self::OPTION_GROUP, 'serpulix_seo_github_include_prerelease', array(
            'type' => 'string',
            'sanitize_callback' => array($this, 'sanitize_include_prerelease'),
            'default' => '0',
            'show_in_rest' => false,
        ));
    }

    public function sanitize_updates_enabled($value) {
        return $this->sanitize_stored_flag('serpulix_seo_github_updates_enabled', 'SERPULIX_SEO_GITHUB_UPDATES', $value);
    }

    public function sanitize_include_prerelease($value) {
        return $this->sanitize_stored_flag('serpulix_seo_github_include_prerelease', 'SERPULIX_SEO_GITHUB_INCLUDE_PRERELEASE', $value);
    }

    /**
     * @param string $option
     * @param string $constant
     * @param mixed $value
     * @return string
     */
    private function sanitize_stored_flag($option, $constant, $value) {
        $existing = get_option($option, '0') === '1' ? '1' : '0';
        if (defined($constant) || !$this->user_can_manage() || $value === null) {
            return $existing;
        }
        if (is_array($value)) {
            $value = end($value);
        }
        return ((string) $value === '1') ? '1' : '0';
    }

    public function sanitize_owner_setting($value) {
        $existing = get_option('serpulix_seo_github_owner', '');
        if (!is_string($existing)) {
            $existing = '';
        }
        if (defined('SERPULIX_SEO_GITHUB_OWNER') || !$this->user_can_manage() || $value === null) {
            return $existing;
        }
        $clean = $this->clean_owner($value);
        if (trim((string) $value) !== '' && $clean === '') {
            add_settings_error(
                'serpulix_seo_github_owner',
                'invalid_owner',
                'GitHub owner must be a GitHub username or organization, not a full URL.'
            );
            return $existing;
        }
        return $clean;
    }

    public function sanitize_repo_setting($value) {
        $existing = get_option('serpulix_seo_github_repo', '');
        if (!is_string($existing)) {
            $existing = '';
        }
        if (defined('SERPULIX_SEO_GITHUB_REPO') || !$this->user_can_manage() || $value === null) {
            return $existing;
        }
        $clean = $this->clean_repo($value);
        if (trim((string) $value) !== '' && $clean === '') {
            add_settings_error(
                'serpulix_seo_github_repo',
                'invalid_repo',
                'GitHub repository must be the repository name, not a full URL.'
            );
            return $existing;
        }
        return $clean;
    }

    public function sanitize_visibility_setting($value) {
        if (defined('SERPULIX_SEO_GITHUB_VISIBILITY') || !$this->user_can_manage() || $value === null) {
            return $this->visibility_from_option();
        }
        return ((string) $value === 'private') ? 'private' : 'public';
    }

    public function sanitize_token_setting($value) {
        $existing = get_option('serpulix_seo_github_token', '');
        if (!is_string($existing)) {
            $existing = '';
        }
        if (defined('SERPULIX_SEO_GITHUB_TOKEN') || !$this->user_can_manage() || $value === null) {
            return $existing;
        }

        $posted = is_string($value) ? trim($value) : '';
        if ($posted !== '') {
            $posted = sanitize_text_field($posted);
            if (!preg_match('/^[A-Za-z0-9_]{20,255}$/', $posted)) {
                add_settings_error(
                    'serpulix_seo_github_token',
                    'invalid_token',
                    'That GitHub token format was not saved. Leave the field blank to keep the current token.'
                );
                return $existing;
            }
            return $posted;
        }

        if (!empty($_POST['serpulix_seo_github_token_clear']) && $this->settings_nonce_ok()) {
            return '';
        }

        return $existing;
    }

    public function disable_token_autoload() {
        global $wpdb;
        if (!isset($wpdb->options)) {
            return;
        }
        $wpdb->update(
            $wpdb->options,
            array('autoload' => 'no'),
            array('option_name' => 'serpulix_seo_github_token'),
            array('%s'),
            array('%s')
        );
        wp_cache_delete('serpulix_seo_github_token', 'options');
        wp_cache_delete('alloptions', 'options');
    }

    public function bust_update_cache() {
        delete_transient(self::CACHE_KEY);
        delete_site_transient('update_plugins');
    }

    public function handle_manual_check() {
        if (!$this->user_can_manage()) {
            wp_die(esc_html('Sorry, you are not allowed to manage these options.'), '', array('response' => 403));
        }
        check_admin_referer('serpulix_seo_github_check');

        if (function_exists('set_time_limit')) {
            set_time_limit(60);
        }

        delete_transient(self::CACHE_KEY);
        $this->get_release(true);
        delete_site_transient('update_plugins');
        if ($this->updates_enabled() && $this->is_configured()) {
            wp_update_plugins();
        }

        wp_safe_redirect(admin_url('edit.php?post_type=seobot_page&page=seobot-settings'));
        exit;
    }

    public function render_settings_fields() {
        if (!$this->user_can_manage()) {
            return;
        }

        $owner = $this->get_owner();
        $repo = $this->get_repo();
        $visibility = $this->get_visibility();
        $enabled = $this->updates_enabled();
        $prerelease = $this->include_prerelease();
        $token_saved = $this->token_is_saved();
        $cache = $this->read_cache();
        ?>
        <h2>GitHub Update Settings</h2>
        <p class="description">
            WordPress checks this repository on its normal update schedule, and when you open Dashboard → Updates.
            The installed plugin version stays <?php echo esc_html(SERPULIX_SEO_VERSION); ?> until you update.
        </p>
        <table class="form-table" role="presentation">
            <tr>
                <th scope="row">Enable GitHub Updates</th>
                <td>
                    <?php if ($this->constant_defined('SERPULIX_SEO_GITHUB_UPDATES')) : ?>
                        <p><strong><?php echo $enabled ? 'Yes' : 'No'; ?></strong></p>
                        <p class="description">Controlled by <code>SERPULIX_SEO_GITHUB_UPDATES</code> in wp-config.php.</p>
                    <?php else : ?>
                        <input type="hidden" name="serpulix_seo_github_updates_enabled" value="0" />
                        <label>
                            <input type="checkbox" name="serpulix_seo_github_updates_enabled" value="1" <?php checked($enabled); ?> />
                            Yes, check GitHub Releases for a newer version
                        </label>
                    <?php endif; ?>
                </td>
            </tr>
            <tr>
                <th scope="row"><label for="serpulix_seo_github_owner">GitHub Owner</label></th>
                <td>
                    <?php if ($this->constant_defined('SERPULIX_SEO_GITHUB_OWNER')) : ?>
                        <p><code><?php echo esc_html($owner !== '' ? $owner : '(invalid)'); ?></code></p>
                        <p class="description">Controlled by <code>SERPULIX_SEO_GITHUB_OWNER</code> in wp-config.php.</p>
                    <?php else : ?>
                        <input type="text" id="serpulix_seo_github_owner" name="serpulix_seo_github_owner" value="<?php echo esc_attr(get_option('serpulix_seo_github_owner', '')); ?>" class="regular-text" autocomplete="off" />
                        <p class="description">Username or organization only. Example: <code>YOUR_GITHUB_USERNAME</code></p>
                    <?php endif; ?>
                </td>
            </tr>
            <tr>
                <th scope="row"><label for="serpulix_seo_github_repo">GitHub Repository</label></th>
                <td>
                    <?php if ($this->constant_defined('SERPULIX_SEO_GITHUB_REPO')) : ?>
                        <p><code><?php echo esc_html($repo !== '' ? $repo : '(invalid)'); ?></code></p>
                        <p class="description">Controlled by <code>SERPULIX_SEO_GITHUB_REPO</code> in wp-config.php.</p>
                    <?php else : ?>
                        <input type="text" id="serpulix_seo_github_repo" name="serpulix_seo_github_repo" value="<?php echo esc_attr(get_option('serpulix_seo_github_repo', '')); ?>" class="regular-text" autocomplete="off" />
                        <p class="description">Repository name only. Example: <code>serpulix-seo</code></p>
                    <?php endif; ?>
                </td>
            </tr>
            <tr>
                <th scope="row">GitHub URL</th>
                <td>
                    <?php if ($owner !== '' && $repo !== '') : ?>
                        <?php $repo_url = 'https://github.com/' . rawurlencode($owner) . '/' . rawurlencode($repo); ?>
                        <a href="<?php echo esc_url($repo_url); ?>" target="_blank" rel="noopener noreferrer"><?php echo esc_html($repo_url); ?></a>
                    <?php else : ?>
                        <p class="description">Shown after the owner and repository are saved.</p>
                    <?php endif; ?>
                </td>
            </tr>
            <tr>
                <th scope="row"><label for="serpulix_seo_github_visibility">Repository Visibility</label></th>
                <td>
                    <?php if ($this->constant_defined('SERPULIX_SEO_GITHUB_VISIBILITY')) : ?>
                        <p><strong><?php echo esc_html($visibility === 'private' ? 'Private' : 'Public'); ?></strong></p>
                        <p class="description">Controlled by <code>SERPULIX_SEO_GITHUB_VISIBILITY</code> in wp-config.php (<code>public</code> or <code>private</code>).</p>
                    <?php else : ?>
                        <select id="serpulix_seo_github_visibility" name="serpulix_seo_github_visibility">
                            <option value="public" <?php selected($visibility, 'public'); ?>>Public</option>
                            <option value="private" <?php selected($visibility, 'private'); ?>>Private</option>
                        </select>
                        <p class="description">Public repositories do not need a token. Private repositories need a token with read access to contents.</p>
                    <?php endif; ?>
                </td>
            </tr>
            <tr>
                <th scope="row"><label for="serpulix_seo_github_token">GitHub Token</label></th>
                <td>
                    <?php if ($this->constant_defined('SERPULIX_SEO_GITHUB_TOKEN')) : ?>
                        <p><strong><?php echo $token_saved ? 'Configured' : 'Missing'; ?></strong></p>
                        <p class="description">Controlled by <code>SERPULIX_SEO_GITHUB_TOKEN</code> in wp-config.php. The value is not shown here.</p>
                    <?php else : ?>
                        <input type="password" id="serpulix_seo_github_token" name="serpulix_seo_github_token" value="" class="regular-text" autocomplete="new-password" spellcheck="false" />
                        <?php if ($token_saved) : ?>
                            <p class="description">A token is saved. Leave this blank to keep it.</p>
                            <label>
                                <input type="checkbox" name="serpulix_seo_github_token_clear" value="1" />
                                Remove saved token
                            </label>
                        <?php else : ?>
                            <p class="description">No token is saved. Paste a personal access token to set one. It is stored in WordPress and is not displayed again.</p>
                        <?php endif; ?>
                        <p class="description">For a private repository, create a fine-grained token with Contents: Read-only, or a classic token with the <code>repo</code> scope. The token is sent only to api.github.com from this server.</p>
                    <?php endif; ?>
                </td>
            </tr>
            <tr>
                <th scope="row">Prereleases</th>
                <td>
                    <?php if ($this->constant_defined('SERPULIX_SEO_GITHUB_INCLUDE_PRERELEASE')) : ?>
                        <p><strong><?php echo $prerelease ? 'Yes' : 'No'; ?></strong></p>
                        <p class="description">Controlled by <code>SERPULIX_SEO_GITHUB_INCLUDE_PRERELEASE</code> in wp-config.php.</p>
                    <?php else : ?>
                        <input type="hidden" name="serpulix_seo_github_include_prerelease" value="0" />
                        <label>
                            <input type="checkbox" name="serpulix_seo_github_include_prerelease" value="1" <?php checked($prerelease); ?> />
                            Offer prerelease versions
                        </label>
                        <p class="description">Drafts are always ignored. Prereleases are ignored unless this is checked.</p>
                    <?php endif; ?>
                </td>
            </tr>
        </table>
        <?php $this->render_status($cache, $owner, $repo); ?>
        <?php
    }

    public function render_manual_check_form() {
        if (!$this->user_can_manage()) {
            return;
        }
        ?>
        <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
            <?php wp_nonce_field('serpulix_seo_github_check'); ?>
            <input type="hidden" name="action" value="serpulix_seo_github_check" />
            <?php submit_button('Check GitHub for updates now', 'secondary'); ?>
        </form>
        <?php
    }

    /**
     * @param mixed $transient
     * @return mixed
     */
    public function filter_update_transient($transient) {
        if (!is_object($transient) || $this->checking) {
            return $transient;
        }

        $this->checking = true;
        $transient = $this->apply_release_to_transient($transient);
        $this->checking = false;

        return $transient;
    }

    /**
     * WordPress 5.8+ entry point for the Update URI header (hostname serpulix.com).
     *
     * @param mixed $update
     * @param array $plugin_data
     * @param string $plugin_file
     * @param array $locales
     * @return mixed
     */
    public function filter_update_uri($update, $plugin_data, $plugin_file, $locales) {
        unset($plugin_data, $locales);
        if ($plugin_file !== plugin_basename(SERPULIX_SEO_PLUGIN_FILE)) {
            return $update;
        }
        if (!$this->updates_enabled() || !$this->is_configured()) {
            return $update;
        }

        $release = $this->get_release(false);
        if (!$this->release_is_usable($release)) {
            return $update;
        }

        return $this->build_update_array($release);
    }

    /**
     * @param mixed $result
     * @param string $action
     * @param object $args
     * @return mixed
     */
    public function filter_plugins_api($result, $action, $args) {
        if ($action !== 'plugin_information') {
            return $result;
        }
        if (!is_object($args) || !isset($args->slug) || $args->slug !== self::SLUG) {
            return $result;
        }

        $release = null;
        if ($this->updates_enabled() && $this->is_configured()) {
            $cached = $this->get_release(false);
            if ($this->release_is_usable($cached)) {
                $release = $cached;
            }
        }
        return $this->build_plugin_info($release);
    }

    /**
     * @param mixed $reply
     * @param string $package
     * @param WP_Upgrader $upgrader
     * @param array $hook_extra
     * @return mixed
     */
    public function filter_pre_download($reply, $package, $upgrader, $hook_extra) {
        unset($upgrader);
        if (false !== $reply) {
            return $reply;
        }
        if (!is_string($package) || !$this->is_our_package($package)) {
            return $reply;
        }
        if ($this->package_contains_credential($package)) {
            return new WP_Error(
                'serpulix_seo_github_package',
                'The update package URL is not allowed to contain credentials.'
            );
        }

        $needs_server_download = ($this->get_visibility() === 'private') || $this->is_api_package($package);
        if (!$needs_server_download) {
            return $reply;
        }

        return $this->download_package($package, is_array($hook_extra) ? $hook_extra : array());
    }

    /**
     * Rename GitHub's archive folder so the upgrade replaces this plugin directory.
     *
     * @param string $source
     * @param string $remote_source
     * @param WP_Upgrader $upgrader
     * @param array $hook_extra
     * @return string|WP_Error
     */
    public function filter_source_selection($source, $remote_source, $upgrader, $hook_extra) {
        unset($upgrader);
        if (!is_string($source) || $source === '') {
            return $source;
        }
        if (!is_array($hook_extra) || empty($hook_extra['plugin'])) {
            return $source;
        }
        if ($hook_extra['plugin'] !== plugin_basename(SERPULIX_SEO_PLUGIN_FILE)) {
            return $source;
        }

        global $wp_filesystem;
        if (!is_object($wp_filesystem)) {
            return $source;
        }

        $folder = $this->plugin_folder_name();
        $main_file = basename(SERPULIX_SEO_PLUGIN_FILE);
        $source = $this->locate_plugin_root($wp_filesystem, $source, $main_file);
        if (is_wp_error($source)) {
            return $source;
        }

        $current = trailingslashit($source);
        $current_name = basename(untrailingslashit(wp_normalize_path($current)));
        if ($current_name !== $folder) {
            $desired = trailingslashit($remote_source) . $folder . '/';
            if (!$this->path_is_inside($desired, $remote_source)) {
                return new WP_Error(
                    'serpulix_seo_github_dir',
                    'Could not prepare the plugin directory for update.'
                );
            }

            $flat = untrailingslashit(wp_normalize_path($current)) === untrailingslashit(wp_normalize_path($remote_source));
            if ($flat) {
                if (!$wp_filesystem->is_dir($desired) && !$wp_filesystem->mkdir($desired)) {
                    return new WP_Error(
                        'serpulix_seo_github_dir',
                        'Could not create the ' . $folder . ' directory for the update.'
                    );
                }
                $entries = $wp_filesystem->dirlist($current);
                if (!is_array($entries)) {
                    return new WP_Error('serpulix_seo_github_dir', 'Could not read the update archive.');
                }
                foreach ($entries as $name => $info) {
                    unset($info);
                    if ($name === $folder || $name === '.' || $name === '..') {
                        continue;
                    }
                    if (!$wp_filesystem->move(trailingslashit($current) . $name, trailingslashit($desired) . $name)) {
                        return new WP_Error(
                            'serpulix_seo_github_dir',
                            'Could not move the update into the ' . $folder . ' directory.'
                        );
                    }
                }
            } else {
                if ($wp_filesystem->exists($desired)) {
                    $wp_filesystem->delete($desired, true);
                }
                if (!$wp_filesystem->move($current, $desired)) {
                    return new WP_Error(
                        'serpulix_seo_github_dir',
                        'Could not rename the update directory to ' . $folder . '.'
                    );
                }
            }
            $current = trailingslashit($desired);
        }

        $header_path = $current . $main_file;
        $size = $wp_filesystem->size($header_path);
        if (is_numeric($size) && (int) $size > 1024 * 1024) {
            return new WP_Error(
                'serpulix_seo_github_package',
                'The update archive does not contain a valid Serpulix SEO plugin file.'
            );
        }
        $header = $wp_filesystem->get_contents($header_path);
        if (!is_string($header) || $header === '' || strlen($header) > 1024 * 1024 || !preg_match('/Plugin Name:\s*Serpulix SEO/', $header)) {
            return new WP_Error(
                'serpulix_seo_github_package',
                'The update archive does not contain Serpulix SEO.'
            );
        }

        return $current;
    }

    /**
     * Strip a leading v and accept only version-like tags.
     *
     * @param string $tag
     * @return string
     */
    public static function normalize_version($tag) {
        $tag = trim((string) $tag);
        if ($tag === '' || strlen($tag) > 32) {
            return '';
        }
        if ($tag[0] === 'v' || $tag[0] === 'V') {
            $tag = substr($tag, 1);
        }
        if (!preg_match('/^[0-9]+(?:\.[0-9]+){1,3}(?:[-+][0-9A-Za-z.]+)?$/', $tag)) {
            return '';
        }
        return $tag;
    }

    /**
     * @param string $remote
     * @param string $installed
     * @return bool
     */
    public static function is_newer_version($remote, $installed) {
        $remote = self::normalize_version($remote);
        $installed = self::normalize_version($installed);
        if ($remote === '' || $installed === '') {
            return false;
        }
        return version_compare($remote, $installed, '>');
    }

    /**
     * @param object $transient
     * @return object
     */
    private function apply_release_to_transient($transient) {
        if (!isset($transient->response) || !is_array($transient->response)) {
            $transient->response = array();
        }
        if (!isset($transient->no_update) || !is_array($transient->no_update)) {
            $transient->no_update = array();
        }

        $basename = plugin_basename(SERPULIX_SEO_PLUGIN_FILE);
        $this->strip_foreign_update($transient, $basename);

        if (!$this->updates_enabled() || !$this->is_configured()) {
            unset($transient->response[$basename], $transient->no_update[$basename]);
            return $transient;
        }

        $release = $this->get_release(false);
        if (!$this->release_is_usable($release)) {
            return $transient;
        }

        $item = (object) $this->build_update_array($release);
        if (self::is_newer_version($release['version'], SERPULIX_SEO_VERSION)) {
            $transient->response[$basename] = $item;
            unset($transient->no_update[$basename]);
        } else {
            $transient->no_update[$basename] = $item;
            unset($transient->response[$basename]);
        }

        return $transient;
    }

    /**
     * @param object $transient
     * @param string $basename
     */
    private function strip_foreign_update($transient, $basename) {
        if (!isset($transient->response[$basename])) {
            return;
        }
        $existing = $transient->response[$basename];
        $package = '';
        if (is_object($existing) && isset($existing->package)) {
            $package = (string) $existing->package;
        } elseif (is_array($existing) && isset($existing['package'])) {
            $package = (string) $existing['package'];
        }
        if (!$this->is_our_package($package)) {
            unset($transient->response[$basename]);
        }
    }

    /**
     * @param bool $force
     * @return array
     */
    private function get_release($force) {
        if (!$force && !$this->request_forces_check()) {
            $cached = $this->read_cache();
            if (is_array($cached) && $this->cache_matches_config($cached)) {
                return $cached;
            }
        }

        if (!$this->is_configured()) {
            return $this->store_cache(array(
                'ok' => false,
                'message' => 'GitHub updates are not configured.',
            ));
        }

        $fetched = $this->fetch_release();
        return $this->store_cache($fetched);
    }

    /**
     * Dashboard → Updates → Check Again sends force-check=1.
     *
     * @return bool
     */
    private function request_forces_check() {
        if (!is_admin() || !current_user_can('update_plugins')) {
            return false;
        }
        return isset($_GET['force-check']) && (string) $_GET['force-check'] === '1';
    }

    /**
     * @return array|null
     */
    private function read_cache() {
        $cached = get_transient(self::CACHE_KEY);
        return is_array($cached) ? $cached : null;
    }

    /**
     * @param array $payload
     * @return array
     */
    private function store_cache($payload) {
        $payload['owner'] = strtolower($this->get_owner());
        $payload['repo'] = strtolower($this->get_repo());
        $payload['visibility'] = $this->get_visibility();
        $payload['prerelease_flag'] = $this->include_prerelease() ? '1' : '0';
        $payload['token_sig'] = $this->token_signature();
        $payload['stored_at'] = time();

        if (!isset($payload['ttl']) && !empty($payload['ok']) && !empty($payload['version'])
            && !self::is_newer_version($payload['version'], SERPULIX_SEO_VERSION)) {
            $payload['ttl'] = 15 * MINUTE_IN_SECONDS;
        }

        $ttl = isset($payload['ttl']) ? (int) $payload['ttl'] : 12 * HOUR_IN_SECONDS;
        if ($ttl < 5 * MINUTE_IN_SECONDS) {
            $ttl = 5 * MINUTE_IN_SECONDS;
        }
        if ($ttl > 12 * HOUR_IN_SECONDS) {
            $ttl = 12 * HOUR_IN_SECONDS;
        }
        unset($payload['ttl']);

        set_transient(self::CACHE_KEY, $payload, $ttl);
        return $payload;
    }

    /**
     * @param array $cached
     * @return bool
     */
    private function cache_matches_config($cached) {
        if (!isset($cached['owner'], $cached['repo'], $cached['visibility'], $cached['prerelease_flag'], $cached['token_sig'])) {
            return false;
        }
        $signature = $this->token_signature();
        $cached_signature = (string) $cached['token_sig'];
        if (strlen($cached_signature) !== strlen($signature)) {
            return false;
        }
        return strtolower($this->get_owner()) === $cached['owner']
            && strtolower($this->get_repo()) === $cached['repo']
            && $this->get_visibility() === $cached['visibility']
            && ($this->include_prerelease() ? '1' : '0') === $cached['prerelease_flag']
            && hash_equals($cached_signature, $signature);
    }

    /**
     * @return array
     */
    private function fetch_release() {
        $path = $this->include_prerelease() ? '/releases?per_page=20' : '/releases/latest';
        $response = $this->github_get($this->api_base() . $path);
        if (is_wp_error($response)) {
            return array(
                'ok' => false,
                'message' => 'GitHub could not be reached. The plugin was left unchanged.',
                'ttl' => 30 * MINUTE_IN_SECONDS,
            );
        }

        $code = (int) wp_remote_retrieve_response_code($response);
        $body = json_decode(wp_remote_retrieve_body($response), true);

        if ($code === 200 && is_array($body)) {
            $release = $this->include_prerelease() ? $this->select_listed_release($body) : $this->normalize_release($body);
            if ($release === null) {
                return array(
                    'ok' => false,
                    'message' => 'No published GitHub release was found.',
                    'ttl' => HOUR_IN_SECONDS,
                );
            }
            $release['ok'] = true;
            $release['message'] = '';
            $release['ttl'] = 12 * HOUR_IN_SECONDS;
            return $release;
        }

        return array(
            'ok' => false,
            'message' => $this->http_error_message($code, $response, $body),
            'ttl' => $this->http_error_ttl($code, $response),
        );
    }

    /**
     * @param array $list
     * @return array|null
     */
    private function select_listed_release($list) {
        if (!$this->is_list($list)) {
            return null;
        }
        $count = 0;
        foreach ($list as $item) {
            $count++;
            if ($count > 20 || !is_array($item)) {
                continue;
            }
            $release = $this->normalize_release($item);
            if ($release !== null) {
                return $release;
            }
        }
        return null;
    }

    /**
     * @param array $data
     * @return array|null
     */
    private function normalize_release($data) {
        if (!empty($data['draft'])) {
            return null;
        }
        if (!empty($data['prerelease']) && !$this->include_prerelease()) {
            return null;
        }

        $tag = isset($data['tag_name']) ? (string) $data['tag_name'] : '';
        if (!$this->is_safe_tag($tag)) {
            return null;
        }
        $version = self::normalize_version($tag);
        if ($version === '') {
            return null;
        }

        $asset = $this->select_zip_asset(isset($data['assets']) && is_array($data['assets']) ? $data['assets'] : array());
        $package = $this->build_package_url($tag, $asset);
        if ($package === '') {
            return null;
        }

        $published = isset($data['published_at']) ? (string) $data['published_at'] : '';
        if (!preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}(?:Z|[+-]\d{2}:\d{2})$/', $published)) {
            $published = '';
        }

        $name = isset($data['name']) ? sanitize_text_field((string) $data['name']) : '';
        if (strlen($name) > 180) {
            $name = substr($name, 0, 180);
        }

        $body = isset($data['body']) ? (string) $data['body'] : '';
        if (strlen($body) > 50000) {
            $body = substr($body, 0, 50000);
        }

        $html_url = $this->release_page_url($tag);

        return array(
            'tag' => $tag,
            'version' => $version,
            'name' => $name,
            'body' => $body,
            'published_at' => $published,
            'html_url' => $html_url,
            'package' => $package,
            'asset_name' => $asset ? $asset['name'] : '',
            'icons' => $this->artwork_urls($data, $tag, 'icon'),
            'banners' => $this->artwork_urls($data, $tag, 'banner'),
            'prerelease' => !empty($data['prerelease']),
        );
    }

    /**
     * @param array $assets
     * @return array|null
     */
    private function select_zip_asset($assets) {
        $preferred = null;
        $fallback = null;
        $count = 0;
        foreach ($assets as $asset) {
            $count++;
            if ($count > 30 || !is_array($asset)) {
                continue;
            }
            if (isset($asset['state']) && $asset['state'] !== 'uploaded') {
                continue;
            }
            $name = $this->safe_zip_name(isset($asset['name']) ? $asset['name'] : '');
            if ($name === '') {
                continue;
            }
            $id = isset($asset['id']) ? (int) $asset['id'] : 0;
            if ($id < 1) {
                continue;
            }
            $row = array('id' => $id, 'name' => $name);
            if (strtolower($name) === self::SLUG . '.zip') {
                $preferred = $row;
                break;
            }
            if ($fallback === null) {
                $fallback = $row;
            }
        }
        return $preferred ? $preferred : $fallback;
    }

    /**
     * @param string $tag
     * @param array|null $asset
     * @return string
     */
    private function build_package_url($tag, $asset) {
        if ($this->get_visibility() === 'private') {
            if (is_array($asset)) {
                return $this->api_base() . '/releases/assets/' . $asset['id'];
            }
            return $this->api_base() . '/zipball/' . rawurlencode($tag);
        }

        if (is_array($asset)) {
            return 'https://github.com/' . rawurlencode($this->get_owner()) . '/' . rawurlencode($this->get_repo())
                . '/releases/download/' . rawurlencode($tag) . '/' . rawurlencode($asset['name']);
        }

        return $this->api_base() . '/zipball/' . rawurlencode($tag);
    }

    /**
     * @param array $data
     * @param string $tag
     * @param string $kind
     * @return array
     */
    private function artwork_urls($data, $tag, $kind) {
        if ($this->get_visibility() !== 'public' || empty($data['assets']) || !is_array($data['assets'])) {
            return array();
        }

        $wanted = ($kind === 'icon')
            ? array(
                'icon-128x128.png' => '1x',
                'icon-128x128.jpg' => '1x',
                'icon-256x256.png' => '2x',
                'icon.svg' => 'svg',
            )
            : array(
                'banner-772x250.png' => 'low',
                'banner-772x250.jpg' => 'low',
                'banner-1544x500.png' => 'high',
                'banner-1544x500.jpg' => 'high',
            );

        $found = array();
        foreach ($data['assets'] as $asset) {
            if (!is_array($asset) || empty($asset['name'])) {
                continue;
            }
            $name = strtolower(basename((string) $asset['name']));
            if (!isset($wanted[$name])) {
                continue;
            }
            $key = $wanted[$name];
            if (isset($found[$key])) {
                continue;
            }
            $url = 'https://github.com/' . rawurlencode($this->get_owner()) . '/' . rawurlencode($this->get_repo())
                . '/releases/download/' . rawurlencode($tag) . '/' . rawurlencode($name);
            if ($this->is_our_package($url)) {
                $found[$key] = $url;
            }
        }
        if ($kind === 'icon' && isset($found['1x']) && !isset($found['default'])) {
            $found['default'] = $found['1x'];
        }
        return $found;
    }

    /**
     * @param array $release
     * @return array
     */
    private function build_update_array($release) {
        $requirements = $this->requirements_from_release(isset($release['body']) ? $release['body'] : '');
        $homepage = 'https://serpulix.com';
        $url = !empty($release['html_url']) ? $release['html_url'] : $homepage;

        return array(
            'id' => 'https://serpulix.com/' . self::SLUG,
            'slug' => self::SLUG,
            'plugin' => plugin_basename(SERPULIX_SEO_PLUGIN_FILE),
            'new_version' => $release['version'],
            'version' => $release['version'],
            'url' => $url,
            'package' => $release['package'],
            'icons' => isset($release['icons']) && is_array($release['icons']) ? $release['icons'] : array(),
            'banners' => isset($release['banners']) && is_array($release['banners']) ? $release['banners'] : array(),
            'banners_rtl' => array(),
            'tested' => $requirements['tested'],
            'requires' => $requirements['requires'],
            'requires_php' => $requirements['requires_php'],
        );
    }

    /**
     * @param array|null $release
     * @return object
     */
    private function build_plugin_info($release) {
        $requirements = $this->requirements_from_release(
            (is_array($release) && isset($release['body'])) ? $release['body'] : ''
        );
        $installed = self::normalize_version(SERPULIX_SEO_VERSION);
        $version = (is_array($release) && !empty($release['version'])) ? $release['version'] : $installed;
        $name = (is_array($release) && !empty($release['name'])) ? $release['name'] : ('Version ' . $version);
        $html_url = (is_array($release) && !empty($release['html_url'])) ? $release['html_url'] : 'https://serpulix.com';
        $published = (is_array($release) && !empty($release['published_at'])) ? $release['published_at'] : '';
        $package = (is_array($release) && !empty($release['package'])) ? $release['package'] : '';
        $notes = (is_array($release) && isset($release['body'])) ? $this->markdown_to_safe_html($release['body']) : '<p>Release notes could not be loaded from GitHub.</p>';

        $description = '<p>' . esc_html('Sync and publish SEO content from Serpulix and deploy schema.org structured data to your site.') . '</p>';
        $description .= '<p><strong>' . esc_html($name) . '</strong> (' . esc_html($version) . ')</p>';
        if ($published !== '') {
            $timestamp = strtotime($published);
            if ($timestamp) {
                $description .= '<p>' . esc_html('Published ' . date_i18n(get_option('date_format') . ' ' . get_option('time_format'), $timestamp)) . '</p>';
            }
        }
        $description .= '<p><a href="' . esc_url($html_url) . '" target="_blank" rel="noopener noreferrer">' . esc_html('View this release on GitHub') . '</a></p>';

        $allowed = array(
            'p' => array(),
            'strong' => array(),
            'a' => array(
                'href' => array(),
                'target' => array(),
                'rel' => array(),
            ),
        );

        $info = new stdClass();
        $info->name = 'Serpulix SEO';
        $info->slug = self::SLUG;
        $info->version = $version;
        $info->author = '<a href="https://serpulix.com">Serpulix</a>';
        $info->author_profile = 'https://serpulix.com';
        $info->homepage = 'https://serpulix.com';
        $info->requires = $requirements['requires'];
        $info->tested = $requirements['tested'];
        $info->requires_php = $requirements['requires_php'];
        $info->download_link = $package;
        $info->trunk = $package;
        $info->last_updated = $published !== '' ? $published : gmdate('Y-m-d H:i:s');
        $info->sections = array(
            'description' => wp_kses($description, $allowed),
            'changelog' => $notes,
        );
        $info->banners = (is_array($release) && isset($release['banners']) && is_array($release['banners'])) ? $release['banners'] : array();
        $info->icons = (is_array($release) && isset($release['icons']) && is_array($release['icons'])) ? $release['icons'] : array();
        $info->rating = 0;
        $info->num_ratings = 0;
        $info->downloaded = 0;
        $info->active_installs = 0;
        $info->external = true;
        return $info;
    }

    /**
     * @param string $package
     * @param array $hook_extra
     * @return string|WP_Error
     */
    private function download_package($package, $hook_extra) {
        unset($hook_extra);
        if (!$this->is_our_package($package) || $this->package_contains_credential($package)) {
            return new WP_Error('serpulix_seo_github_package', 'The update package URL is not an allowed GitHub URL.');
        }

        $token = $this->get_token();
        if ($this->get_visibility() === 'private' && $token === '') {
            return new WP_Error(
                'serpulix_seo_github_token',
                'A GitHub token is required to download an update from a private repository.'
            );
        }

        $headers = $this->github_headers($token);
        $headers['Accept'] = 'application/octet-stream';

        $response = wp_safe_remote_get($package, array(
            'headers' => $headers,
            'timeout' => 20,
            'redirection' => 0,
        ));
        if (is_wp_error($response)) {
            return new WP_Error('serpulix_seo_github_download', 'The update could not be downloaded from GitHub. The installed plugin was not changed.');
        }

        $code = (int) wp_remote_retrieve_response_code($response);
        if ($code === 301 || $code === 302 || $code === 307) {
            $location = $this->header_value($response, 'location');
            if (!$this->is_allowed_redirect($location)) {
                return new WP_Error('serpulix_seo_github_download', 'GitHub returned an unexpected download location. The installed plugin was not changed.');
            }
            return $this->stream_to_temp($location);
        }

        if ($code !== 200) {
            return new WP_Error(
                'serpulix_seo_github_download',
                'GitHub download failed (HTTP ' . $code . '). The installed plugin was not changed.'
            );
        }

        $body = wp_remote_retrieve_body($response);
        if (!is_string($body) || strlen($body) < 4 || strlen($body) > 50 * 1024 * 1024) {
            return new WP_Error('serpulix_seo_github_download', 'GitHub returned an unexpected update file. The installed plugin was not changed.');
        }
        if (!$this->zip_magic($body)) {
            return new WP_Error('serpulix_seo_github_download', 'The downloaded update was not a ZIP archive. The installed plugin was not changed.');
        }

        $tmp = wp_tempnam('serpulix-seo.zip');
        if (!$tmp) {
            return new WP_Error('serpulix_seo_github_download', 'Could not create a temporary file for the update.');
        }
        $written = file_put_contents($tmp, $body);
        if ($written === false) {
            wp_delete_file($tmp);
            return new WP_Error('serpulix_seo_github_download', 'Could not store the update file.');
        }
        return $tmp;
    }

    /**
     * @param string $url
     * @return string|WP_Error
     */
    private function stream_to_temp($url) {
        if (!$this->is_allowed_redirect($url)) {
            return new WP_Error('serpulix_seo_github_download', 'GitHub returned an unexpected download location.');
        }

        $tmp = wp_tempnam('serpulix-seo.zip');
        if (!$tmp) {
            return new WP_Error('serpulix_seo_github_download', 'Could not create a temporary file for the update.');
        }

        $response = wp_safe_remote_get($url, array(
            'timeout' => 300,
            'stream' => true,
            'filename' => $tmp,
            'redirection' => 2,
        ));
        if (is_wp_error($response)) {
            wp_delete_file($tmp);
            return new WP_Error('serpulix_seo_github_download', 'The update could not be downloaded. The installed plugin was not changed.');
        }

        $code = (int) wp_remote_retrieve_response_code($response);
        if ($code !== 200 || !$this->file_is_zip($tmp)) {
            wp_delete_file($tmp);
            return new WP_Error('serpulix_seo_github_download', 'The downloaded update was not a valid ZIP archive. The installed plugin was not changed.');
        }

        return $tmp;
    }

    /**
     * @param string $url
     * @return array|WP_Error
     */
    private function github_get($url) {
        if (!$this->is_our_api_url($url)) {
            return new WP_Error('serpulix_seo_github_url', 'Blocked a request to a non-GitHub URL.');
        }

        return wp_safe_remote_get($url, array(
            'headers' => $this->github_headers($this->get_token()),
            'timeout' => 10,
            'redirection' => 0,
        ));
    }

    /**
     * @param string $token
     * @return array
     */
    private function github_headers($token) {
        $headers = array(
            'Accept' => 'application/vnd.github+json',
            'User-Agent' => 'Serpulix-SEO/' . SERPULIX_SEO_VERSION . ' (+https://serpulix.com)',
            'X-GitHub-Api-Version' => '2022-11-28',
        );
        if (is_string($token) && $token !== '') {
            $headers['Authorization'] = 'Bearer ' . $token;
        }
        return $headers;
    }

    /**
     * @param int $code
     * @param array $response
     * @param mixed $body
     * @return string
     */
    private function http_error_message($code, $response, $body) {
        if ($code === 404) {
            return 'GitHub did not find that repository or a published release. Check the owner, repository, and that a non-draft release exists.';
        }
        if ($code === 401) {
            return 'GitHub rejected the token. Save a token with read access to this repository.';
        }
        if ($code === 429 || ($code === 403 && $this->header_value($response, 'x-ratelimit-remaining') === '0')) {
            return 'GitHub rate limit reached. Update checks are paused until the limit resets.';
        }
        if ($code === 403) {
            return 'GitHub denied access. For a private repository, set visibility to Private and save a token with read access.';
        }
        unset($body);
        return 'GitHub returned HTTP ' . (int) $code . '. The installed plugin was left unchanged.';
    }

    /**
     * @param int $code
     * @param array $response
     * @return int
     */
    private function http_error_ttl($code, $response) {
        if ($code === 429 || ($code === 403 && $this->header_value($response, 'x-ratelimit-remaining') === '0')) {
            $reset = (int) $this->header_value($response, 'x-ratelimit-reset');
            if ($reset > time()) {
                return min(6 * HOUR_IN_SECONDS, max(15 * MINUTE_IN_SECONDS, $reset - time()));
            }
            $retry = (int) $this->header_value($response, 'retry-after');
            if ($retry > 0) {
                return min(6 * HOUR_IN_SECONDS, max(15 * MINUTE_IN_SECONDS, $retry));
            }
            return HOUR_IN_SECONDS;
        }
        if ($code === 404) {
            return HOUR_IN_SECONDS;
        }
        if ($code === 401 || $code === 403) {
            return 15 * MINUTE_IN_SECONDS;
        }
        return 30 * MINUTE_IN_SECONDS;
    }

    /**
     * @param string $notes
     * @return array
     */
    private function requirements_from_release($notes) {
        $defaults = $this->local_requirements();
        return array(
            'requires' => $this->requirement_value($notes, 'Requires at least', $defaults['requires']),
            'tested' => $this->requirement_value($notes, 'Tested up to', $defaults['tested']),
            'requires_php' => $this->requirement_value($notes, 'Requires PHP', $defaults['requires_php']),
        );
    }

    /**
     * @return array
     */
    private function local_requirements() {
        $defaults = array(
            'requires' => '5.8',
            'tested' => '7.0',
            'requires_php' => '7.4',
        );
        $readme = SERPULIX_SEO_PLUGIN_DIR . 'readme.txt';
        if (!is_readable($readme)) {
            return $defaults;
        }
        $data = get_file_data($readme, array(
            'requires' => 'Requires at least',
            'tested' => 'Tested up to',
            'requires_php' => 'Requires PHP',
        ));
        foreach ($defaults as $key => $fallback) {
            if (isset($data[$key]) && preg_match('/^[0-9]+(?:\.[0-9]+){1,2}$/', trim($data[$key]))) {
                $defaults[$key] = trim($data[$key]);
            }
        }
        return $defaults;
    }

    /**
     * @param string $notes
     * @param string $label
     * @param string $fallback
     * @return string
     */
    private function requirement_value($notes, $label, $fallback) {
        $pattern = '/^' . preg_quote($label, '/') . ':\s*([0-9]+(?:\.[0-9]+){1,2})\s*$/mi';
        if (is_string($notes) && preg_match($pattern, $notes, $matches)) {
            return $matches[1];
        }
        return $fallback;
    }

    /**
     * @param string $markdown
     * @return string
     */
    private function markdown_to_safe_html($markdown) {
        $markdown = str_replace(array("\r\n", "\r"), "\n", (string) $markdown);
        if (strlen($markdown) > 20000) {
            $markdown = substr($markdown, 0, 20000);
        }
        if (trim($markdown) === '') {
            return '<p>No release notes were published for this version.</p>';
        }

        $lines = explode("\n", $markdown);
        $html = '';
        $paragraph = array();
        $in_ul = false;
        $in_ol = false;
        $in_code = false;

        $flush_paragraph = function () use (&$html, &$paragraph) {
            if (empty($paragraph)) {
                return;
            }
            $html .= '<p>' . $this->inline_markdown(implode(' ', $paragraph)) . '</p>';
            $paragraph = array();
        };
        $close_lists = function () use (&$html, &$in_ul, &$in_ol) {
            if ($in_ul) {
                $html .= '</ul>';
                $in_ul = false;
            }
            if ($in_ol) {
                $html .= '</ol>';
                $in_ol = false;
            }
        };

        foreach ($lines as $line) {
            if (preg_match('/^```/', $line)) {
                $flush_paragraph();
                $close_lists();
                if ($in_code) {
                    $html .= "</code></pre>\n";
                    $in_code = false;
                } else {
                    $html .= '<pre><code>';
                    $in_code = true;
                }
                continue;
            }
            if ($in_code) {
                $html .= esc_html($line) . "\n";
                continue;
            }
            if (trim($line) === '') {
                $flush_paragraph();
                $close_lists();
                continue;
            }
            if (preg_match('/^(#{1,4})\s+(.*)$/', $line, $matches)) {
                $flush_paragraph();
                $close_lists();
                $level = strlen($matches[1]);
                if ($level < 2) {
                    $level = 2;
                }
                $html .= '<h' . $level . '>' . $this->inline_markdown($matches[2]) . '</h' . $level . '>';
                continue;
            }
            if (preg_match('/^[-*]\s+(.*)$/', $line, $matches)) {
                $flush_paragraph();
                if ($in_ol) {
                    $html .= '</ol>';
                    $in_ol = false;
                }
                if (!$in_ul) {
                    $html .= '<ul>';
                    $in_ul = true;
                }
                $html .= '<li>' . $this->inline_markdown($matches[1]) . '</li>';
                continue;
            }
            if (preg_match('/^\d+\.\s+(.*)$/', $line, $matches)) {
                $flush_paragraph();
                if ($in_ul) {
                    $html .= '</ul>';
                    $in_ul = false;
                }
                if (!$in_ol) {
                    $html .= '<ol>';
                    $in_ol = true;
                }
                $html .= '<li>' . $this->inline_markdown($matches[1]) . '</li>';
                continue;
            }
            $close_lists();
            $paragraph[] = trim($line);
        }

        if ($in_code) {
            $html .= "</code></pre>\n";
        }
        $flush_paragraph();
        $close_lists();

        return wp_kses($html, array(
            'p' => array(),
            'br' => array(),
            'strong' => array(),
            'em' => array(),
            'code' => array(),
            'pre' => array(),
            'ul' => array(),
            'ol' => array(),
            'li' => array(),
            'h2' => array(),
            'h3' => array(),
            'h4' => array(),
            'a' => array(
                'href' => array(),
                'rel' => array(),
                'target' => array(),
            ),
        ));
    }

    /**
     * @param string $text
     * @return string
     */
    private function inline_markdown($text) {
        $text = esc_html($text);
        $text = preg_replace_callback('/`([^`]+)`/', function ($matches) {
            return '<code>' . $matches[1] . '</code>';
        }, $text);
        if (!is_string($text)) {
            return '';
        }
        $text = preg_replace('/\*\*([^*]+)\*\*/', '<strong>$1</strong>', $text);
        if (!is_string($text)) {
            return '';
        }
        $text = preg_replace('/(?<!\*)\*([^*]+)\*(?!\*)/', '<em>$1</em>', $text);
        if (!is_string($text)) {
            return '';
        }
        $linked = preg_replace_callback('/\[([^\]]+)\]\((https?:\/\/[^\s)]+)\)/', function ($matches) {
            $url = html_entity_decode($matches[2], ENT_QUOTES, 'UTF-8');
            $clean = esc_url($url, array('http', 'https'));
            if ($clean === '') {
                return $matches[1];
            }
            return '<a href="' . esc_url($clean) . '" target="_blank" rel="noopener noreferrer">' . $matches[1] . '</a>';
        }, $text);
        return is_string($linked) ? $linked : '';
    }

    /**
     * @param array|null $cache
     * @param string $owner
     * @param string $repo
     */
    private function render_status($cache, $owner, $repo) {
        echo '<h3>Update check</h3>';
        if (!$this->updates_enabled()) {
            echo '<p>GitHub updates are turned off. WordPress will not offer an update until you enable them.</p>';
            if ($owner === '' || $repo === '' || !is_array($cache)) {
                return;
            }
        } elseif ($owner === '' || $repo === '') {
            echo '<p>Add a GitHub owner and repository, save, and WordPress can check for a release.</p>';
            return;
        }
        if (!is_array($cache)) {
            echo '<p>No check has been stored yet. Save these settings or use Check GitHub for updates now. Opening Dashboard → Updates also runs WordPress\'s normal check.</p>';
            return;
        }

        if (empty($cache['ok'])) {
            $message = isset($cache['message']) ? $cache['message'] : 'GitHub could not be checked.';
            echo '<div class="notice notice-warning inline"><p>' . esc_html($message) . '</p></div>';
            return;
        }

        $remote = isset($cache['version']) ? $cache['version'] : '';
        $newer = self::is_newer_version($remote, SERPULIX_SEO_VERSION);
        echo '<div class="notice notice-' . ($newer ? 'info' : 'success') . ' inline"><p>';
        echo esc_html('Installed version ' . SERPULIX_SEO_VERSION . '. Latest GitHub release ' . $remote . '.');
        if ($newer) {
            echo ' ' . esc_html('WordPress should show an update to ' . $remote . ' on Plugins and on Dashboard → Updates.');
        } else {
            echo ' ' . esc_html('No update is available.');
        }
        echo '</p></div>';
    }

    /**
     * @param object $filesystem
     * @param string $source
     * @param string $main_file
     * @return string|WP_Error
     */
    private function locate_plugin_root($filesystem, $source, $main_file) {
        $source = trailingslashit($source);
        if ($filesystem->exists($source . $main_file)) {
            return $source;
        }

        $nested = $source . 'serpulix-seo/' . $main_file;
        if ($filesystem->exists($nested)) {
            return trailingslashit($source . 'serpulix-seo');
        }

        $list = $filesystem->dirlist($source);
        if (!is_array($list) || count($list) !== 1) {
            return new WP_Error(
                'serpulix_seo_github_package',
                'The update archive does not contain ' . $main_file . ' in the expected folder.'
            );
        }

        $only = trailingslashit($source . key($list));
        if ($filesystem->exists($only . $main_file)) {
            return $only;
        }
        if ($filesystem->exists($only . 'serpulix-seo/' . $main_file)) {
            return trailingslashit($only . 'serpulix-seo');
        }

        return new WP_Error(
            'serpulix_seo_github_package',
            'The update archive does not contain ' . $main_file . '.'
        );
    }

    /**
     * @param string $path
     * @param string $directory
     * @return bool
     */
    private function path_is_inside($path, $directory) {
        $path = wp_normalize_path($path);
        $directory = trailingslashit(wp_normalize_path($directory));
        return strpos($path, $directory) === 0;
    }

    /**
     * @param string $url
     * @return bool
     */
    private function is_our_package($url) {
        $parts = $this->https_parts($url);
        if ($parts === null) {
            return false;
        }
        $owner = strtolower($this->get_owner());
        $repo = strtolower($this->get_repo());
        if ($owner === '' || $repo === '') {
            return false;
        }
        $path = strtolower($parts['path']);
        $host = $parts['host'];
        $repo_path = '/' . $owner . '/' . $repo . '/';
        $api_path = '/repos/' . $owner . '/' . $repo . '/';

        if ($host === 'github.com' && strpos($path, $repo_path . 'releases/download/') === 0) {
            return true;
        }
        if ($host === 'api.github.com' && strpos($path, $api_path . 'releases/assets/') === 0) {
            return true;
        }
        if ($host === 'api.github.com' && strpos($path, $api_path . 'zipball/') === 0) {
            return true;
        }
        if ($host === 'codeload.github.com' && strpos($path, $repo_path) === 0) {
            return true;
        }
        return false;
    }

    /**
     * @param string $url
     * @return bool
     */
    private function is_our_api_url($url) {
        $parts = $this->https_parts($url);
        if ($parts === null || $parts['host'] !== 'api.github.com') {
            return false;
        }
        $owner = strtolower($this->get_owner());
        $repo = strtolower($this->get_repo());
        $path = strtolower($parts['path']);
        $prefix = '/repos/' . $owner . '/' . $repo . '/releases';
        return strpos($path, $prefix) === 0;
    }

    /**
     * @param string $url
     * @return bool
     */
    private function is_api_package($url) {
        $parts = $this->https_parts($url);
        return $parts !== null && $parts['host'] === 'api.github.com';
    }

    /**
     * @param string $url
     * @return bool
     */
    private function is_allowed_redirect($url) {
        $parts = $this->https_parts($url);
        if ($parts === null) {
            return false;
        }
        $host = $parts['host'];
        if ($host === 'github.com' || $host === 'api.github.com' || $host === 'codeload.github.com') {
            return $this->is_our_package($url) || $this->is_our_api_url($url);
        }
        return (bool) preg_match('/^[a-z0-9.-]+\.githubusercontent\.com$/', $host);
    }

    /**
     * @param string $url
     * @return array|null
     */
    private function https_parts($url) {
        if (!is_string($url) || $url === '' || preg_match('/\s/', $url)) {
            return null;
        }
        $parts = wp_parse_url($url);
        if (!is_array($parts) || empty($parts['scheme']) || empty($parts['host']) || empty($parts['path'])) {
            return null;
        }
        if (strtolower($parts['scheme']) !== 'https') {
            return null;
        }
        $host = strtolower($parts['host']);
        if (strpos($host, '..') !== false || !preg_match('/^[a-z0-9.-]+$/', $host)) {
            return null;
        }
        return array(
            'host' => $host,
            'path' => $parts['path'],
        );
    }

    /**
     * @param string $package
     * @return bool
     */
    private function package_contains_credential($package) {
        return (bool) preg_match('/(?:access_token|client_secret|password|token)=/i', $package);
    }

    /**
     * @param string $tag
     * @return string
     */
    private function release_page_url($tag) {
        return 'https://github.com/' . rawurlencode($this->get_owner()) . '/' . rawurlencode($this->get_repo()) . '/releases/tag/' . rawurlencode($tag);
    }

    /**
     * @return string
     */
    private function api_base() {
        return 'https://api.github.com/repos/' . rawurlencode($this->get_owner()) . '/' . rawurlencode($this->get_repo());
    }

    /**
     * @param mixed $value
     * @return string
     */
    private function clean_owner($value) {
        $value = sanitize_text_field(is_string($value) ? $value : '');
        if (!preg_match('/^[A-Za-z0-9](?:[A-Za-z0-9-]{0,37}[A-Za-z0-9])?$/', $value)) {
            return '';
        }
        return $value;
    }

    /**
     * @param mixed $value
     * @return string
     */
    private function clean_repo($value) {
        $value = sanitize_text_field(is_string($value) ? $value : '');
        if ($value === '.' || $value === '..' || !preg_match('/^[A-Za-z0-9._-]{1,100}$/', $value)) {
            return '';
        }
        return $value;
    }

    /**
     * @param mixed $name
     * @return string
     */
    private function safe_zip_name($name) {
        $name = basename(str_replace('\\', '/', (string) $name));
        if (!preg_match('/^[A-Za-z0-9._-]{1,180}\.zip$/', $name)) {
            return '';
        }
        return $name;
    }

    /**
     * @param string $tag
     * @return bool
     */
    private function is_safe_tag($tag) {
        return is_string($tag) && preg_match('/^[A-Za-z0-9._-]{1,100}$/', $tag) === 1;
    }

    /**
     * @param mixed $data
     * @return bool
     */
    private function is_list($data) {
        if (!is_array($data)) {
            return false;
        }
        if ($data === array()) {
            return true;
        }
        return array_keys($data) === range(0, count($data) - 1);
    }

    /**
     * @param array $release
     * @return bool
     */
    private function release_is_usable($release) {
        return is_array($release)
            && !empty($release['ok'])
            && !empty($release['version'])
            && !empty($release['package'])
            && $this->is_our_package($release['package']);
    }

    /**
     * @param string $body
     * @return bool
     */
    private function zip_magic($body) {
        return strncmp($body, "PK\x03\x04", 4) === 0
            || strncmp($body, "PK\x05\x06", 4) === 0
            || strncmp($body, "PK\x07\x08", 4) === 0;
    }

    /**
     * @param string $path
     * @return bool
     */
    private function file_is_zip($path) {
        $handle = fopen($path, 'rb');
        if (!$handle) {
            return false;
        }
        $magic = fread($handle, 4);
        fclose($handle);
        return is_string($magic) && $this->zip_magic($magic);
    }

    /**
     * @param array $response
     * @param string $name
     * @return string
     */
    private function header_value($response, $name) {
        $value = wp_remote_retrieve_header($response, $name);
        if (is_array($value)) {
            $value = end($value);
        }
        return is_string($value) ? trim($value) : '';
    }

    /**
     * @return bool
     */
    private function user_can_manage() {
        return current_user_can('manage_options');
    }

    /**
     * @return bool
     */
    private function settings_nonce_ok() {
        $nonce = isset($_POST['_wpnonce']) ? sanitize_text_field(wp_unslash($_POST['_wpnonce'])) : '';
        return is_string($nonce) && wp_verify_nonce($nonce, self::OPTION_GROUP . '-options');
    }

    /**
     * @param string $name
     * @return bool
     */
    private function constant_defined($name) {
        return defined($name);
    }

    /**
     * @return bool
     */
    private function updates_enabled() {
        $constant = $this->constant_bool('SERPULIX_SEO_GITHUB_UPDATES');
        if ($constant !== null) {
            return $constant;
        }
        return true;
    }

    /**
     * @return bool
     */
    private function include_prerelease() {
        $constant = $this->constant_bool('SERPULIX_SEO_GITHUB_INCLUDE_PRERELEASE');
        if ($constant !== null) {
            return $constant;
        }
        return false;
    }

    /**
     * @param string $name
     * @return bool|null
     */
    private function constant_bool($name) {
        if (!defined($name)) {
            return null;
        }
        $value = constant($name);
        if (is_bool($value)) {
            return $value;
        }
        if (is_int($value)) {
            return $value === 1;
        }
        if (is_string($value)) {
            $value = strtolower($value);
            if (in_array($value, array('1', 'true', 'yes', 'on'), true)) {
                return true;
            }
            if (in_array($value, array('0', 'false', 'no', 'off', ''), true)) {
                return false;
            }
        }
        return false;
    }

    /**
     * @return string
     */
    private function get_owner() {
        if (defined('SERPULIX_SEO_GITHUB_OWNER')) {
            return $this->clean_owner(SERPULIX_SEO_GITHUB_OWNER);
        }
        return $this->clean_owner(self::DEFAULT_OWNER);
    }

    /**
     * @return string
     */
    private function get_repo() {
        if (defined('SERPULIX_SEO_GITHUB_REPO')) {
            return $this->clean_repo(SERPULIX_SEO_GITHUB_REPO);
        }
        return $this->clean_repo(self::DEFAULT_REPO);
    }

    /**
     * @return string
     */
    private function get_visibility() {
        if (defined('SERPULIX_SEO_GITHUB_VISIBILITY')) {
            $value = SERPULIX_SEO_GITHUB_VISIBILITY;
            return (is_string($value) && strtolower($value) === 'private') ? 'private' : 'public';
        }
        return 'public';
    }

    /**
     * @return string
     */
    private function visibility_from_option() {
        return get_option('serpulix_seo_github_visibility', 'public') === 'private' ? 'private' : 'public';
    }

    /**
     * @return string
     */
    private function get_token() {
        if (defined('SERPULIX_SEO_GITHUB_TOKEN')) {
            $value = SERPULIX_SEO_GITHUB_TOKEN;
            if (!is_string($value) || !preg_match('/^[A-Za-z0-9_]{20,255}$/', $value)) {
                return '';
            }
            return $value;
        }
        $value = get_option('serpulix_seo_github_token', '');
        if (!is_string($value) || !preg_match('/^[A-Za-z0-9_]{20,255}$/', $value)) {
            return '';
        }
        return $value;
    }

    /**
     * @return bool
     */
    private function token_is_saved() {
        return $this->get_token() !== '';
    }

    /**
     * @return string
     */
    private function token_signature() {
        $token = $this->get_token();
        if ($token === '') {
            return '';
        }
        return substr(hash_hmac('sha256', $token, wp_salt('auth')), 0, 16);
    }

    /**
     * @return bool
     */
    private function is_configured() {
        return $this->get_owner() !== '' && $this->get_repo() !== '';
    }

    /**
     * @return string
     */
    private function plugin_folder_name() {
        $folder = dirname(plugin_basename(SERPULIX_SEO_PLUGIN_FILE));
        if (!is_string($folder) || $folder === '.' || $folder === '' || !preg_match('/^[A-Za-z0-9._-]{1,100}$/', $folder)) {
            return self::SLUG;
        }
        return $folder;
    }
}
