<?php
namespace WP_Live_Chat;

if (!defined('ABSPATH')) {
    exit;
}

class Chat_Frontend {
    public function init(): void {
        add_action('wp_ajax_nopriv_process_conversation_step', [$this, 'handle_process_conversation_step']);
        add_action('wp_ajax_process_conversation_step', [$this, 'handle_process_conversation_step']);
        add_action('wp_ajax_nopriv_get_conversation_step', [$this, 'handle_get_conversation_step']);
        add_action('wp_ajax_get_conversation_step', [$this, 'handle_get_conversation_step']);
        add_action('wp_ajax_nopriv_check_admin_status', [$this, 'handle_check_admin_status']);
        add_action('wp_ajax_check_admin_status', [$this, 'handle_check_admin_status']);
        add_action('wp_ajax_get_chat_history', [$this, 'handle_get_chat_history']);
        add_action('wp_ajax_nopriv_get_chat_history', [$this, 'handle_get_chat_history']);
        add_action('wp_ajax_save_user_info', [$this, 'handle_save_user_info']);
        add_action('wp_ajax_nopriv_save_user_info', [$this, 'handle_save_user_info']);
        add_action('wp_enqueue_scripts', [$this, 'enqueue_assets']);
        add_action('wp_footer', [$this, 'render_chat_widget']);
    }

    public function enqueue_assets(): void {
        if (!(bool) get_option('wp_live_chat_enable_chat', true)) return;

        wp_enqueue_style('wp-live-chat-frontend', WP_LIVE_CHAT_PLUGIN_URL . 'build/css/frontend-style.css', [], WP_LIVE_CHAT_VERSION);
        wp_enqueue_script('pusher', 'https://js.pusher.com/8.2.0/pusher.min.js', [], '8.2.0', true);
        wp_enqueue_script('wp-live-chat-frontend', WP_LIVE_CHAT_PLUGIN_URL . 'build/js/frontend.js', ['jquery', 'pusher'], WP_LIVE_CHAT_VERSION, true);

        $session_id = $this->generate_session_id();
        $user_data = $this->get_saved_user_data($session_id);

        wp_localize_script('wp-live-chat-frontend', 'wpLiveChat', [
            'ajaxurl' => admin_url('admin-ajax.php'),
            'nonce' => wp_create_nonce('wp_live_chat_nonce'),
            'pusherKey' => get_option('wp_live_chat_pusher_key', ''),
            'pusherCluster' => get_option('wp_live_chat_pusher_cluster', 'mt1'),
            'sessionId' => $session_id,
            'userData' => $user_data,
            'strings' => [
                'typeMessage' => __('پیام خود را تایپ کنید...', 'wp-live-chat'),
                'phonePlaceholder' => __('09xxxxxxxxx', 'wp-live-chat'),
                'namePlaceholder' => __('نام شما یا شرکت', 'wp-live-chat'),
                'welcome' => __('👋 سلام! به پشتیبانی آنلاین خوش آمدید', 'wp-live-chat')
            ],
            'debug' => defined('WP_DEBUG') && WP_DEBUG
        ]);
    }

    private function generate_session_id(): string {
        if (!empty($_COOKIE['wp_live_chat_session'])) {
            return sanitize_text_field($_COOKIE['wp_live_chat_session']);
        }
        $id = 'chat_' . wp_generate_uuid4();
        setcookie('wp_live_chat_session', $id, time() + (30 * DAY_IN_SECONDS), COOKIEPATH, COOKIE_DOMAIN);
        return $id;
    }

    private function get_saved_user_data(string $session_id): array {
        $key = 'wp_live_chat_user_' . $session_id;
        $data = get_transient($key);
        return is_array($data) ? $data : [];
    }

    public function render_chat_widget(): void {
        if (!(bool) get_option('wp_live_chat_enable_chat', true)) return;
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
                        <p><?php echo esc_html($this->get_welcome_text()); ?></p>
                    </div>
                </div>
                <div class="chat-input-area">
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

    private function get_welcome_text(): string {
        return get_option('wp_live_chat_welcome_text', __('👋 سلام! به پشتیبانی آنلاین خوش آمدید.', 'wp-live-chat'));
    }

    // همه handlerهای AJAX در ادامه — بدون تغییر و بدون تداخل
    // ...

    public function handle_get_chat_history(): void {
        check_ajax_referer('wp_live_chat_nonce', 'nonce');
        $session_id = sanitize_text_field($_POST['session_id'] ?? '');
        if (empty($session_id)) {
            wp_send_json_error('شناسه جلسه الزامی است');
        }
        try {
            $database = Plugin::get_instance()->get_service('database');
            $messages = $database->get_session_messages($session_id);
            wp_send_json_success($messages);
        } catch (\Exception $e) {
            wp_send_json_error('خطای سرور: ' . $e->getMessage());
        }
    }

    public function handle_save_user_info(): void {
        check_ajax_referer('wp_live_chat_nonce', 'nonce');
        $session_id = sanitize_text_field($_POST['session_id'] ?? '');
        $phone = sanitize_text_field($_POST['phone'] ?? '');
        $name = sanitize_text_field($_POST['name'] ?? '');
        if (empty($session_id) || (empty($phone) && empty($name))) {
            wp_send_json_error('اطلاعات الزامی است');
        }
        try {
            $database = Plugin::get_instance()->get_service('database');
            $success = $database->update_session_user_info($session_id, $name, $phone, '');
            wp_send_json_success(['saved' => true]);
        } catch (\Exception $e) {
            wp_send_json_error('خطای سرور: ' . $e->getMessage());
        }
    }

    public function handle_process_conversation_step(): void {
        check_ajax_referer('wp_live_chat_nonce', 'nonce');
        $session_id = sanitize_text_field($_POST['session_id'] ?? '');
        $input = sanitize_textarea_field($_POST['input'] ?? '');
        if (empty($session_id) || empty($input)) {
            wp_send_json_error('شناسه جلسه و ورودی الزامی است');
        }
        try {
            $flow = new Conversation_Flow($session_id);
            $result = $flow->process_input($input);
            if ($result['success']) {
                $database = Plugin::get_instance()->get_service('database');
                if (!empty($result['user_data']['phone']) && !empty($result['user_data']['name'])) {
                    $database->update_session_user_info(
                        $session_id,
                        $result['user_data']['name'],
                        $result['user_data']['phone'],
                        ''
                    );
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
        check_ajax_referer('wp_live_chat_nonce', 'nonce');
        $session_id = sanitize_text_field($_POST['session_id'] ?? '');
        if (empty($session_id)) {
            wp_send_json_error('شناسه جلسه الزامی است');
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
            $admins = get_users(['role' => 'administrator']);
            $online = false;
            foreach ($admins as $admin) {
                $last = (int) get_user_meta($admin->ID, 'wp_live_chat_last_activity', true);
                if (time() - $last < 300) { // 5 دقیقه
                    $online = true;
                    break;
                }
            }
            wp_send_json_success(['admin_online' => $online]);
        } catch (\Exception $e) {
            wp_send_json_error('خطای سرور: ' . $e->getMessage());
        }
    }
}