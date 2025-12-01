<?php

namespace WP_Live_Chat;

use Exception; // این خط را اضافه کنید


class Database {
    
    private $charset_collate;
    
    public function init(): void {
        add_action('wp_live_chat_loaded', [$this, 'check_tables']);
    }
    
    public function create_tables(): void {
        global $wpdb;
        
        $this->charset_collate = $wpdb->get_charset_collate();
        
        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        
        $this->create_chat_messages_table();
        $this->create_chat_sessions_table();
        
        // ذخیره نسخه دیتابیس
        update_option('wp_live_chat_db_version', '1.0.0');
    }
    
    private function create_chat_sessions_table(): void {
        global $wpdb;
        
        $table_name = $wpdb->prefix . 'wp_live_chat_sessions';
        
        $sql = "CREATE TABLE $table_name (
            id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
            session_id VARCHAR(100) NOT NULL,
            user_id BIGINT(20) UNSIGNED DEFAULT 0,
            user_name VARCHAR(255) NOT NULL,
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
        
        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        dbDelta($sql);
        
        if (!empty($wpdb->last_error)) {
            error_log('WP Live Chat - Sessions table creation error: ' . $wpdb->last_error);
        } else {
            error_log('WP Live Chat - Sessions table created successfully');
        }
    }
    
    private function create_chat_messages_table(): void {
        global $wpdb;
        
        $table_name = $wpdb->prefix . 'wp_live_chat_messages';
        
        $sql = "CREATE TABLE $table_name (
            id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
            session_id VARCHAR(100) NOT NULL,
            user_id BIGINT(20) UNSIGNED DEFAULT 0,
            user_name VARCHAR(255) NOT NULL,
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
        
        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        dbDelta($sql);
        
        if (!empty($wpdb->last_error)) {
            error_log('WP Live Chat - Messages table creation error: ' . $wpdb->last_error);
        } else {
            error_log('WP Live Chat - Messages table created successfully');
        }
    }
    
    public function check_tables(): void {
        $current_db_version = get_option('wp_live_chat_db_version', '0');
        if (version_compare($current_db_version, '1.0.0', '<')) {
            $this->create_tables();
        }
    }
    
    public function update_session_user_info(string $session_id, string $user_name, string $phone, string $company = ''): bool {
        global $wpdb;
        
        $table_name = $wpdb->prefix . 'wp_live_chat_sessions';
        
        // 🔥 **اصلاح: بررسی وجود session**
        $existing = $wpdb->get_var($wpdb->prepare(
            "SELECT id FROM $table_name WHERE session_id = %s",
            $session_id
        ));
        
        if (!$existing) {
            // اگر session وجود ندارد، ایجاد کن
            error_log("📝 Session {$session_id} does not exist, creating new session");
            
            $result = $wpdb->insert(
                $table_name,
                [
                    'session_id' => $session_id,
                    'user_id' => 0,
                    'user_name' => $user_name,
                    'user_email' => $phone . '@chat.user',
                    'user_phone' => $phone,
                    'user_company' => $company,
                    'user_ip' => $this->get_user_ip(),
                    'user_agent' => $this->get_user_agent(),
                    'status' => 'active',
                    'unread_count' => 0,
                    'last_activity' => current_time('mysql'),
                    'created_at' => current_time('mysql')
                ],
                ['%s', '%d', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%d', '%s', '%s']
            );
            
            if ($result) {
                error_log("✅ New session created with user info for session {$session_id}");
                return true;
            } else {
                error_log("❌ Failed to create session: {$wpdb->last_error}");
                return false;
            }
        } else {
            // آپدیت session موجود
            $result = $wpdb->update(
                $table_name,
                [
                    'user_name' => $user_name,
                    'user_email' => $phone . '@chat.user',
                    'user_phone' => $phone,
                    'user_company' => $company,
                    'last_activity' => current_time('mysql')
                ],
                ['session_id' => $session_id],
                ['%s', '%s', '%s', '%s', '%s'],
                ['%s']
            );
            
            if ($result) {
                error_log("✅ User info updated for session {$session_id} - Name: {$user_name}, Phone: {$phone}");
                return true;
            } else {
                error_log("❌ Failed to update user info for session {$session_id}");
                return false;
            }
        }
    }
    // اضافه کردن این متد برای ایجاد session هنگام اولین پیام
    public function ensure_session_exists($session_id, $user_data) {
        // اعتبارسنجی و encode کردن نام کاربر
        $user_name = $user_data['name'] ?? '';
        $user_email = $user_data['email'] ?? '';
        
        if (empty($user_name) || $user_name === 'undefined') {
            $user_name = 'کاربر مهمان ' . rand(1000, 9999);
        }
        
        // اطمینان از encode صحیح کاراکترهای فارسی
        $user_name = mb_convert_encoding($user_name, 'UTF-8', 'auto');
        $user_name = sanitize_text_field($user_name);
        
        $session_data = [
            'session_id' => $session_id,
            'user_id' => $user_data['id'] ?? 0,
            'user_name' => $user_name,
            'user_email' => $user_email
        ];
        
        return $this->create_or_update_session($session_data);
    }
    
    public function save_message(array $message_data): int {
        global $wpdb;
        
        $table_name = $wpdb->prefix . 'wp_live_chat_messages';
        
        $defaults = [
            'session_id' => '',
            'user_id' => 0,
            'user_name' => 'کاربر ناشناس',
            'user_email' => '',
            'message_type' => 'user',
            'message_content' => '',
            'message_status' => 'sent',
            'created_at' => current_time('mysql')
        ];
        
        $data = wp_parse_args($message_data, $defaults);
        
        // encode کردن کاراکترهای فارسی
        $data['user_name'] = mb_convert_encoding($data['user_name'], 'UTF-8', 'auto');
        $data['message_content'] = mb_convert_encoding($data['message_content'], 'UTF-8', 'auto');
        
        $result = $wpdb->insert(
            $table_name,
            $data,
            ['%s', '%d', '%s', '%s', '%s', '%s', '%s', '%s']
        );
        
        if ($result) {
            // آپدیت last_activity در sessions
            $this->update_session_activity($data['session_id']);
            
            return $wpdb->insert_id;
        }
        
        return 0;
    }
    
    public function update_session_activity(string $session_id): bool {
        global $wpdb;
        
        $table_name = $wpdb->prefix . 'wp_live_chat_sessions';
        
        $result = $wpdb->update(
            $table_name,
            [
                'last_activity' => current_time('mysql'),
                'status' => 'active'
            ],
            ['session_id' => $session_id],
            ['%s', '%s'],
            ['%s']
        );
        
        return (bool) $result;
    }
    
    public function close_session(string $session_id): bool {
        global $wpdb;
        
        $table_name = $wpdb->prefix . 'wp_live_chat_sessions';
        
        $result = $wpdb->update(
            $table_name,
            [
                'status' => 'closed',
                'closed_at' => current_time('mysql')
            ],
            ['session_id' => $session_id],
            ['%s', '%s'],
            ['%s']
        );
        
        return (bool) $result;
    }
    
    public function get_session_messages(string $session_id, int $limit = 100): array {
        global $wpdb;
        
        $table_name = $wpdb->prefix . 'wp_live_chat_messages';
        
        try {
            if (!$this->table_exists($table_name)) {
                return [];
            }
            
            $messages = $wpdb->get_results(
                $wpdb->prepare(
                    "SELECT * FROM {$table_name} 
                     WHERE session_id = %s 
                     ORDER BY created_at ASC 
                     LIMIT %d",
                    $session_id,
                    $limit
                ),
                ARRAY_A
            );
            
            // اگر خطای دیتابیس وجود دارد، لاگ کن
            if ($wpdb->last_error) {
                error_log('WP Live Chat - Database error in get_session_messages: ' . $wpdb->last_error);
                error_log('WP Live Chat - Last query: ' . $wpdb->last_query);
                return [];
            }
            
            return is_array($messages) ? $messages : [];
            
        } catch (Exception $e) {
            error_log('WP Live Chat - Exception in get_session_messages: ' . $e->getMessage());
            return [];
        }
    }
    

    public function create_or_update_session(array $session_data): int {
        global $wpdb;
        
        $table_name = $wpdb->prefix . 'wp_live_chat_sessions';
        
        $defaults = [
            'session_id' => '',
            'user_id' => 0,
            'user_name' => 'کاربر ناشناس',
            'user_email' => '',
            'user_ip' => $this->get_user_ip(),
            'user_agent' => $this->get_user_agent(),
            'status' => 'active',
            'unread_count' => 0,
            'last_activity' => current_time('mysql'),
            'created_at' => current_time('mysql')
        ];
        
        $data = wp_parse_args($session_data, $defaults);
        
        // بررسی وجود session
        $existing_session = $wpdb->get_var($wpdb->prepare(
            "SELECT id FROM $table_name WHERE session_id = %s",
            $data['session_id']
        ));
        
        if ($existing_session) {
            // آپدیت session موجود
            $result = $wpdb->update(
                $table_name,
                [
                    'last_activity' => $data['last_activity'],
                    'user_ip' => $data['user_ip'],
                    'user_agent' => $data['user_agent'],
                    'status' => 'active',
                    'unread_count' => $data['unread_count']
                ],
                ['session_id' => $data['session_id']],
                ['%s', '%s', '%s', '%s', '%d'],
                ['%s']
            );
            
            return $existing_session;
        } else {
            // ایجاد session جدید
            $result = $wpdb->insert(
                $table_name,
                $data,
                ['%s', '%d', '%s', '%s', '%s', '%s', '%s', '%d', '%s', '%s']
            );
            
            if ($result) {
                error_log('WP Live Chat: New session created - ' . $data['session_id'] . ' - User: ' . $data['user_name']);
                return $wpdb->insert_id;
            } else {
                error_log('WP Live Chat: Failed to create session - ' . $wpdb->last_error);
                return 0;
            }
        }
    }

    public function get_session_message_count(string $session_id): int {
        global $wpdb;
        
        $table_name = $wpdb->prefix . 'wp_live_chat_messages';
        
        try {
            if (!$this->table_exists($table_name)) {
                return 0;
            }
            
            // 🔥 **اصلاح: شمارش تمام پیام‌های کاربر (نه فقط نوع user)**
            $count = (int) $wpdb->get_var($wpdb->prepare(
                "SELECT COUNT(*) FROM {$table_name} WHERE session_id = %s",
                $session_id
            ));
            
            error_log("📨 Session {$session_id} has {$count} total messages");
            
            return $count;
            
        } catch (Exception $e) {
            error_log('WP Live Chat - Exception in get_session_message_count: ' . $e->getMessage());
            return 0;
        }
    }

    public function get_last_session_message(string $session_id): array {
        global $wpdb;
        
        $table_name = $wpdb->prefix . 'wp_live_chat_messages';
        
        try {
            if (!$this->table_exists($table_name)) {
                return [];
            }
            
            $message = $wpdb->get_row($wpdb->prepare(
                "SELECT * FROM {$table_name} 
                WHERE session_id = %s 
                ORDER BY created_at DESC 
                LIMIT 1",
                $session_id
            ), ARRAY_A);
            
            return $message ?: [];
            
        } catch (Exception $e) {
            error_log('WP Live Chat - Exception in get_last_session_message: ' . $e->getMessage());
            return [];
        }
    }

    public function get_active_sessions(): array {
        global $wpdb;
        
        $table_name = $wpdb->prefix . 'wp_live_chat_sessions';
        
        try {
            // ابتدا مطمئن شویم جدول وجود دارد
            if (!$this->table_exists($table_name)) {
                error_log('WP Live Chat - Table does not exist: ' . $table_name);
                return [];
            }
            
            $sessions = $wpdb->get_results(
                "SELECT * FROM {$table_name} 
                WHERE status = 'active' 
                ORDER BY last_activity DESC",
                ARRAY_A
            );
            
            // اگر خطای دیتابیس وجود دارد، لاگ کن
            if ($wpdb->last_error) {
                error_log('WP Live Chat - Database error in get_active_sessions: ' . $wpdb->last_error);
                error_log('WP Live Chat - Last query: ' . $wpdb->last_query);
                return [];
            }
            
            return is_array($sessions) ? $sessions : [];
            
        } catch (Exception $e) {
            error_log('WP Live Chat - Exception in get_active_sessions: ' . $e->getMessage());
            return [];
        }
    }

    private function table_exists(string $table_name): bool {
        global $wpdb;
        
        $result = $wpdb->get_var($wpdb->prepare(
            "SHOW TABLES LIKE %s",
            $table_name
        ));
        
        return $result === $table_name;
    }

    // اضافه کردن متد برای ایجاد جداول اگر وجود ندارند
    public function check_and_create_tables(): void {
        global $wpdb;
        
        $sessions_table = $wpdb->prefix . 'wp_live_chat_sessions';
        $messages_table = $wpdb->prefix . 'wp_live_chat_messages';
        
        if (!$this->table_exists($sessions_table)) {
            error_log('WP Live Chat - Sessions table missing, creating...');
            $this->create_chat_sessions_table();
        }
        
        if (!$this->table_exists($messages_table)) {
            error_log('WP Live Chat - Messages table missing, creating...');
            $this->create_chat_messages_table();
        }
    }
    
    public function cleanup_old_sessions(int $days = 30): int {
        global $wpdb;
        
        $table_name = $wpdb->prefix . 'wp_live_chat_sessions';
        $cutoff_date = date('Y-m-d H:i:s', strtotime("-$days days"));
        
        $result = $wpdb->query(
            $wpdb->prepare(
                "DELETE FROM $table_name 
                 WHERE last_activity < %s 
                 AND status != 'active'",
                $cutoff_date
            )
        );
        
        return (int) $result;
    }
    
    private function get_user_ip(): string {
        $ip = '';
        
        if (!empty($_SERVER['HTTP_CLIENT_IP'])) {
            $ip = $_SERVER['HTTP_CLIENT_IP'];
        } elseif (!empty($_SERVER['HTTP_X_FORWARDED_FOR'])) {
            $ip = $_SERVER['HTTP_X_FORWARDED_FOR'];
        } else {
            $ip = $_SERVER['REMOTE_ADDR'] ?? '';
        }
        
        return sanitize_text_field($ip);
    }
    
    private function get_user_agent(): string {
        return sanitize_text_field($_SERVER['HTTP_USER_AGENT'] ?? '');
    }

    /**
     * دریافت تمام sessions با قابلیت فیلتر بر اساس وضعیت
     */
    public function get_all_sessions(string $status = 'all'): array {
        global $wpdb;
        
        $table_name = $wpdb->prefix . 'wp_live_chat_sessions';
        
        try {
            if (!$this->table_exists($table_name)) {
                return [];
            }
            
            $query = "SELECT * FROM {$table_name}";
            $params = [];
            
            if ($status !== 'all') {
                $query .= " WHERE status = %s";
                $params[] = $status;
            }
            
            $query .= " ORDER BY last_activity DESC";
            
            if (!empty($params)) {
                $sessions = $wpdb->get_results(
                    $wpdb->prepare($query, $params),
                    ARRAY_A
                );
            } else {
                $sessions = $wpdb->get_results($query, ARRAY_A);
            }
            
            // اگر خطای دیتابیس وجود دارد، لاگ کن
            if ($wpdb->last_error) {
                error_log('WP Live Chat - Database error in get_all_sessions: ' . $wpdb->last_error);
                return [];
            }
            
            return is_array($sessions) ? $sessions : [];
            
        } catch (Exception $e) {
            error_log('WP Live Chat - Exception in get_all_sessions: ' . $e->getMessage());
            return [];
        }
    }

    /**
     * دریافت اطلاعات یک session خاص بر اساس session_id
     */
    public function get_session(string $session_id): ?array {
        global $wpdb;
        
        $table_name = $wpdb->prefix . 'wp_live_chat_sessions';
        
        try {
            if (!$this->table_exists($table_name)) {
                return null;
            }
            
            $session = $wpdb->get_row(
                $wpdb->prepare(
                    "SELECT * FROM {$table_name} WHERE session_id = %s",
                    $session_id
                ),
                ARRAY_A
            );
            
            // اگر خطای دیتابیس وجود دارد، لاگ کن
            if ($wpdb->last_error) {
                error_log('WP Live Chat - Database error in get_session: ' . $wpdb->last_error);
                return null;
            }
            
            return $session ?: null;
            
        } catch (Exception $e) {
            error_log('WP Live Chat - Exception in get_session: ' . $e->getMessage());
            return null;
        }
    }

    /**
     * دریافت sessions بسته شده
     */
    public function get_closed_sessions(): array {
        return $this->get_all_sessions('closed');
    }

    /**
     * دریافت تعداد کل sessions
     */
    public function get_total_sessions_count(): int {
        global $wpdb;
        
        $table_name = $wpdb->prefix . 'wp_live_chat_sessions';
        
        try {
            if (!$this->table_exists($table_name)) {
                return 0;
            }
            
            $count = (int) $wpdb->get_var("SELECT COUNT(*) FROM {$table_name}");
            
            return $count;
            
        } catch (Exception $e) {
            error_log('WP Live Chat - Exception in get_total_sessions_count: ' . $e->getMessage());
            return 0;
        }
    }

    /**
     * دریافت sessions با pagination
     */
    public function get_sessions_paginated(int $page = 1, int $per_page = 20, string $status = 'all'): array {
        global $wpdb;
        
        $table_name = $wpdb->prefix . 'wp_live_chat_sessions';
        $offset = ($page - 1) * $per_page;
        
        try {
            if (!$this->table_exists($table_name)) {
                return [];
            }
            
            $query = "SELECT * FROM {$table_name}";
            $params = [];
            
            if ($status !== 'all') {
                $query .= " WHERE status = %s";
                $params[] = $status;
            }
            
            $query .= " ORDER BY last_activity DESC LIMIT %d OFFSET %d";
            $params[] = $per_page;
            $params[] = $offset;
            
            $sessions = $wpdb->get_results(
                $wpdb->prepare($query, $params),
                ARRAY_A
            );
            
            // اگر خطای دیتابیس وجود دارد، لاگ کن
            if ($wpdb->last_error) {
                error_log('WP Live Chat - Database error in get_sessions_paginated: ' . $wpdb->last_error);
                return [];
            }
            
            return is_array($sessions) ? $sessions : [];
            
        } catch (Exception $e) {
            error_log('WP Live Chat - Exception in get_sessions_paginated: ' . $e->getMessage());
            return [];
        }
    }
    
    public function get_table_status(): array {
        global $wpdb;
        
        $tables = [
            $wpdb->prefix . 'wp_live_chat_sessions',
            $wpdb->prefix . 'wp_live_chat_messages'
        ];
        
        $status = [];
        
        foreach ($tables as $table) {
            $status[$table] = $wpdb->get_var("SHOW TABLES LIKE '$table'") === $table;
        }
        
        return $status;
    }
}