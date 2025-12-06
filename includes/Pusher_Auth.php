<?php
namespace WP_Live_Chat;

class Pusher_Auth {
    
    public function init() {
        add_action('wp_ajax_nopriv_pusher_auth', [$this, 'handle_auth']);
        add_action('wp_ajax_pusher_auth', [$this, 'handle_auth']);
    }
    
    public function handle_auth() {
        // بررسی session و کاربر
        $session_id = sanitize_text_field($_POST['session_id'] ?? '');
        $socket_id = sanitize_text_field($_POST['socket_id'] ?? '');
        $channel_name = sanitize_text_field($_POST['channel_name'] ?? '');
        
        if (empty($session_id) || empty($socket_id) || empty($channel_name)) {
            wp_send_json_error('داده‌های ناقص', 400);
            return;
        }
        
        // فقط اجازه دادن به کانال‌های private مربوط به session کاربر
        if (strpos($channel_name, 'private-chat-') !== 0) {
            wp_send_json_error('کانال مجاز نیست', 403);
            return;
        }
        
        // استخراج session_id از نام کانال
        $channel_session_id = str_replace('private-chat-', '', $channel_name);
        
        // بررسی تطابق session_id
        if ($channel_session_id !== $session_id) {
            wp_send_json_error('عدم تطابق session', 403);
            return;
        }
        
        // تولید signature با استفاده از Pusher PHP library
        try {
            $pusher_service = Plugin::get_instance()->get_service('pusher_service');
            
            if (!$pusher_service || !$pusher_service->is_connected()) {
                wp_send_json_error('سرویس Pusher در دسترس نیست', 500);
                return;
            }
            
            $pusher = $pusher_service->get_pusher_instance();
            
            // تولید auth response
            $auth = $pusher->authorizeChannel($channel_name, $socket_id);
            
            wp_send_json_success($auth);
            
        } catch (\Exception $e) {
            error_log('Pusher Auth Error: ' . $e->getMessage());
            wp_send_json_error('خطا در احراز هویت', 500);
        }
    }
}