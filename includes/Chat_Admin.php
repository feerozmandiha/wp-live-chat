<?php

namespace WP_Live_Chat;

use Exception; // این خط را اضافه کنید
use WP_Live_Chat\Plugin;
use WP_Live_Chat\Database;
use WP_Live_Chat\Pusher_Service;


class Chat_Admin {
    
    public function init(): void {
        add_action('admin_menu', [$this, 'add_chat_admin_page']);
        add_action('wp_ajax_get_chat_sessions', [$this, 'get_chat_sessions']);
        add_action('wp_ajax_send_admin_message', [$this, 'send_admin_message']);
        add_action('wp_ajax_get_session_messages', [$this, 'get_session_messages_ajax']);
        add_action('wp_ajax_auth_pusher_channel_admin', [$this, 'handle_admin_channel_auth']);
        add_action('admin_enqueue_scripts', [$this, 'enqueue_admin_chat_scripts']);
        
        // بررسی وجود جداول هنگام لود صفحه ادمین
        add_action('admin_init', [$this, 'check_tables']);
    }

    public function check_tables(): void {
        /** @var Database $database */
        $database = Plugin::get_instance()->get_service('database');
        if ($database && method_exists($database, 'check_and_create_tables')) {
            $database->check_and_create_tables();
        }
    }


    public function add_chat_admin_page(): void {
        add_menu_page(
            __('مدیریت چت', 'wp-live-chat'),
            __('چت زنده', 'wp-live-chat'),
            'manage_options',
            'wp-live-chat-admin',
            [$this, 'render_chat_admin_page'],
            'dashicons-format-chat',
            25
        );
    }
    
    public function render_chat_admin_page(): void {
        if (!current_user_can('manage_options')) {
            wp_die(__('دسترسی غیرمجاز', 'wp-live-chat'));
        }
        ?>

        
        <div class="wrap">
            <h1>مدیریت چت زنده</h1>
                        <!-- دکمه reload را اینجا اضافه کنید -->
            <button id="reload-sessions" class="button button-primary">بارگذاری مجدد گفتگوها</button>
            
            <div id="chat-admin-app">
            
            <div id="chat-admin-app">
                <div class="chat-admin-container">
                    <!-- لیست sessions -->
                    <div class="sessions-list">
                        <h3>گفتگوهای فعال</h3>
                        <div id="sessions-container"></div>
                    </div>
                    
                    <!-- پنجره چت -->
                    <div class="chat-window-admin">
                        <div class="chat-header-admin">
                            <h3 id="current-session-name">انتخاب گفتگو</h3>
                            <span id="session-status" class="status-offline">آفلاین</span>
                        </div>
                        
                        <div class="chat-messages-admin" id="admin-chat-messages">
                            <div class="no-chat-selected">
                                لطفاً یک گفتگو را از لیست انتخاب کنید
                            </div>
                        </div>
                        
                        <div class="chat-input-admin">
                            <textarea id="admin-message-input" placeholder="پیام خود را وارد کنید..." disabled></textarea>
                            <button id="admin-send-button" disabled>ارسال</button>
                        </div>
                    </div>
                </div>
            </div>
            </div>
        </div>
        
        <style>
        .chat-admin-container {
            display: grid;
            grid-template-columns: 300px 1fr;
            gap: 20px;
            height: 70vh;
        }
        
        .sessions-list {
            border: 1px solid #ccc;
            border-radius: 8px;
            padding: 15px;
            background: #f9f9f9;
        }
        
        .session-item {
            padding: 10px;
            border: 1px solid #ddd;
            margin-bottom: 10px;
            border-radius: 5px;
            cursor: pointer;
            background: white;
        }
        
        .session-item.active {
            border-color: #007cba;
            background: #e7f3ff;
        }
        
        .session-item:hover {
            background: #f0f0f0;
        }
        
        .chat-window-admin {
            border: 1px solid #ccc;
            border-radius: 8px;
            display: flex;
            flex-direction: column;
        }
        
        .chat-header-admin {
            padding: 15px;
            background: #f5f5f5;
            border-bottom: 1px solid #ddd;
            display: flex;
            justify-content: space-between;
            align-items: center;
        }
        
        .chat-messages-admin {
            flex: 1;
            padding: 15px;
            overflow-y: auto;
            background: white;
        }
        
        .chat-input-admin {
            padding: 15px;
            border-top: 1px solid #ddd;
            background: #f9f9f9;
        }
        
        .chat-input-admin textarea {
            width: 100%;
            height: 60px;
            resize: vertical;
        }
        
        .message-admin {
            margin-bottom: 10px;
            padding: 8px 12px;
            border-radius: 8px;
            max-width: 80%;
        }
        
        .message-user {
            background: #e3f2fd;
            margin-left: auto;
        }
        
        .message-admin-user {
            background: #f5f5f5;
            margin-right: auto;
        }

                /* استایل‌های پیشرفته برای پنل مدیریت */
        .session-item {
            display: flex;
            justify-content: space-between;
            align-items: center;
            padding: 12px;
            border: 1px solid #e1e1e1;
            border-radius: 8px;
            margin-bottom: 8px;
            cursor: pointer;
            transition: all 0.3s ease;
            background: white;
        }

        .session-item:hover {
            border-color: #007cba;
            background: #f8f9fa;
        }

        .session-item.active {
            border-color: #007cba;
            background: #e7f3ff;
            box-shadow: 0 2px 4px rgba(0, 124, 186, 0.1);
        }

        .session-user {
            flex: 1;
        }

        .session-user strong {
            display: block;
            margin-bottom: 4px;
            color: #1e1e1e;
        }

        .session-user small {
            color: #666;
            font-size: 12px;
        }

        .session-info {
            font-size: 11px;
            color: #888;
            text-align: left;
            margin-right: 10px;
        }

        .session-status {
            display: flex;
            align-items: center;
            gap: 8px;
        }

        .status-dot {
            width: 8px;
            height: 8px;
            border-radius: 50%;
        }

        .status-dot.online {
            background: #46b450;
            animation: pulse 2s infinite;
        }

        .status-dot.offline {
            background: #ccc;
        }

        .unread-badge {
            background: #dc3232;
            color: white;
            border-radius: 10px;
            padding: 2px 6px;
            font-size: 10px;
            min-width: 16px;
            text-align: center;
        }

        .no-sessions, .no-messages {
            text-align: center;
            padding: 20px;
            color: #666;
            font-style: italic;
        }

        /* دکمه reload */
        #reload-sessions {
            margin-bottom: 15px;
            background: #007cba;
            color: white;
            border: none;
            padding: 8px 16px;
            border-radius: 4px;
            cursor: pointer;
        }

        #reload-sessions:hover {
            background: #005a87;
        }

        /* انیمیشن */
        @keyframes pulse {
            0% { opacity: 1; }
            50% { opacity: 0.5; }
            100% { opacity: 1; }
        }
        
        .status-online { color: #46b450; }
        .status-offline { color: #666; }
        </style>


        <?php
    }
    
    public function enqueue_admin_chat_scripts($hook): void {
        if ('toplevel_page_wp-live-chat-admin' !== $hook) {
            return;
        }

        // بارگذاری کتابخانه Pusher
        wp_enqueue_script(
            'pusher',
            'https://js.pusher.com/8.2.0/pusher.min.js',
            [],
            '8.2.0',
            false
        );
        
        wp_enqueue_script('wp-live-chat-admin-app', WP_LIVE_CHAT_PLUGIN_URL . 'assets/js/admin-chat.js', ['jquery', 'pusher'], WP_LIVE_CHAT_VERSION, true);
        
        wp_localize_script('wp-live-chat-admin-app', 'wpLiveChatAdmin', [
            'ajaxurl' => admin_url('admin-ajax.php'),
            'nonce' => wp_create_nonce('wp_live_chat_admin_nonce'),
            'pusherKey' => get_option('wp_live_chat_pusher_key', ''),
            'pusherCluster' => get_option('wp_live_chat_pusher_cluster', 'mt1'),
            'adminId' => get_current_user_id()
        ]);
    }
    
    public function get_session_messages(string $session_id, int $limit = 100): array {
        global $wpdb;
        
        $table_name = $wpdb->prefix . 'wp_live_chat_messages';
        
        try {
            $messages = $wpdb->get_results(
                $wpdb->prepare(
                    "SELECT * FROM $table_name 
                    WHERE session_id = %s 
                    ORDER BY created_at ASC 
                    LIMIT %d",
                    $session_id,
                    $limit
                ),
                ARRAY_A
            );
            
            // اگر خطای دیتابیس وجود دارد، لاگ کن
            if ($wpdb->last_error) {
                error_log('WP Live Chat - Database error in get_session_messages: ' . $wpdb->last_error);
                return [];
            }
            
            return $messages ?: [];
            
        } catch (Exception $e) {
            error_log('WP Live Chat - Exception in get_session_messages: ' . $e->getMessage());
            return [];
        }
    }


    public function get_chat_sessions(): void {
        // بررسی nonce
        if (!wp_verify_nonce($_POST['nonce'] ?? '', 'wp_live_chat_admin_nonce')) {
            wp_send_json_error('Invalid nonce');
            return;
        }

        if (!current_user_can('manage_options')) {
            wp_send_json_error('Access denied');
            return;
        }

        try {
            /** @var Database $database */
            $database = Plugin::get_instance()->get_service('database');
            
            if (!$database) {
                wp_send_json_error('Database service not available');
                return;
            }
            
            // بررسی وجود جداول
            $database->check_and_create_tables();
            
            $sessions = $database->get_active_sessions();
            
            // اضافه کردن اطلاعات اضافی
            foreach ($sessions as &$session) {
                $session['message_count'] = $database->get_session_message_count($session['session_id']);
                $session['last_message'] = $database->get_last_session_message($session['session_id']);
                $session['unread_count'] = 0;
            }
            
            wp_send_json_success($sessions);
            
        } catch (Exception $e) {
            error_log('WP Live Chat - Error in get_chat_sessions: ' . $e->getMessage());
            wp_send_json_error('Error: ' . $e->getMessage());
        }
    }
    
    public function send_admin_message(): void {
        // بررسی nonce
        if (!wp_verify_nonce($_POST['nonce'] ?? '', 'wp_live_chat_admin_nonce')) {
            wp_send_json_error('Invalid nonce');
            return;
        }

        if (!current_user_can('manage_options')) {
            wp_send_json_error('Access denied');
            return;
        }

        $message = sanitize_text_field($_POST['message'] ?? '');
        $session_id = sanitize_text_field($_POST['session_id'] ?? '');
        
        if (empty($message) || empty($session_id)) {
            wp_send_json_error('Message and session ID are required');
            return;
        }
        
        try {
            /** @var Database $database */
            $database = Plugin::get_instance()->get_service('database');
            
            // ذخیره پیام ادمین
            $message_id = $database->save_message([
                'session_id' => $session_id,
                'user_id' => get_current_user_id(),
                'user_name' => 'پشتیبان',
                'message_content' => $message,
                'message_type' => 'admin'
            ]);
            
            if (!$message_id) {
                wp_send_json_error('Failed to save message');
                return;
            }
            
            // ارسال از طریق Pusher
            /** @var Pusher_Service $pusher_service */
            $pusher_service = Plugin::get_instance()->get_service('pusher_service');
            
            $message_data = [
                'id' => $message_id,
                'message' => $message,
                'user_id' => get_current_user_id(),
                'user_name' => 'پشتیبان',
                'session_id' => $session_id,
                'timestamp' => current_time('mysql'),
                'type' => 'admin'
            ];
            
            $result = $pusher_service->trigger(
                'private-chat-' . $session_id,
                'client-message',
                $message_data
            );
            
            if ($result) {
                wp_send_json_success(['message_id' => $message_id]);
            } else {
                wp_send_json_error('Failed to send via Pusher');
            }
            
        } catch (Exception $e) {
            wp_send_json_error('Error: ' . $e->getMessage());
        }
    }

    public function handle_admin_channel_auth(): void {
        // بررسی nonce
        if (!wp_verify_nonce($_POST['nonce'] ?? '', 'wp_live_chat_admin_nonce')) {
            wp_send_json_error('Invalid nonce');
            return;
        }

        if (!current_user_can('manage_options')) {
            wp_send_json_error('Access denied');
            return;
        }

        $socket_id = sanitize_text_field($_POST['socket_id'] ?? '');
        $channel_name = sanitize_text_field($_POST['channel_name'] ?? '');

        if (empty($socket_id) || empty($channel_name)) {
            wp_send_json_error('Invalid authentication data');
            return;
        }

        /** @var Pusher_Service $pusher_service */
        $pusher_service = Plugin::get_instance()->get_service('pusher_service');
        
        if (!$pusher_service->is_connected()) {
            wp_send_json_error('Pusher service not connected');
            return;
        }

        try {
            $auth = $pusher_service->authenticate_channel($channel_name, $socket_id);
            
            if ($auth) {
                header('Content-Type: application/json');
                echo $auth;
                wp_die();
            } else {
                wp_send_json_error('Authentication failed');
            }
        } catch (Exception $e) {
            wp_send_json_error('Auth error: ' . $e->getMessage());
        }
    }

    public function get_session_messages_ajax(): void {
        // بررسی nonce
        if (!wp_verify_nonce($_POST['nonce'] ?? '', 'wp_live_chat_admin_nonce')) {
            wp_send_json_error('Invalid nonce');
            return;
        }

        if (!current_user_can('manage_options')) {
            wp_send_json_error('Access denied');
            return;
        }

        $session_id = sanitize_text_field($_POST['session_id'] ?? '');
        
        if (empty($session_id)) {
            wp_send_json_error('Session ID is required');
            return;
        }

        try {
            /** @var Database $database */
            $database = Plugin::get_instance()->get_service('database');
            
            if (!$database) {
                wp_send_json_error('Database service not available');
                return;
            }
            
            // بررسی وجود جداول
            $database->check_and_create_tables();
            
            $messages = $database->get_session_messages($session_id);
            
            wp_send_json_success($messages);
            
        } catch (Exception $e) {
            error_log('WP Live Chat - Error in get_session_messages_ajax: ' . $e->getMessage());
            wp_send_json_error('Error: ' . $e->getMessage());
        }
    }

}