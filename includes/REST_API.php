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
                    ],
                    'page' => [
                        'required' => false,
                        'type' => 'integer',
                        'default' => 1
                    ],
                    'per_page' => [
                        'required' => false,
                        'type' => 'integer',
                        'default' => 20
                    ]
                ]
            ]
        ]);
        
        register_rest_route('wp-live-chat/v1', '/sessions/(?P<id>[a-zA-Z0-9_-]+)', [
            [
                'methods' => 'GET',
                'callback' => [$this, 'get_session'],
                'permission_callback' => [$this, 'check_admin_permissions']
            ],
            [
                'methods' => 'DELETE',
                'callback' => [$this, 'close_session'],
                'permission_callback' => [$this, 'check_admin_permissions']
            ]
        ]);
        
        register_rest_route('wp-live-chat/v1', '/sessions/(?P<id>[a-zA-Z0-9_-]+)/messages', [
            [
                'methods' => 'GET',
                'callback' => [$this, 'get_session_messages'],
                'permission_callback' => [$this, 'check_admin_permissions']
            ],
            [
                'methods' => 'POST',
                'callback' => [$this, 'send_message'],
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
    }
    
    public function check_admin_permissions(): bool {
        return current_user_can('manage_options');
    }
    
    public function get_sessions(\WP_REST_Request $request): \WP_REST_Response {
        $status = $request->get_param('status') ?? 'active';
        $page = $request->get_param('page') ?? 1;
        $per_page = $request->get_param('per_page') ?? 20;
        
        /** @var Database $database */
        $database = Plugin::get_instance()->get_service('database');
        
        if ($page > 1 || $per_page != 20) {
            // استفاده از pagination
            $sessions = $database->get_sessions_paginated($page, $per_page, $status);
        } else {
            // استفاده از متد ساده
            $sessions = $database->get_all_sessions($status);
        }
        
        // اضافه کردن اطلاعات اضافی به هر session
        foreach ($sessions as &$session) {
            $session['message_count'] = $database->get_session_message_count($session['session_id']);
            $session['last_message'] = $database->get_last_session_message($session['session_id']);
        }
        
        return new \WP_REST_Response([
            'success' => true,
            'data' => $sessions,
            'count' => count($sessions),
            'pagination' => [
                'page' => $page,
                'per_page' => $per_page,
                'total' => $database->get_total_sessions_count()
            ]
        ]);
    }
    
    public function get_session(\WP_REST_Request $request): \WP_REST_Response {
        $session_id = $request->get_param('id');
        
        /** @var Database $database */
        $database = Plugin::get_instance()->get_service('database');
        $session = $database->get_session($session_id);
        
        if (!$session) {
            return new \WP_REST_Response([
                'success' => false,
                'message' => 'Session not found'
            ], 404);
        }
        
        // اضافه کردن اطلاعات اضافی
        $session['message_count'] = $database->get_session_message_count($session_id);
        $session['last_message'] = $database->get_last_session_message($session_id);
        $session['messages'] = $database->get_session_messages($session_id, 50); // آخرین 50 پیام
        
        return new \WP_REST_Response([
            'success' => true,
            'data' => $session
        ]);
    }
    
    public function get_session_messages(\WP_REST_Request $request): \WP_REST_Response {
        $session_id = $request->get_param('id');
        $limit = $request->get_param('limit') ?? 100;
        
        /** @var Database $database */
        $database = Plugin::get_instance()->get_service('database');
        $messages = $database->get_session_messages($session_id, $limit);
        
        return new \WP_REST_Response([
            'success' => true,
            'data' => $messages,
            'count' => count($messages)
        ]);
    }
    
    public function send_message(\WP_REST_Request $request): \WP_REST_Response {
        $session_id = $request->get_param('id');
        $message = $request->get_param('message');
        
        if (empty($message)) {
            return new \WP_REST_Response([
                'success' => false,
                'message' => 'Message content is required'
            ], 400);
        }
        
        /** @var Database $database */
        $database = Plugin::get_instance()->get_service('database');
        
        $message_id = $database->save_message([
            'session_id' => $session_id,
            'user_id' => get_current_user_id(),
            'user_name' => 'پشتیبان',
            'message_content' => sanitize_text_field($message),
            'message_type' => 'admin'
        ]);
        
        if ($message_id) {
            /** @var Pusher_Service $pusher_service */
            $pusher_service = Plugin::get_instance()->get_service('pusher_service');
            
            $pusher_service->trigger(
                'private-chat-' . $session_id,
                'client-message',
                [
                    'id' => $message_id,
                    'message' => $message,
                    'user_id' => get_current_user_id(),
                    'user_name' => 'پشتیبان',
                    'session_id' => $session_id,
                    'timestamp' => current_time('mysql'),
                    'type' => 'admin'
                ]
            );
            
            /** @var Cache_Manager $cache */
            $cache = Plugin::get_instance()->get_service('cache');
            $cache->invalidate_session_cache($session_id);
            
            return new \WP_REST_Response([
                'success' => true,
                'message_id' => $message_id
            ]);
        }
        
        return new \WP_REST_Response([
            'success' => false,
            'message' => 'Failed to send message'
        ], 500);
    }
    
    public function close_session(\WP_REST_Request $request): \WP_REST_Response {
        $session_id = $request->get_param('id');
        
        /** @var Database $database */
        $database = Plugin::get_instance()->get_service('database');
        $result = $database->close_session($session_id);
        
        if ($result) {
            /** @var Cache_Manager $cache */
            $cache = Plugin::get_instance()->get_service('cache');
            $cache->invalidate_session_cache($session_id);
            
            return new \WP_REST_Response([
                'success' => true,
                'message' => 'Session closed successfully'
            ]);
        }
        
        return new \WP_REST_Response([
            'success' => false,
            'message' => 'Failed to close session'
        ], 500);
    }
    
    public function get_stats(): \WP_REST_Response {
        /** @var Database $database */
        $database = Plugin::get_instance()->get_service('database');
        
        global $wpdb;
        $table_sessions = $wpdb->prefix . 'wp_live_chat_sessions';
        $table_messages = $wpdb->prefix . 'wp_live_chat_messages';
        
        $stats = [
            'total_sessions' => $database->get_total_sessions_count(),
            'active_sessions' => count($database->get_active_sessions()),
            'closed_sessions' => count($database->get_closed_sessions()),
            'total_messages' => (int) $wpdb->get_var("SELECT COUNT(*) FROM $table_messages"),
            'today_messages' => (int) $wpdb->get_var(
                $wpdb->prepare("SELECT COUNT(*) FROM $table_messages WHERE DATE(created_at) = %s", current_time('Y-m-d'))
            ),
            'user_messages' => (int) $wpdb->get_var("SELECT COUNT(*) FROM $table_messages WHERE message_type = 'user'"),
            'admin_messages' => (int) $wpdb->get_var("SELECT COUNT(*) FROM $table_messages WHERE message_type = 'admin'"),
            'system_messages' => (int) $wpdb->get_var("SELECT COUNT(*) FROM $table_messages WHERE message_type = 'system'"),
            'avg_response_time' => $this->calculate_avg_response_time()
        ];
        
        return new \WP_REST_Response([
            'success' => true,
            'data' => $stats
        ]);
    }
    
    private function calculate_avg_response_time(): float {
        global $wpdb;
        $table_messages = $wpdb->prefix . 'wp_live_chat_messages';
        
        // محاسبه میانگین زمان پاسخگویی
        $query = "
            SELECT AVG(TIMESTAMPDIFF(SECOND, user_msg.created_at, admin_msg.created_at)) as avg_time
            FROM $table_messages user_msg
            INNER JOIN $table_messages admin_msg ON (
                admin_msg.session_id = user_msg.session_id 
                AND admin_msg.message_type = 'admin' 
                AND admin_msg.created_at > user_msg.created_at
                AND NOT EXISTS (
                    SELECT 1 FROM $table_messages t 
                    WHERE t.session_id = user_msg.session_id 
                    AND t.message_type = 'admin' 
                    AND t.created_at > user_msg.created_at 
                    AND t.created_at < admin_msg.created_at
                )
            )
            WHERE user_msg.message_type = 'user'
        ";
        
        $result = $wpdb->get_var($query);
        return round(floatval($result ?: 0), 2);
    }
}