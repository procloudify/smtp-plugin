<?php
if (!defined('ABSPATH')) {
    exit;
}

class Procloudify_SMTP_Admin {

    public function __construct() {
        add_action('admin_menu', [$this, 'add_admin_menu']);
        add_action('admin_enqueue_scripts', [$this, 'enqueue_assets']);
        add_action('admin_init', [$this, 'handle_save_settings']);
        add_action('admin_init', [$this, 'handle_send_test']);
        add_action('wp_ajax_procloudify_ajax_test_email', [$this, 'ajax_send_test_email']);
    }

    public function add_admin_menu() {
        add_menu_page(
            __('Procloudify SMTP', 'smtp-by-procloudify'),
            __('Procloudify SMTP', 'smtp-by-procloudify'),
            'manage_options',
            'procloudify-smtp',
            [$this, 'render_page'],
            'dashicons-email-alt2',
            80
        );
    }

    public function enqueue_assets($hook) {
        if (strpos($hook, 'procloudify-smtp') === false) {
            return;
        }

        wp_enqueue_style(
            'procloudify-smtp-admin-css',
            PROCLOUDIFY_SMTP_URL . 'assets/css/admin.css',
            [],
            PROCLOUDIFY_SMTP_VERSION
        );

        wp_enqueue_script(
            'procloudify-smtp-admin-js',
            PROCLOUDIFY_SMTP_URL . 'assets/js/admin.js',
            ['jquery'],
            PROCLOUDIFY_SMTP_VERSION,
            true
        );

        wp_localize_script('procloudify-smtp-admin-js', 'procloudifySmtp', [
            'ajaxUrl' => admin_url('admin-ajax.php'),
            'nonce'   => wp_create_nonce('procloudify_smtp_nonce'),
            'sending' => __('Sending Test Email...', 'smtp-by-procloudify'),
            'sendBtn' => __('Send Test Email', 'smtp-by-procloudify'),
        ]);
    }

    public function handle_save_settings() {
        if (!isset($_POST['procloudify_save_settings'])) {
            return;
        }

        check_admin_referer('procloudify_save_settings_action', 'procloudify_save_settings_nonce');

        if (!current_user_can('manage_options')) {
            wp_die(__('Unauthorized access.', 'smtp-by-procloudify'));
        }

        $existing = get_option('procloudify_smtp_settings', []);

        $server      = sanitize_text_field($_POST['server'] ?? 'bdix');
        $email       = sanitize_email($_POST['email'] ?? '');
        $password    = isset($_POST['password']) ? trim($_POST['password']) : '';
        $sender_name = sanitize_text_field($_POST['sender_name'] ?? '');

        if (empty($password)) {
            $password = $existing['password'] ?? '';
        }

        $new_settings = [
            'server'      => in_array($server, ['bdix', 'global']) ? $server : 'bdix',
            'email'       => $email,
            'password'    => $password,
            'sender_name' => $sender_name ?: get_bloginfo('name'),
        ];

        update_option('procloudify_smtp_settings', $new_settings);

        wp_redirect(add_query_arg([
            'page'    => 'procloudify-smtp',
            'tab'     => 'settings',
            'updated' => '1',
        ], admin_url('admin.php')));
        exit;
    }

    public function handle_send_test() {
        if (!isset($_POST['procloudify_send_test'])) {
            return;
        }

        check_admin_referer('procloudify_send_test_action', 'procloudify_send_test_nonce');

        if (!current_user_can('manage_options')) {
            wp_die(__('Unauthorized access.', 'smtp-by-procloudify'));
        }

        $to_email = sanitize_email($_POST['test_to_email'] ?? '');
        $result = $this->send_test_mail($to_email);

        wp_redirect(add_query_arg([
            'page'        => 'procloudify-smtp',
            'tab'         => 'test',
            'test_status' => $result['status'],
            'test_msg'    => urlencode($result['message']),
        ], admin_url('admin.php')));
        exit;
    }

    public function ajax_send_test_email() {
        check_ajax_referer('procloudify_smtp_nonce', 'nonce');

        if (!current_user_can('manage_options')) {
            wp_send_json_error(['message' => __('Unauthorized access.', 'smtp-by-procloudify')]);
        }

        $to_email = sanitize_email($_POST['test_to_email'] ?? '');
        $result = $this->send_test_mail($to_email);

        if ($result['status'] === 'success') {
            wp_send_json_success(['message' => $result['message']]);
        } else {
            wp_send_json_error(['message' => $result['message']]);
        }
    }

    private function send_test_mail($to_email) {
        $settings = get_option('procloudify_smtp_settings', []);

        if (empty($settings['email']) || empty($settings['password'])) {
            return [
                'status'  => 'error',
                'message' => __('SMTP is not configured yet. Please complete the settings first.', 'smtp-by-procloudify')
            ];
        }

        if (empty($to_email) || !is_email($to_email)) {
            $to_email = $settings['email'];
        }

        $mail_error = '';
        $fail_hook = function($wp_error) use (&$mail_error) {
            $mail_error = $wp_error->get_error_message();
        };
        add_action('wp_mail_failed', $fail_hook);

        $server_label = ($settings['server'] ?? 'bdix') === 'global' ? 'Global Server' : 'BDIX Server';
        $sender_name  = !empty($settings['sender_name']) ? $settings['sender_name'] : get_bloginfo('name');
        $from_email   = $settings['email'];

        $subject   = sprintf(__('[Test] Procloudify SMTP — %s', 'smtp-by-procloudify'), date('H:i:s'));
        $site_name = get_bloginfo('name');
        $time_str  = date('r');

        $body = sprintf(
            "Hello,\n\nThis is a test email sent from %s via Procloudify SMTP (%s).\n\nSMTP Account: %s\nSender Name: %s\nPort: 587 (TLS)\nTimestamp: %s\n\nIf you received this message, your WordPress SMTP email delivery is working perfectly!",
            $site_name,
            $server_label,
            $from_email,
            $sender_name,
            $time_str
        );

        $sent = wp_mail($to_email, $subject, $body);

        remove_action('wp_mail_failed', $fail_hook);

        if ($sent) {
            return [
                'status'  => 'success',
                'message' => sprintf(__('Test email delivered successfully to %s!', 'smtp-by-procloudify'), $to_email)
            ];
        } else {
            $reason = $mail_error ?: __('Could not establish connection to the SMTP server. Please verify your email, password, and server selection.', 'smtp-by-procloudify');
            return [
                'status'  => 'error',
                'message' => sprintf(__('Test email delivery failed: %s', 'smtp-by-procloudify'), $reason)
            ];
        }
    }

    public function render_page() {
        if (!current_user_can('manage_options')) {
            return;
        }

        $tab = sanitize_key($_GET['tab'] ?? 'settings');
        if (!in_array($tab, ['settings', 'test'])) {
            $tab = 'settings';
        }

        $defaults = [
            'server'      => 'bdix',
            'email'       => '',
            'password'    => '',
            'sender_name' => get_bloginfo('name'),
        ];
        $settings = wp_parse_args(get_option('procloudify_smtp_settings', []), $defaults);

        $is_updated    = !empty($_GET['updated']);
        $test_status   = sanitize_key($_GET['test_status'] ?? '');
        $test_msg      = isset($_GET['test_msg']) ? sanitize_text_field(urldecode($_GET['test_msg'])) : '';
        $is_configured = !empty($settings['email']) && !empty($settings['password']);
        ?>
        <div class="wrap procloudify-wp-wrap">
            <h1 class="wp-heading-inline">
                <img src="<?php echo esc_url(PROCLOUDIFY_SMTP_URL . 'assets/images/icon-128x128.png'); ?>" alt="Procloudify" style="width: 26px; height: 26px; vertical-align: -5px; border-radius: 5px; margin-right: 6px;">
                <?php esc_html_e('Procloudify SMTP', 'smtp-by-procloudify'); ?>
                <span class="procloudify-badge-tag"><?php esc_html_e('For Procloudify Clients', 'smtp-by-procloudify'); ?></span>
            </h1>
            <hr class="wp-header-end">

            <nav class="nav-tab-wrapper wp-clearfix">
                <a href="<?php echo esc_url(admin_url('admin.php?page=procloudify-smtp&tab=settings')); ?>" class="nav-tab <?php echo $tab === 'settings' ? 'nav-tab-active' : ''; ?>">
                    <span class="dashicons dashicons-admin-generic"></span> <?php esc_html_e('SMTP Settings', 'smtp-by-procloudify'); ?>
                </a>
                <a href="<?php echo esc_url(admin_url('admin.php?page=procloudify-smtp&tab=test')); ?>" class="nav-tab <?php echo $tab === 'test' ? 'nav-tab-active' : ''; ?>">
                    <span class="dashicons dashicons-email-alt"></span> <?php esc_html_e('Test Email', 'smtp-by-procloudify'); ?>
                    <?php if ($is_configured): ?>
                        <span class="status-dot-configured" title="Configured"></span>
                    <?php endif; ?>
                </a>
            </nav>

            <?php if ($is_updated): ?>
                <div class="notice notice-success is-dismissible">
                    <p><strong><?php esc_html_e('SMTP settings saved successfully.', 'smtp-by-procloudify'); ?></strong> <a href="<?php echo esc_url(admin_url('admin.php?page=procloudify-smtp&tab=test')); ?>"><?php esc_html_e('Send a test email &rarr;', 'smtp-by-procloudify'); ?></a></p>
                </div>
            <?php endif; ?>

            <?php if (!empty($test_status) && !empty($test_msg)): ?>
                <div class="notice notice-<?php echo $test_status === 'success' ? 'success' : 'error'; ?> is-dismissible">
                    <p><strong><?php echo esc_html($test_msg); ?></strong></p>
                </div>
            <?php endif; ?>

            <div class="procloudify-main-layout">
                <div class="procloudify-content-area">
                    <?php if ($tab === 'settings'): ?>
                        <div class="postbox procloudify-postbox">
                            <div class="postbox-header">
                                <h2><?php esc_html_e('Configure SMTP Mailer', 'smtp-by-procloudify'); ?></h2>
                            </div>
                            <div class="inside">
                                <form method="post" action="">
                                    <?php wp_nonce_field('procloudify_save_settings_action', 'procloudify_save_settings_nonce'); ?>
                                    <input type="hidden" name="procloudify_save_settings" value="1">

                                    <table class="form-table" role="presentation">
                                        <tbody>
                                            <tr>
                                                <th scope="row">
                                                    <label for="server"><?php esc_html_e('SMTP Server', 'smtp-by-procloudify'); ?></label>
                                                </th>
                                                <td>
                                                    <select name="server" id="server" class="regular-text">
                                                        <option value="bdix" <?php selected($settings['server'] ?? 'bdix', 'bdix'); ?>>BDIX Server</option>
                                                        <option value="global" <?php selected($settings['server'] ?? '', 'global'); ?>>Global Server</option>
                                                    </select>
                                                    <p class="description"><?php esc_html_e('Choose your server from here.', 'smtp-by-procloudify'); ?></p>
                                                </td>
                                            </tr>

                                            <tr>
                                                <th scope="row">
                                                    <label for="email"><?php esc_html_e('Email', 'smtp-by-procloudify'); ?></label>
                                                </th>
                                                <td>
                                                    <input type="email" name="email" id="email" value="<?php echo esc_attr($settings['email'] ?? ''); ?>" class="regular-text" placeholder="info@abcshop.com" required>
                                                    <p class="description"><?php esc_html_e('Your full email address created in your Procloudify cPanel/Mail account.', 'smtp-by-procloudify'); ?></p>
                                                </td>
                                            </tr>

                                            <tr>
                                                <th scope="row">
                                                    <label for="password"><?php esc_html_e('Password', 'smtp-by-procloudify'); ?></label>
                                                </th>
                                                <td>
                                                    <div class="procloudify-pass-wrap">
                                                        <input type="password" name="password" id="password" value="<?php echo esc_attr($settings['password'] ?? ''); ?>" class="regular-text" placeholder="••••••••••••••••" required autocomplete="current-password">
                                                        <button type="button" class="button button-secondary procloudify-toggle-pw" title="<?php esc_attr_e('Show / Hide Password', 'smtp-by-procloudify'); ?>">
                                                            <span class="dashicons dashicons-visibility"></span>
                                                        </button>
                                                    </div>
                                                    <p class="description"><?php esc_html_e('The password for this email account.', 'smtp-by-procloudify'); ?></p>
                                                </td>
                                            </tr>

                                            <tr>
                                                <th scope="row">
                                                    <label for="sender_name"><?php esc_html_e('Sender Name', 'smtp-by-procloudify'); ?></label>
                                                </th>
                                                <td>
                                                    <input type="text" name="sender_name" id="sender_name" value="<?php echo esc_attr($settings['sender_name'] ?? get_bloginfo('name')); ?>" class="regular-text" placeholder="Abc Shop" required>
                                                    <p class="description"><?php esc_html_e('The name recipients will see when receiving emails (e.g. your website or business name).', 'smtp-by-procloudify'); ?></p>
                                                </td>
                                            </tr>
                                        </tbody>
                                    </table>

                                    <p class="submit">
                                        <button type="submit" class="button button-primary button-large">
                                            <span class="dashicons dashicons-saved"></span> <?php esc_html_e('Save Settings', 'smtp-by-procloudify'); ?>
                                        </button>
                                    </p>
                                </form>
                            </div>
                        </div>

                    <?php else: ?>
                        <div class="postbox procloudify-postbox">
                            <div class="postbox-header">
                                <h2><?php esc_html_e('Send a Test Email', 'smtp-by-procloudify'); ?></h2>
                            </div>
                            <div class="inside">
                                <?php if (!$is_configured): ?>
                                    <div class="notice notice-warning inline" style="margin: 10px 0 15px 0;">
                                        <p><?php esc_html_e('Please configure your Email and Password under the SMTP Settings tab before running a test.', 'smtp-by-procloudify'); ?> <a href="<?php echo esc_url(admin_url('admin.php?page=procloudify-smtp&tab=settings')); ?>"><?php esc_html_e('Go to Settings &rarr;', 'smtp-by-procloudify'); ?></a></p>
                                    </div>
                                <?php endif; ?>

                                <p style="margin-top: 5px;"><?php esc_html_e('Send a quick test email to verify that your WordPress site can successfully deliver emails through your Procloudify mail server.', 'smtp-by-procloudify'); ?></p>

                                <form method="post" action="" id="procloudify-test-form">
                                    <?php wp_nonce_field('procloudify_send_test_action', 'procloudify_send_test_nonce'); ?>
                                    <input type="hidden" name="procloudify_send_test" value="1">

                                    <table class="form-table" role="presentation">
                                        <tbody>
                                            <tr>
                                                <th scope="row">
                                                    <label for="test_to_email"><?php esc_html_e('Send To', 'smtp-by-procloudify'); ?></label>
                                                </th>
                                                <td>
                                                    <input type="email" name="test_to_email" id="test_to_email" value="<?php echo esc_attr(!empty($settings['email']) ? $settings['email'] : get_option('admin_email')); ?>" class="regular-text" required>
                                                    <p class="description"><?php esc_html_e('Enter the recipient email address where the test message should be delivered.', 'smtp-by-procloudify'); ?></p>
                                                </td>
                                            </tr>
                                        </tbody>
                                    </table>

                                    <div id="procloudify-ajax-alert" style="display:none; margin: 15px 0;"></div>

                                    <p class="submit">
                                        <button type="submit" id="btn-send-test" class="button button-primary button-large" <?php disabled(!$is_configured); ?>>
                                            <span class="dashicons dashicons-controls-play"></span> <span class="btn-label"><?php esc_html_e('Send Test Email', 'smtp-by-procloudify'); ?></span>
                                        </button>
                                    </p>
                                </form>
                            </div>
                        </div>

                    <?php endif; ?>
                </div>

                <div class="procloudify-sidebar-area">
                    <div class="postbox procloudify-sidebar-box">
                        <div class="postbox-header">
                            <h2>
                                <span class="dashicons dashicons-shield"></span>
                                <?php esc_html_e('Connection Status', 'smtp-by-procloudify'); ?>
                            </h2>
                        </div>
                        <div class="inside">
                            <div class="procloudify-status-banner <?php echo $is_configured ? 'status-banner-active' : 'status-banner-inactive'; ?>" style="margin-bottom: 0;">
                                <div class="banner-icon">
                                    <span class="dashicons <?php echo $is_configured ? 'dashicons-yes-alt' : 'dashicons-warning'; ?>"></span>
                                </div>
                                <div class="banner-content">
                                    <strong><?php echo $is_configured ? esc_html__('SMTP Configured', 'smtp-by-procloudify') : esc_html__('Setup Pending', 'smtp-by-procloudify'); ?></strong>
                                    <small><?php echo $is_configured ? esc_html__('Ready to deliver emails', 'smtp-by-procloudify') : esc_html__('Enter email & password', 'smtp-by-procloudify'); ?></small>
                                </div>
                            </div>
                        </div>
                    </div>

                    <div class="postbox procloudify-sidebar-box">
                        <div class="postbox-header">
                            <h2>
                                <span class="dashicons dashicons-sos"></span>
                                <?php esc_html_e('Procloudify Support', 'smtp-by-procloudify'); ?>
                            </h2>
                        </div>
                        <div class="inside">
                            <p class="support-text"><?php esc_html_e('Need assistance with your mail server, password reset, or domain DNS records (SPF, DKIM, DMARC)?', 'smtp-by-procloudify'); ?></p>
                            <a href="https://procloudify.com/help/" target="_blank" class="button button-secondary procloudify-sidebar-btn" rel="noopener noreferrer">
                                <span class="dashicons dashicons-external"></span>
                                <span><?php esc_html_e('Open Support Portal', 'smtp-by-procloudify'); ?></span>
                            </a>
                        </div>
                    </div>

                    <div class="postbox procloudify-sidebar-box procloudify-review-box">
                        <div class="postbox-header">
                            <h2>
                                <span class="dashicons dashicons-star-filled"></span>
                                <?php esc_html_e('Rate Us on Trustpilot', 'smtp-by-procloudify'); ?>
                            </h2>
                        </div>
                        <div class="inside">
                            <div class="procloudify-stars trustpilot-stars">
                                <span class="dashicons dashicons-star-filled"></span>
                                <span class="dashicons dashicons-star-filled"></span>
                                <span class="dashicons dashicons-star-filled"></span>
                                <span class="dashicons dashicons-star-filled"></span>
                                <span class="dashicons dashicons-star-filled"></span>
                            </div>
                            <p class="support-text"><?php esc_html_e('Enjoying fast & reliable email deliverability? Leave us a review on Trustpilot!', 'smtp-by-procloudify'); ?></p>
                            <a href="https://www.trustpilot.com/evaluate/procloudify.com?utm_medium=trustbox&utm_source=TrustBoxReviewCollector" target="_blank" class="button button-secondary procloudify-sidebar-btn procloudify-review-btn" rel="noopener noreferrer">
                                <span class="dashicons dashicons-star-filled"></span>
                                <span><?php esc_html_e('Submit Review on Trustpilot', 'smtp-by-procloudify'); ?></span>
                            </a>
                        </div>
                    </div>
                </div>
            </div>
        </div>
        <?php
    }
}
