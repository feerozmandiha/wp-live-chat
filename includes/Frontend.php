<?php

namespace WP_Live_Chat;

use Exception; // این خط را اضافه کنید


class Frontend {
    
    private $session_id;
    private $user_data;

    
    public function init(): void {
        add_action('wp_enqueue_scripts', [$this, 'enqueue_scripts']);
        add_action('wp_footer', [$this, 'render_chat_widget']);
        add_action('wp_ajax_send_chat_message', [$this, 'handle_send_message']);
        add_action('wp_ajax_nopriv_send_chat_message', [$this, 'handle_send_message']);
        add_action('wp_ajax_auth_pusher_channel', [$this, 'handle_channel_auth']);
        add_action('wp_ajax_nopriv_auth_pusher_channel', [$this, 'handle_channel_auth']);
        add_action('wp_ajax_get_chat_history', [$this, 'get_chat_history']);
        add_action('wp_ajax_nopriv_get_chat_history', [$this, 'get_chat_history']);
        add_action('wp_ajax_save_user_info', [$this, 'save_user_info']);
        add_action('wp_ajax_nopriv_save_user_info', [$this, 'save_user_info']);
        
        $this->session_id = $this->generate_session_id();
        $this->user_data = $this->get_current_user_data();
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

    private function save_user_data(array $data): bool {
        $key = 'wp_live_chat_user_' . $this->session_id;
        // ذخیره به مدت 30 روز
        return set_transient($key, $data, 30 * DAY_IN_SECONDS);
    }

    private function get_saved_user_data(): array {
        $key = 'wp_live_chat_user_' . $this->session_id;
        $data = get_transient($key);
        
        if ($data && is_array($data)) {
            $data['info_completed'] = !empty($data['phone']) && !empty($data['name']);
            return $data;
        }
        
        return [];
    }

    public function save_user_info(): void {
        check_ajax_referer('wp_live_chat_nonce', 'nonce');
        
        $phone = sanitize_text_field($_POST['phone'] ?? '');
        $name = sanitize_text_field($_POST['name'] ?? '');
        $company = sanitize_text_field($_POST['company'] ?? '');
        $session_id = sanitize_text_field($_POST['session_id'] ?? '');
        
        if (empty($phone) || empty($name) || empty($session_id)) {
            wp_send_json_error('لطفاً اطلاعات ضروری را وارد کنید');
            return;
        }
        
        // اعتبارسنجی شماره تلفن
        if (!$this->validate_phone($phone)) {
            wp_send_json_error('شماره تلفن معتبر نیست');
            return;
        }
        
        try {
            // ذخیره اطلاعات کاربر
            $user_data = [
                'id' => 0,
                'name' => $name,
                'email' => '',
                'phone' => $phone,
                'company' => $company,
                'is_logged_in' => false,
                'info_completed' => true
            ];
            
            $saved = $this->save_user_data($user_data);
            
            if ($saved) {
                // آپدیت session با اطلاعات جدید کاربر
                /** @var Database $database */
                $database = Plugin::get_instance()->get_service('database');
                $database->update_session_user_info($session_id, $name, $phone, $company);
                
                wp_send_json_success([
                    'message' => 'اطلاعات با موفقیت ذخیره شد',
                    'user_data' => $user_data
                ]);
            } else {
                wp_send_json_error('خطا در ذخیره اطلاعات');
            }
            
        } catch (Exception $e) {
            wp_send_json_error('خطا: ' . $e->getMessage());
        }
    }

    private function validate_phone($phone): bool {
        // حذف فاصله و کاراکترهای غیرعددی
        $phone = preg_replace('/\D/', '', $phone);
        
        // بررسی طول شماره (حداقل 10 رقم)
        if (strlen($phone) < 10) {
            return false;
        }
        
        // اگر با 0 شروع شده، 0 را حذف کن
        if (substr($phone, 0, 1) === '0') {
            $phone = substr($phone, 1);
        }
        
        // اضافه کردن پیشوند ایران
        if (substr($phone, 0, 2) !== '98') {
            $phone = '98' . $phone;
        }
        
        return strlen($phone) === 12; // 989123456789
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
        
        // انتقال داده‌ها به JavaScript
        wp_localize_script('wp-live-chat-frontend', 'wpLiveChat', [
            'ajaxurl' => admin_url('admin-ajax.php'),
            'nonce' => wp_create_nonce('wp_live_chat_nonce'),
            'pusherKey' => get_option('wp_live_chat_pusher_key', ''),
            'pusherCluster' => get_option('wp_live_chat_pusher_cluster', 'mt1'),
            'sessionId' => $this->session_id,
            'currentUser' => $this->user_data,
            'strings' => [
                'typeMessage' => __('پیام خود را تایپ کنید...', 'wp-live-chat'),
                'send' => __('ارسال', 'wp-live-chat'),
                'online' => __('آنلاین', 'wp-live-chat'),
                'offline' => __('آفلاین', 'wp-live-chat'),
                'connecting' => __('در حال اتصال...', 'wp-live-chat'),
                'welcome' => __('سلام! برای شروع چت، لطفاً اطلاعات خود را وارد کنید.', 'wp-live-chat'),
                'phoneRequired' => __('شماره تلفن همراه الزامی است', 'wp-live-chat'),
                'nameRequired' => __('نام الزامی است', 'wp-live-chat'),
                'invalidPhone' => __('شماره تلفن معتبر نیست', 'wp-live-chat')
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
        // ابتدا بررسی می‌کنیم آیا اطلاعات کاربر از قبل ذخیره شده است
        $saved_data = $this->get_saved_user_data();
        
        if ($saved_data) {
            return $saved_data;
        }
        
        // اگر کاربر لاگین باشد
        if (is_user_logged_in()) {
            $current_user = wp_get_current_user();
            return [
                'id' => $current_user->ID,
                'name' => $current_user->display_name ?: $current_user->user_login,
                'email' => $current_user->user_email,
                'phone' => get_user_meta($current_user->ID, 'phone', true),
                'company' => get_user_meta($current_user->ID, 'company', true),
                'is_logged_in' => true,
                'info_completed' => true
            ];
        }
        
        // کاربر مهمان
        return [
            'id' => 0,
            'name' => $this->generate_guest_name(),
            'email' => '',
            'phone' => '',
            'company' => '',
            'is_logged_in' => false,
            'info_completed' => false
        ];
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
        <div id="wp-live-chat-container" class="wp-live-chat-hidden position-bottom-left">
            <div class="chat-widget">
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
                    <!-- پیام خوش‌آمدگویی -->
                    <div class="welcome-message">
                        <p><?php echo esc_html__('سلام! چگونه می‌توانم کمک کنم؟', 'wp-live-chat'); ?></p>
                    </div>
                </div>
                
                <!-- فرم دریافت اطلاعات کاربر -->
                <div class="user-info-form" id="user-info-form" style="display: none;">
                    <div class="form-header">
                        <h5><?php echo esc_html__('اطلاعات تماس', 'wp-live-chat'); ?></h5>
                        <p><?php echo esc_html__('لطفاً اطلاعات خود را وارد کنید تا بتوانیم با شما در ارتباط باشیم', 'wp-live-chat'); ?></p>
                    </div>
                    
                    <form id="contact-info-form">
                        <div class="form-group">
                            <label for="user-phone"><?php echo esc_html__('شماره تلفن همراه', 'wp-live-chat'); ?> *</label>
                            <input type="tel" id="user-phone" name="phone" required 
                                   placeholder="09xxxxxxxxx" pattern="09[0-9]{9}">
                            <span class="error-message" id="phone-error"></span>
                        </div>
                        
                        <div class="form-group">
                            <label for="user-name"><?php echo esc_html__('نام و نام خانوادگی', 'wp-live-chat'); ?> *</label>
                            <input type="text" id="user-name" name="name" required 
                                   placeholder="نام خود را وارد کنید">
                            <span class="error-message" id="name-error"></span>
                        </div>
                        
                        <div class="form-group">
                            <label for="user-company"><?php echo esc_html__('نام شرکت (اختیاری)', 'wp-live-chat'); ?></label>
                            <input type="text" id="user-company" name="company" 
                                   placeholder="نام شرکت یا سازمان">
                        </div>
                        
                        <div class="form-actions">
                            <button type="submit" class="submit-btn">
                                <?php echo esc_html__('شروع گفتگو', 'wp-live-chat'); ?>
                            </button>
                        </div>
                    </form>
                </div>
                
                <div class="chat-input-area" style="display: none;">
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

                <!-- بخش راه‌های ارتباطی -->
                <div class="salenoo-chat-alternatives">
                    <small><?php echo esc_html__('راه‌های دیگر تماس:', 'wp-live-chat'); ?></small>
                    <div class="salenoo-contact-buttons">
                        <a class="salenoo-contact-btn salenoo-contact-wa" href="https://wa.me/message/IAP7KGPJ32HWP1" target="_blank" rel="noopener noreferrer">
                            <svg width="18" height="18" viewBox="0 0 24 24" fill="none">
                                <path d="M20.52 3.48C18.09 1.05 14.88 0 11.69 0 5.77 0 .98 4.79 .98 10.71c0 1.89.5 3.73 1.45 5.33L0 24l8.33-2.46c1.48.41 3.03.63 4.58.63 5.91 0 10.7-4.79 10.7-10.71 0-3.19-1.05-6.4-2.99-8.31z" fill="#25D366"/>
                                <path d="M17.45 14.21c-.34-.17-2.02-.99-2.34-1.1-.32-.11-.55-.17-.78.17-.23.34-.9 1.1-1.1 1.33-.2.23-.39.26-.73.09-.34-.17-1.44-.53-2.74-1.68-1.01-.9-1.69-2.01-1.89-2.35-.2-.34-.02-.52.15-.69.15-.15.34-.39.51-.59.17-.2.23-.34.34-.56.11-.23 0-.43-.02-.6-.02-.17-.78-1.88-1.07-2.58-.28-.68-.57-.59-.78-.6-.2-.01-.43-.01-.66-.01-.23 0-.6.09-.92.43-.32.34-1.22 1.19-1.22 2.9 0 1.71 1.25 3.37 1.42 3.6.17.23 2.46 3.75 5.96 5.12 3.5 1.37 3.5.92 4.13.86.63-.05 2.02-.82 2.31-1.63.29-.8.29-1.49.2-1.63-.09-.15-.32-.23-.66-.4z" fill="#fff"/>
                            </svg>
                            <span><?php echo esc_html__('واتساپ', 'wp-live-chat'); ?></span>
                        </a>
                        <a class="salenoo-contact-btn salenoo-contact-call" href="tel:09124533878">
                            <svg width="16" height="16" viewBox="0 0 24 24" fill="none">
                                <path d="M22 16.92v3a2 2 0 0 1-2.18 2 19.86 19.86 0 0 1-8.63-3.07 19.5 19.5 0 0 1-6-6 19.86 19.86 0 0 1-3.07-8.63A2 2 0 0 1 4.09 2h3a2 2 0 0 1 2 1.72c.12.99.38 1.95.76 2.84a2 2 0 0 1-.45 2.11L8.91 10.91a16 16 0 0 0 6 6l1.24-1.24a2 2 0 0 1 2.11-.45c.89.38 1.85.64 2.84.76A2 2 0 0 1 22 16.92z" fill="#0066cc"/>
                            </svg>
                            <span><?php echo esc_html__('تماس', 'wp-live-chat'); ?></span>
                        </a>
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