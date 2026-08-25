<?php

if (!defined('WP_UNINSTALL_PLUGIN')) {
    exit;
}

delete_option('procloudify_smtp_settings');
