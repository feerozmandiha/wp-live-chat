<?php
namespace WP_Live_Chat; // ✅ اصلاح namespace — هماهنگ با سایر کلاس‌ها

use Pusher\Pusher;
use Throwable;

class Pusher_Service {
    private ?Pusher $pusher = null;
    private bool $is_connected = false;
    private ?array $config = null;
    private int $max_trigger_retries = 1;

    public function init(): void {
        // ❌ حذف: add_action('wp_loaded', [$this, 'setup_pusher']);
        // Pusher فقط هنگام نیاز ایجاد می‌شود (lazy initialization)
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

    // ✅ ایجاد Pusher فقط هنگام نیاز
    public function get_pusher_instance() {
        if ($this->pusher === null) {
            if (!$this->has_valid_config() || !class_exists('\Pusher\Pusher')) {
                $this->is_connected = false;
                return null;
            }

            try {
                $c = $this->get_config();
                $options = [
                    'cluster' => $c['cluster'],
                    'useTLS' => $c['useTLS'] ?? true,
                    'encrypted' => true
                ];
                $this->pusher = new Pusher($c['key'], $c['secret'], $c['app_id'], $options);
                $this->is_connected = true;

                // ❌ حذف: $channels = $this->pusher->get_channels();

                // ذخیره وضعیت موفقیت‌آمیز
                update_option('wp_live_chat_pusher_connected', true);
                update_option('wp_live_chat_pusher_last_test', time());

                // لاگ موفقیت
                $logger = Plugin::get_instance()->get_service('logger');
                if ($logger) {
                    $logger->info('Pusher initialized successfully.');
                }
            } catch (Throwable $e) {
                $this->is_connected = false;
                $this->log_error('Pusher setup failed: ' . $e->getMessage());
                update_option('wp_live_chat_pusher_connected', false);
                update_option('wp_live_chat_pusher_last_error', $e->getMessage());
            }
        }
        return $this->pusher;
    }

    public function is_connected(): bool {
        return $this->is_connected && $this->pusher !== null;
    }

    // ... (بقیه متدها trigger, authenticate_channel, test_connection بدون تغییر)

    public function test_connection(): array {
        $result = [
            'success' => false,
            'message' => '',
            'details' => []
        ];

        // بررسی تنظیمات
        if (!$this->has_valid_config()) {
            $result['message'] = 'تنظیمات Pusher (App ID, Key, Secret, Cluster) را تکمیل کنید.';
            return $result;
        }

        // بررسی وجود کتابخانه
        if (!class_exists('\Pusher\Pusher')) {
            $result['message'] = 'کتابخانه Pusher یافت نشد. لطفاً composer install را اجرا کنید.';
            return $result;
        }

        try {
            // ایجاد نمونه جدید فقط برای تست
            $c = $this->get_config();
            $test_pusher = new Pusher($c['key'], $c['secret'], $c['app_id'], [
                'cluster' => $c['cluster'],
                'useTLS' => true,
                'timeout' => 10
            ]);

            // ❌ حذف get_channels() — جایگزین با تست ارسال ساده
            $test_result = $test_pusher->trigger('test-channel-' . time(), 'test', ['test' => 'ok']);

            $result['success'] = true;
            $result['message'] = 'اتصال به Pusher با موفقیت برقرار شد.';
            $result['details'] = [
                'app_id' => '****' . substr($c['app_id'], -4),
                'cluster' => $c['cluster'],
                'trigger_test' => $test_result !== false
            ];

            update_option('wp_live_chat_pusher_last_test', time());
            update_option('wp_live_chat_pusher_connected', true);
            update_option('wp_live_chat_pusher_last_error', '');

        } catch (Throwable $e) {
            $error_msg = 'خطا در اتصال به Pusher: ' . $e->getMessage();
            $result['message'] = $error_msg;
            $this->log_error($error_msg);
            update_option('wp_live_chat_pusher_connected', false);
            update_option('wp_live_chat_pusher_last_error', $e->getMessage());
        }

        return $result;
    }

    private function log_error(string $message): void {
        $logger = Plugin::get_instance()->get_service('logger');
        if ($logger && (defined('WP_DEBUG') && WP_DEBUG)) {
            $logger->error($message);
        }
    }

    public function get_connection_status(): array {
        return [
            'connected' => $this->is_connected(),
            'config_valid' => $this->has_valid_config(),
            'last_test' => get_option('wp_live_chat_pusher_last_test', 0),
            'last_error' => get_option('wp_live_chat_pusher_last_error', ''),
            'config' => $this->get_config_for_debug()
        ];
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