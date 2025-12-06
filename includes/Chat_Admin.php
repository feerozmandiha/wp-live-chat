<?php
namespace WP_Live_Chat;
defined('ABSPATH') || exit;

class Chat_Admin {
    public function init(): void {
        add_action('admin_menu', [$this, 'add_chat_admin_page']);
        add_action('admin_enqueue_scripts', [$this, 'enqueue_admin_chat_scripts']);

        // register AJAX handlers for admin (privileged)
        add_action('wp_ajax_wp_live_chat_get_sessions', [$this, 'ajax_get_sessions']);
        add_action('wp_ajax_wp_live_chat_get_messages', [$this, 'ajax_get_messages']);
        add_action('wp_ajax_wp_live_chat_send_admin_message', [$this, 'ajax_send_admin_message']);
        add_action('wp_ajax_wp_live_chat_mark_read', [$this, 'ajax_mark_read']);
        // also support legacy names if needed
    }

    public function add_chat_admin_page(): void {
        add_menu_page(__('مدیریت چت', 'wp-live-chat'), __('چت زنده', 'wp-live-chat'), 'manage_options', 'wp-live-chat-admin', [$this, 'render_chat_admin_page'], 'dashicons-format-chat', 25);
    }

    public function render_chat_admin_page(): void {
        if (!current_user_can('manage_options')) wp_die(__('دسترسی غیرمجاز', 'wp-live-chat'));
        include WP_LIVE_CHAT_PLUGIN_PATH . 'templates/admin-chat-page.php';
    }

    public function enqueue_admin_chat_scripts(string $hook): void {
        if ($hook !== 'toplevel_page_wp-live-chat-admin') return;

        wp_enqueue_script('pusher', 'https://js.pusher.com/8.2.0/pusher.min.js', [], '8.2.0', true);
        wp_enqueue_script('wp-live-chat-admin-chat', WP_LIVE_CHAT_PLUGIN_URL . 'build/js/admin-chat.js', ['jquery','pusher'], WP_LIVE_CHAT_VERSION, true);
        wp_localize_script('wp-live-chat-admin-chat', 'wpLiveChatAdmin', [
            'ajaxurl' => admin_url('admin-ajax.php'),
            'nonce' => wp_create_nonce('wp_live_chat_admin_nonce'),
            'pusherKey' => get_option('wp_live_chat_pusher_key',''),
            'pusherCluster' => get_option('wp_live_chat_pusher_cluster','mt1'),
            'strings' => ['online'=>__('آنلاین','wp-live-chat'),'offline'=>__('آفلاین','wp-live-chat')]
        ]);
        wp_enqueue_style('wp-live-chat-admin-style', WP_LIVE_CHAT_PLUGIN_URL . 'build/css/admin-style.css', [], WP_LIVE_CHAT_VERSION);
    }

    public function ajax_get_sessions() {
        check_ajax_referer('wp_live_chat_admin_nonce','nonce');
        if (!current_user_can('manage_options')) wp_send_json_error('forbidden',403);

        $db = Plugin::get_instance()->get_service('database');
        $sessions = $db->get_active_sessions();

        foreach ($sessions as &$s) {
            $s['unread_count'] = $db->get_unread_count($s['session_id']);
        }

        wp_send_json_success($sessions);
    }

    public function ajax_get_messages() {
        check_ajax_referer('wp_live_chat_admin_nonce','nonce');
        if (!current_user_can('manage_options')) wp_send_json_error('forbidden',403);

        $session_id = sanitize_text_field($_POST['session_id'] ?? '');
        if (empty($session_id)) wp_send_json_error('session required',400);

        $db = Plugin::get_instance()->get_service('database');
        $messages = $db->get_session_messages($session_id);
        wp_send_json_success($messages);
    }

    public function ajax_send_admin_message() {
        check_ajax_referer('wp_live_chat_admin_nonce','nonce');
        if (!current_user_can('manage_options')) wp_send_json_error('forbidden',403);

        $session_id = sanitize_text_field($_POST['session_id'] ?? '');
        $message = sanitize_textarea_field($_POST['message'] ?? '');
        if (empty($session_id) || empty($message)) wp_send_json_error('session & message required',400);

        $db = Plugin::get_instance()->get_service('database');
        $msg_id = $db->save_message([
            'session_id' => $session_id,
            'user_id' => get_current_user_id(),
            'user_name' => wp_get_current_user()->display_name ?: 'پشتیبان',
            'message_content' => $message,
            'message_type' => 'admin'
        ]);

        $pusher = Plugin::get_instance()->get_service('pusher_service');
        if ($pusher && $pusher->is_connected()) {
            $pusher->trigger("private-chat-{$session_id}", 'new-message', [
                'id' => $msg_id,
                'message' => $message,
                'user_name' => wp_get_current_user()->display_name ?: 'پشتیبان',
                'type' => 'admin',
                'timestamp' => current_time('mysql')
            ]);
        }

        wp_send_json_success(['id' => $msg_id, 'pusher' => $pusher?->is_connected()]);
    }

    public function ajax_mark_read() {
        check_ajax_referer('wp_live_chat_admin_nonce','nonce');
        if (!current_user_can('manage_options')) wp_send_json_error('forbidden',403);

        $session_id = sanitize_text_field($_POST['session_id'] ?? '');
        $message_ids = isset($_POST['message_ids']) ? array_map('intval', (array)$_POST['message_ids']) : [];

        $db = Plugin::get_instance()->get_service('database');
        $marked = $db->mark_messages_as_read($session_id, get_current_user_id(), $message_ids);
        wp_send_json_success(['marked' => $marked]);
    }
}
