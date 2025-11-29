<?php

namespace WP_Live_Chat;

use Exception; // این خط را اضافه کنید


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
            'wp-live-chat',
            [$this, 'render_admin_page']
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
            'default' => __('در حال حاضر آنلاین نیستیم. لطفاً پیام خود را بگذارید.', 'wp-live-chat')
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
        ?>
        <div class="wrap">
            <h1><?php echo esc_html__('تنظیمات چت آنلاین', 'wp-live-chat'); ?></h1>
            
            <div class="wp-live-chat-admin">
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
            </div>
        </div>
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
}