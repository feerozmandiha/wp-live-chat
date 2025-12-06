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
            if (!empty($result)) {
                return $result;
            }
            
            if (strpos($_SERVER['REQUEST_URI'], '/wp-json/wp-live-chat/') !== false) {
                return true;
            }
            
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

    // private function should_initialize(): bool {
    //     // فقط در صفحات مورد نیاز initialize کن
    //     if (is_admin()) {
    //         return true;
    //     }
        
    //     // در فرانت‌اند فقط اگر shortcode یا block استفاده شده
    //     global $post;
    //     if ($post && (
    //         has_shortcode($post->post_content, 'wp_live_chat') ||
    //         has_block('wp-live-chat/chat-widget', $post) ||
    //         has_block('wp-live-chat/chat-button', $post)
    //     )) {
    //         return true;
    //     }
        
    //     // یا اگر کاربر لاگین کرده و ادمین است
    //     if (is_user_logged_in() && current_user_can('manage_options')) {
    //         return true;
    //     }
        
    //     // در حالت دیباگ همیشه لود شود
    //     if (defined('WP_DEBUG') && WP_DEBUG) {
    //         return true;
    //     }
        
    //     return false;
    // }
    
    private function register_autoloader(): void {
        spl_autoload_register(function ($class) {
            $namespace = 'WP_Live_Chat\\';
            
            if (strpos($class, $namespace) !== 0) {
                return;
            }
            
            $class_name = str_replace($namespace, '', $class);
            $file_path = WP_LIVE_CHAT_PLUGIN_PATH . 'includes/' . str_replace('\\', '/', $class_name) . '.php';
            
            if (file_exists($file_path)) {
                require_once $file_path;
            } else {
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
        
        // اصلاح: بارگذاری textdomain در action init
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
            'pusher_auth' => Pusher_Auth::class
        ];

        // اضافه کردن Conversation_Flow به صورت lazy - نه در init_services
        // زیرا نیاز به session_id دارد که بعداً ایجاد می‌شود
        $services['conversation_flow'] = null; // اینجا null می‌گذاریم

        foreach ($services as $key => $class) {
            if ($key === 'conversation_flow') {
                // این سرویس را بعداً ایجاد می‌کنیم
                continue;
            }
            
            if (class_exists($class)) {
                $this->services[$key] = new $class();
                if (method_exists($this->services[$key], 'init')) {
                    $this->services[$key]->init();
                }
            }
        }
        
        // اضافه کردن method برای ایجاد lazy Conversation_Flow
        $this->services['conversation_flow'] = function($session_id = null) {
            if (!class_exists('WP_Live_Chat\Conversation_Flow')) {
                return null;
            }
            
            try {
                // اگر session_id داده نشده، یک session موقت ایجاد کن
                if (empty($session_id)) {
                    if (!empty($_COOKIE['wp_live_chat_session'])) {
                        $session_id = sanitize_text_field($_COOKIE['wp_live_chat_session']);
                    } else {
                        $session_id = 'chat_' . wp_generate_uuid4();
                    }
                }
                
                return new Conversation_Flow($session_id);
            } catch (\Exception $e) {
                error_log('WP Live Chat: Failed to create Conversation_Flow: ' . $e->getMessage());
                return null;
            }
        };
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
        if ($service_name === 'conversation_flow') {
            // اگر conversation_flow است، closure را اجرا کن
            if (isset($this->services[$service_name]) && is_callable($this->services[$service_name])) {
                // session_id را از frontend بگیر یا ایجاد کن
                $session_id = null;
                
                // اگر در frontend هستیم، session_id را از cookie بگیر
                if (!is_admin()) {
                    if (!empty($_COOKIE['wp_live_chat_session'])) {
                        $session_id = sanitize_text_field($_COOKIE['wp_live_chat_session']);
                    }
                }
                
                return call_user_func($this->services[$service_name], $session_id);
            }
            return null;
        }
        
        return $this->services[$service_name] ?? null;
    }
    
    public function get_services(): array {
        return $this->services;
    }
}