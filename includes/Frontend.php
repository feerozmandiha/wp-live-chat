<?php
namespace WP_Live_Chat;


if (!defined('WP_LIVE_CHAT_PLUGIN_FILE')) {
    return;
}

use Exception;

class Frontend {
    private string $session_id;
    private array $user_data = [];
    private Conversation_Flow $conversation_flow;
    

    public function init(): void {
        add_action('wp_enqueue_scripts', [$this, 'enqueue_assets']);
        add_action('wp_footer', [$this, 'render_chat_widget']);
        add_action('wp_ajax_send_chat_message', [$this, 'handle_send_chat_message']);
        add_action('wp_ajax_nopriv_send_chat_message', [$this, 'handle_send_chat_message']);
        
        // add_action('wp_ajax_get_conversation_step', [$this, 'handle_get_conversation_step']);
        // add_action('wp_ajax_nopriv_get_conversation_step', [$this, 'handle_get_conversation_step']);
        
        // add_action('wp_ajax_process_conversation_step', [$this, 'handle_process_conversation_step']);
        // add_action('wp_ajax_nopriv_process_conversation_step', [$this, 'handle_process_conversation_step']);
        
        // add_action('wp_ajax_check_admin_status', [$this, 'handle_check_admin_status']);
        // add_action('wp_ajax_nopriv_check_admin_status', [$this, 'handle_check_admin_status']);
        // add_action('wp_ajax_handle_notify_admin_connected', [$this, 'handle_notify_admin_connected']);
        // add_action('wp_ajax_nopriv_handle_notify_admin_connected', [$this, 'handle_notify_admin_connected']);

        // AJAX handlers
        add_action('wp_ajax_get_chat_history', [$this, 'handle_get_chat_history']);
        add_action('wp_ajax_nopriv_get_chat_history', [$this, 'handle_get_chat_history']);
        add_action('wp_ajax_save_user_info', [$this, 'handle_save_user_info']);
        add_action('wp_ajax_nopriv_save_user_info', [$this, 'handle_save_user_info']);
        add_action('wp_ajax_send_welcome_message', [$this, 'handle_send_welcome_message']);
        add_action('wp_ajax_nopriv_send_welcome_message', [$this, 'handle_send_welcome_message']);

        $this->session_id = $this->generate_session_id();
        try {
            // بررسی وجود کلاس Conversation_Flow
            if (!class_exists('WP_Live_Chat\Conversation_Flow')) {
                // اگر کلاس وجود ندارد، فایل را include کن
                $flow_file = WP_LIVE_CHAT_PLUGIN_PATH . 'includes/Conversation_Flow.php';
                if (file_exists($flow_file)) {
                    require_once $flow_file;
                } else {
                    // اگر فایل وجود ندارد، لاگ کن و از حالت ساده استفاده کن
                    error_log('WP Live Chat: Conversation_Flow.php not found at ' . $flow_file);
                    $this->use_simple_flow();
                    return;
                }
            }
            
            $this->conversation_flow = new Conversation_Flow($this->session_id);
            $this->user_data = $this->conversation_flow->get_user_data();
            
        } catch (Exception $e) {
            error_log('WP Live Chat: Error initializing Conversation_Flow: ' . $e->getMessage());
            $this->use_simple_flow();
        }
    }

    private function use_simple_flow() {
        // حالت ساده fallback
        $this->user_data = $this->get_saved_user_data();
        
        // اضافه کردن hook برای دیباگ
        add_action('admin_notices', function() {
            if (current_user_can('manage_options')) {
                echo '<div class="notice notice-warning"><p>WP Live Chat: Conversation Flow در حالت ساده کار می‌کند. فایل Conversation_Flow.php بررسی شود.</p></div>';
            }
        });
    }

    public function render_chat_widget(): void {
        if (!$this->should_display_chat()) {
            return;
        }

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


    public function handle_save_user_info(): void {
        $this->verify_nonce('save_user_info');
        
        $session_id = sanitize_text_field($_POST['session_id'] ?? '');
        $phone = sanitize_text_field($_POST['phone'] ?? '');
        $name = sanitize_text_field($_POST['name'] ?? '');
        $company = sanitize_text_field($_POST['company'] ?? '');
        
        if (empty($session_id) || (empty($phone) && empty($name))) {
            wp_send_json_error('اطلاعات الزامی است');
        }
        
        try {
            $database = Plugin::get_instance()->get_service('database');
            $success = $database->update_session_user_info($session_id, $name, $phone, $company);
            
            if ($success) {
                wp_send_json_success(['saved' => true]);
            } else {
                wp_send_json_error('خطا در ذخیره اطلاعات');
            }
            
        } catch (\Exception $e) {
            wp_send_json_error('خطای سرور: ' . $e->getMessage());
        }
    }

    public function handle_get_chat_history(): void {
        $this->verify_nonce('get_chat_history');
        
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
            $pusher = Plugin::get_instance()->get_service('pusher_service');
            if ($pusher) {
                $pusher->trigger("private-chat-{$session_id}", 'new-message', [
                    'id' => $id,
                    'message' => sprintf(__('✅ ممنون %s! اطلاعات شما ثبت شد.', 'wp-live-chat'), $user_name),
                    'user_name' => 'سیستم',
                    'timestamp' => current_time('mysql'),
                    'type' => 'system'
                ]);
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

    public function handle_send_chat_message(): void {
        $this->verify_nonce('wp_live_chat_nonce');
        
        $session_id = sanitize_text_field($_POST['session_id'] ?? '');
        $message = sanitize_textarea_field($_POST['message'] ?? '');
        
        if (empty($session_id) || empty($message)) {
            wp_send_json_error('شناسه جلسه و پیام الزامی است');
        }
        
        try {
            $database = Plugin::get_instance()->get_service('database');
            
            $message_id = $database->save_message([
                'session_id' => $session_id,
                'user_name' => 'کاربر',
                'message_content' => $message,
                'message_type' => 'user'
            ]);
            
            if ($message_id) {
                // ارسال از طریق Pusher
                $pusher_service = Plugin::get_instance()->get_service('pusher_service');
                if ($pusher_service && $pusher_service->is_connected()) {
                    $pusher_service->trigger(
                        "private-chat-{$session_id}",
                        'new-message',
                        [
                            'id' => $message_id,
                            'message' => $message,
                            'user_name' => 'کاربر',
                            'timestamp' => current_time('mysql'),
                            'type' => 'user'
                        ]
                    );
                }
                
                wp_send_json_success(['message_id' => $message_id]);
            } else {
                wp_send_json_error('خطا در ذخیره پیام');
            }
            
        } catch (\Exception $e) {
            wp_send_json_error('خطای سرور: ' . $e->getMessage());
        }
    }
    
    // private function send_system_response($flow_result) {
    //     $database = Plugin::get_instance()->get_service('database');
    //     $pusher_service = Plugin::get_instance()->get_service('pusher_service');
        
    //     if (!$database || !$pusher_service) {
    //         return;
    //     }
        
    //     // ارسال پیام سیستم
    //     $system_message = $flow_result['message'] ?? '';
        
    //     if (!empty($system_message)) {
    //         $system_message_id = $database->save_message([
    //             'session_id' => $this->session_id,
    //             'user_id' => 0,
    //             'user_name' => 'سیستم',
    //             'message_content' => $system_message,
    //             'message_type' => 'system'
    //         ]);
            
    //         if ($system_message_id) {
    //             $pusher_service->trigger(
    //                 "chat-{$this->session_id}",
    //                 'new-message',
    //                 [
    //                     'id' => $system_message_id,
    //                     'message' => $system_message,
    //                     'user_name' => 'سیستم',
    //                     'timestamp' => current_time('mysql'),
    //                     'type' => 'system',
    //                     'step' => $flow_result['next_step']
    //                 ]
    //             );
    //         }
    //     }
        
    //     // اگر اطلاعات کاربر کامل شد، به ادمین اطلاع بده
    //     if ($flow_result['next_step'] === 'waiting_for_admin' || $flow_result['next_step'] === 'chat_active') {
    //         $this->notify_admin_new_chat($flow_result);
    //     }
    // }
    
    // private function notify_admin_new_chat($flow_result) {
    //     $pusher_service = Plugin::get_instance()->get_service('pusher_service');
        
    //     if ($pusher_service) {
    //         $pusher_service->trigger('admin-notifications', 'new-chat-completed', [
    //             'session_id' => $this->session_id,
    //             'user_data' => $flow_result['user_data'],
    //             'first_message' => $flow_result['user_data']['first_message'] ?? '',
    //             'step' => $flow_result['next_step'],
    //             'timestamp' => current_time('mysql')
    //         ]);
    //     }
    // }
    
    // public function handle_process_conversation_step(): void {
    //     error_log('WP Live Chat: handle_process_conversation_step called');
        
    //     $this->verify_nonce('process_conversation_step');
        
    //     $session_id = sanitize_text_field($_POST['session_id'] ?? '');
    //     $input = sanitize_text_field($_POST['input'] ?? '');
    //     $step = sanitize_text_field($_POST['step'] ?? 'welcome');
        
    //     error_log("WP Live Chat: process_conversation_step - session_id: {$session_id}, step: {$step}, input: " . substr($input, 0, 50));
        
    //     if (empty($session_id) || empty($input)) {
    //         wp_send_json_error('شناسه جلسه و ورودی الزامی است');
    //     }
        
    //     try {
    //         $flow = new Conversation_Flow($session_id);
            
    //         // پردازش ورودی
    //         $result = $flow->process_input($input);
            
    //         error_log("WP Live Chat: Flow process result: " . json_encode($result, JSON_UNESCAPED_UNICODE));
            
    //         if ($result['success']) {
    //             // ذخیره اطلاعات کاربر در دیتابیس
    //             $user_data = $result['user_data'] ?? [];
    //             if (!empty($user_data['phone']) && !empty($user_data['name'])) {
    //                 $database = Plugin::get_instance()->get_service('database');
    //                 if ($database) {
    //                     $database->update_session_user_info(
    //                         $session_id,
    //                         $user_data['name'],
    //                         $user_data['phone'],
    //                         $user_data['company'] ?? ''
    //                     );
    //                     error_log("WP Live Chat: User info saved to database");
    //                 }
    //             }
                
    //             wp_send_json_success($result);
    //         } else {
    //             wp_send_json_error($result['message'] ?? 'خطا در پردازش');
    //         }
            
    //     } catch (\Exception $e) {
    //         error_log("WP Live Chat: Error in process_conversation_step: " . $e->getMessage());
    //         wp_send_json_error('خطای سرور: ' . $e->getMessage());
    //     }
    // }
    
    // public function handle_get_conversation_step(): void {
    //     error_log('WP Live Chat: handle_get_conversation_step called');
        
    //     $this->verify_nonce('get_conversation_step');
        
    //     $session_id = sanitize_text_field($_POST['session_id'] ?? '');
        
    //     if (empty($session_id)) {
    //         wp_send_json_error('شناسه جلسه الزامی است');
    //     }
        
    //     try {
    //         $flow = new Conversation_Flow($session_id);
            
    //         $response = [
    //             'current_step' => $flow->get_current_step(),
    //             'user_data' => $flow->get_user_data(),
    //             'requires_input' => $flow->requires_input(),
    //             'input_type' => $flow->get_input_type(),
    //             'input_placeholder' => $flow->get_input_placeholder(),
    //             'input_hint' => $flow->get_input_hint(),
    //             'message' => $flow->get_step_message()
    //         ];
            
    //         error_log("WP Live Chat: get_conversation_step response: " . json_encode($response, JSON_UNESCAPED_UNICODE));
            
    //         wp_send_json_success($response);
            
    //     } catch (\Exception $e) {
    //         error_log("WP Live Chat: Error in get_conversation_step: " . $e->getMessage());
    //         wp_send_json_error('خطای سرور: ' . $e->getMessage());
    //     }
    // }
    
    // public function handle_check_admin_status(): void {
    //     $this->verify_nonce('check_admin_status');
        
    //     try {
    //         // بررسی ادمین‌های آنلاین
    //         $online_admins = $this->get_online_admins();
            
    //         wp_send_json_success([
    //             'admin_online' => count($online_admins) > 0,
    //             'admin_count' => count($online_admins),
    //             'timestamp' => current_time('mysql')
    //         ]);
            
    //     } catch (\Exception $e) {
    //         wp_send_json_error('خطای سرور: ' . $e->getMessage());
    //     }
    // }

    // private function get_online_admins(): array {
    //      // بررسی ادمین‌های فعال در 5 دقیقه اخیر
    //     $args = [
    //         'role' => 'administrator',
    //         'meta_key' => 'last_activity',
    //         'meta_value' => time() - 300, // 5 دقیقه
    //         'meta_compare' => '>'
    //     ];
        
    //     return get_users($args);
    // }
    
    public function enqueue_assets(): void {
        if (!$this->should_display_chat()) {
            return;
        }

        wp_enqueue_style('wp-live-chat-frontend', WP_LIVE_CHAT_PLUGIN_URL . 'build/css/frontend-style.css', [], WP_LIVE_CHAT_VERSION);
        wp_enqueue_script('pusher', 'https://js.pusher.com/8.2.0/pusher.min.js', [], '8.2.0', true);
        wp_enqueue_script('wp-live-chat-frontend', WP_LIVE_CHAT_PLUGIN_URL . 'build/js/frontend.js', ['jquery','pusher'], WP_LIVE_CHAT_VERSION, true);

        // اصلاح localize script برای ارسال داده‌های conversation flow
        wp_localize_script('wp-live-chat-frontend', 'wpLiveChat', [
            'ajaxurl' => admin_url('admin-ajax.php'),
            'nonce' => wp_create_nonce('wp_live_chat_nonce'),
            'pusherKey' => get_option('wp_live_chat_pusher_key', ''),
            'pusherCluster' => get_option('wp_live_chat_pusher_cluster', 'mt1'),
            'sessionId' => $this->session_id,
            'userData' => $this->user_data,
            'strings' => [
                'typeMessage' => __('پیام خود را تایپ کنید...', 'wp-live-chat'),
                'phonePlaceholder' => __('09xxxxxxxxx', 'wp-live-chat'),
                'namePlaceholder' => __('نام شما یا شرکت', 'wp-live-chat'),
                'welcome' => __('👋 سلام! به پشتیبانی آنلاین خوش آمدید', 'wp-live-chat')
            ],
            // اضافه کردن داده‌های conversation flow
            'conversationFlow' => [
                'stepMessage' => __('👋 سلام! به پشتیبانی آنلاین خوش آمدید. لطفاً سوال یا درخواست خود را بنویسید.', 'wp-live-chat'),
                'currentStep' => 'welcome'
            ],
            'debug' => WP_DEBUG // فعال کردن دیباگ در حالت توسعه
        ]);
    }

    // public function handle_notify_admin_connected(): void {
    //     $this->verify_nonce('test_connection');
        
    //     $session_id = sanitize_text_field($_POST['session_id'] ?? '');
        
    //     if (empty($session_id)) {
    //         wp_send_json_error('شناسه جلسه الزامی است');
    //     }
        
    //     // می‌توانید نوتیفیکیشن‌های لازم را اینجا ارسال کنید
    //     wp_send_json_success(['notified' => true]);
    // }

    private function verify_nonce(string $action): void {
        if (!isset($_POST['nonce'])) {
            error_log("WP Live Chat: Nonce not set for action: {$action}");
            wp_send_json_error('Nonce verification failed - nonce not set', 403);
            exit;
        }
        
        $nonce = sanitize_text_field($_POST['nonce']);
        
        if (!wp_verify_nonce($nonce, 'wp_live_chat_nonce')) {
            error_log("WP Live Chat: Invalid nonce for action: {$action}, nonce: {$nonce}");
            wp_send_json_error('Nonce verification failed - invalid nonce', 403);
            exit;
        }
        
        error_log("WP Live Chat: Nonce verified for action: {$action}");
    }
}
