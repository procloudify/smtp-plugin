<?php
if (!defined('ABSPATH')) {
    exit;
}

class Procloudify_SMTP_Mailer {

    public function __construct() {
        add_action('phpmailer_init', [$this, 'configure_phpmailer'], 9999);
    }

    public function configure_phpmailer($phpmailer) {
        $settings = get_option('procloudify_smtp_settings', []);

        $email       = !empty($settings['email']) ? trim($settings['email']) : '';
        $password    = !empty($settings['password']) ? $settings['password'] : '';
        $sender_name = !empty($settings['sender_name']) ? trim($settings['sender_name']) : get_bloginfo('name');
        $server      = !empty($settings['server']) ? $settings['server'] : 'bdix';

        if (empty($email) || empty($password)) {
            return;
        }

        $domain = '';
        if (strpos($email, '@') !== false) {
            $parts = explode('@', $email);
            $domain = end($parts);
        }

        if ($server === 'global') {
            $host = 'mail.procloudify.com';
        } else {
            $host = !empty($domain) ? 'mail.' . $domain : 'mail.procloudify.com';
        }

        $phpmailer->isSMTP();
        $phpmailer->Host        = $host;
        $phpmailer->SMTPAuth    = true;
        $phpmailer->Port        = 587;
        $phpmailer->Username    = $email;
        $phpmailer->Password    = $password;
        $phpmailer->SMTPSecure  = 'tls';
        $phpmailer->SMTPAutoTLS = true;
        $phpmailer->Timeout     = 15;

        $phpmailer->From     = $email;
        $phpmailer->FromName = $sender_name;
        $phpmailer->Sender   = $email;
    }
}
