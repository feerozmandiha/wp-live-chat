<?php
/**
 * Plugin Name: WP Live Chat
 * Plugin URI: https://github.com/feerozmandiha/wp-live-chat
 * Description: یک سیستم چت آنلاین سبک و مدرن با قابلیت اتصال به Pusher
 * Version: 1.2.3
 * Author: Your Name
 * License: GPL v2 or later
 * Text Domain: wp-live-chat
 * Requires at least: 6.8
 * Requires PHP: 8.0
 */
defined('ABSPATH') || exit;

define('WP_LIVE_CHAT_VERSION', '1.2.3');
define('WP_LIVE_CHAT_PLUGIN_URL', plugin_dir_url(__FILE__));
define('WP_LIVE_CHAT_PLUGIN_PATH', plugin_dir_path(__FILE__));
define('WP_LIVE_CHAT_PLUGIN_FILE', __FILE__);

if (version_compare(PHP_VERSION, '8.0.0', '<')) {
    add_action('admin_notices', function() {
        echo '<div class="error"><p>WP Live Chat نیاز به PHP 8.0 یا بالاتر دارد.</p></div>';
    });
    return;
}

$autoloader = WP_LIVE_CHAT_PLUGIN_PATH . 'vendor/autoload.php';
if (!file_exists($autoloader)) {
    add_action('admin_notices', function() {
        echo '<div class="error"><p>فایل‌های وابستگی WP Live Chat یافت نشد. لطفاً <code>composer install</code> را اجرا کنید.</p></div>';
    });
    return;
}
require_once $autoloader;

// بررسی فعال بودن چت + عدم اجرای افزونه در REST API یا AJAX غیرمرتبط
function wp_live_chat_should_load(): bool {
    // اگر در ادمین هستیم، بارگذاری شود
    if (is_admin()) return true;
    // اگر AJAX مربوط به چت است، بارگذاری شود
    if (wp_doing_ajax() && isset($_REQUEST['action']) && strpos($_REQUEST['action'], 'wp_live_chat') === 0) {
        return true;
    }
    // اگر چت فعال است، بارگذاری شود
    return (bool) get_option('wp_live_chat_enable_chat', true);
}

if (wp_live_chat_should_load()) {
    add_action('plugins_loaded', function() {
        \WP_Live_Chat\Plugin::get_instance()->init();
    });
}