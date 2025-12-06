<?php
namespace WP_Live_Chat;
defined('ABSPATH') || exit;

class Plugin {
    private static ?Plugin $instance = null;
    private array $services = [];

    public static function get_instance(): Plugin {
        if (self::$instance === null) {
            self::$instance = new Plugin();
        }
        return self::$instance;
    }

    public function init(): void {
        // register autoload for includes/ classes (PSR-4 assumed handled by composer,
        // but keep fallback)
        spl_autoload_register([$this, 'autoload']);

        // instantiate core services (order matters: database first, logger, cache, pusher, then features)
        $this->services['logger'] = new Logger();
        $this->services['logger']->init();

        $this->services['database'] = new Database();
        // Database init (create tables on activation handled below)
        if (method_exists($this->services['database'], 'init')) {
            $this->services['database']->init();
        }

        $this->services['cache'] = new Cache_Manager();
        $this->services['cache']->init();

        $this->services['pusher_service'] = new Pusher_Service();
        $this->services['pusher_service']->init();

        // Admin & Frontend & Chat handlers
        $this->services['admin'] = new Admin();
        $this->services['admin']->init();

        $this->services['chat_admin'] = new Chat_Admin();
        $this->services['chat_admin']->init();

        $this->services['chat_frontend'] = new Chat_Frontend();
        $this->services['chat_frontend']->init();

        $this->services['blocks'] = new Blocks();
        $this->services['blocks']->init();

        $this->services['pusher_auth'] = new Pusher_Auth();
        $this->services['pusher_auth']->init();

        // activation/deactivation hooks
        register_activation_hook(WP_LIVE_CHAT_PLUGIN_FILE, [$this, 'activate']);
        register_deactivation_hook(WP_LIVE_CHAT_PLUGIN_FILE, [$this, 'deactivate']);

        // textdomain
        add_action('init', [$this, 'load_textdomain']);
    }

    private function autoload(string $class): void {
        $prefix = __NAMESPACE__ . '\\';
        if (strpos($class, $prefix) !== 0) return;
        $relative = substr($class, strlen($prefix));
        $file = WP_LIVE_CHAT_PLUGIN_PATH . 'includes/' . str_replace('\\', '/', $relative) . '.php';
        if (file_exists($file)) require_once $file;
    }

    public function activate(): void {
        $this->services['database']->create_tables();
        if (!wp_next_scheduled('wp_live_chat_cleanup')) {
            wp_schedule_event(time(), 'daily', 'wp_live_chat_cleanup');
        }
        update_option('wp_live_chat_version', WP_LIVE_CHAT_VERSION);
    }

    public function deactivate(): void {
        wp_clear_scheduled_hook('wp_live_chat_cleanup');
    }

    public function load_textdomain(): void {
        load_plugin_textdomain('wp-live-chat', false, dirname(plugin_basename(WP_LIVE_CHAT_PLUGIN_FILE)) . '/languages');
    }

    public function get_service(string $name) {
        return $this->services[$name] ?? null;
    }
}
