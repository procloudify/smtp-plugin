<?php
/**
 * Plugin Name: Procloudify SMTP
 * Plugin URI: https://procloudify.com
 * Description: Dedicated high-speed SMTP mail routing plugin for Procloudify clients.
 * Version: 1.0.0
 * Author: Procloudify
 * Author URI: https://procloudify.com
 * License: GPL-2.0-or-later
 * Text Domain: smtp-by-procloudify
 */

if (!defined('ABSPATH')) {
    exit;
}

define('PROCLOUDIFY_SMTP_VERSION', '1.0.0');
define('PROCLOUDIFY_SMTP_FILE', __FILE__);
define('PROCLOUDIFY_SMTP_PATH', plugin_dir_path(__FILE__));
define('PROCLOUDIFY_SMTP_URL', plugin_dir_url(__FILE__));
define('PROCLOUDIFY_SMTP_GITHUB_REPO', 'procloudify/smtp-plugin');

require_once PROCLOUDIFY_SMTP_PATH . 'includes/class-mailer.php';
require_once PROCLOUDIFY_SMTP_PATH . 'includes/class-admin.php';
require_once PROCLOUDIFY_SMTP_PATH . 'includes/class-updater.php';

register_activation_hook(__FILE__, function() {
    $defaults = [
        'server'      => 'bdix',
        'email'       => get_option('admin_email', ''),
        'password'    => '',
        'sender_name' => get_bloginfo('name'),
    ];
    $existing = get_option('procloudify_smtp_settings', []);
    update_option('procloudify_smtp_settings', wp_parse_args($existing, $defaults));
});

function procloudify_smtp_init() {
    new Procloudify_SMTP_Mailer();
    new Procloudify_SMTP_Admin();

    if (is_admin()) {
        new Procloudify_SMTP_Updater(PROCLOUDIFY_SMTP_FILE, PROCLOUDIFY_SMTP_GITHUB_REPO);
    }
}
add_action('plugins_loaded', 'procloudify_smtp_init');
