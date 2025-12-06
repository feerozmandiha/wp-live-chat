<?php
namespace WP_Live_Chat;
defined('ABSPATH') || exit;

class Admin {
    public function init(): void {
        add_action('admin_menu', [$this, 'add_menu']);
        add_action('admin_init', [$this, 'register_settings']);
        add_action('admin_enqueue_scripts', [$this, 'enqueue_scripts']);
        add_action('wp_ajax_test_pusher_connection', [$this, 'ajax_test_pusher']);
    }

    public function add_menu(): void {
        add_options_page(__('تنظیمات چت آنلاین','wp-live-chat'), __('چت آنلاین','wp-live-chat'), 'manage_options', 'wp-live-chat-settings', [$this,'render_settings']);
    }

    public function register_settings(): void {
        register_setting('wp_live_chat_settings','wp_live_chat_pusher_app_id');
        register_setting('wp_live_chat_settings','wp_live_chat_pusher_key');
        register_setting('wp_live_chat_settings','wp_live_chat_pusher_secret');
        register_setting('wp_live_chat_settings','wp_live_chat_pusher_cluster');
    }

    public function render_settings(): void {
        if (!current_user_can('manage_options')) wp_die(__('forbidden'));
        include WP_LIVE_CHAT_PLUGIN_PATH . 'templates/settings-page.php';
    }

    public function enqueue_scripts(string $hook) {
        if ($hook !== 'settings_page_wp-live-chat-settings') return;
        wp_enqueue_script('pusher', 'https://js.pusher.com/8.2.0/pusher.min.js', [], '8.2.0', true);
        wp_enqueue_script('wp-live-chat-admin', WP_LIVE_CHAT_PLUGIN_URL . 'build/js/admin.js', ['jquery','pusher'], WP_LIVE_CHAT_VERSION, true);
        wp_localize_script('wp-live-chat-admin','wpLiveChatAdminSettings',[
            'ajaxurl'=>admin_url('admin-ajax.php'),
            'nonce'=>wp_create_nonce('wp_live_chat_admin_nonce')
        ]);
    }

    public function ajax_test_pusher() {
        check_ajax_referer('wp_live_chat_admin_nonce','nonce');
        if (!current_user_can('manage_options')) wp_send_json_error('forbidden',403);

        $pusher = Plugin::get_instance()->get_service('pusher_service');
        if (!$pusher) wp_send_json_error('pusher service not found',500);

        $res = $pusher->test_connection();
        if ($res['success']) wp_send_json_success($res);
        wp_send_json_error($res, 500);
    }
}
