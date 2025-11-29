<?php

namespace WP_Live_Chat;

use Exception; // این خط را اضافه کنید


class Frontend {
    
    private $session_id;
    
    public function init(): void {
        add_action('wp_enqueue_scripts', [$this, 'enqueue_scripts']);
        add_action('wp_footer', [$this, 'render_chat_widget']);
        add_action('wp_ajax_send_chat_message', [$this, 'handle_send_message']);
        add_action('wp_ajax_nopriv_send_chat_message', [$this, 'handle_send_message']);
        add_action('wp_ajax_auth_pusher_channel', [$this, 'handle_channel_auth']);
        add_action('wp_ajax_nopriv_auth_pusher_channel', [$this, 'handle_channel_auth']);
                // اضافه کردن hook جدید برای تاریخچه چت
        add_action('wp_ajax_get_chat_history', [$this, 'get_chat_history']);
        add_action('wp_ajax_nopriv_get_chat_history', [$this, 'get_chat_history']);
        
        $this->session_id = $this->generate_session_id();
    }

    public function get_chat_history(): void {
        check_ajax_referer('wp_live_chat_nonce', 'nonce');
        
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
            
            // دریافت تمام پیام‌های session (تا 200 پیام اخیر)
            $messages = $database->get_session_messages($session_id, 200);
            
            // لاگ برای دیباگ
            error_log('WP Live Chat - Loading chat history for session: ' . $session_id . ' - Found: ' . count($messages) . ' messages');
            
            wp_send_json_success($messages);
            
        } catch (Exception $e) {
            error_log('WP Live Chat - Error in get_chat_history: ' . $e->getMessage());
            wp_send_json_error('Error loading chat history: ' . $e->getMessage());
        }
    }
    
    private function generate_session_id(): string {
        if (isset($_COOKIE['wp_live_chat_session'])) {
            return sanitize_text_field($_COOKIE['wp_live_chat_session']);
        }
        
        $session_id = 'chat_' . wp_generate_uuid4();
        setcookie('wp_live_chat_session', $session_id, time() + (30 * DAY_IN_SECONDS), COOKIEPATH, COOKIE_DOMAIN);
        
        return $session_id;
    }
    
    public function enqueue_scripts(): void {
        if (!$this->should_display_chat()) {
            return;
        }
        
        // کتابخانه Pusher
        wp_enqueue_script(
            'pusher',
            'https://js.pusher.com/8.2.0/pusher.min.js',
            [],
            '8.2.0',
            true
        );
        
        // اسکریپت اصلی چت
        wp_enqueue_script(
            'wp-live-chat-frontend',
            WP_LIVE_CHAT_PLUGIN_URL . 'build/js/frontend.js',
            ['jquery', 'pusher'],
            WP_LIVE_CHAT_VERSION,
            true
        );
        
        // استایل‌ها - از پوشه build
        $css_path = WP_LIVE_CHAT_PLUGIN_PATH . 'build/frontend-style.css';
        if (file_exists($css_path)) {
            wp_enqueue_style(
                'wp-live-chat-frontend',
                WP_LIVE_CHAT_PLUGIN_URL . 'build/frontend-style.css',
                [],
                WP_LIVE_CHAT_VERSION
            );
        } else {
            // Fallback به استایل داخلی
            $this->add_inline_styles();
        }
        
        // انتقال داده‌ها به JavaScript
        wp_localize_script('wp-live-chat-frontend', 'wpLiveChat', [
            'ajaxurl' => admin_url('admin-ajax.php'),
            'nonce' => wp_create_nonce('wp_live_chat_nonce'),
            'pusherKey' => get_option('wp_live_chat_pusher_key', ''),
            'pusherCluster' => get_option('wp_live_chat_pusher_cluster', 'mt1'),
            'sessionId' => $this->session_id,
            'currentUser' => $this->get_current_user_data(),
            'strings' => [
                'typeMessage' => __('پیام خود را تایپ کنید...', 'wp-live-chat'),
                'send' => __('ارسال', 'wp-live-chat'),
                'online' => __('آنلاین', 'wp-live-chat'),
                'offline' => __('آفلاین', 'wp-live-chat'),
                'connecting' => __('در حال اتصال...', 'wp-live-chat')
            ]
        ]);
    }

    private function add_inline_styles(): void {
        $inline_css = "
            /* WP Live Chat Emergency Styles */
            #wp-live-chat-container {
                position: fixed !important;
                z-index: 999999 !important;
                font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif !important;
            }
            
            .position-bottom-right {
                bottom: 30px !important;
                right: 30px !important;
            }
            
            .position-bottom-left {
                bottom: 30px !important;
                left: 30px !important;
            }
            
            .position-top-right {
                top: 30px !important;
                right: 30px !important;
            }
            
            .position-top-left {
                top: 30px !important;
                left: 30px !important;
            }
            
            .chat-toggle {
                display: flex !important;
                visibility: visible !important;
                position: fixed !important;
                z-index: 999998 !important;
                cursor: pointer !important;
                align-items: center !important;
                justify-content: center !important;
            }
            
            .chat-icon {
                width: 60px !important;
                height: 60px !important;
                background: linear-gradient(135deg, #007cba, #005a87) !important;
                border-radius: 50% !important;
                display: flex !important;
                align-items: center !important;
                justify-content: center !important;
                font-size: 24px !important;
                color: white !important;
                box-shadow: 0 4px 25px rgba(0, 0, 0, 0.15) !important;
            }
            
            .chat-widget {
                width: 380px !important;
                height: 600px !important;
                background: white !important;
                border-radius: 16px !important;
                box-shadow: 0 4px 25px rgba(0, 0, 0, 0.15) !important;
                display: flex !important;
                flex-direction: column !important;
            }
            
            .wp-live-chat-hidden .chat-widget {
                display: none !important;
            }
            
            .wp-live-chat-hidden .chat-toggle {
                display: flex !important;
            }
            
            #wp-live-chat-container:not(.wp-live-chat-hidden) .chat-widget {
                display: flex !important;
            }
            
            #wp-live-chat-container:not(.wp-live-chat-hidden) .chat-toggle {
                display: none !important;
            }
            
            .chat-header {
                padding: 20px !important;
                background: linear-gradient(135deg, #007cba, #005a87) !important;
                color: white !important;
                border-radius: 16px 16px 0 0 !important;
                display: flex !important;
                align-items: center !important;
                justify-content: space-between !important;
            }
            
            .chat-messages {
                flex: 1 !important;
                padding: 20px !important;
                overflow-y: auto !important;
                background: #f8f9fa !important;
                display: flex !important;
                flex-direction: column !important;
            }
            
            .chat-input-area {
                padding: 20px !important;
                background: white !important;
                border-top: 1px solid #ddd !important;
                border-radius: 0 0 16px 16px !important;
            }
            
            @media (max-width: 767px) {
                .position-bottom-right,
                .position-bottom-left {
                    bottom: 20px !important;
                }
                
                .chat-widget {
                    width: calc(100vw - 40px) !important;
                    height: 70vh !important;
                }
            }
        ";
        
        wp_add_inline_style('wp-live-chat-frontend', $inline_css);
    }
    
    private function get_current_user_data(): array {
        $user_data = [
            'id' => 0,
            'name' => $this->generate_guest_name(),
            'email' => '',
            'is_logged_in' => false
        ];
        
        if (is_user_logged_in()) {
            $current_user = wp_get_current_user();
            $user_data = [
                'id' => $current_user->ID,
                'name' => $current_user->display_name ?: $current_user->user_login,
                'email' => $current_user->user_email,
                'is_logged_in' => true
            ];
            
            // اگر نام کاربر خالی است، یک نام پیش‌فرض قرار دهید
            if (empty($user_data['name'])) {
                $user_data['name'] = 'کاربر ' . $current_user->ID;
            }
        }
        
        return $user_data;
    }
    
    private function should_display_chat(): bool {
        // بررسی فعال بودن چت
        if (!get_option('wp_live_chat_enable_chat', true)) {
            return false;
        }
        
        // می‌توانید شرایط خاصی برای نمایش چت اضافه کنید
        return true;
    }
    
    public function render_chat_widget(): void {
        if (!$this->should_display_chat()) {
            return;
        }
        ?>
            <div id="wp-live-chat-container" class="wp-live-chat-hidden position-bottom-left">            <div class="chat-widget">
                <div class="chat-header">
                    <div class="chat-title">
                        <h4><?php echo esc_html__('چت آنلاین', 'wp-live-chat'); ?></h4>
                        <span class="status-indicator">
                            <span class="status-dot"></span>
                            <span class="status-text"><?php echo esc_html__('در حال اتصال...', 'wp-live-chat'); ?></span>
                        </span>
                    </div>
                    <button class="chat-close" aria-label="<?php echo esc_attr__('بستن چت', 'wp-live-chat'); ?>">
                        &times;
                    </button>
                </div>
                
                <div class="chat-messages">
                    <div class="welcome-message">
                        <p><?php echo esc_html__('سلام! چگونه می‌توانم کمک کنم؟', 'wp-live-chat'); ?></p>
                    </div>
                </div>
                
                <div class="chat-input-area">
                    <textarea 
                        placeholder="<?php echo esc_attr__('پیام خود را تایپ کنید...', 'wp-live-chat'); ?>" 
                        rows="3" 
                        maxlength="500"
                    ></textarea>
                    <div class="chat-actions">
                        <span class="char-counter">0/500</span>
                        <button class="send-button" disabled>
                            <?php echo esc_html__('ارسال', 'wp-live-chat'); ?>
                        </button>
                    </div>
                </div>
            </div>
            
            <div class="chat-toggle">
                <div class="chat-icon">💬</div>
                <span class="notification-badge"></span>
            </div>
        </div>
        <?php
    }
    
    public function handle_send_message(): void {
        check_ajax_referer('wp_live_chat_nonce', 'nonce');
        
        $message = sanitize_text_field(wp_unslash($_POST['message'] ?? ''));
        $user_id = intval($_POST['user_id'] ?? 0);
        $user_name = sanitize_text_field(wp_unslash($_POST['user_name'] ?? ''));
        $session_id = sanitize_text_field(wp_unslash($_POST['session_id'] ?? ''));
        
        if (empty($message) || empty($session_id)) {
            wp_send_json_error(__('داده‌های ناقص', 'wp-live-chat'));
        }

        // اعتبارسنجی و پیش‌پردازش نام کاربر
        if (empty($user_name) || $user_name === 'undefined' || $user_name === 'مهمان') {
            $user_name = $this->generate_guest_name();
        }
        
        if (empty($user_name)) {
            $user_name = __('کاربر مهمان', 'wp-live-chat');
        }

        // ابتدا مطمئن شویم session وجود دارد
        /** @var Database $database */
        $database = Plugin::get_instance()->get_service('database');
        
        $user_data = [
            'id' => $user_id,
            'name' => $user_name,
            'email' => sanitize_email($_POST['user_email'] ?? '')
        ];
        
        $session_created = $database->ensure_session_exists($session_id, $user_data);
        
        if (!$session_created) {
            error_log('WP Live Chat: Failed to create session for message');
        }
        
        // سپس پیام را ذخیره کنید
        $message_id = $database->save_message([
            'session_id' => $session_id,
            'user_id' => $user_id,
            'user_name' => $user_name,
            'user_email' => $user_data['email'],
            'message_content' => $message,
            'message_type' => 'user'
        ]);
        
        if ($message_id) {
            // ارسال از طریق Pusher
            /** @var Pusher_Service $pusher_service */
            $pusher_service = Plugin::get_instance()->get_service('pusher_service');
            
            $message_data = [
                'id' => $message_id,
                'message' => $message,
                'user_id' => $user_id,
                'user_name' => $user_name,
                'session_id' => $session_id,
                'timestamp' => current_time('mysql'),
                'type' => 'user'
            ];
            
            $pusher_service->trigger(
                'private-chat-' . $session_id,
                'client-message',
                $message_data
            );
            
            wp_send_json_success(['message_id' => $message_id]);
        } else {
            wp_send_json_error(__('خطا در ذخیره پیام', 'wp-live-chat'));
        }
    }
    
    public function handle_channel_auth(): void {
        // بررسی nonce
        if (!wp_verify_nonce($_POST['nonce'] ?? '', 'wp_live_chat_nonce')) {
            wp_send_json_error('Invalid nonce');
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

        $auth = $pusher_service->authenticate_channel($channel_name, $socket_id);

        if ($auth) {
            // بازگشت داده‌های احراز هویت به فرمت مورد انتظار Pusher
            header('Content-Type: application/json');
            echo $auth;
            wp_die();
        } else {
            wp_send_json_error('Authentication failed');
        }
    }

    private function generate_guest_name(): string {
        $guest_names = [
            'کاربر مهمان ۱',
            'کاربر مهمان ۲', 
            'کاربر مهمان ۳',
            'کاربر مهمان ۴',
            'کاربر مهمان ۵'
        ];
        
        return $guest_names[array_rand($guest_names)] . ' ' . rand(100, 999);
    }
}