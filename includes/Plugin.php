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
        $this->register_autoloader();
        $this->register_hooks();
        $this->init_services();
        
        do_action('wp_live_chat_loaded');
    }
    
    private function register_autoloader(): void {
        spl_autoload_register(function ($class) {
            $namespace = 'WP_Live_Chat\\';
            
            // بررسی آیا کلاس مربوط به namespace ما است
            if (strpos($class, $namespace) !== 0) {
                return;
            }
            
            // تبدیل namespace به مسیر فایل
            $class_name = str_replace($namespace, '', $class);
            $file_path = WP_LIVE_CHAT_PLUGIN_PATH . 'includes/' . 'class-' . strtolower(str_replace('_', '-', $class_name)) . '.php';
            
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
    ];
    
    foreach ($services as $key => $class) {
        if (class_exists($class)) {
            error_log("Initializing service: {$key} => {$class}");
            
            // برای کلاس‌های Singleton از get_instance استفاده کنید
            if ($class === Logger::class) {
                $this->services[$key] = $class::get_instance();
                error_log("✅ Logger initialized as singleton");
            } else {
                $this->services[$key] = new $class();
                error_log("✅ {$class} initialized as new instance");
            }
            
            if (method_exists($this->services[$key], 'init')) {
                $this->services[$key]->init();
                error_log("✅ {$class} init() method called");
            } else {
                error_log("⚠️ {$class} does not have init() method");
            }
        } else {
            error_log("❌ Class not found: {$class}");
        }
    }
    
    error_log("🎯 Total services initialized: " . count($this->services));
}
    
    public function activate(): void {
        // ایجاد جداول دیتابیس
        $database = new Database();
        $table_status = $database->get_table_status();
        
        // اگر جداول وجود ندارند، ایجاد شوند
        foreach ($table_status as $table_name => $exists) {
            if (!$exists) {
                $database->create_tables();
                break;
            }
        }
        
        // ثبت رویداد پاک‌سازی دوره‌ای
        if (!wp_next_scheduled('wp_live_chat_cleanup')) {
            wp_schedule_event(time(), 'daily', 'wp_live_chat_cleanup');
        }
        
        update_option('wp_live_chat_version', WP_LIVE_CHAT_VERSION);
        update_option('wp_live_chat_installed_time', time());
        
        // لاگ فعال‌سازی
        error_log('WP Live Chat activated successfully');
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