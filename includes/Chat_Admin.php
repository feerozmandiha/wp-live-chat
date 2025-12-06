<?php
namespace WP_Live_Chat;

class Chat_Admin {
    
    public function init(): void {
        add_action('admin_menu', [$this, 'add_chat_admin_page']);
        add_action('admin_enqueue_scripts', [$this, 'enqueue_admin_chat_scripts']);
        
        // AJAX handlers
        $ajax_actions = [
            'get_chat_sessions',
            'get_session_messages',
            'send_admin_message'
        ];
        
        foreach ($ajax_actions as $action) {
            add_action("wp_ajax_{$action}", [$this, "handle_{$action}"]);
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
            <h1><?php _e('مدیریت چت زنده', 'wp-live-chat'); ?></h1>
            
            <div id="chat-admin-app">
                <div class="chat-admin-container">
                    <div class="sessions-panel">
                        <div class="panel-header">
                            <h3><?php _e('گفتگوهای فعال', 'wp-live-chat'); ?></h3>
                            <button id="refresh-sessions" class="button button-small">
                                <?php _e('بروزرسانی', 'wp-live-chat'); ?>
                            </button>
                        </div>
                        <div id="sessions-list" class="sessions-list"></div>
                    </div>
                    
                    <div class="chat-panel">
                        <div class="chat-header">
                            <h3 id="current-session-title"><?php _e('انتخاب گفتگو', 'wp-live-chat'); ?></h3>
                            <span id="session-status" class="status-offline"></span>
                        </div>
                        
                        <div class="chat-messages" id="admin-chat-messages">
                            <div class="no-chat-selected">
                                <?php _e('لطفاً یک گفتگو را از لیست انتخاب کنید', 'wp-live-chat'); ?>
                            </div>
                        </div>
                        
                        <div class="chat-input" id="admin-chat-input" style="display: none;">
                            <textarea id="admin-message-input" 
                                      placeholder="<?php _e('پیام خود را وارد کنید...', 'wp-live-chat'); ?>"
                                      rows="3"></textarea>
                            <div class="chat-actions">
                                <button id="admin-send-button" class="button button-primary">
                                    <?php _e('ارسال', 'wp-live-chat'); ?>
                                </button>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
        <?php
    }
    
    public function enqueue_admin_chat_scripts(string $hook): void {
        if ('toplevel_page_wp-live-chat-admin' !== $hook) {
            return;
        }
        
        // Pusher library
        wp_enqueue_script(
            'pusher',
            'https://js.pusher.com/8.2.0/pusher.min.js',
            [],
            '8.2.0',
            true
        );
        
        // Admin chat JS
        wp_enqueue_script(
            'wp-live-chat-admin-chat',
            WP_LIVE_CHAT_PLUGIN_URL . 'build/js/admin-chat.js',
            ['jquery', 'pusher'],
            WP_LIVE_CHAT_VERSION,
            true
        );
        
        // Admin chat CSS
        wp_enqueue_style(
            'wp-live-chat-admin-chat',
            WP_LIVE_CHAT_PLUGIN_URL . 'build/css/admin-style.css',
            [],
            WP_LIVE_CHAT_VERSION
        );
        
        wp_localize_script('wp-live-chat-admin-chat', 'wpLiveChatAdmin', [
            'ajaxurl' => admin_url('admin-ajax.php'),
            'nonce' => wp_create_nonce('wp_live_chat_admin_nonce'),
            'pusherKey' => get_option('wp_live_chat_pusher_key', ''),
            'pusherCluster' => get_option('wp_live_chat_pusher_cluster', 'mt1'),
            'currentUser' => [
                'id' => get_current_user_id(),
                'name' => wp_get_current_user()->display_name
            ],
            'strings' => [
                'noActiveChats' => __('هیچ گفتگوی فعالی وجود ندارد', 'wp-live-chat'),
                'selectChat' => __('انتخاب گفتگو', 'wp-live-chat'),
                'online' => __('آنلاین', 'wp-live-chat'),
                'offline' => __('آفلاین', 'wp-live-chat')
            ]
        ]);
    }
    
    // در Chat_Admin.php
    public function handle_get_chat_sessions(): void {
        check_ajax_referer('wp_live_chat_admin_nonce', 'nonce');
        if (!current_user_can('manage_options')) {
            wp_send_json_error('دسترسی غیرمجاز');
        }
        try {
            $database = Plugin::get_instance()->get_service('database');
            $sessions = $database->get_active_sessions();
            foreach ($sessions as &$session) {
                $session['unread_count'] = $database->get_unread_count($session['session_id'], get_current_user_id());
                $session['has_unread'] = $session['unread_count'] > 0;
            }
            wp_send_json_success($sessions);
        } catch (\Exception $e) {
            wp_send_json_error($e->getMessage());
        }
    }

    // متد جدید برای علامت گذاری پیام‌ها به عنوان خوانده شده
    public function handle_mark_as_read(): void {
        check_ajax_referer('wp_live_chat_admin_nonce', 'nonce');
        
        if (!current_user_can('manage_options')) {
            wp_send_json_error('دسترسی غیرمجاز');
        }
        
        $session_id = sanitize_text_field($_POST['session_id'] ?? '');
        $message_ids = isset($_POST['message_ids']) ? array_map('intval', (array)$_POST['message_ids']) : [];
        
        try {
            $database = Plugin::get_instance()->get_service('database');
            $result = $database->mark_messages_as_read($session_id, get_current_user_id(), $message_ids);
            
            wp_send_json_success([
                'marked_count' => $result,
                'session_id' => $session_id
            ]);
        } catch (\Exception $e) {
            wp_send_json_error($e->getMessage());
        }
    }
    
    public function handle_get_session_messages(): void {
        check_ajax_referer('wp_live_chat_admin_nonce', 'nonce');
        
        if (!current_user_can('manage_options')) {
            wp_send_json_error('دسترسی غیرمجاز');
        }
        
        $session_id = sanitize_text_field($_POST['session_id'] ?? '');
        
        if (empty($session_id)) {
            wp_send_json_error('شناسه جلسه الزامی است');
        }
        
        try {
            $database = Plugin::get_instance()->get_service('database');
            $messages = $database->get_session_messages($session_id);
            
            wp_send_json_success($messages);
        } catch (\Exception $e) {
            wp_send_json_error($e->getMessage());
        }
    }
    
    public function handle_send_admin_message(): void {
        // بررسی nonce - اصلاح شده
        if (!isset($_POST['nonce']) || !wp_verify_nonce($_POST['nonce'], 'wp_live_chat_admin_nonce')) {
            wp_send_json_error('Nonce verification failed', 403);
            return;
        }
        
        if (!current_user_can('manage_options')) {
            wp_send_json_error('دسترسی غیرمجاز', 403);
            return;
        }
        
        $session_id = sanitize_text_field($_POST['session_id'] ?? '');
        $message = sanitize_text_field($_POST['message'] ?? '');
        
        if (empty($session_id) || empty($message)) {
            wp_send_json_error('شناسه جلسه و پیام الزامی است', 400);
            return;
        }
        
        try {
            $database = Plugin::get_instance()->get_service('database');
            
            if (!$database) {
                wp_send_json_error('پایگاه داده در دسترس نیست', 500);
                return;
            }
            
            // ذخیره پیام ادمین
            $message_id = $database->save_message([
                'session_id' => $session_id,
                'user_id' => get_current_user_id(),
                'user_name' => 'پشتیبان',
                'message_content' => $message,
                'message_type' => 'admin'
            ]);
            
            if ($message_id) {
                $pusher_service = Plugin::get_instance()->get_service('pusher_service');
                
                if ($pusher_service && $pusher_service->is_connected()) {
                    // ارسال به کانال کاربر
                    $pusher_result = $pusher_service->trigger(
                        "private-chat-{$session_id}",
                        'new-message',
                        [
                            'id' => $message_id,
                            'message' => $message,
                            'user_name' => 'پشتیبان',
                            'type' => 'admin',
                            'timestamp' => current_time('mysql')
                        ]
                    );

                    
                    if (!$pusher_result) {
                        error_log('WP Live Chat: Failed to trigger Pusher event for admin message ' . $message_id);
                    }
                } else {
                    error_log('WP Live Chat: Pusher service not connected for admin message');
                }
                
                wp_send_json_success([
                    'message_id' => $message_id,
                    'pusher_sent' => $pusher_service && $pusher_service->is_connected()
                ]);
                return;
            }
            
            wp_send_json_error('خطا در ذخیره پیام', 500);
        } catch (\Exception $e) {
            wp_send_json_error('خطای سرور: ' . $e->getMessage(), 500);
        }
    }
}