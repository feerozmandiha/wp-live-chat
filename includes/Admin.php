<?php
namespace WP_Live_Chat;

class Admin {
    
    public function init(): void {
        add_action('admin_menu', [$this, 'add_admin_menu']);
        add_action('admin_init', [$this, 'register_settings']);
        add_action('admin_enqueue_scripts', [$this, 'enqueue_admin_scripts']);
        add_action('wp_ajax_test_pusher_connection', [$this, 'test_pusher_connection']);
    }
    
    public function add_admin_menu(): void {
        add_options_page(
            __('تنظیمات چت آنلاین', 'wp-live-chat'),
            __('چت آنلاین', 'wp-live-chat'),
            'manage_options',
            'wp-live-chat-settings',
            [$this, 'render_settings_page']
        );
    }
    
    public function register_settings(): void {
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
            'default' => __('در حال حاضر آنلاین نیستیم. پیام خود را بگذارید تا با شما تماس بگیریم.', 'wp-live-chat')
        ]);

        // اضافه کردن به تابع register_settings
        register_setting('wp_live_chat_settings', 'wp_live_chat_debug_mode', [
            'type' => 'boolean',
            'default' => false
        ]);
    }
    
    public function enqueue_admin_scripts(string $hook): void {
        if ('settings_page_wp-live-chat-settings' !== $hook) {
            return;
        }
        
        wp_enqueue_style(
            'wp-live-chat-admin',
            WP_LIVE_CHAT_PLUGIN_URL . 'build/css/admin-style.css',
            [],
            WP_LIVE_CHAT_VERSION
        );
        
        wp_enqueue_script(
            'wp-live-chat-admin',
            WP_LIVE_CHAT_PLUGIN_URL . 'build/js/admin.js',
            ['jquery'],
            WP_LIVE_CHAT_VERSION,
            true
        );
        
        wp_localize_script('wp-live-chat-admin', 'wpLiveChatAdmin', [
            'ajaxurl' => admin_url('admin-ajax.php'),
            'nonce' => wp_create_nonce('wp_live_chat_admin_nonce'),
            'strings' => [
                'testing' => __('در حال تست اتصال...', 'wp-live-chat'),
                'success' => __('اتصال موفقیت‌آمیز بود!', 'wp-live-chat'),
                'error' => __('اتصال ناموفق بود', 'wp-live-chat')
            ]
        ]);
    }
    
    public function render_settings_page(): void {
        if (!current_user_can('manage_options')) {
            wp_die(__('دسترسی غیرمجاز', 'wp-live-chat'));
        }

                        // دریافت وضعیت فعلی
        $pusher_service = Plugin::get_instance()->get_service('pusher_service');
        $status = $pusher_service ? $pusher_service->get_connection_status() : [];

        ?>
        <div class="wrap">
            <h1><?php echo esc_html__('تنظیمات چت آنلاین', 'wp-live-chat'); ?></h1>
                        <!-- نمایش وضعیت اتصال -->
            <div class="connection-status-card" style="margin-bottom: 20px; padding: 15px; background: #f8f9fa; border-radius: 8px; border: 1px solid #ddd;">
                <h3><?php _e('وضعیت اتصال Pusher', 'wp-live-chat'); ?></h3>
                <div id="current-connection-status">
                    <?php if ($status && $status['connected']): ?>
                        <div style="color: #46b450; font-weight: bold;">
                            <span class="dashicons dashicons-yes-alt"></span>
                            <?php _e('اتصال برقرار است', 'wp-live-chat'); ?>
                        </div>
                        <div style="margin-top: 10px; font-size: 13px;">
                            <strong><?php _e('آخرین تست:', 'wp-live-chat'); ?></strong>
                            <?php echo $status['last_test'] ? date_i18n('Y-m-d H:i:s', $status['last_test']) : __('هرگز', 'wp-live-chat'); ?>
                        </div>
                    <?php elseif ($status && !$status['config_valid']): ?>
                        <div style="color: #dc3232; font-weight: bold;">
                            <span class="dashicons dashicons-warning"></span>
                            <?php _e('تنظیمات ناقص است', 'wp-live-chat'); ?>
                        </div>
                    <?php elseif ($status && $status['last_error']): ?>
                        <div style="color: #dc3232; font-weight: bold;">
                            <span class="dashicons dashicons-dismiss"></span>
                            <?php _e('اتصال ناموفق', 'wp-live-chat'); ?>
                        </div>
                        <div style="margin-top: 10px; font-size: 13px; color: #666;">
                            <strong><?php _e('آخرین خطا:', 'wp-live-chat'); ?></strong>
                            <?php echo esc_html($status['last_error']); ?>
                        </div>
                    <?php else: ?>
                        <div style="color: #ffb900; font-weight: bold;">
                            <span class="dashicons dashicons-hourglass"></span>
                            <?php _e('در حال بررسی...', 'wp-live-chat'); ?>
                        </div>
                    <?php endif; ?>
                </div>
            </div>

            <form method="post" action="options.php">
                <?php settings_fields('wp_live_chat_settings'); ?>
                
                <table class="form-table">
                    <tr>
                        <th scope="row">
                            <label for="wp_live_chat_pusher_app_id"><?php _e('App ID', 'wp-live-chat'); ?></label>
                        </th>
                        <td>
                            <input type="text" 
                                id="wp_live_chat_pusher_app_id" 
                                name="wp_live_chat_pusher_app_id" 
                                value="<?php echo esc_attr(get_option('wp_live_chat_pusher_app_id')); ?>" 
                                class="regular-text" />
                            <p class="description"><?php _e('App ID مربوط به اپلیکیشن Pusher', 'wp-live-chat'); ?></p>
                        </td>
                    </tr>
                    
                    <tr>
                        <th scope="row">
                            <label for="wp_live_chat_pusher_key"><?php _e('Key', 'wp-live-chat'); ?></label>
                        </th>
                        <td>
                            <input type="text" 
                                id="wp_live_chat_pusher_key" 
                                name="wp_live_chat_pusher_key" 
                                value="<?php echo esc_attr(get_option('wp_live_chat_pusher_key')); ?>" 
                                class="regular-text" />
                            <p class="description"><?php _e('کلید عمومی اپلیکیشن', 'wp-live-chat'); ?></p>
                        </td>
                    </tr>
                    
                    <tr>
                        <th scope="row">
                            <label for="wp_live_chat_pusher_secret"><?php _e('Secret', 'wp-live-chat'); ?></label>
                        </th>
                        <td>
                            <input type="password" 
                                id="wp_live_chat_pusher_secret" 
                                name="wp_live_chat_pusher_secret" 
                                value="<?php echo esc_attr(get_option('wp_live_chat_pusher_secret')); ?>" 
                                class="regular-text" />
                            <p class="description"><?php _e('کلید خصوصی اپلیکیشن', 'wp-live-chat'); ?></p>
                        </td>
                    </tr>
                    
                    <tr>
                        <th scope="row">
                            <label for="wp_live_chat_pusher_cluster"><?php _e('Cluster', 'wp-live-chat'); ?></label>
                        </th>
                        <td>
                            <input type="text" 
                                id="wp_live_chat_pusher_cluster" 
                                name="wp_live_chat_pusher_cluster" 
                                value="<?php echo esc_attr(get_option('wp_live_chat_pusher_cluster', 'mt1')); ?>" 
                                class="regular-text" />
                            <p class="description"><?php _e('مثلاً: mt1, eu, ap3', 'wp-live-chat'); ?></p>
                        </td>
                    </tr>
                    
                    <tr>
                        <th scope="row">
                            <label for="wp_live_chat_enable_chat"><?php _e('فعال کردن چت', 'wp-live-chat'); ?></label>
                        </th>
                        <td>
                            <input type="checkbox" 
                                id="wp_live_chat_enable_chat" 
                                name="wp_live_chat_enable_chat" 
                                value="1" 
                                <?php checked(get_option('wp_live_chat_enable_chat', true)); ?> />
                            <label for="wp_live_chat_enable_chat"><?php _e('فعال سازی سیستم چت', 'wp-live-chat'); ?></label>
                        </td>
                    </tr>
                    
                    <tr>
                        <th scope="row">
                            <label for="wp_live_chat_offline_message"><?php _e('پیام آفلاین', 'wp-live-chat'); ?></label>
                        </th>
                        <td>
                            <textarea 
                                id="wp_live_chat_offline_message" 
                                name="wp_live_chat_offline_message" 
                                class="large-text" 
                                rows="3"
                            ><?php echo esc_textarea(get_option('wp_live_chat_offline_message')); ?></textarea>
                            <p class="description"><?php _e('پیامی که هنگام آفلاین بودن ادمین نمایش داده می‌شود', 'wp-live-chat'); ?></p>
                        </td>
                    </tr>

                    <tr>
                        <th scope="row">
                            <label for="wp_live_chat_debug_mode"><?php _e('حالت دیباگ', 'wp-live-chat'); ?></label>
                        </th>
                        <td>
                            <input type="checkbox" 
                                id="wp_live_chat_debug_mode" 
                                name="wp_live_chat_debug_mode" 
                                value="1" 
                                <?php checked(get_option('wp_live_chat_debug_mode', false)); ?> />
                            <label for="wp_live_chat_debug_mode"><?php _e('فعال کردن لاگ دیباگ', 'wp-live-chat'); ?></label>
                            <p class="description"><?php _e('برای عیب‌یابی مشکلات Pusher فعال کنید', 'wp-live-chat'); ?></p>
                        </td>
                    </tr>


                </table>
                
                <?php submit_button(__('ذخیره تنظیمات', 'wp-live-chat')); ?>
            </form>
            
            <div class="test-connection">
                <h3><?php _e('تست اتصال', 'wp-live-chat'); ?></h3>
                <button type="button" id="test-pusher-connection" class="button button-secondary">
                    <?php _e('تست اتصال به Pusher', 'wp-live-chat'); ?>
                </button>
                <div id="test-result" class="test-result" style="margin-top: 10px; min-height: 50px;"></div>
            </div>

            <!-- نمایش اطلاعات دیباگ (فقط برای ادمین) -->
            <details style="margin-top: 30px;">
                <summary style="cursor: pointer; color: #666; font-size: 13px;">
                    <?php _e('اطلاعات دیباگ (فقط توسعه)', 'wp-live-chat'); ?>
                </summary>
                <div style="background: #f1f1f1; padding: 15px; margin-top: 10px; border-radius: 5px; font-family: monospace; font-size: 12px;">
                    <pre><?php echo esc_html(json_encode($status, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE)); ?></pre>
                </div>
            </details>
        </div>
          
        <script>
        jQuery(document).ready(function($) {
            $('#test-pusher-connection').on('click', function() {
                var $button = $(this);
                var $result = $('#test-result');
                
                $button.prop('disabled', true).text('در حال تست...');
                $result.html('<div class="notice notice-info"><p>در حال تست اتصال...</p></div>');
                
                $.ajax({
                    url: '<?php echo admin_url('admin-ajax.php'); ?>',
                    type: 'POST',
                    data: {
                        action: 'test_pusher_connection',
                        nonce: '<?php echo wp_create_nonce('wp_live_chat_admin_nonce'); ?>'
                    },
                    dataType: 'json',
                    success: function(response) {
                        if (response.success) {
                            $result.html('<div class="notice notice-success"><p>' + response.data.message + '</p>' + 
                                        '<p><small>شناسه برنامه: ' + (response.data.details.app_id || 'N/A') + 
                                        ' | کلاستر: ' + (response.data.details.cluster || 'N/A') + '</small></p></div>');
                            // به‌روزرسانی وضعیت فعلی
                            $('#current-connection-status').html('<div style="color: #46b450; font-weight: bold;">' +
                                '<span class="dashicons dashicons-yes-alt"></span> اتصال برقرار است' +
                                '</div><div style="margin-top: 10px; font-size: 13px;">' +
                                '<strong>آخرین تست:</strong> هم اکنون' +
                                '</div>');
                        } else {
                            $result.html('<div class="notice notice-error"><p>' + response.data.message + '</p>' +
                                        (response.data.details.error ? '<p><small>خطا: ' + response.data.details.error + '</small></p>' : '') +
                                        '</div>');
                        }
                    },
                    error: function(xhr, status, error) {
                        $result.html('<div class="notice notice-error"><p>خطا در ارتباط با سرور</p><p><small>' + error + '</small></p></div>');
                    },
                    complete: function() {
                        $button.prop('disabled', false).text('تست اتصال به Pusher');
                    }
                });
            });
        });
        </script>
        <?php


    }
    
    // اضافه کردن به کلاس Admin
    public function test_pusher_connection(): void {
        check_ajax_referer('wp_live_chat_admin_nonce', 'nonce');
        
        if (!current_user_can('manage_options')) {
            wp_send_json_error('دسترسی غیرمجاز', 403);
        }
        
        try {
            $pusher_service = Plugin::get_instance()->get_service('pusher_service');
            
            if (!$pusher_service) {
                wp_send_json_error([
                    'message' => 'سرویس Pusher در دسترس نیست',
                    'details' => ['error' => 'Service not found']
                ]);
                return;
            }
            
            $result = $pusher_service->test_connection();
            
            if ($result['success']) {
                wp_send_json_success([
                    'message' => $result['message'],
                    'details' => $result['details']
                ]);
            } else {
                wp_send_json_error([
                    'message' => $result['message'],
                    'details' => $result['details']
                ]);
            }
            
        } catch (\Exception $e) {
            wp_send_json_error([
                'message' => 'خطای غیرمنتظره: ' . $e->getMessage(),
                'details' => ['exception' => $e->getTraceAsString()]
            ]);
        }
    }

}