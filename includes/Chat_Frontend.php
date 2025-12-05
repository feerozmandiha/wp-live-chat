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

        add_action('wp_ajax_nopriv_debug_conversation_flow', [$this, 'handle_debug_flow']);
        add_action('wp_ajax_debug_conversation_flow', [$this, 'handle_debug_flow']);
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

    public function handle_debug_flow(): void {
        check_ajax_referer('wp_live_chat_nonce', 'nonce');
        
        $session_id = sanitize_text_field($_POST['session_id'] ?? '');
        
        if (empty($session_id)) {
            wp_send_json_error('شناسه جلسه الزامی است');
            return;
        }
        
        try {
            $flow = new Conversation_Flow($session_id);
            
            wp_send_json_success([
                'debug_info' => $flow->get_debug_info(),
                'current_step' => $flow->get_current_step(),
                'user_data' => $flow->get_user_data(),
                'requires_input' => $flow->requires_input(),
                'input_type' => $flow->get_input_type(),
                'is_admin_online' => $flow->is_admin_online()
            ]);
            
        } catch (\Exception $e) {
            wp_send_json_error('خطا: ' . $e->getMessage());
        }
    }
    
    public function handle_process_conversation_step(): void {
        // دیباگ: شروع
        error_log('=== PROCESS_CONVERSATION_STEP START ===');
        error_log('POST data: ' . print_r($_POST, true));
        
        // بررسی nonce
        if (!isset($_POST['nonce']) || !wp_verify_nonce($_POST['nonce'], 'wp_live_chat_nonce')) {
            error_log('Nonce verification failed');
            wp_send_json_error('درخواست نامعتبر', 403);
            exit;
        }
        
        // اعتبارسنجی input
        $session_id = isset($_POST['session_id']) ? sanitize_text_field($_POST['session_id']) : '';
        $input = isset($_POST['input']) ? sanitize_textarea_field($_POST['input']) : '';
        $step = isset($_POST['step']) ? sanitize_text_field($_POST['step']) : 'welcome';
        
        error_log("Session ID: {$session_id}");
        error_log("Input: {$input}");
        error_log("Step: {$step}");
        
        if (empty($session_id) || empty($input)) {
            error_log('Empty session_id or input');
            wp_send_json_error('ورودی ناقص', 400);
            exit;
        }
        
        try {
            // ایجاد یا بازیابی conversation flow
            error_log('Creating Conversation_Flow instance...');
            $flow = new Conversation_Flow($session_id);
            
            // دریافت مرحله فعلی از flow
            $current_flow_step = $flow->get_current_step();
            error_log("Current flow step: {$current_flow_step}");
            error_log("Requested step: {$step}");
            
            // اگر مرحله درخواستی با مرحله flow متفاوت است، از flow پیروی کن
            if ($step !== $current_flow_step) {
                error_log("Step mismatch. Using flow step: {$current_flow_step}");
                $step = $current_flow_step;
            }
            
            // پردازش ورودی کاربر
            error_log('Processing input...');
            $result = $flow->process_input($input, $flow->get_input_type());
            
            error_log('Process result: ' . print_r($result, true));
            
            if ($result['success']) {
                // ذخیره پیام کاربر در دیتابیس
                $database = Plugin::get_instance()->get_service('database');
                if ($database) {
                    // تشخیص نوع پیام
                    $input_type = $flow->get_input_type($step);
                    $message_type = 'user';
                    if ($input_type === 'phone' || $input_type === 'name') {
                        $message_type = 'user_info';
                    }
                    
                    $user_name = $result['user_data']['name'] ?? 'کاربر';
                    if (empty($user_name) && !empty($result['user_data']['phone'])) {
                        $user_name = 'کاربر (' . substr($result['user_data']['phone'], 0, 3) . '***)';
                    }
                    
                    $message_id = $database->save_message([
                        'session_id' => $session_id,
                        'user_name' => $user_name,
                        'message_content' => $input,
                        'message_type' => $message_type
                    ]);
                    
                    error_log("Message saved to DB with ID: {$message_id}");
                }
                
                // پاسخ موفقیت‌آمیز
                error_log('Sending success response');
                wp_send_json_success($result);
                
            } else {
                error_log('Process failed: ' . ($result['message'] ?? 'Unknown error'));
                wp_send_json_error($result['message'] ?? 'خطا در پردازش');
            }
            
        } catch (\Exception $e) {
            error_log('Exception in process_conversation_step: ' . $e->getMessage());
            error_log('Trace: ' . $e->getTraceAsString());
            wp_send_json_error('خطای سرور: ' . $e->getMessage());
        }
        
        error_log('=== PROCESS_CONVERSATION_STEP END ===');
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