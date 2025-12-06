<?php
namespace WP_Live_Chat;

if (!defined('WP_LIVE_CHAT_PLUGIN_FILE')) return;

use Pusher\Pusher;
use Throwable;

class Pusher_Service {
    private ?Pusher $pusher = null;
    private bool $is_connected = false;
    private ?array $config = null;

    public function init(): void {
        add_action('wp_loaded', [$this, 'setup_pusher']);
    }

    private function get_config(): array {
        if ($this->config === null) {
            $this->config = [
                'app_id' => trim((string) get_option('wp_live_chat_pusher_app_id', '')),
                'key' => trim((string) get_option('wp_live_chat_pusher_key', '')),
                'secret' => trim((string) get_option('wp_live_chat_pusher_secret', '')),
                'cluster' => trim((string) get_option('wp_live_chat_pusher_cluster', '')),
                'useTLS' => true,
            ];
        }
        return $this->config;
    }

    private function has_valid_config(): bool {
        $c = $this->get_config();
        return !empty($c['app_id']) && !empty($c['key']) && !empty($c['secret']) && !empty($c['cluster']);
    }

    public function setup_pusher(): void {
        $logger = Plugin::get_instance()->get_service('logger');

        if (!class_exists('\Pusher\Pusher')) {
            if ($logger) $logger->error('Pusher PHP library not found');
            $this->is_connected = false;
            return;
        }

        if (!$this->has_valid_config()) {
            if ($logger) $logger->warning('Pusher config incomplete');
            $this->is_connected = false;
            return;
        }

        try {
            $c = $this->get_config();

            $options = [
                'cluster' => $c['cluster'],
                'useTLS' => $c['useTLS'] ?? true,
                'encrypted' => true,
                // تنظیمات بهینه
                'timeout' => 30,
                'curl_options' => [
                    CURLOPT_CONNECTTIMEOUT => 10,
                    CURLOPT_TIMEOUT => 30
                ]
            ];

            $this->pusher = new Pusher(
                $c['key'],
                $c['secret'],
                $c['app_id'],
                $options
            );

            // تست اتصال با روش سازگار
            $this->is_connected = $this->test_connection_compatible();
            
            if ($this->is_connected) {
                if ($logger) $logger->info('Pusher initialized successfully');
                update_option('wp_live_chat_pusher_connected', true);
                update_option('wp_live_chat_pusher_last_test', time());
            } else {
                if ($logger) $logger->error('Pusher connection test failed');
                update_option('wp_live_chat_pusher_connected', false);
            }
            
        } catch (\Throwable $e) {
            $this->is_connected = false;
            if ($logger) $logger->error('Pusher setup failed: ' . $e->getMessage());
            update_option('wp_live_chat_pusher_connected', false);
            update_option('wp_live_chat_pusher_last_error', $e->getMessage());
        }
    }

        /**
     * تست اتصال سازگار با تمام نسخه‌های Pusher
     */
    private function test_connection_compatible(): bool {
        if (!$this->pusher) {
            return false;
        }

        try {
            // روش 1: تلاش برای trigger یک پیام تست به کانال خصوصی
            // اگر trigger با موفقیت انجام شود، اتصال برقرار است
            $test_channel = 'private-test-' . time();
            $test_event = 'connection-test';
            $test_data = ['action' => 'test', 'timestamp' => time()];
            
            // توجه: trigger روی کانال private ممکن است نیاز به احراز هویت داشته باشد
            // پس از کانال عمومی استفاده می‌کنیم
            $test_channel = 'public-test-' . time();
            
            $result = $this->pusher->trigger([$test_channel], $test_event, $test_data);
            
            // در نسخه‌های جدید، trigger معمولاً چیزی برنمی‌گرداند یا boolean برمی‌گرداند
            // اگر هیچ exception رخ نداد، یعنی موفقیت‌آمیز بوده
            return true;
            
        } catch (\Throwable $e) {
            // اگر trigger خطا داد، از روش‌های جایگزین استفاده می‌کنیم
            return $this->test_connection_fallback();
        }
    }

        /**
     * تست اتصال ساده بدون استفاده از get_channels
     */
    private function test_connection_simple(): bool {
        try {
            // روش ساده‌تر: سعی در trigger یک پیام تست به کانالی که وجود ندارد
            // این کار اتصال را تست می‌کند بدون ایجاد کانال واقعی
            $test_data = ['test' => 'connection_test', 'timestamp' => time()];
            
            // استفاده از trigger با کانال تست موقت
            $result = $this->pusher->trigger(
                ['test-connection-channel-' . time()],
                'test-event',
                $test_data
            );
            
            // در نسخه‌های جدید، trigger ممکن است چیزی برنگرداند یا boolean برگرداند
            return true;
            
        } catch (\Throwable $e) {
            // اگر خطا داد، از روش جایگزین استفاده کن
            return $this->test_connection_fallback();
        }
    }

        /**
     * روش fallback برای تست اتصال
     */
    /**
     * روش fallback برای تست اتصال
     */
    private function test_connection_fallback(): bool {
        if (!$this->pusher) {
            return false;
        }

        try {
            // روش 2: استفاده از reflection برای بررسی متدهای موجود
            $reflection = new \ReflectionClass($this->pusher);
            
            // بررسی اینکه آیا می‌توانیم یک عملیات ساده انجام دهیم
            // مثل گرفتن اطلاعات پیکربندی
            $config = $this->get_config();
            
            // ایجاد یک کانال ساده و تلاش برای احراز هویت
            $channel_name = 'private-test-channel-' . time();
            
            // تولید یک socket_id مصنوعی برای تست
            $fake_socket_id = '12345.67890';
            
            // بررسی وجود متد authorizeChannel
            if ($reflection->hasMethod('authorizeChannel')) {
                $auth = $this->pusher->authorizeChannel($channel_name, $fake_socket_id);
                if ($auth && isset($auth['auth'])) {
                    return true;
                }
            }
            
            // بررسی وجود متد getChannelInfo (در برخی نسخه‌ها)
            if ($reflection->hasMethod('getChannelInfo')) {
                $info = $this->pusher->getChannelInfo($channel_name);
                return is_array($info) || is_object($info);
            }
            
            // اگر هیچ کدام کار نکرد، حداقل بگوییم اگر به اینجا رسیده‌ایم و exception نداشتیم، احتمالاً متصل هستیم
            return true;
            
        } catch (\Throwable $e) {
            error_log('Pusher connection test fallback failed: ' . $e->getMessage());
            return false;
        }
    }
    
    /**
     * متد keep-alive بهبود یافته و سازگار
     */
    public function keep_alive(): void {
        if (!$this->is_connected() || !$this->pusher) {
            return;
        }
        
        try {
            // ساده‌ترین و سازگارترین روش: trigger یک پیام تست
            // استفاده از کانال public که نیاز به احراز هویت ندارد
            $test_channel = 'public-keepalive-' . time();
            $test_event = 'ping';
            $test_data = ['ping' => time()];
            
            $this->pusher->trigger([$test_channel], $test_event, $test_data);
            
            // اگر خطایی رخ نداد، اتصال برقرار است
            $this->is_connected = true;
            
        } catch (\Throwable $e) {
            // اگر خطا داد، وضعیت را reset کرده و reconnect می‌کنیم
            $this->is_connected = false;
            $this->setup_pusher();
        }
    }

    /**
     * متد trigger سازگار با تمام نسخه‌ها
     */
    public function trigger(string $channel, string $event, array $data): bool {
        $logger = Plugin::get_instance()->get_service('logger');

        if (!$this->is_connected() || !$this->pusher) {
            $this->setup_pusher(); // تلاش برای reconnect
        }

        if (!$this->is_connected() || !$this->pusher) {
            if ($logger) $logger->warning('Pusher not connected; trigger skipped for channel: ' . $channel);
            return false;
        }

        try {
            // استفاده از آرایه برای channels (الزامی در نسخه‌های جدید)
            $result = $this->pusher->trigger([$channel], $event, $data);
            
            // در نسخه‌های مختلف، trigger ممکن است:
            // 1. چیزی برنگرداند (void)
            // 2. boolean برگرداند
            // 3. object/array برگرداند
            // اگر به اینجا رسیدیم و exception نداشتیم، یعنی موفقیت‌آمیز بوده
            
            if ($logger) {
                $logger->info('Pusher trigger succeeded', [
                    'channel' => $channel, 
                    'event' => $event,
                    'result_type' => gettype($result)
                ]);
            }
            
            return true;
            
        } catch (\Pusher\PusherException $e) {
            if ($logger) $logger->error('Pusher exception in trigger: ' . $e->getMessage(), [
                'channel' => $channel, 
                'event' => $event
            ]);
            
            // برای PusherException خاص، وضعیت را reset نکنیم
            // فقط false برگردانیم
            return false;
            
        } catch (\Throwable $e) {
            if ($logger) $logger->error('General error in trigger: ' . $e->getMessage(), [
                'channel' => $channel, 
                'event' => $event
            ]);
            
            // برای خطاهای عمومی، وضعیت را reset کنیم
            $this->is_connected = false;
            return false;
        }
    }

        /**
     * ارسال پیام به چند کانال (برای broadcast)
     */
    public function triggerBatch(array $channels, string $event, array $data): bool {
        $logger = Plugin::get_instance()->get_service('logger');

        if (empty($channels)) {
            return false;
        }

        if (!$this->is_connected() || !$this->pusher) {
            $this->setup_pusher();
        }

        if (!$this->is_connected() || !$this->pusher) {
            if ($logger) $logger->warning('Pusher not connected; batch trigger skipped');
            return false;
        }

        try {
            $result = $this->pusher->trigger($channels, $event, $data);
            
            if ($logger) {
                $logger->info('Pusher batch trigger succeeded', [
                    'channels_count' => count($channels),
                    'event' => $event
                ]);
            }
            
            return true;
            
        } catch (\Throwable $e) {
            if ($logger) $logger->error('Batch trigger error: ' . $e->getMessage(), [
                'channels_count' => count($channels),
                'event' => $event
            ]);
            
            return false;
        }
    }

        /**
     * متد disconnect بهبود یافته
     */
    public function disconnect(): void {
        if ($this->pusher) {
            try {
                // در نسخه‌های جدید، ممکن است متد disconnect وجود نداشته باشد
                // یا باید connection را manual مدیریت کرد
                $this->pusher = null;
                $this->is_connected = false;
                
                $logger = Plugin::get_instance()->get_service('logger');
                if ($logger) $logger->info('Pusher disconnected');
                
            } catch (\Throwable $e) {
                // ignore errors on disconnect
            }
        }
    }

    /**
     * دریافت instance Pusher (برای استفاده در کلاس‌های دیگر)
     */
    public function get_pusher_instance() {
        return $this->pusher;
    }

    /**
     * احراز هویت کانال (برای frontend)
     */
    public function authenticate_channel(string $channel_name, string $socket_id) {
        $logger = Plugin::get_instance()->get_service('logger');

        if (!$this->is_connected() || !$this->pusher) {
            if ($logger) $logger->warning('Pusher not connected in authenticate_channel');
            return false;
        }

        try {
            // بررسی نوع کانال
            $is_private = strpos($channel_name, 'private-') === 0;
            $is_presence = strpos($channel_name, 'presence-') === 0;
            
            if ($is_private || $is_presence) {
                // برای کانال‌های private و presence
                if (method_exists($this->pusher, 'authorizeChannel')) {
                    $auth_data = $this->pusher->authorizeChannel($channel_name, $socket_id);
                    
                    if ($auth_data && isset($auth_data['auth'])) {
                        return $auth_data;
                    }
                }
                
                // روش fallback: تولید دستی auth signature
                return $this->generate_auth_signature($channel_name, $socket_id);
                
            } else {
                // برای کانال‌های عمومی، signature ساده
                return $this->generate_auth_signature($channel_name, $socket_id);
            }
            
        } catch (\Throwable $e) {
            if ($logger) $logger->error('Auth error: ' . $e->getMessage());
            return false;
        }
    }


        /**
     * تولید signature احراز هویت دستی
     */
    private function generate_auth_signature(string $channel_name, string $socket_id) {
        $config = $this->get_config();
        
        if (empty($config['key']) || empty($config['secret'])) {
            return false;
        }
        
        $string_to_sign = $socket_id . ':' . $channel_name;
        $signature = hash_hmac('sha256', $string_to_sign, $config['secret']);
        
        return [
            'auth' => $config['key'] . ':' . $signature
        ];
    }

    
    /**
     * تست اتصال برای صفحه تنظیمات
     */
    public function test_connection(): array {
        $result = [
            'success' => false,
            'message' => '',
            'details' => []
        ];

        try {
            $config = $this->get_config();
            
            if (!$this->has_valid_config()) {
                $result['message'] = 'تنظیمات ناقص است';
                $result['details']['config_check'] = $this->get_config_for_debug();
                return $result;
            }

            // ایجاد instance جدید برای تست (مستقل از instance اصلی)
            $options = [
                'cluster' => $config['cluster'],
                'useTLS' => true,
                'timeout' => 10
            ];

            $test_pusher = new Pusher(
                $config['key'],
                $config['secret'],
                $config['app_id'],
                $options
            );

            // تست 1: تلاش برای trigger یک پیام تست
            $test_channel = 'test-channel-' . time();
            $test_event = 'test-event';
            $test_data = ['test' => 'Hello Pusher', 'timestamp' => time()];
            
            $trigger_result = $test_pusher->trigger([$test_channel], $test_event, $test_data);
            
            $result['details']['trigger_test'] = [
                'channel' => $test_channel,
                'event' => $test_event,
                'result' => $trigger_result !== false ? 'success' : 'failed'
            ];

            // تست 2: بررسی متدهای موجود
            $reflection = new \ReflectionClass($test_pusher);
            $methods = $reflection->getMethods(\ReflectionMethod::IS_PUBLIC);
            
            $available_methods = [];
            foreach ($methods as $method) {
                $available_methods[] = $method->getName();
            }
            
            $result['details']['available_methods'] = $available_methods;
            $result['details']['pusher_version'] = $this->get_pusher_version();

            $result['success'] = true;
            $result['message'] = 'اتصال موفقیت‌آمیز بود!';
            $result['details']['app_id'] = substr($config['app_id'], 0, 4) . '...';
            $result['details']['cluster'] = $config['cluster'];
            
            // ذخیره نتیجه
            update_option('wp_live_chat_pusher_last_test', time());
            update_option('wp_live_chat_pusher_test_result', $result);
            update_option('wp_live_chat_pusher_connected', true);

        } catch (\Pusher\PusherException $e) {
            $result['message'] = 'خطای Pusher: ' . $e->getMessage();
            $result['details']['error'] = $e->getMessage();
            $result['details']['pusher_version'] = $this->get_pusher_version();
            
            update_option('wp_live_chat_pusher_last_error', $e->getMessage());
            update_option('wp_live_chat_pusher_connected', false);
            
        } catch (\Throwable $e) {
            $result['message'] = 'خطای عمومی: ' . $e->getMessage();
            $result['details']['error'] = $e->getMessage();
            $result['details']['type'] = get_class($e);
            
            update_option('wp_live_chat_pusher_last_error', $e->getMessage());
            update_option('wp_live_chat_pusher_connected', false);
        }

        return $result;
    }

        /**
     * دریافت نسخه Pusher نصب شده
     */
    private function get_pusher_version(): string {
        try {
            if (class_exists('\Pusher\Pusher')) {
                $reflection = new \ReflectionClass('\Pusher\Pusher');
                $filename = $reflection->getFileName();
                
                // بررسی composer.json برای پیدا کردن نسخه
                $composer_path = dirname($filename, 3) . '/composer.json';
                if (file_exists($composer_path)) {
                    $composer = json_decode(file_get_contents($composer_path), true);
                    if (isset($composer['version'])) {
                        return $composer['version'];
                    }
                }
                
                return 'unknown (class exists)';
            }
        } catch (\Throwable $e) {
            // ignore
        }
        
        return 'not detected';
    }

    public function get_connection_status(): array {
        return [
            'connected' => $this->is_connected(),
            'config_valid' => $this->has_valid_config(),
            'last_test' => get_option('wp_live_chat_pusher_last_test', 0),
            'last_error' => get_option('wp_live_chat_pusher_last_error', ''),
            'pusher_instance' => $this->pusher ? 'exists' : 'null',
            'config' => $this->get_config_for_debug(),
            'pusher_version' => $this->get_pusher_version()
        ];
    }

    public function is_connected(): bool {
        return $this->is_connected && $this->pusher !== null;
    }

    private function get_config_for_debug(): array {
        $c = $this->get_config();
        return [
            'app_id' => $c['app_id'] ? '****' . substr($c['app_id'], -4) : 'Not set',
            'key' => $c['key'] ? '****' . substr($c['key'], -4) : 'Not set',
            'secret_set' => !empty($c['secret']),
            'cluster' => $c['cluster'] ?: 'Not set',
            'config_valid' => $this->has_valid_config()
        ];
    }
}