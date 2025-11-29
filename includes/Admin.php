<?php

namespace WP_Live_Chat;

use Exception; // این خط را اضافه کنید


class Admin {
    
    public function init(): void {
        add_action('admin_menu', [$this, 'add_admin_menu']);
        add_action('admin_init', [$this, 'register_settings']);
        add_action('admin_enqueue_scripts', [$this, 'enqueue_admin_scripts']);
        add_action('wp_ajax_test_pusher_connection', [$this, 'test_pusher_connection']);
        add_action('wp_ajax_clear_chat_logs', [$this, 'clear_chat_logs']);
        add_action('wp_ajax_download_chat_logs', [$this, 'download_chat_logs']);
    }
    
    public function add_admin_menu(): void {
        add_options_page(
            __('تنظیمات چت آنلاین', 'wp-live-chat'),
            __('چت آنلاین', 'wp-live-chat'),
            'manage_options',
            'wp-live-chat',
            [$this, 'render_admin_page']
        );
    }
    
    public function register_settings(): void {
        // تنظیمات عمومی
        register_setting('wp_live_chat_settings', 'wp_live_chat_pusher_app_id', [
            'type' => 'string',
            'sanitize_callback' => 'sanitize_text_field',
            'default' => ''
        ]);
        
        register_setting('wp_live_chat_settings', 'wp_live_chat_pusher_key', [
            'type' => 'string',
            'sanitize_callback' => 'sanitize_text_field',
            'default' => ''
        ]);
        
        register_setting('wp_live_chat_settings', 'wp_live_chat_pusher_secret', [
            'type' => 'string',
            'sanitize_callback' => 'sanitize_text_field',
            'default' => ''
        ]);
        
        register_setting('wp_live_chat_settings', 'wp_live_chat_pusher_cluster', [
            'type' => 'string',
            'sanitize_callback' => 'sanitize_text_field',
            'default' => 'mt1'
        ]);
        
        register_setting('wp_live_chat_settings', 'wp_live_chat_enable_chat', [
            'type' => 'boolean',
            'default' => true
        ]);
        
        register_setting('wp_live_chat_settings', 'wp_live_chat_offline_message', [
            'type' => 'string',
            'sanitize_callback' => 'sanitize_textarea_field',
            'default' => __('در حال حاضر آنلاین نیستیم. لطفاً پیام خود را بگذارید.', 'wp-live-chat')
        ]);

        // تنظیمات پیشرفته
        register_setting('wp_live_chat_advanced_settings', 'wp_live_chat_cache_enabled', [
            'type' => 'boolean',
            'default' => true
        ]);
        
        register_setting('wp_live_chat_advanced_settings', 'wp_live_chat_auto_cleanup', [
            'type' => 'integer',
            'sanitize_callback' => 'absint',
            'default' => 30
        ]);
        
        register_setting('wp_live_chat_advanced_settings', 'wp_live_chat_max_message_length', [
            'type' => 'integer',
            'sanitize_callback' => 'absint',
            'default' => 500
        ]);
    }
    
    public function enqueue_admin_scripts(string $hook): void {
        if ('settings_page_wp-live-chat' !== $hook) {
            return;
        }
        
        wp_enqueue_style(
            'wp-live-chat-admin',
            WP_LIVE_CHAT_PLUGIN_URL . 'assets/css/admin.css',
            [],
            WP_LIVE_CHAT_VERSION
        );
        
        wp_enqueue_script(
            'wp-live-chat-admin',
            WP_LIVE_CHAT_PLUGIN_URL . 'assets/js/admin.js',
            ['jquery'],
            WP_LIVE_CHAT_VERSION,
            true
        );
        
        wp_localize_script('wp-live-chat-admin', 'wpLiveChatAdmin', [
            'ajaxurl' => admin_url('admin-ajax.php'),
            'nonce' => wp_create_nonce('wp_live_chat_admin_nonce'),
            'testing' => __('در حال تست اتصال...', 'wp-live-chat'),
            'success' => __('اتصال موفقیت‌آمیز بود!', 'wp-live-chat'),
            'error' => __('اتصال ناموفق بود. لطفاً تنظیمات را بررسی کنید.', 'wp-live-chat')
        ]);
    }
    
    public function render_admin_page(): void {
        if (!current_user_can('manage_options')) {
            wp_die(__('دسترسی غیرمجاز', 'wp-live-chat'));
        }
        
        $active_tab = $_GET['tab'] ?? 'general';
        ?>
        <div class="wrap">
            <h1><?php echo esc_html__('تنظیمات چت آنلاین', 'wp-live-chat'); ?></h1>
            
            <h2 class="nav-tab-wrapper">
                <a href="?page=wp-live-chat&tab=general" class="nav-tab <?php echo $active_tab === 'general' ? 'nav-tab-active' : ''; ?>">
                    <?php echo esc_html__('عمومی', 'wp-live-chat'); ?>
                </a>
                <a href="?page=wp-live-chat&tab=advanced" class="nav-tab <?php echo $active_tab === 'advanced' ? 'nav-tab-active' : ''; ?>">
                    <?php echo esc_html__('پیشرفته', 'wp-live-chat'); ?>
                </a>
                <a href="?page=wp-live-chat&tab=logs" class="nav-tab <?php echo $active_tab === 'logs' ? 'nav-tab-active' : ''; ?>">
                    <?php echo esc_html__('لاگ‌ها', 'wp-live-chat'); ?>
                </a>
            </h2>
            
            <div class="wp-live-chat-admin">
                <?php
                switch ($active_tab) {
                    case 'advanced':
                        $this->render_advanced_tab();
                        break;
                    case 'logs':
                        $this->render_logs_tab();
                        break;
                    default:
                        $this->render_general_tab();
                        break;
                }
                ?>
            </div>
        </div>
        <?php
    }

    private function render_general_tab(): void {
        ?>
        <div class="chat-admin-header">
            <h2><?php echo esc_html__('پیکربندی Pusher', 'wp-live-chat'); ?></h2>
            <p><?php echo esc_html__('برای استفاده از سیستم چت، ابتدا اطلاعات اتصال Pusher را وارد کنید.', 'wp-live-chat'); ?></p>
        </div>
        
        <form method="post" action="options.php">
            <?php 
            settings_fields('wp_live_chat_settings');
            do_settings_sections('wp_live_chat_settings');
            ?>
            
            <table class="form-table">
                <tr>
                    <th scope="row">
                        <label for="wp_live_chat_pusher_app_id"><?php echo esc_html__('App ID', 'wp-live-chat'); ?></label>
                    </th>
                    <td>
                        <input type="text" 
                            id="wp_live_chat_pusher_app_id" 
                            name="wp_live_chat_pusher_app_id" 
                            value="<?php echo esc_attr(get_option('wp_live_chat_pusher_app_id')); ?>" 
                            class="regular-text" />
                        <p class="description"><?php echo esc_html__('App ID مربوط به اپلیکیشن Pusher', 'wp-live-chat'); ?></p>
                    </td>
                </tr>
                
                <tr>
                    <th scope="row">
                        <label for="wp_live_chat_pusher_key"><?php echo esc_html__('Key', 'wp-live-chat'); ?></label>
                    </th>
                    <td>
                        <input type="text" 
                            id="wp_live_chat_pusher_key" 
                            name="wp_live_chat_pusher_key" 
                            value="<?php echo esc_attr(get_option('wp_live_chat_pusher_key')); ?>" 
                            class="regular-text" />
                        <p class="description"><?php echo esc_html__('کلید عمومی اپلیکیشن', 'wp-live-chat'); ?></p>
                    </td>
                </tr>
                
                <tr>
                    <th scope="row">
                        <label for="wp_live_chat_pusher_secret"><?php echo esc_html__('Secret', 'wp-live-chat'); ?></label>
                    </th>
                    <td>
                        <input type="password" 
                            id="wp_live_chat_pusher_secret" 
                            name="wp_live_chat_pusher_secret" 
                            value="<?php echo esc_attr(get_option('wp_live_chat_pusher_secret')); ?>" 
                            class="regular-text" />
                        <p class="description"><?php echo esc_html__('کلید خصوصی اپلیکیشن', 'wp-live-chat'); ?></p>
                    </td>
                </tr>
                
                <tr>
                    <th scope="row">
                        <label for="wp_live_chat_pusher_cluster"><?php echo esc_html__('Cluster', 'wp-live-chat'); ?></label>
                    </th>
                    <td>
                        <input type="text" 
                            id="wp_live_chat_pusher_cluster" 
                            name="wp_live_chat_pusher_cluster" 
                            value="<?php echo esc_attr(get_option('wp_live_chat_pusher_cluster', 'mt1')); ?>" 
                            class="regular-text" />
                        <p class="description"><?php echo esc_html__('مثلاً: mt1, eu, ap3', 'wp-live-chat'); ?></p>
                    </td>
                </tr>
                
                <tr>
                    <th scope="row">
                        <label for="wp_live_chat_enable_chat"><?php echo esc_html__('فعال کردن چت', 'wp-live-chat'); ?></label>
                    </th>
                    <td>
                        <input type="checkbox" 
                            id="wp_live_chat_enable_chat" 
                            name="wp_live_chat_enable_chat" 
                            value="1" 
                            <?php checked(get_option('wp_live_chat_enable_chat', true)); ?> />
                        <label for="wp_live_chat_enable_chat"><?php echo esc_html__('فعال سازی سیستم چت', 'wp-live-chat'); ?></label>
                    </td>
                </tr>

                <tr>
                    <th scope="row">
                        <label for="wp_live_chat_offline_message"><?php echo esc_html__('پیام آفلاین', 'wp-live-chat'); ?></label>
                    </th>
                    <td>
                        <textarea 
                            id="wp_live_chat_offline_message" 
                            name="wp_live_chat_offline_message" 
                            class="large-text" 
                            rows="3"
                        ><?php echo esc_textarea(get_option('wp_live_chat_offline_message', __('در حال حاضر آنلاین نیستیم. لطفاً پیام خود را بگذارید.', 'wp-live-chat'))); ?></textarea>
                        <p class="description"><?php echo esc_html__('پیامی که هنگام آفلاین بودن ادمین نمایش داده می‌شود', 'wp-live-chat'); ?></p>
                    </td>
                </tr>
            </table>
            
            <?php submit_button(__('ذخیره تنظیمات', 'wp-live-chat')); ?>
        </form>
        
        <div class="chat-admin-test">
            <h3><?php echo esc_html__('تست اتصال', 'wp-live-chat'); ?></h3>
            <button type="button" id="test-pusher-connection" class="button button-secondary">
                <?php echo esc_html__('تست اتصال به Pusher', 'wp-live-chat'); ?>
            </button>
            <div id="test-result" class="test-result"></div>
        </div>
        <?php
    }

    private function render_advanced_tab(): void {
        ?>
        <div class="chat-admin-header">
            <h2><?php echo esc_html__('تنظیمات پیشرفته', 'wp-live-chat'); ?></h2>
            <p><?php echo esc_html__('تنظیمات پیشرفته سیستم چت', 'wp-live-chat'); ?></p>
        </div>
        
        <form method="post" action="options.php">
            <?php 
            settings_fields('wp_live_chat_advanced_settings');
            do_settings_sections('wp_live_chat_advanced_settings');
            ?>
            
            <table class="form-table">
                <tr>
                    <th scope="row">
                        <label for="wp_live_chat_cache_enabled">فعالسازی کش</label>
                    </th>
                    <td>
                        <input type="checkbox" 
                            id="wp_live_chat_cache_enabled" 
                            name="wp_live_chat_cache_enabled" 
                            value="1" 
                            <?php checked(get_option('wp_live_chat_cache_enabled', true)); ?> />
                        <label for="wp_live_chat_cache_enabled">استفاده از کش برای بهبود عملکرد</label>
                    </td>
                </tr>
                
                <tr>
                    <th scope="row">
                        <label for="wp_live_chat_auto_cleanup">پاکسازی خودکار</label>
                    </th>
                    <td>
                        <input type="number" 
                            id="wp_live_chat_auto_cleanup" 
                            name="wp_live_chat_auto_cleanup" 
                            value="<?php echo esc_attr(get_option('wp_live_chat_auto_cleanup', 30)); ?>" 
                            min="1" max="365" />
                        <p class="description">تعداد روزهای نگهداری تاریخچه چت (1-365 روز)</p>
                    </td>
                </tr>
                
                <tr>
                    <th scope="row">
                        <label for="wp_live_chat_max_message_length">حداکثر طول پیام</label>
                    </th>
                    <td>
                        <input type="number" 
                            id="wp_live_chat_max_message_length" 
                            name="wp_live_chat_max_message_length" 
                            value="<?php echo esc_attr(get_option('wp_live_chat_max_message_length', 500)); ?>" 
                            min="100" max="5000" />
                        <p class="description">حداکثر تعداد کاراکتر مجاز برای هر پیام</p>
                    </td>
                </tr>
            </table>
            
            <?php submit_button(__('ذخیره تنظیمات پیشرفته', 'wp-live-chat')); ?>
        </form>
        <?php
    }    

    private function render_logs_tab(): void {
        /** @var Logger $logger */
        $logger = Plugin::get_instance()->get_service('logger');
        $logs = $logger->get_logs(50);
        ?>
        <div class="chat-admin-header">
            <h2><?php echo esc_html__('لاگ‌های سیستم', 'wp-live-chat'); ?></h2>
            <p><?php echo esc_html__('نمایش لاگ‌های دیباگ سیستم چت', 'wp-live-chat'); ?></p>
        </div>
        
        <div class="chat-logs-container">
            <div class="log-actions">
                <button type="button" id="refresh-logs" class="button button-secondary">
                    بروزرسانی لاگ‌ها
                </button>
                <button type="button" id="clear-logs" class="button button-secondary">
                    پاک کردن لاگ‌ها
                </button>
                <button type="button" id="download-logs" class="button button-primary">
                    دانلود لاگ‌ها
                </button>
            </div>
            
            <div class="log-content">
                <?php if (empty($logs)): ?>
                    <p class="no-logs">هیچ لاگی برای نمایش وجود ندارد.</p>
                <?php else: ?>
                    <pre class="log-entries"><code><?php
                        foreach (array_reverse($logs) as $log) {
                            echo esc_html($log) . "\n";
                        }
                    ?></code></pre>
                <?php endif; ?>
            </div>
        </div>
        
        <style>
        .log-actions {
            margin-bottom: 20px;
        }
        
        .log-actions .button {
            margin-right: 10px;
        }
        
        .log-content {
            background: #1d2327;
            color: #f0f0f1;
            padding: 20px;
            border-radius: 4px;
            max-height: 500px;
            overflow-y: auto;
            font-family: 'Courier New', monospace;
            font-size: 12px;
            line-height: 1.4;
        }
        
        .log-entries {
            margin: 0;
            white-space: pre-wrap;
            word-wrap: break-word;
        }
        
        .no-logs {
            text-align: center;
            padding: 40px;
            color: #666;
            font-style: italic;
        }
        </style>
        
        <script>
        jQuery(document).ready(function($) {
            $('#refresh-logs').on('click', function() {
                location.reload();
            });
            
            $('#clear-logs').on('click', function() {
                if (confirm('آیا از پاک کردن تمام لاگ‌ها مطمئن هستید؟')) {
                    $.post(ajaxurl, {
                        action: 'clear_chat_logs',
                        nonce: '<?php echo wp_create_nonce('wp_live_chat_admin_nonce'); ?>'
                    }, function(response) {
                        if (response.success) {
                            location.reload();
                        } else {
                            alert('خطا در پاک کردن لاگ‌ها');
                        }
                    });
                }
            });
            
            $('#download-logs').on('click', function() {
                window.open('<?php echo admin_url('admin-ajax.php?action=download_chat_logs&nonce=' . wp_create_nonce('wp_live_chat_admin_nonce')); ?>');
            });
        });
        </script>
        <?php
    }
    
    public function test_pusher_connection(): void {
        check_ajax_referer('wp_live_chat_admin_nonce', 'nonce');
        
        if (!current_user_can('manage_options')) {
            wp_send_json_error(__('دسترسی غیرمجاز', 'wp-live-chat'));
        }

        /** @var Pusher_Service $pusher_service */
        $pusher_service = Plugin::get_instance()->get_service('pusher_service');
        
        if (!$pusher_service) {
            wp_send_json_error(['message' => 'Pusher service not available']);
            return;
        }
        
        if ($pusher_service->is_connected()) {
            // تست واقعی با ارسال یک پیام تست
            try {
                $test_result = $pusher_service->trigger(
                    'private-chat-test',
                    'test-event', 
                    ['message' => 'Test connection']
                );
                
                if ($test_result) {
                    wp_send_json_success(['message' => __('اتصال موفقیت‌آمیز بود!', 'wp-live-chat')]);
                } else {
                    wp_send_json_error(['message' => __('اتصال برقرار شد اما ارسال پیام ناموفق بود', 'wp-live-chat')]);
                }
            } catch (Exception $e) {
                wp_send_json_error(['message' => 'Pusher error: ' . $e->getMessage()]);
            }
        } else {
            wp_send_json_error(['message' => __('اتصال ناموفق بود. لطفاً تنظیمات را بررسی کنید.', 'wp-live-chat')]);
        }
    }

    public function clear_chat_logs(): void {
        check_ajax_referer('wp_live_chat_admin_nonce', 'nonce');
        
        if (!current_user_can('manage_options')) {
            wp_send_json_error(__('دسترسی غیرمجاز', 'wp-live-chat'));
        }

        try {
            /** @var Logger $logger */
            $logger = Plugin::get_instance()->get_service('logger');
            $result = $logger->clear_logs();
            
            if ($result) {
                wp_send_json_success(['message' => __('لاگ‌ها با موفقیت پاک شدند', 'wp-live-chat')]);
            } else {
                wp_send_json_error(['message' => __('خطا در پاک کردن لاگ‌ها', 'wp-live-chat')]);
            }
        } catch (Exception $e) {
            wp_send_json_error(['message' => 'Error: ' . $e->getMessage()]);
        }
    }

    public function download_chat_logs(): void {
        check_ajax_referer('wp_live_chat_admin_nonce', 'nonce');
        
        if (!current_user_can('manage_options')) {
            wp_die(__('دسترسی غیرمجاز', 'wp-live-chat'));
        }

        try {
            /** @var Logger $logger */
            $logger = Plugin::get_instance()->get_service('logger');
            $logs = $logger->get_logs(1000); // آخرین 1000 خط
            
            header('Content-Type: text/plain');
            header('Content-Disposition: attachment; filename="wp-live-chat-logs-' . date('Y-m-d-H-i-s') . '.txt"');
            
            foreach (array_reverse($logs) as $log) {
                echo $log . "\n";
            }
            
            exit;
        } catch (Exception $e) {
            wp_die('Error: ' . $e->getMessage());
        }
    }
}