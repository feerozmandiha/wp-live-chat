<?php
namespace WP_LIVE_CHAT;

if (!defined('WP_LIVE_CHAT_PLUGIN_FILE')) {
    return;
}

class Frontend {
    private string $session_id;
    private array $user_data = [];

    public function init(): void {
        add_action('wp_enqueue_scripts', [$this, 'enqueue_assets']);
        add_action('wp_footer', [$this, 'render_chat_widget']);

        // AJAX handlers
        add_action('wp_ajax_send_chat_message', [$this, 'handle_send_chat_message']);
        add_action('wp_ajax_nopriv_send_chat_message', [$this, 'handle_send_chat_message']);
        add_action('wp_ajax_get_chat_history', [$this, 'handle_get_chat_history']);
        add_action('wp_ajax_nopriv_get_chat_history', [$this, 'handle_get_chat_history']);
        add_action('wp_ajax_save_user_info', [$this, 'handle_save_user_info']);
        add_action('wp_ajax_nopriv_save_user_info', [$this, 'handle_save_user_info']);
        add_action('wp_ajax_send_welcome_message', [$this, 'handle_send_welcome_message']);
        add_action('wp_ajax_nopriv_send_welcome_message', [$this, 'handle_send_welcome_message']);

        $this->session_id = $this->generate_session_id();
        $this->user_data = $this->get_saved_user_data();
    }

    public function enqueue_assets(): void {
        if (!$this->should_display_chat()) {
            return;
        }

        // اگر شما SCSS را کامپایل کرده‌اید خروجی CSS فایل frontend.css خواهد بود
        wp_enqueue_style('wp-live-chat-frontend', WP_LIVE_CHAT_PLUGIN_URL . 'build/css/frontend-style.css', [], WP_LIVE_CHAT_VERSION);

        // Pusher client
        wp_enqueue_script('pusher', 'https://js.pusher.com/8.2.0/pusher.min.js', [], '8.2.0', true);

        // Frontend JS (از قبل نسخه‌ی اصلاح شده‌ی frontend.js را دارید)
        wp_enqueue_script('wp-live-chat-frontend', WP_LIVE_CHAT_PLUGIN_URL . 'build/js/frontend.js', ['jquery','pusher'], WP_LIVE_CHAT_VERSION, true);

        // انتقال داده‌ها به JS
        wp_localize_script('wp-live-chat-frontend', 'wpLiveChat', [
            'ajaxurl' => admin_url('admin-ajax.php'),
            'nonce' => wp_create_nonce('wp_live_chat_nonce'),
            'pusherKey' => get_option('wp_live_chat_pusher_key', ''),
            'pusherCluster' => get_option('wp_live_chat_pusher_cluster', ''),
            'sessionId' => $this->session_id,
            'userData' => $this->user_data,
            'strings' => [
                'welcome' => __('سلام! به پشتیبانی آنلاین خوش آمدید.', 'wp-live-chat'),
                'typeMessage' => __('پیام خود را تایپ کنید...', 'wp-live-chat'),
                'send' => __('ارسال', 'wp-live-chat'),
            ]
        ]);
    }

    public function render_chat_widget(): void {
        if (!$this->should_display_chat()) {
            return;
        }

        // ساختار HTML مطابق با SCSS شما
        ?>
        <div id="wp-live-chat-container" class="position-bottom-left wp-live-chat-hidden">
            <div class="chat-widget" role="dialog" aria-label="<?php esc_attr_e('چت آنلاین', 'wp-live-chat'); ?>">
                <div class="chat-header">
                    <div class="chat-title">
                        <h4><?php esc_html_e('چت آنلاین', 'wp-live-chat'); ?></h4>
                        <div class="status-indicator">
                            <span class="status-dot connecting"></span>
                            <span class="status-text"><?php esc_html_e('در حال اتصال...', 'wp-live-chat'); ?></span>
                        </div>
                    </div>
                    <button class="chat-close" aria-label="<?php esc_attr_e('بستن چت', 'wp-live-chat'); ?>">&times;</button>
                </div>

                <div class="chat-messages" aria-live="polite">
                    <div class="welcome-message system-message">
                        <div class="message-content">
                            <p><?php echo esc_html($this->get_welcome_text()); ?></p>
                        </div>
                    </div>
                </div>

                <div class="user-info-form">
                    <div class="form-group">
                        <label for="wlch-phone"><?php esc_html_e('شماره موبایل', 'wp-live-chat'); ?></label>
                        <input id="wlch-phone" type="text" placeholder="<?php esc_attr_e('09xxxxxxxxx', 'wp-live-chat'); ?>" />
                    </div>
                    <div class="form-group">
                        <label for="wlch-name"><?php esc_html_e('نام یا شرکت', 'wp-live-chat'); ?></label>
                        <input id="wlch-name" type="text" placeholder="<?php esc_attr_e('نام شما یا شرکت', 'wp-live-chat'); ?>" />
                    </div>
                    <div class="form-actions">
                        <button class="submit-btn" id="wlch-save-info"><?php esc_html_e('ارسال اطلاعات', 'wp-live-chat'); ?></button>
                        <button class="skip-btn" id="wlch-skip-info"><?php esc_html_e('رد کردن', 'wp-live-chat'); ?></button>
                    </div>
                </div>

                <div class="chat-input-area" style="display:none;">
                    <textarea id="wlch-textarea" placeholder="<?php esc_attr_e('پیام خود را تایپ کنید...', 'wp-live-chat'); ?>" rows="3" maxlength="500"></textarea>
                    <div class="chat-actions">
                        <span class="char-counter" id="wlch-counter">0/500</span>
                        <button class="send-button" id="wlch-send-btn" disabled><?php esc_html_e('ارسال', 'wp-live-chat'); ?></button>
                    </div>
                </div>

                <div class="chat-alternatives">
                    <small><?php esc_html_e('راه‌های دیگر تماس:', 'wp-live-chat'); ?></small>
                    <div class="contact-buttons">
                        <a class="contact-btn whatsapp" href="https://wa.me/message/IAP7KGPJ32HWP1" target="_blank" rel="noopener noreferrer"><?php esc_html_e('واتساپ', 'wp-live-chat'); ?></a>
                        <a class="contact-btn call" href="tel:09124533878"><?php esc_html_e('تماس', 'wp-live-chat'); ?></a>
                    </div>
                </div>
            </div>

            <div class="chat-toggle" role="button" aria-label="<?php esc_attr_e('باز کردن چت', 'wp-live-chat'); ?>">
                <div class="chat-icon">💬</div>
                <span class="notification-badge" id="wlch-notification" style="display:none;">0</span>
            </div>
        </div>
        <?php
    }

    // اضافه کردن به کلاس Frontend
    public function handle_send_chat_message(): void {
        check_ajax_referer('wp_live_chat_nonce', 'nonce');

        $message = sanitize_text_field($_POST['message'] ?? '');
        $session_id = sanitize_text_field($_POST['session_id'] ?? '');
        $user_name = sanitize_text_field($_POST['user_name'] ?? ($this->user_data['name'] ?? 'کاربر'));
        $user_id = intval($_POST['user_id'] ?? ($this->user_data['id'] ?? 0));

        if (empty($message) || empty($session_id)) {
            wp_send_json_error('پیام و شناسه جلسه الزامی است');
            return;
        }

        $database = Plugin::get_instance()->get_service('database');
        if (!$database) {
            wp_send_json_error('پایگاه داده در دسترس نیست');
            return;
        }

        $message_id = $database->save_message([
            'session_id' => $session_id,
            'user_id' => $user_id,
            'user_name' => $user_name,
            'message_content' => $message,
            'message_type' => 'user'
        ]);

        if ($message_id) {
            $pusher_service = Plugin::get_instance()->get_service('pusher_service');
            
            if ($pusher_service && $pusher_service->is_connected()) {
                // ارسال پیام به کانال کاربر
                $pusher_service->trigger(
                    "chat-{$session_id}",
                    'new-message',
                    [
                        'id' => $message_id,
                        'message' => $message,
                        'user_name' => $user_name,
                        'timestamp' => current_time('mysql'),
                        'type' => 'user'
                    ]
                );
                
                // اطلاع به ادمین‌ها
                $pusher_service->trigger(
                    'admin-notifications',
                    'user-message-sent',
                    [
                        'session_id' => $session_id,
                        'message_id' => $message_id,
                        'user_name' => $user_name,
                        'message_preview' => mb_substr($message, 0, 100)
                    ]
                );
            }

            wp_send_json_success([
                'message_id' => $message_id,
                'pusher_sent' => $pusher_service && $pusher_service->is_connected()
            ]);
            return;
        }

        wp_send_json_error('خطا در ذخیره پیام');
    }

    public function handle_save_user_info(): void {
        check_ajax_referer('wp_live_chat_nonce', 'nonce');

        $session_id = sanitize_text_field($_POST['session_id'] ?? $this->session_id);
        $phone = sanitize_text_field($_POST['phone'] ?? '');
        $name = sanitize_text_field($_POST['name'] ?? '');
        $company = sanitize_text_field($_POST['company'] ?? '');

        if (empty($session_id) || (empty($phone) && empty($name))) {
            wp_send_json_error('داده‌های نامعتبر');
            return;
        }

        $database = Plugin::get_instance()->get_service('database');
        if (!$database) { wp_send_json_error('DB unavailable'); return; }

        $ok = $database->update_session_user_info($session_id, $name ?: 'کاربر', $phone ?: '', $company ?: '');
        if ($ok) {
            // پیام خوش‌آمد (سرور پخش خواهد کرد)
            $msg_id = $database->save_message([
                'session_id' => $session_id,
                'user_id' => 0,
                'user_name' => 'سیستم',
                'message_content' => sprintf(__('✅ ممنون %s! اطلاعات شما ثبت شد.', 'wp-live-chat'), $name ?: ''),
                'message_type' => 'system'
            ]);

            $pusher = Plugin::get_instance()->get_service('pusher');
            if ($pusher && $msg_id) {
                $pusher->trigger("chat-{$session_id}", 'new-message', [
                    'id' => $msg_id,
                    'message' => sprintf(__('✅ ممنون %s! اطلاعات شما ثبت شد.', 'wp-live-chat'), $name ?: ''),
                    'user_name' => 'سیستم',
                    'timestamp' => current_time('mysql'),
                    'type' => 'system'
                ]);
                $pusher->trigger('admin-chat-channel', 'user-info-completed', [
                    'session_id' => $session_id,
                    'user_name' => $name
                ]);
            }

            wp_send_json_success(['message' => 'اطلاعات ذخیره شد']);
            return;
        }

        wp_send_json_error('خطا در ذخیره اطلاعات');
    }

    public function handle_get_chat_history(): void {
        check_ajax_referer('wp_live_chat_nonce', 'nonce');

        $session_id = sanitize_text_field($_POST['session_id'] ?? $this->session_id);
        if (empty($session_id)) { wp_send_json_error('Session ID required'); return; }

        $database = Plugin::get_instance()->get_service('database');
        if (!$database) { wp_send_json_error('DB unavailable'); return; }

        $messages = $database->get_session_messages($session_id, 500);
        wp_send_json_success($messages);
    }

    public function handle_send_welcome_message(): void {
        check_ajax_referer('wp_live_chat_nonce', 'nonce');

        $session_id = sanitize_text_field($_POST['session_id'] ?? $this->session_id);
        $user_name = sanitize_text_field($_POST['user_name'] ?? ($this->user_data['name'] ?? ''));

        if (empty($session_id) || empty($user_name)) { wp_send_json_error('Invalid'); return; }

        $db = Plugin::get_instance()->get_service('database');
        $id = $db->save_message([
            'session_id' => $session_id,
            'user_id' => 0,
            'user_name' => 'سیستم',
            'message_content' => sprintf(__('✅ ممنون %s! اطلاعات شما ثبت شد.', 'wp-live-chat'), $user_name),
            'message_type' => 'system'
        ]);

        if ($id) {
            $pusher = Plugin::get_instance()->get_service('pusher');
            if ($pusher) {
                $pusher->trigger("chat-{$session_id}", 'new-message', [
                    'id' => $id,
                    'message' => sprintf(__('✅ ممنون %s! اطلاعات شما ثبت شد.', 'wp-live-chat'), $user_name),
                    'user_name' => 'سیستم',
                    'timestamp' => current_time('mysql'),
                    'type' => 'system'
                ]);
                $pusher->trigger('admin-chat-channel', 'user-info-completed', ['session_id' => $session_id, 'user_name' => $user_name]);
            }
            wp_send_json_success(['message_id' => $id]);
            return;
        }

        wp_send_json_error('Failed to save welcome message');
    }

    private function generate_session_id(): string {
        if (!empty($_COOKIE['wp_live_chat_session'])) {
            return sanitize_text_field($_COOKIE['wp_live_chat_session']);
        }
        $id = 'chat_' . wp_generate_uuid4();
        setcookie('wp_live_chat_session', $id, time() + (30 * DAY_IN_SECONDS), COOKIEPATH, COOKIE_DOMAIN);
        return $id;
    }

    private function get_saved_user_data(): array {
        $key = 'wp_live_chat_user_' . ($this->session_id ?? ($_COOKIE['wp_live_chat_session'] ?? ''));
        $data = get_transient($key);
        if ($data && is_array($data)) {
            $data['info_completed'] = !empty($data['phone']) && !empty($data['name']);
            return $data;
        }
        return [];
    }

    private function should_display_chat(): bool {
        return (bool) get_option('wp_live_chat_enable_chat', true);
    }

    private function get_welcome_text(): string {
        return get_option('wp_live_chat_welcome_text', __('👋 سلام! به پشتیبانی آنلاین خوش آمدید.', 'wp-live-chat'));
    }
}
