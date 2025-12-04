<?php
namespace WP_Live_Chat;

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
    
    public function handle_process_conversation_step(): void {
        check_ajax_referer('wp_live_chat_nonce', 'nonce');
        
        $session_id = sanitize_text_field($_POST['session_id'] ?? '');
        $input = sanitize_text_field($_POST['input'] ?? '');
        $step = sanitize_text_field($_POST['step'] ?? 'welcome');
        
        if (empty($session_id) || empty($input)) {
            wp_send_json_error('شناسه جلسه و ورودی الزامی است');
            return;
        }
        
        try {
            // ایجاد یا بازیابی conversation flow
            $flow = new Conversation_Flow($session_id);
            
            // اگر مرحله ارسالی با مرحله فعلی متفاوت است، آن را تنظیم کن
            if ($step !== $flow->get_current_step()) {
                // لاگ برای دیباگ
                error_log("Step mismatch: received {$step}, current is " . $flow->get_current_step());
            }
            
            // پردازش ورودی کاربر
            $result = $flow->process_input($input, $flow->get_input_type());
            
            if ($result['success']) {
                // ذخیره پیام کاربر در دیتابیس
                $database = Plugin::get_instance()->get_service('database');
                if ($database) {
                    $database->save_message([
                        'session_id' => $session_id,
                        'user_name' => $result['user_data']['name'] ?? 'کاربر',
                        'message_content' => $input,
                        'message_type' => 'user'
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