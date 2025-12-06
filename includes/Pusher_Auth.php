<?php
namespace WP_Live_Chat;
defined('ABSPATH') || exit;

class Pusher_Auth {
    public function init(): void {
        add_action('wp_ajax_nopriv_pusher_auth', [$this, 'handle']);
        add_action('wp_ajax_pusher_auth', [$this, 'handle']);
    }

    public function handle(): void {
        // نمونهٔ درخواست از JS حاوی: socket_id, channel_name, session_id (اختیاری)
        $socket_id = sanitize_text_field($_POST['socket_id'] ?? '');
        $channel_name = sanitize_text_field($_POST['channel_name'] ?? '');
        $session_id = sanitize_text_field($_POST['session_id'] ?? '');

        if (empty($socket_id) || empty($channel_name)) {
            wp_send_json_error('Missing socket_id or channel_name', 400);
        }

        // محدودیت: فقط private-chat- یا presence- کانال‌های افزونه را قبول کن
        if (!preg_match('/^(private-chat-|presence-chat)/', $channel_name)) {
            wp_send_json_error('Channel not allowed', 403);
        }

        // در صورت وجود session_id بر اساس منطق شما بررسی کنید
        if (strpos($channel_name, 'private-chat-') === 0) {
            $channel_session = str_replace('private-chat-', '', $channel_name);
            if ($session_id && $channel_session !== $session_id) {
                wp_send_json_error('Session mismatch', 403);
            }
        }

        $pusher = Plugin::get_instance()->get_service('pusher_service');
        if (!$pusher || !$pusher->is_connected()) {
            wp_send_json_error('Pusher unavailable', 503);
        }

        $auth = $pusher->authorizeChannel($channel_name, $socket_id);
        if (!$auth) {
            wp_send_json_error('Authorization failed', 500);
        }

        wp_send_json_success($auth);
    }
}
