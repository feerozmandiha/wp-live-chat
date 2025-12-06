<?php
namespace WP_Live_Chat;

use Exception;
use wpdb;

class Database {
    
    private $charset_collate;
    
    public function init(): void {
        add_action('wp_live_chat_loaded', [$this, 'check_tables']);
    }
    
    public function create_tables(): void {
        global $wpdb;
        
        $this->charset_collate = $wpdb->get_charset_collate();
        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        
        $this->create_chat_sessions_table();
        $this->create_chat_messages_table();
        $this->create_admin_sessions_table(); // اصلاح شده: حذف $ اضافی
        
        update_option('wp_live_chat_db_version', '1.0.0');
        
        // افزودن لاگ برای دیباگ
        if (defined('WP_DEBUG') && WP_DEBUG) {
            error_log('WP Live Chat: Tables created successfully');
        }
    }
    
    private function create_chat_sessions_table(): void {
        global $wpdb;
        
        $table_name = $wpdb->prefix . 'wp_live_chat_sessions';
        
        $sql = "CREATE TABLE IF NOT EXISTS $table_name (
            id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
            session_id VARCHAR(100) NOT NULL,
            user_id BIGINT(20) UNSIGNED DEFAULT 0,
            user_name VARCHAR(255) NOT NULL DEFAULT 'کاربر',
            user_email VARCHAR(255),
            user_phone VARCHAR(20),
            user_company VARCHAR(255),
            user_ip VARCHAR(45),
            user_agent TEXT,
            status ENUM('active', 'closed', 'timeout') DEFAULT 'active',
            unread_count INT(11) DEFAULT 0,
            last_activity DATETIME DEFAULT CURRENT_TIMESTAMP,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            closed_at DATETIME NULL,
            PRIMARY KEY (id),
            UNIQUE KEY session_id (session_id),
            KEY user_id (user_id),
            KEY status (status),
            KEY last_activity (last_activity),
            KEY user_phone (user_phone)
        ) {$this->charset_collate};";
        
        dbDelta($sql);
        
        // افزودن لاگ برای دیباگ
        if (defined('WP_DEBUG') && WP_DEBUG) {
            error_log('WP Live Chat: Created table: ' . $table_name);
        }
    }
    
    private function create_chat_messages_table(): void {
        global $wpdb;
        
        $table_name = $wpdb->prefix . 'wp_live_chat_messages';
        
        $sql = "CREATE TABLE IF NOT EXISTS $table_name (
            id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
            session_id VARCHAR(100) NOT NULL,
            user_id BIGINT(20) UNSIGNED DEFAULT 0,
            user_name VARCHAR(255) NOT NULL DEFAULT 'کاربر',
            user_email VARCHAR(255),
            message_type ENUM('user', 'admin', 'system') DEFAULT 'user',
            message_content TEXT NOT NULL,
            message_status ENUM('sent', 'delivered', 'read') DEFAULT 'sent',
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            KEY session_id (session_id),
            KEY user_id (user_id),
            KEY created_at (created_at),
            KEY message_type (message_type)
        ) {$this->charset_collate};";
        
        dbDelta($sql);
        
        // افزودن لاگ برای دیباگ
        if (defined('WP_DEBUG') && WP_DEBUG) {
            error_log('WP Live Chat: Created table: ' . $table_name);
        }
    }

    private function create_admin_sessions_table(): void {
        global $wpdb;
        
        $table_name = $wpdb->prefix . 'wp_live_chat_admin_sessions';
        
        $sql = "CREATE TABLE IF NOT EXISTS $table_name (
            id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
            admin_id BIGINT(20) UNSIGNED NOT NULL,
            status ENUM('online', 'away', 'offline') DEFAULT 'offline',
            current_session_id VARCHAR(100),
            last_activity DATETIME DEFAULT CURRENT_TIMESTAMP,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            UNIQUE KEY admin_id (admin_id),
            KEY status (status),
            KEY last_activity (last_activity)
        ) {$this->charset_collate};";
        
        dbDelta($sql);
        
        // افزودن لاگ برای دیباگ
        if (defined('WP_DEBUG') && WP_DEBUG) {
            error_log('WP Live Chat: Created table: ' . $table_name);
        }
    }
    
    public function save_message(array $message_data): int {
        global $wpdb;
        
        $table_name = $wpdb->prefix . 'wp_live_chat_messages';
        
        $defaults = [
            'session_id' => '',
            'user_id' => 0,
            'user_name' => 'کاربر',
            'message_type' => 'user',
            'message_content' => '',
            'message_status' => 'sent'
        ];
        
        $data = wp_parse_args($message_data, $defaults);
        
        // اطمینان از وجود session
        $this->ensure_session_exists($data['session_id'], [
            'user_id' => $data['user_id'],
            'user_name' => $data['user_name']
        ]);
        
        $result = $wpdb->insert($table_name, $data);
        
        if ($result) {
            $this->update_session_activity($data['session_id']);
            return $wpdb->insert_id;
        }
        
        return 0;
    }
    
    public function ensure_session_exists($session_id, $user_data = []): bool {
        global $wpdb;
        
        $table_name = $wpdb->prefix . 'wp_live_chat_sessions';
        
        $existing = $wpdb->get_var($wpdb->prepare(
            "SELECT id FROM $table_name WHERE session_id = %s",
            $session_id
        ));
        
        if (!$existing) {
            $data = [
                'session_id' => $session_id,
                'user_id' => $user_data['user_id'] ?? 0,
                'user_name' => $user_data['user_name'] ?? 'کاربر',
                'user_ip' => $this->get_user_ip(),
                'user_agent' => $this->get_user_agent(),
                'status' => 'active'
            ];
            
            return (bool) $wpdb->insert($table_name, $data);
        }
        
        return true;
    }
    
    public function update_session_user_info($session_id, $user_name, $phone, $company = ''): bool {
        global $wpdb;
        
        $table_name = $wpdb->prefix . 'wp_live_chat_sessions';
        
        $data = [
            'user_name' => $user_name,
            'user_phone' => $phone,
            'user_company' => $company,
            'last_activity' => current_time('mysql')
        ];
        
        $result = $wpdb->update(
            $table_name,
            $data,
            ['session_id' => $session_id]
        );
        
        return $result !== false;
    }
    
    public function get_session_messages($session_id, $limit = 100): array {
        global $wpdb;
        
        $table_name = $wpdb->prefix . 'wp_live_chat_messages';
        
        return $wpdb->get_results($wpdb->prepare(
            "SELECT * FROM $table_name 
            WHERE session_id = %s 
            ORDER BY created_at ASC 
            LIMIT %d",
            $session_id,
            $limit
        ), ARRAY_A) ?: [];
    }
    
    public function get_active_sessions(): array {
        global $wpdb;
        
        $table_name = $wpdb->prefix . 'wp_live_chat_sessions';
        
        return $wpdb->get_results(
            "SELECT * FROM $table_name 
            WHERE status = 'active' 
            ORDER BY last_activity DESC",
            ARRAY_A
        ) ?: [];
    }
    
    public function get_session($session_id): ?array {
        global $wpdb;
        
        $table_name = $wpdb->prefix . 'wp_live_chat_sessions';
        
        $session = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM $table_name WHERE session_id = %s",
            $session_id
        ), ARRAY_A);
        
        return $session ?: null;
    }
    
    private function update_session_activity($session_id): bool {
        global $wpdb;
        
        $table_name = $wpdb->prefix . 'wp_live_chat_sessions';
        
        return (bool) $wpdb->update(
            $table_name,
            ['last_activity' => current_time('mysql')],
            ['session_id' => $session_id]
        );
    }
    
    private function get_user_ip(): string {
        $ip = '';
        
        foreach (['HTTP_CLIENT_IP', 'HTTP_X_FORWARDED_FOR', 'REMOTE_ADDR'] as $key) {
            if (!empty($_SERVER[$key])) {
                $ip = $_SERVER[$key];
                break;
            }
        }
        
        return filter_var($ip, FILTER_VALIDATE_IP) ? $ip : '';
    }
    
    private function get_user_agent(): string {
        return $_SERVER['HTTP_USER_AGENT'] ?? '';
    }
    
    public function check_tables(): void {
        $current_db_version = get_option('wp_live_chat_db_version', '0');
        if (version_compare($current_db_version, '1.0.0', '<')) {
            $this->create_tables();
        }
    }
}