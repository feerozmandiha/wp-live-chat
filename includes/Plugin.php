<?php
namespace WP_Live_Chat;

class Plugin {
    
    private static $instance = null;
    private $services = [];
    private $initialized = false;

    
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
        // جلوگیری از init چندباره
        if ($this->initialized) {
            return;
        }
        
        // غیرفعال کردن برخی عملیات برای صفحات غیرضروری
        if (!$this->should_initialize()) {
            return;
        }
        
        $this->register_autoloader();
        $this->register_hooks();
        $this->init_services();
        
        $this->initialized = true;
        do_action('wp_live_chat_loaded');
    }

    private function should_initialize(): bool {
        // فقط در صفحات مورد نیاز initialize کن
        if (is_admin()) {
            return true;
        }
        
        // در فرانت‌اند فقط اگر shortcode یا block استفاده شده
        global $post;
        if ($post && (
            has_shortcode($post->post_content, 'wp_live_chat') ||
            has_block('wp-live-chat/chat-widget', $post) ||
            has_block('wp-live-chat/chat-button', $post)
        )) {
            return true;
        }
        
        // یا اگر کاربر لاگین کرده و ادمین است
        if (is_user_logged_in() && current_user_can('manage_options')) {
            return true;
        }
        
        // در حالت دیباگ همیشه لود شود
        if (defined('WP_DEBUG') && WP_DEBUG) {
            return true;
        }
        
        return false;
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
            'database' => Database::class,
            'pusher_service' => Pusher_Service::class,
            'cache' => Cache_Manager::class,
        ];
        
        // در فرانت‌اند فقط سرویس‌های ضروری
        if (!is_admin()) {
            // $services['frontend'] = Frontend::class;
            // $services['chat_frontend'] = Chat_Frontend::class;
            // $services['conversation_flow'] = Conversation_Flow::class;
        }
        
        // در ادمین پنل
        if (is_admin()) {
            $services['admin'] = Admin::class;
            $services['chat_admin'] = Chat_Admin::class;
            $services['rest_api'] = REST_API::class;
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