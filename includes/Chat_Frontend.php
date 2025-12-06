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

        add_action('wp_ajax_nopriv_sync_flow_state', [$this, 'handle_sync_flow_state']);
        add_action('wp_ajax_sync_flow_state', [$this, 'handle_sync_flow_state']);
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

    public function handle_sync_flow_state(): void {
        check_ajax_referer('wp_live_chat_nonce', 'nonce');
        
        $session_id = sanitize_text_field($_POST['session_id'] ?? '');
        
        if (empty($session_id)) {
            wp_send_json_error('شناسه جلسه الزامی است');
            return;
        }
        
        try {
            $flow = new Conversation_Flow($session_id);
            
            // دریافت state کامل
            $state = $flow->get_full_state();
            
            // همچنین پیام مرحله فعلی را بفرست
            $state['step_message'] = $flow->get_step_message();
            
            wp_send_json_success([
                'state' => $state,
                'sync_time' => current_time('mysql'),
                'server_step' => $flow->get_current_step()
            ]);
            
        } catch (\Exception $e) {
            wp_send_json_error('خطا در sync: ' . $e->getMessage());
        }
    }
    
    public function handle_process_conversation_step(): void {
        // فعال کردن error_logging
        if (defined('WP_DEBUG') && WP_DEBUG) {
            error_log('=== CHAT FRONTEND: PROCESS CONVERSATION STEP ===');
            error_log('POST: ' . print_r($_POST, true));
        }
        
        // بررسی nonce
        if (!isset($_POST['nonce']) || !wp_verify_nonce($_POST['nonce'], 'wp_live_chat_nonce')) {
            if (defined('WP_DEBUG') && WP_DEBUG) {
                error_log('Nonce verification failed');
            }
            wp_send_json_error('درخواست نامعتبر', 403);
            exit;
        }
        
        // اعتبارسنجی input
        $session_id = isset($_POST['session_id']) ? sanitize_text_field($_POST['session_id']) : '';
        $input = isset($_POST['input']) ? sanitize_textarea_field($_POST['input']) : '';
        $step = isset($_POST['step']) ? sanitize_text_field($_POST['step']) : 'welcome';
        
        if (defined('WP_DEBUG') && WP_DEBUG) {
            error_log("Session: {$session_id}");
            error_log("Input length: " . strlen($input));
            error_log("Step: {$step}");
        }
        
        if (empty($session_id) || empty($input)) {
            if (defined('WP_DEBUG') && WP_DEBUG) {
                error_log('Empty session_id or input');
            }
            wp_send_json_error('ورودی ناقص', 400);
            exit;
        }
        
        try {
            // ایجاد flow
            $flow = new Conversation_Flow($session_id);
            
            if (defined('WP_DEBUG') && WP_DEBUG) {
                $initial_state = $flow->get_full_state();
                error_log("Initial flow state:");
                error_log(print_r($initial_state, true));
            }
            
            // پردازش ورودی
            $result = $flow->process_input($input, $flow->get_input_type());
            
            if (defined('WP_DEBUG') && WP_DEBUG) {
                error_log("Process result:");
                error_log(print_r($result, true));
            }
            
            if ($result['success']) {
                // ذخیره پیام در دیتابیس
                $database = Plugin::get_instance()->get_service('database');
                if ($database) {
                    // تعیین نوع پیام
                    $message_type = 'user';
                    if (isset($result['field_type'])) {
                        if ($result['field_type'] === 'phone' || $result['field_type'] === 'name') {
                            $message_type = 'user_info';
                        }
                    }
                    
                    $user_name = 'کاربر';
                    if (!empty($result['user_data']['name'])) {
                        $user_name = $result['user_data']['name'];
                    } elseif (!empty($result['user_data']['phone'])) {
                        $user_name = 'کاربر (' . substr($result['user_data']['phone'], 0, 4) . '***)';
                    }
                    
                    $message_id = $database->save_message([
                        'session_id' => $session_id,
                        'user_name' => $user_name,
                        'message_content' => $input,
                        'message_type' => $message_type
                    ]);
                    
                    if (defined('WP_DEBUG') && WP_DEBUG) {
                        error_log("Message saved to DB with ID: {$message_id}");
                    }
                    
                    // اگر اطلاعات کاربر کامل شد، اطلاعات session را بروزرسانی کن
                    if (!empty($result['user_data']['phone']) && !empty($result['user_data']['name'])) {
                        $database->update_session_user_info(
                            $session_id,
                            $result['user_data']['name'],
                            $result['user_data']['phone'],
                            $result['user_data']['company'] ?? ''
                        );
                        
                        if (defined('WP_DEBUG') && WP_DEBUG) {
                            error_log("Session user info updated");
                        }
                        
                        // ارسال نوتیفیکیشن به ادمین
                        $pusher_service = Plugin::get_instance()->get_service('pusher_service');
                        if ($pusher_service && $pusher_service->is_connected()) {
                            $pusher_service->trigger('admin-notifications', 'user-info-completed', [
                                'session_id' => $session_id,
                                'user_name' => $result['user_data']['name'],
                                'user_phone' => $result['user_data']['phone'],
                                'timestamp' => current_time('mysql')
                            ]);
                            
                            if (defined('WP_DEBUG') && WP_DEBUG) {
                                error_log("Admin notification sent via Pusher");
                            }
                        }
                    }
                }
                
                // پاسخ موفقیت‌آمیز با state کامل
                $response = [
                    'success' => true,
                    'data' => [
                        'next_step' => $result['next_step'] ?? $result['state']['current_step'],
                        'message' => $result['message'],
                        'state' => $result['state'],
                        'user_data' => $result['user_data'],
                        'requires_input' => $result['state']['requires_input'],
                        'input_type' => $result['state']['input_type'],
                        'input_placeholder' => $result['state']['input_placeholder'],
                        'input_hint' => $result['state']['input_hint']
                    ]
                ];
                
                if (defined('WP_DEBUG') && WP_DEBUG) {
                    error_log("Sending success response");
                    error_log(print_r($response, true));
                }
                
                wp_send_json_success($response['data']);
                
            } else {
                // خطا در پردازش
                $error_response = [
                    'success' => false,
                    'message' => $result['message'] ?? 'خطا در پردازش',
                    'state' => $result['state'] ?? []
                ];
                
                if (defined('WP_DEBUG') && WP_DEBUG) {
                    error_log("Sending error response");
                    error_log(print_r($error_response, true));
                }
                
                wp_send_json_error($error_response);
            }
            
        } catch (\Exception $e) {
            if (defined('WP_DEBUG') && WP_DEBUG) {
                error_log('Exception in process_conversation_step: ' . $e->getMessage());
                error_log('Trace: ' . $e->getTraceAsString());
            }
            
            wp_send_json_error([
                'message' => 'خطای سرور: ' . $e->getMessage(),
                'exception' => defined('WP_DEBUG') && WP_DEBUG ? $e->getTraceAsString() : ''
            ]);
        }
        
        if (defined('WP_DEBUG') && WP_DEBUG) {
            error_log('=== CHAT FRONTEND: PROCESS CONVERSATION STEP END ===');
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