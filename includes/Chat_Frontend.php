<?php
namespace WP_Live_Chat;
defined('ABSPATH') || exit;

class Chat_Frontend {
    public function init(): void {
        add_action('wp_ajax_nopriv_wp_live_chat_send_message', [$this, 'handle_send_message']);
        add_action('wp_ajax_wp_live_chat_send_message', [$this, 'handle_send_message']);

        add_action('wp_ajax_nopriv_wp_live_chat_poll_messages', [$this, 'handle_poll_messages']);
        add_action('wp_ajax_wp_live_chat_poll_messages', [$this, 'handle_poll_messages']);

        add_action('wp_ajax_nopriv_wp_live_chat_mark_message_read', [$this, 'handle_mark_message_read']);
        add_action('wp_ajax_wp_live_chat_mark_message_read', [$this, 'handle_mark_message_read']);

        // additional: get chat history
        add_action('wp_ajax_nopriv_wp_live_chat_get_messages', [$this, 'handle_get_messages']);
        add_action('wp_ajax_wp_live_chat_get_messages', [$this, 'handle_get_messages']);
    }

    private function verify_nonce_field(string $action) {
        $nonce = $_POST['nonce'] ?? $_REQUEST['nonce'] ?? '';
        if ($action === 'admin') {
            check_ajax_referer('wp_live_chat_admin_nonce', 'nonce');
        } else {
            check_ajax_referer('wp_live_chat_nonce', 'nonce');
        }
    }

    public function handle_send_message() {
        check_ajax_referer('wp_live_chat_nonce', 'nonce');

        $session_id = sanitize_text_field($_POST['session_id'] ?? '');
        $message = sanitize_textarea_field($_POST['message'] ?? '');

        if (empty($session_id) || empty($message)) {
            wp_send_json_error('session_id and message required', 400);
        }

        try {
            $db = Plugin::get_instance()->get_service('database');
            $id = $db->save_message([
                'session_id' => $session_id,
                'user_id' => 0,
                'user_name' => 'کاربر',
                'message_content' => $message,
                'message_type' => 'user'
            ]);

            // send to pusher if available
            $pusher = Plugin::get_instance()->get_service('pusher_service');
            if ($pusher && $pusher->is_connected()) {
                $pusher->trigger("private-chat-{$session_id}", 'new-message', [
                    'id' => $id,
                    'message' => $message,
                    'user_name' => 'کاربر',
                    'type' => 'user',
                    'timestamp' => current_time('mysql')
                ]);
            }

            wp_send_json_success(['id' => $id]);
        } catch (\Exception $e) {
            wp_send_json_error('server error: ' . $e->getMessage(), 500);
        }
    }

    public function handle_poll_messages() {
        check_ajax_referer('wp_live_chat_nonce', 'nonce');

        $session_id = sanitize_text_field($_POST['session_id'] ?? '');
        $since = sanitize_text_field($_POST['last_message_id'] ?? '');

        if (empty($session_id)) wp_send_json_error('session_id required', 400);

        $db = Plugin::get_instance()->get_service('database');
        $messages = $db->get_messages_since($session_id, $since);
        wp_send_json_success(['messages' => $messages]);
    }

    public function handle_mark_message_read() {
        check_ajax_referer('wp_live_chat_nonce', 'nonce');

        $message_id = intval($_POST['message_id'] ?? 0);
        if (!$message_id) wp_send_json_error('message_id required', 400);

        $db = Plugin::get_instance()->get_service('database');
        // simple mark (could also add admin_reads table logic)
        $db->mark_messages_as_read('', 0, [$message_id]);
        wp_send_json_success(['message_id' => $message_id]);
    }

    public function handle_get_messages() {
        check_ajax_referer('wp_live_chat_nonce', 'nonce');

        $session_id = sanitize_text_field($_POST['session_id'] ?? '');
        if (empty($session_id)) wp_send_json_error('session_id required', 400);

        $db = Plugin::get_instance()->get_service('database');
        $messages = $db->get_session_messages($session_id);
        wp_send_json_success($messages);
    }
}
