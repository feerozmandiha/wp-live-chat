<?php
namespace WP_Live_Chat;

use Pusher\Pusher;
use Throwable;

class Plugin {
    private static $instance = null;
    private $services = [];

    public static function get_instance(): self {
        if (is_null(self::$instance)) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    private function __construct() {}

    public function init(): void {
        add_filter('rest_authentication_errors', function($result) {
            if (!empty($result)) return $result;
            if (strpos($_SERVER['REQUEST_URI'], '/wp-json/wp-live-chat/') !== false) return true;
            if (strpos($_SERVER['REQUEST_URI'], 'admin-ajax.php') !== false && 
                isset($_REQUEST['action']) && 
                strpos($_REQUEST['action'], 'wp_live_chat') === 0) {
                return true;
            }
            return $result;
        });

        $this->register_autoloader();
        $this->register_hooks();
        $this->init_services();
        do_action('wp_live_chat_loaded');
    }

    private function register_autoloader(): void {
        spl_autoload_register(function ($class) {
            $namespace = 'WP_Live_Chat\\';
            if (strpos($class, $namespace) !== 0) return;
            $class_name = str_replace($namespace, '', $class);
            $file_path = WP_LIVE_CHAT_PLUGIN_PATH . 'includes/' . str_replace('\\', '/', $class_name) . '.php';
            if (file_exists($file_path)) {
                require_once $file_path;
            }
        });
    }

    private function register_hooks(): void {
        register_activation_hook(WP_LIVE_CHAT_PLUGIN_FILE, [$this, 'activate']);
        register_deactivation_hook(WP_LIVE_CHAT_PLUGIN_FILE, [$this, 'deactivate']);
        add_action('init', [$this, 'load_textdomain']);
    }

    private function init_services(): void {
        // فقط سرویس‌های ضروری در frontend
        $services = ['core' => Core::class, 'database' => Database::class, 'logger' => Logger::class, 'cache' => Cache_Manager::class];

        if (is_admin()) {
            // سرویس‌های ادمین
            $admin_services = [
                'admin' => Admin::class,
                'chat_admin' => Chat_Admin::class,
                'blocks' => Blocks::class,
                'rest_api' => REST_API::class,
                'pusher_service' => Pusher_Service::class,
                'pusher_auth' => Pusher_Auth::class
            ];
            $services = array_merge($services, $admin_services);
        } else {
            // فقط در صورت فعال بودن چت
            if ((bool) get_option('wp_live_chat_enable_chat', true)) {
                $frontend_services = [
                    'chat_frontend' => Chat_Frontend::class
                ];
                $services = array_merge($services, $frontend_services);
            }
        }

        foreach ($services as $key => $class) {
            if (class_exists($class)) {
                $this->services[$key] = new $class();
                if (method_exists($this->services[$key], 'init')) {
                    $this->services[$key]->init();
                }
            }
        }
    }

    // ... (بقیه متدها activate, deactivate, load_textdomain, get_service بدون تغییر)
    public function activate(): void {
        $database = new Database();
        $database->create_tables();
        if (!wp_next_scheduled('wp_live_chat_cleanup')) {
            wp_schedule_event(time(), 'daily', 'wp_live_chat_cleanup');
        }
        add_option('wp_live_chat_enable_chat', true);
        add_option('wp_live_chat_pusher_cluster', 'mt1');
        update_option('wp_live_chat_version', WP_LIVE_CHAT_VERSION);
        update_option('wp_live_chat_installed_time', time());
        $logger = new Logger();
        $logger->info('WP Live Chat activated successfully');
    }

    public function deactivate(): void {
        wp_clear_scheduled_hook('wp_live_chat_cleanup');
    }

    public function load_textdomain(): void {
        load_plugin_textdomain(
            'wp-live-chat',
            false,
            dirname(plugin_basename(WP_LIVE_CHAT_PLUGIN_FILE)) . '/languages'
        );
    }

    public function get_service(string $service_name) {
        return $this->services[$service_name] ?? null;
    }
}