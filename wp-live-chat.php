<?php
/**
 * Plugin Name: WP Live Chat
 * Plugin URI: https://example.com/wp-live-chat
 * Description: یک سیستم چت آنلاین سبک و مدرن با قابلیت اتصال به Pusher
 * Version: 1.0.1
 * Author: Your Name
 * License: GPL v2 or later
 * Text Domain: wp-live-chat
 * Requires at least: 6.8
 * Requires PHP: 8.0
 */

// جلوگیری از دسترسی مستقیم
defined('ABSPATH') || exit;

// تعریف ثابت‌های اصلی
define('WP_LIVE_CHAT_VERSION', '1.0.1');
define('WP_LIVE_CHAT_PLUGIN_URL', plugin_dir_url(__FILE__));
define('WP_LIVE_CHAT_PLUGIN_PATH', plugin_dir_path(__FILE__));
define('WP_LIVE_CHAT_PLUGIN_FILE', __FILE__);

// بررسی نسخه PHP
if (version_compare(PHP_VERSION, '8.0.0', '<')) {
    add_action('admin_notices', function() {
        echo '<div class="error"><p>WP Live Chat نیاز به PHP 8.0 یا بالاتر دارد.</p></div>';
    });
    return;
}

// بارگذاری اتولودر Composer
$autoloader = WP_LIVE_CHAT_PLUGIN_PATH . 'vendor/autoload.php';
if (file_exists($autoloader)) {
    require_once $autoloader;
} else {
    add_action('admin_notices', function() {
        echo '<div class="error"><p>فایل‌های وابستگی WP Live Chat یافت نشد. لطفا composer install را اجرا کنید.</p></div>';
    });
    return;
}

// راه‌اندازی افزونه
add_action('plugins_loaded', function() {
    \WP_Live_Chat\Plugin::get_instance()->init();
});