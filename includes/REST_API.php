<?php
namespace WP_Live_Chat;

class REST_API {
    
    public function init(): void {
        add_action('rest_api_init', [$this, 'register_routes']);
    }
    
    public function register_routes(): void {
        register_rest_route('wp-live-chat/v1', '/sessions', [
            [
                'methods' => 'GET',
                'callback' => [$this, 'get_sessions'],
                'permission_callback' => [$this, 'check_admin_permissions'],
                'args' => [
                    'status' => [
                        'required' => false,
                        'type' => 'string',
                        'enum' => ['active', 'closed', 'all'],
                        'default' => 'active'
                    ]
                ]
            ]
        ]);
        
        register_rest_route('wp-live-chat/v1', '/sessions/(?P<id>[a-zA-Z0-9_-]+)', [
            [
                'methods' => 'GET',
                'callback' => [$this, 'get_session'],
                'permission_callback' => [$this, 'check_admin_permissions']
            ]
        ]);
        
        register_rest_route('wp-live-chat/v1', '/stats', [
            [
                'methods' => 'GET',
                'callback' => [$this, 'get_stats'],
                'permission_callback' => [$this, 'check_admin_permissions']
            ]
        ]);

        // اضافه کردن route جدید برای ارسال پیام
        register_rest_route('wp-live-chat/v1', '/messages', [
            [
                'methods' => 'POST',
                'callback' => [$this, 'send_message'],
                'permission_callback' => '__return_true', // یا بررسی خاص
                'args' => [
                    'session_id' => [
                        'required' => true,
                        'type' => 'string',
                        'sanitize_callback' => 'sanitize_text_field'
                    ],
                    'message' => [
                        'required' => true,
                        'type' => 'string',
                        'sanitize_callback' => 'sanitize_text_field'
                    ]
                ]
            ]
        ]);
    }

        // متد جدید برای ارسال پیام
    public function send_message(\WP_REST_Request $request): \WP_REST_Response {
        $session_id = $request->get_param('session_id');
        $message = $request->get_param('message');
        
        $database = Plugin::get_instance()->get_service('database');
        $pusher_service = Plugin::get_instance()->get_service('pusher_service');
        
        if (!$database) {
            return new \WP_REST_Response([
                'success' => false,
                'message' => 'Database service unavailable'
            ], 500);
        }
        
        // ذخیره پیام
        $message_id = $database->save_message([
            'session_id' => $session_id,
            'user_id' => 0,
            'user_name' => 'کاربر',
            'message_content' => $message,
            'message_type' => 'user'
        ]);
        
        if ($message_id) {
            // ارسال از طریق Pusher
            $pusher_sent = false;
            if ($pusher_service && $pusher_service->is_connected()) {
                $pusher_sent = $pusher_service->trigger(
                    "chat-{$session_id}",
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
            
            return new \WP_REST_Response([
                'success' => true,
                'message_id' => $message_id,
                'pusher_sent' => $pusher_sent
            ]);
        }
        
        return new \WP_REST_Response([
            'success' => false,
            'message' => 'Failed to save message'
        ], 500);
    }
    
    public function check_admin_permissions(): bool {
        return current_user_can('manage_options');
    }
    
    public function get_sessions(\WP_REST_Request $request): \WP_REST_Response {
        $status = $request->get_param('status') ?? 'active';
        
        $database = Plugin::get_instance()->get_service('database');
        $sessions = $database->get_active_sessions();
        
        if ($status !== 'active') {
            // For other statuses, you might need a different method
            $sessions = [];
        }
        
        return new \WP_REST_Response([
            'success' => true,
            'data' => $sessions,
            'count' => count($sessions)
        ]);
    }
    
    public function get_session(\WP_REST_Request $request): \WP_REST_Response {
        $session_id = $request->get_param('id');
        
        $database = Plugin::get_instance()->get_service('database');
        $session = $database->get_session($session_id);
        
        if (!$session) {
            return new \WP_REST_Response([
                'success' => false,
                'message' => 'Session not found'
            ], 404);
        }
        
        $messages = $database->get_session_messages($session_id);
        
        $session['messages'] = $messages;
        $session['message_count'] = count($messages);
        
        return new \WP_REST_Response([
            'success' => true,
            'data' => $session
        ]);
    }
    
    public function get_stats(): \WP_REST_Response {
        global $wpdb;
        
        $sessions_table = $wpdb->prefix . 'wp_live_chat_sessions';
        $messages_table = $wpdb->prefix . 'wp_live_chat_messages';
        
        $stats = [
            'total_sessions' => (int) $wpdb->get_var("SELECT COUNT(*) FROM $sessions_table"),
            'active_sessions' => (int) $wpdb->get_var("SELECT COUNT(*) FROM $sessions_table WHERE status = 'active'"),
            'total_messages' => (int) $wpdb->get_var("SELECT COUNT(*) FROM $messages_table"),
            'today_messages' => (int) $wpdb->get_var(
                $wpdb->prepare("SELECT COUNT(*) FROM $messages_table WHERE DATE(created_at) = %s", current_time('Y-m-d'))
            )
        ];
        
        return new \WP_REST_Response([
            'success' => true,
            'data' => $stats
        ]);
    }
}