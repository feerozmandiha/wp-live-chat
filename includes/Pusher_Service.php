<?php
namespace WP_LIVE_CHAT;

if (!defined('WP_LIVE_CHAT_PLUGIN_FILE')) return;

use Pusher\Pusher;
use Throwable;

class Pusher_Service {
    private ?Pusher $pusher = null;
    private bool $is_connected = false;
    private ?array $config = null;
    private int $max_trigger_retries = 1;

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
            if ($logger) $logger->error('Pusher PHP library not found. Run composer install.');
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
                'encrypted' => true
            ];

            $this->pusher = new Pusher(
                $c['key'],
                $c['secret'],
                $c['app_id'],
                $options
            );

            // تست اتصال با یک درخواست ساده
            $channels = $this->pusher->get_channels();
            $this->is_connected = true;
            
            if ($logger) $logger->info('Pusher initialized successfully.', [
                'channels_count' => is_array($channels) ? count($channels) : 0
            ]);
            
            // ذخیره وضعیت در option برای نمایش در تنظیمات
            update_option('wp_live_chat_pusher_connected', true);
            update_option('wp_live_chat_pusher_last_test', time());
            
        } catch (Throwable $e) {
            $this->is_connected = false;
            if ($logger) $logger->error('Pusher setup failed: ' . $e->getMessage());
            update_option('wp_live_chat_pusher_connected', false);
            update_option('wp_live_chat_pusher_last_error', $e->getMessage());
        }
    }

    public function trigger(string $channel, string $event, array $data): bool {
        $logger = Plugin::get_instance()->get_service('logger');

        if (!$this->is_connected() || !$this->pusher) {
            $this->setup_pusher();
        }

        if (!$this->is_connected() || !$this->pusher) {
            if ($logger) $logger->error('Pusher not connected; trigger skipped');
            return false;
        }

        $attempt = 0;
        $maxAttempts = 1 + max(0, (int)$this->max_trigger_retries);
        
        while ($attempt < $maxAttempts) {
            try {
                $attempt++;
                $this->pusher->trigger($channel, $event, $data);
                
                if ($logger) $logger->info('Pusher trigger succeeded', [
                    'channel' => $channel, 
                    'event' => $event,
                    'attempt' => $attempt
                ]);
                
                return true;
            } catch (Throwable $e) {
                if ($logger) $logger->error('Pusher trigger error: ' . $e->getMessage(), [
                    'channel' => $channel, 
                    'event' => $event,
                    'attempt' => $attempt
                ]);
                
                if ($attempt < $maxAttempts) {
                    usleep(200000);
                    continue;
                }
                return false;
            }
        }
        return false;
    }

    // در Pusher_Service.php
    public function get_pusher_instance() {
        return $this->pusher;
    }

    public function authorize_channel($channel_name, $socket_id) {
        if (!$this->pusher) {
            return false;
        }
        
        try {
            return $this->pusher->authorizeChannel($channel_name, $socket_id);
        } catch (\Exception $e) {
            error_log('Pusher Auth Error: ' . $e->getMessage());
            return false;
        }
    }

    

/**
 * احراز هویت کانال - نسخه سازگار با Pusher 7.x
 */
    public function authenticate_channel(string $channel, string $socket_id) {
        $logger = Plugin::get_instance()->get_service('logger');

        if (!$this->is_connected() || !$this->pusher) {
            if ($logger) $logger->warning('Pusher not connected in authenticate_channel');
            return false;
        }

        try {
            $config = $this->get_config();
            
            // برای کانال‌های عمومی نیازی به احراز هویت پیشرفته نیست
            // اما باید signature برگردانیم
            if (strpos($channel, 'private-') !== 0 && strpos($channel, 'presence-') !== 0) {
                // برای کانال‌های عمومی، signature ساده ایجاد می‌کنیم
                $auth_key = $config['key'];
                $auth_secret = $config['secret'];
                
                if (empty($auth_key) || empty($auth_secret)) {
                    if ($logger) $logger->error('Pusher key or secret missing for authentication');
                    return false;
                }
                
                $signature = hash_hmac('sha256', $socket_id . ':' . $channel, $auth_secret);
                
                return [
                    'auth' => $auth_key . ':' . $signature
                ];
            }
            
            // برای کانال‌های خصوصی/حضور از روش‌های مختلف استفاده می‌کنیم
            // روش 1: استفاده از authorizeChannel (نسخه‌های جدید)
            if (method_exists($this->pusher, 'authorizeChannel')) {
                try {
                    $auth = $this->pusher->authorizeChannel($channel, $socket_id);
                    if ($auth && isset($auth['auth'])) {
                        return $auth;
                    }
                } catch (Throwable $e) {
                    if ($logger) $logger->warning('authorizeChannel failed: ' . $e->getMessage());
                }
            }
            
            // روش 2: استفاده از socket_auth (نسخه‌های قدیمی)
            if (method_exists($this->pusher, 'socket_auth')) {
                try {
                    return $this->pusher->socket_auth($channel, $socket_id);
                } catch (Throwable $e) {
                    if ($logger) $logger->warning('socket_auth failed: ' . $e->getMessage());
                }
            }

            // روش 3: تولید دستی signature برای کانال خصوصی
            if (strpos($channel, 'private-') === 0 || strpos($channel, 'presence-') === 0) {
                $auth_key = $config['key'];
                $auth_secret = $config['secret'];
                
                if (empty($auth_key) || empty($auth_secret)) {
                    if ($logger) $logger->error('Pusher key or secret missing for private channel auth');
                    return false;
                }
                
                $signature = hash_hmac('sha256', $socket_id . ':' . $channel, $auth_secret);
                
                // برای کانال‌های presence可能需要 data اضافی
                if (strpos($channel, 'presence-') === 0) {
                    // داده کاربر برای presence channel
                    $user_data = [
                        'user_id' => get_current_user_id(),
                        'user_info' => [
                            'name' => wp_get_current_user()->display_name,
                            'role' => current_user_can('manage_options') ? 'admin' : 'user'
                        ]
                    ];
                    
                    $string_to_sign = $socket_id . ':' . $channel . ':' . json_encode($user_data);
                    $signature = hash_hmac('sha256', $string_to_sign, $auth_secret);
                    
                    return [
                        'auth' => $auth_key . ':' . $signature,
                        'channel_data' => json_encode($user_data)
                    ];
                } else {
                    // برای کانال‌های private ساده
                    return [
                        'auth' => $auth_key . ':' . $signature
                    ];
                }
            }
            
            if ($logger) $logger->error('No valid authentication method could be used');
            return false;
            
        } catch (Throwable $e) {
            if ($logger) $logger->error('Auth error: ' . $e->getMessage());
            return false;
        }
    }
    /**
     * تست اتصال Pusher - برای استفاده در صفحه تنظیمات
     */
    public function test_connection(): array {
        $result = [
            'success' => false,
            'message' => '',
            'details' => [],
            'debug' => []
        ];

        try {
            $config = $this->get_config();
            
            // بررسی تنظیمات
            $result['debug']['config_check'] = [
                'app_id' => !empty($config['app_id']),
                'key' => !empty($config['key']),
                'secret' => !empty($config['secret']),
                'cluster' => !empty($config['cluster'])
            ];
            
            if (!$this->has_valid_config()) {
                $missing = [];
                if (empty($config['app_id'])) $missing[] = 'App ID';
                if (empty($config['key'])) $missing[] = 'Key';
                if (empty($config['secret'])) $missing[] = 'Secret';
                if (empty($config['cluster'])) $missing[] = 'Cluster';
                
                $result['message'] = 'تنظیمات ناقص است: ' . implode(', ', $missing);
                return $result;
            }

            if (!class_exists('\Pusher\Pusher')) {
                $result['message'] = 'کتابخانه Pusher یافت نشد. لطفا composer install را اجرا کنید.';
                return $result;
            }

            // ایجاد اتصال جدید برای تست
            $options = [
                'cluster' => $config['cluster'],
                'useTLS' => true,
                'timeout' => 10,
                'debug' => true
            ];

            $result['debug']['connection_params'] = [
                'app_id' => substr($config['app_id'], 0, 4) . '...' . substr($config['app_id'], -4),
                'key' => substr($config['key'], 0, 4) . '...' . substr($config['key'], -4),
                'secret_set' => !empty($config['secret']),
                'cluster' => $config['cluster'],
                'options' => $options
            ];

            $test_pusher = new Pusher(
                $config['key'],
                $config['secret'],
                $config['app_id'],
                $options
            );

            // تست گرفتن لیست کانال‌ها
            $result['debug']['before_get_channels'] = 'Calling get_channels()';
            $channels = $test_pusher->get_channels();
            $result['debug']['after_get_channels'] = 'get_channels() completed';
            
            // تست ارسال یک پیام ساده
            $test_channel = 'test-channel-' . time();
            $test_event = 'test-event';
            $test_data = ['test' => 'Hello Pusher', 'timestamp' => time()];
            
            $trigger_result = $test_pusher->trigger($test_channel, $test_event, $test_data);
            $result['debug']['trigger_test'] = [
                'channel' => $test_channel,
                'event' => $test_event,
                'data' => $test_data,
                'result' => $trigger_result
            ];
            
            $result['success'] = true;
            $result['message'] = 'اتصال موفقیت‌آمیز بود! تمام تست‌ها passed شدند.';
            $result['details'] = [
                'app_id' => substr($config['app_id'], 0, 4) . '...' . substr($config['app_id'], -4),
                'cluster' => $config['cluster'],
                'channels_count' => is_array($channels) ? count($channels) : 'N/A',
                'trigger_success' => $trigger_result === true || $trigger_result === null
            ];
            
            // ذخیره نتیجه تست
            update_option('wp_live_chat_pusher_last_test', time());
            update_option('wp_live_chat_pusher_test_result', $result);
            update_option('wp_live_chat_pusher_connected', true);
            
        } catch (\Pusher\PusherException $e) {
            $result['message'] = 'خطای Pusher: ' . $e->getMessage();
            $result['debug']['pusher_exception'] = [
                'message' => $e->getMessage(),
                'code' => $e->getCode(),
                'file' => $e->getFile(),
                'line' => $e->getLine()
            ];
            
            // ذخیره خطا
            update_option('wp_live_chat_pusher_last_error', $e->getMessage());
            update_option('wp_live_chat_pusher_connected', false);
            
        } catch (\Exception $e) {
            $result['message'] = 'خطای عمومی: ' . $e->getMessage();
            $result['debug']['general_exception'] = [
                'message' => $e->getMessage(),
                'type' => get_class($e)
            ];
            
            update_option('wp_live_chat_pusher_last_error', $e->getMessage());
            update_option('wp_live_chat_pusher_connected', false);
            
        } catch (Throwable $e) {
            $result['message'] = 'خطای Throwable: ' . $e->getMessage();
            $result['debug']['throwable'] = [
                'message' => $e->getMessage(),
                'type' => get_class($e)
            ];
            
            update_option('wp_live_chat_pusher_last_error', $e->getMessage());
            update_option('wp_live_chat_pusher_connected', false);
        }

        return $result;
    }
    /**
     * دریافت وضعیت فعلی اتصال
     */
    public function get_connection_status(): array {
        return [
            'connected' => $this->is_connected(),
            'config_valid' => $this->has_valid_config(),
            'last_test' => get_option('wp_live_chat_pusher_last_test', 0),
            'last_error' => get_option('wp_live_chat_pusher_last_error', ''),
            'config' => $this->get_config_for_debug()
        ];
    }

    public function is_connected(): bool {
        return $this->is_connected && $this->pusher !== null;
    }

    public function get_config_for_debug(): array {
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