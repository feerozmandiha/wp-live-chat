<?php
namespace WP_Live_Chat;

class Plugin {
    
    private static $instance = null;
    private $services = [];
    
    public static function get_instance(): self {
        if (is_null(self::$instance)) {
            self::$instance = new self();
        }
        return self::$instance;
    }
    
    private function __construct() {
        // جلوگیری از ایجاد نمونه مستقیم
    }
    
    public function init(): void {
            // اجازه دادن به REST API قبل از هر چیزی
    add_filter('rest_authentication_errors', function($result) {
        // اگر قبلاً خطایی وجود دارد، برگردان
        if (!empty($result)) {
            return $result;
        }
        
        // اجازه دادن به درخواست‌های افزونه چت
        if (strpos($_SERVER['REQUEST_URI'], '/wp-json/wp-live-chat/') !== false) {
            return true;
        }
        
        // اجازه دادن به admin-ajax.php
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
            
            if (strpos($class, $namespace) !== 0) {
                return;
            }
            
            $class_name = str_replace($namespace, '', $class);
            $file_path = WP_LIVE_CHAT_PLUGIN_PATH . 'includes/' . str_replace('\\', '/', $class_name) . '.php';
            
            // همچنین چک کردن برای فایل‌های داخل subdirectories
            if (file_exists($file_path)) {
                require_once $file_path;
            } else {
                // اگر فایل پیدا نشد، در subdirectories جستجو کن
                $file_path = WP_LIVE_CHAT_PLUGIN_PATH . 'includes/' . $class_name . '.php';
                if (file_exists($file_path)) {
                    require_once $file_path;
                }
            }
        });
    }
    
    private function register_hooks(): void {
        register_activation_hook(WP_LIVE_CHAT_PLUGIN_FILE, [$this, 'activate']);
        register_deactivation_hook(WP_LIVE_CHAT_PLUGIN_FILE, [$this, 'deactivate']);
        
        add_action('init', [$this, 'load_textdomain']);
    }
    
    private function init_services(): void {
        $services = [
            'core' => Core::class,
            'pusher_service' => Pusher_Service::class,
            'admin' => Admin::class,
            'chat_admin' => Chat_Admin::class,
            'frontend' => Frontend::class,
            'blocks' => Blocks::class,
            'database' => Database::class,
            'logger' => Logger::class,
            'cache' => Cache_Manager::class,
            'rest_api' => REST_API::class,
            'chat_frontend' => Chat_Frontend::class,
            'pusher_auth' => \WP_Live_Chat\Pusher_Auth::class
        ];

        foreach ($services as $key => $class) {
            if (class_exists($class)) {
                $this->services[$key] = new $class();
                if (method_exists($this->services[$key], 'init')) {
                    $this->services[$key]->init();
                }
            }
        }
    }
    
    public function activate(): void {
        $database = new Database();
        $database->create_tables();
        
        if (!wp_next_scheduled('wp_live_chat_cleanup')) {
            wp_schedule_event(time(), 'daily', 'wp_live_chat_cleanup');
        }

            // اضافه کردن options اولیه
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
    
    public function get_services(): array {
        return $this->services;
    }
}