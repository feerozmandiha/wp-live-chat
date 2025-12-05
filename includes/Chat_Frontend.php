<?php

namespace WP_Live_Chat;

// قبل از شروع کلاس
if (!defined('ABSPATH')) {
    exit; // خروج اگر مستقیم فراخوانی شود
}


class Chat_Frontend {
    
    public function init(): void {
        // AJAX handlers برای conversation flow
        add_action('wp_ajax_nopriv_process_conversation_step', [$this, 'handle_process_conversation_step']);
        add_action('wp_ajax_process_conversation_step', [$this, 'handle_process_conversation_step']);
        
        add_action('wp_ajax_nopriv_get_conversation_step', [$this, 'handle_get_conversation_step']);
        add_action('wp_ajax_get_conversation_step', [$this, 'handle_get_conversation_step']);
        
        add_action('wp_ajax_nopriv_check_admin_status', [$this, 'handle_check_admin_status']);
        add_action('wp_ajax_check_admin_status', [$this, 'handle_check_admin_status']);
    }

    public function handle_typing_event() {
        check_ajax_referer('wp_live_chat_nonce', 'nonce');
        
        $session_id = sanitize_text_field($_POST['session_id'] ?? '');
        $status = sanitize_text_field($_POST['status'] ?? '');
        $user_name = sanitize_text_field($_POST['user_name'] ?? 'کاربر');
        
        if (empty($session_id)) {
            wp_send_json_error('شناسه جلسه الزامی است');
            return;
        }
        
        // اگر Pusher متصل است، از Pusher استفاده کن
        $pusher_service = Plugin::get_instance()->get_service('pusher_service');
        
        if ($pusher_service && $pusher_service->is_connected()) {
            try {
                $event_name = $status === 'typing' ? 'client-user-typing' : 'client-user-stopped-typing';
                
                $pusher_service->trigger(
                    'private-chat-' . $session_id,
                    $event_name,
                    [
                        'user_name' => $user_name,
                        'timestamp' => current_time('timestamp')
                    ]
                );
                
                wp_send_json_success(['sent_via' => 'pusher']);
                
            } catch (\Exception $e) {
                // اگر Pusher خطا داد، فقط JSON موفقیت برگردان
                wp_send_json_success(['sent_via' => 'ajax_fallback']);
            }
        } else {
            // فقط پاسخ موفقیت‌آمیز بده
            wp_send_json_success(['sent_via' => 'ajax']);
        }
    }
    
    public function handle_process_conversation_step(): void {
        // اضافه کردن header های امنیتی
        header('X-Content-Type-Options: nosniff');
        header('X-Frame-Options: SAMEORIGIN');
        header('X-XSS-Protection: 1; mode=block');
        
        // بررسی nonce با روش مطمئن‌تر
        if (!isset($_POST['nonce']) || !wp_verify_nonce(sanitize_text_field($_POST['nonce']), 'wp_live_chat_nonce')) {
            error_log('WP Live Chat: Nonce verification failed');
            wp_send_json_error('درخواست نامعتبر', 403);
            exit;
        }
        
        // اعتبارسنجی input
        $session_id = isset($_POST['session_id']) ? sanitize_text_field($_POST['session_id']) : '';
        $input = isset($_POST['input']) ? sanitize_textarea_field($_POST['input']) : '';
        $step = isset($_POST['step']) ? sanitize_text_field($_POST['step']) : 'welcome';
        
        if (empty($session_id) || empty($input)) {
            wp_send_json_error('ورودی ناقص', 400);
            exit;
        }
        
        // محدود کردن طول input
        if (strlen($input) > 1000) {
            wp_send_json_error('پیام بسیار طولانی است', 400);
            exit;
        }
        
        // === خط 74: حذف duplicate check_ajax_referer ===
        // check_ajax_referer('wp_live_chat_nonce', 'nonce'); // این خط را حذف کنید
        
        try {
            // ایجاد یا بازیابی conversation flow
            $flow = new Conversation_Flow($session_id);
            
            // پردازش ورودی کاربر
            $result = $flow->process_input($input, $flow->get_input_type());
            
            if ($result['success']) {
                // ذخیره پیام کاربر در دیتابیس
                $database = Plugin::get_instance()->get_service('database');
                if ($database) {
                    $message_type = ($flow->get_input_type() === 'general_message') ? 'user' : 'user_info';
                    $database->save_message([
                        'session_id' => $session_id,
                        'user_name' => $result['user_data']['name'] ?? 'کاربر',
                        'message_content' => $input,
                        'message_type' => $message_type
                    ]);
                }
                
                wp_send_json_success($result);
            } else {
                wp_send_json_error($result['message'] ?? 'خطا در پردازش');
            }
            
        } catch (\Exception $e) {
            wp_send_json_error('خطای سرور: ' . $e->getMessage());
        }
    }
    
    public function handle_get_conversation_step(): void {

            // دیباگ لاگ
        error_log('=== GET_CONVERSATION_STEP CALLED ===');
        error_log('Session ID: ' . ($_POST['session_id'] ?? 'NONE'));
        error_log('Nonce: ' . ($_POST['nonce'] ?? 'NONE'));
        
        if (!isset($_POST['nonce']) || !wp_verify_nonce($_POST['nonce'], 'wp_live_chat_nonce')) {
            error_log('Nonce verification failed');
            wp_send_json_error('Nonce verification failed');
            return;
        }
        check_ajax_referer('wp_live_chat_nonce', 'nonce');
        
        $session_id = sanitize_text_field($_POST['session_id'] ?? '');
        
        if (empty($session_id)) {
            wp_send_json_error('شناسه جلسه الزامی است');
            return;
        }
        
        try {
            $flow = new Conversation_Flow($session_id);
            
            wp_send_json_success([
                'current_step' => $flow->get_current_step(),
                'user_data' => $flow->get_user_data(),
                'requires_input' => $flow->requires_input(),
                'input_type' => $flow->get_input_type(),
                'input_placeholder' => $flow->get_input_placeholder(),
                'input_hint' => $flow->get_input_hint(),
                'message' => $flow->get_step_message()
            ]);
            
        } catch (\Exception $e) {
            wp_send_json_error('خطای سرور: ' . $e->getMessage());
        }
    }
    
    public function handle_check_admin_status(): void {
        check_ajax_referer('wp_live_chat_nonce', 'nonce');
        
        try {
            // بررسی وضعیت ادمین‌ها
            $admins = get_users([
                'role' => 'administrator',
                'meta_key' => 'wp_live_chat_last_activity',
                'meta_value' => time() - 300, // 5 دقیقه اخیر
                'meta_compare' => '>'
            ]);
            
            wp_send_json_success([
                'admin_online' => count($admins) > 0,
                'admin_count' => count($admins),
                'timestamp' => current_time('mysql')
            ]);
            
        } catch (\Exception $e) {
            wp_send_json_error('خطای سرور: ' . $e->getMessage());
        }
    }
}