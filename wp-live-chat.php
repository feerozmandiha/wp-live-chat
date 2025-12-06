<?php
/**
 * Plugin Name: WP Live Chat
 * Description: چت زنده با پشتیبانی Pusher — نسخهٔ یکپارچه‌شده
 * Version: 1.2.2
 * Text Domain: wp-live-chat
 */

defined('ABSPATH') || exit;

define('WP_LIVE_CHAT_VERSION', '1.2.2');
define('WP_LIVE_CHAT_PLUGIN_FILE', __FILE__);
define('WP_LIVE_CHAT_PLUGIN_PATH', plugin_dir_path(__FILE__));
define('WP_LIVE_CHAT_PLUGIN_URL', plugin_dir_url(__FILE__));

if (version_compare(PHP_VERSION, '8.0.0', '<')) {
    add_action('admin_notices', function(){
        echo '<div class="error"><p>WP Live Chat نیاز به PHP 8.0 یا بالاتر دارد.</p></div>';
    });
    return;
}

// composer autoload (pusher library)
$autoload = WP_LIVE_CHAT_PLUGIN_PATH . 'vendor/autoload.php';
if (file_exists($autoload)) {
    require_once $autoload;
}

require_once WP_LIVE_CHAT_PLUGIN_PATH . 'includes/Plugin.php';

// boot
add_action('plugins_loaded', function() {
    \WP_Live_Chat\Plugin::get_instance()->init();
});
