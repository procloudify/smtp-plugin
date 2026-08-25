<?php
if (!defined('ABSPATH')) {
    exit;
}

class Procloudify_SMTP_Updater {

    private $file;
    private $plugin_slug;
    private $version;
    private $github_repo;
    private $github_api_result;

    public function __construct($file, $github_repo) {
        $this->file = $file;
        $this->plugin_slug = plugin_basename($file);
        $this->version = PROCLOUDIFY_SMTP_VERSION;
        $this->github_repo = $github_repo;

        add_filter('pre_set_site_transient_update_plugins', [$this, 'check_update']);
        add_filter('plugins_api', [$this, 'plugin_info'], 20, 3);
        add_filter('upgrader_source_selection', [$this, 'fix_source_dir'], 10, 4);
    }

    private function get_github_release() {
        if (!empty($this->github_api_result)) {
            return $this->github_api_result;
        }

        $transient_key = 'procloudify_smtp_gh_release';
        
        if (isset($_GET['force-check']) || isset($_GET['check_again'])) {
            delete_site_transient($transient_key);
        } else {
            $cached = get_site_transient($transient_key);
            if ($cached !== false) {
                $this->github_api_result = $cached;
                return $cached;
            }
        }

        // 1. Try Releases API
        $url = "https://api.github.com/repos/{$this->github_repo}/releases/latest";
        $response = wp_remote_get($url, [
            'timeout'    => 10,
            'user-agent' => 'WordPress/' . get_bloginfo('version') . '; ' . home_url(),
            'headers'    => [
                'Accept' => 'application/vnd.github.v3+json',
            ],
        ]);

        $data = [];
        if (!is_wp_error($response) && 200 === wp_remote_retrieve_response_code($response)) {
            $data = json_decode(wp_remote_retrieve_body($response), true);
        }

        // 2. Fallback to Tags API if no formal release yet
        if (empty($data['tag_name'])) {
            $tags_url = "https://api.github.com/repos/{$this->github_repo}/tags";
            $tags_resp = wp_remote_get($tags_url, [
                'timeout'    => 10,
                'user-agent' => 'WordPress/' . get_bloginfo('version') . '; ' . home_url(),
                'headers'    => [
                    'Accept' => 'application/vnd.github.v3+json',
                ],
            ]);

            if (!is_wp_error($tags_resp) && 200 === wp_remote_retrieve_response_code($tags_resp)) {
                $tags = json_decode(wp_remote_retrieve_body($tags_resp), true);
                if (!empty($tags) && is_array($tags) && !empty($tags[0]['name'])) {
                    $latest_tag = $tags[0];
                    $data = [
                        'tag_name'    => $latest_tag['name'],
                        'zipball_url' => $latest_tag['zipball_url'],
                        'html_url'    => "https://github.com/{$this->github_repo}/releases/tag/{$latest_tag['name']}",
                        'body'        => 'Release ' . $latest_tag['name'],
                    ];
                }
            }
        }

        if (empty($data) || empty($data['tag_name'])) {
            return false;
        }

        $this->github_api_result = $data;
        set_site_transient($transient_key, $data, 3 * HOUR_IN_SECONDS);
        return $data;
    }

    public function check_update($transient) {
        if (empty($transient->checked)) {
            return $transient;
        }

        $release = $this->get_github_release();
        if (!$release) {
            return $transient;
        }

        $new_version = ltrim($release['tag_name'], 'v');
        if (version_compare($this->version, $new_version, '<')) {
            $package = !empty($release['zipball_url']) ? $release['zipball_url'] : '';
            if (!empty($release['assets']) && is_array($release['assets'])) {
                foreach ($release['assets'] as $asset) {
                    if (!empty($asset['browser_download_url']) && preg_match('/\.zip$/i', $asset['browser_download_url'])) {
                        $package = $asset['browser_download_url'];
                        break;
                    }
                }
            }

            $obj = new stdClass();
            $obj->slug = 'smtp-by-procloudify';
            $obj->new_version = $new_version;
            $obj->url = !empty($release['html_url']) ? $release['html_url'] : "https://github.com/{$this->github_repo}";
            $obj->package = $package;
            $obj->plugin = $this->plugin_slug;
            $obj->icons = [
                '1x'      => PROCLOUDIFY_SMTP_URL . 'assets/images/icon-128x128.png',
                '2x'      => PROCLOUDIFY_SMTP_URL . 'assets/images/icon-256x256.png',
                'default' => PROCLOUDIFY_SMTP_URL . 'assets/images/icon-256x256.png',
            ];
            $obj->banners = [
                'low'  => PROCLOUDIFY_SMTP_URL . 'assets/images/banner-772x250.png',
                'high' => PROCLOUDIFY_SMTP_URL . 'assets/images/banner-1544x500.png',
            ];

            $transient->response[$this->plugin_slug] = $obj;
        }

        return $transient;
    }

    public function plugin_info($result, $action, $args) {
        if ('plugin_information' !== $action || empty($args->slug) || ('smtp-by-procloudify' !== $args->slug && dirname($this->plugin_slug) !== $args->slug)) {
            return $result;
        }

        $release = $this->get_github_release();
        if (!$release) {
            return $result;
        }

        $new_version = ltrim($release['tag_name'], 'v');

        $package = !empty($release['zipball_url']) ? $release['zipball_url'] : '';
        if (!empty($release['assets']) && is_array($release['assets'])) {
            foreach ($release['assets'] as $asset) {
                if (!empty($asset['browser_download_url']) && preg_match('/\.zip$/i', $asset['browser_download_url'])) {
                    $package = $asset['browser_download_url'];
                    break;
                }
            }
        }

        $info = new stdClass();
        $info->name = 'Procloudify SMTP';
        $info->slug = 'smtp-by-procloudify';
        $info->version = $new_version;
        $info->author = '<a href="https://procloudify.com">Procloudify</a>';
        $info->homepage = 'https://procloudify.com';
        $info->download_link = $package;
        $info->icons = [
            '1x'      => PROCLOUDIFY_SMTP_URL . 'assets/images/icon-128x128.png',
            '2x'      => PROCLOUDIFY_SMTP_URL . 'assets/images/icon-256x256.png',
            'default' => PROCLOUDIFY_SMTP_URL . 'assets/images/icon-256x256.png',
        ];
        $info->banners = [
            'low'  => PROCLOUDIFY_SMTP_URL . 'assets/images/banner-772x250.png',
            'high' => PROCLOUDIFY_SMTP_URL . 'assets/images/banner-1544x500.png',
        ];
        $info->sections = [
            'description' => 'Dedicated high-speed SMTP mail routing plugin for Procloudify clients.',
            'changelog'   => !empty($release['body']) ? nl2br(esc_html($release['body'])) : 'Bug fixes and performance improvements.',
        ];

        return $info;
    }

    public function fix_source_dir($source, $remote_source, $upgrader, $hook_extra = []) {
        global $wp_filesystem;

        if (empty($hook_extra['plugin']) || $hook_extra['plugin'] !== $this->plugin_slug) {
            return $source;
        }

        $correct_dir_name = dirname($this->plugin_slug);
        $correct_source = trailingslashit($remote_source) . $correct_dir_name . '/';

        if (trailingslashit($source) !== $correct_source) {
            $wp_filesystem->move($source, $correct_source);
            return $correct_source;
        }

        return $source;
    }
}
