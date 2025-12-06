<?php
namespace WP_Live_Chat;
defined('ABSPATH') || exit;

class Database {
    private $charset_collate;

    public function init(): void {
        // placeholder if needed
    }

    public function create_tables(): void {
        global $wpdb;
        $this->charset_collate = $wpdb->get_charset_collate();
        require_once ABSPATH . 'wp-admin/includes/upgrade.php';

        $sessions = $wpdb->prefix . 'wp_live_chat_sessions';
        $messages = $wpdb->prefix . 'wp_live_chat_messages';
        $admin_reads = $wpdb->prefix . 'wp_live_chat_admin_reads';

        $sql1 = "CREATE TABLE IF NOT EXISTS {$sessions} (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            session_id VARCHAR(120) NOT NULL,
            user_id BIGINT UNSIGNED DEFAULT 0,
            user_name VARCHAR(255),
            user_email VARCHAR(255),
            user_phone VARCHAR(50),
            user_ip VARCHAR(45),
            user_agent TEXT,
            status VARCHAR(20) DEFAULT 'active',
            last_activity DATETIME DEFAULT CURRENT_TIMESTAMP,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            UNIQUE KEY session_idx (session_id)
        ) {$this->charset_collate};";

        $sql2 = "CREATE TABLE IF NOT EXISTS {$messages} (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            session_id VARCHAR(120) NOT NULL,
            user_id BIGINT UNSIGNED DEFAULT 0,
            user_name VARCHAR(255),
            message_type VARCHAR(20) DEFAULT 'user',
            message_content LONGTEXT,
            is_read TINYINT(1) DEFAULT 0,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            KEY session_idx (session_id),
            KEY created_idx (created_at)
        ) {$this->charset_collate};";

        $sql3 = "CREATE TABLE IF NOT EXISTS {$admin_reads} (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            session_id VARCHAR(120) NOT NULL,
            message_id BIGINT UNSIGNED NOT NULL,
            admin_id BIGINT UNSIGNED NOT NULL,
            read_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            KEY msg_idx (message_id),
            KEY session_idx (session_id)
        ) {$this->charset_collate};";

        dbDelta([$sql1, $sql2, $sql3]);
    }

    public function save_message(array $data): int {
        global $wpdb;
        $table = $wpdb->prefix . 'wp_live_chat_messages';

        $now = current_time('mysql');
        $insert = [
            'session_id' => $data['session_id'] ?? '',
            'user_id' => $data['user_id'] ?? 0,
            'user_name' => $data['user_name'] ?? '',
            'message_type' => $data['message_type'] ?? 'user',
            'message_content' => $data['message_content'] ?? '',
            'is_read' => $data['is_read'] ?? 0,
            'created_at' => $now
        ];

        $format = ['%s','%d','%s','%s','%s','%d','%s'];
        $result = $wpdb->insert($table, $insert, $format);
        if ($result === false) {
            throw new \Exception('DB insert failed: ' . $wpdb->last_error);
        }

        // ensure session exists and update
        $this->ensure_session_exists($insert['session_id'], ['user_name' => $insert['user_name'], 'user_id' => $insert['user_id']]);
        $this->update_session_activity($insert['session_id']);
        return (int) $wpdb->insert_id;
    }

    public function get_session_messages(string $session_id, int $limit = 200): array {
        global $wpdb;
        $table = $wpdb->prefix . 'wp_live_chat_messages';
        $sql = $wpdb->prepare("SELECT * FROM {$table} WHERE session_id = %s ORDER BY created_at ASC LIMIT %d", $session_id, $limit);
        return $wpdb->get_results($sql, ARRAY_A) ?: [];
    }

    public function get_active_sessions(): array {
        global $wpdb;
        $table = $wpdb->prefix . 'wp_live_chat_sessions';
        $sql = "SELECT * FROM {$table} WHERE status = 'active' ORDER BY last_activity DESC";
        return $wpdb->get_results($sql, ARRAY_A) ?: [];
    }

    public function get_unread_count(string $session_id): int {
        global $wpdb;
        $table = $wpdb->prefix . 'wp_live_chat_messages';
        $sql = $wpdb->prepare("SELECT COUNT(*) FROM {$table} WHERE session_id = %s AND is_read = 0 AND message_type = 'user'", $session_id);
        return (int) $wpdb->get_var($sql);
    }

    public function mark_messages_as_read(string $session_id, int $admin_id = 0, array $message_ids = []): int {
        global $wpdb;
        $table = $wpdb->prefix . 'wp_live_chat_messages';
        if (!empty($message_ids)) {
            $ids = implode(',', array_map('intval', $message_ids));
            $res = $wpdb->query("UPDATE {$table} SET is_read = 1 WHERE id IN ({$ids})");
        } else {
            $res = $wpdb->update($table, ['is_read' => 1], ['session_id' => $session_id], ['%d'], ['%s']);
        }
        return (int) $res;
    }

    public function get_messages_since(string $session_id, $since_id = null): array {
        global $wpdb;
        $table = $wpdb->prefix . 'wp_live_chat_messages';
        if ($since_id) {
            $sql = $wpdb->prepare("SELECT * FROM {$table} WHERE session_id = %s AND id > %d ORDER BY id ASC", $session_id, $since_id);
        } else {
            $sql = $wpdb->prepare("SELECT * FROM {$table} WHERE session_id = %s ORDER BY id ASC", $session_id);
        }
        return $wpdb->get_results($sql, ARRAY_A) ?: [];
    }

    public function ensure_session_exists(string $session_id, array $user = []): bool {
        global $wpdb;
        $table = $wpdb->prefix . 'wp_live_chat_sessions';
        $exists = $wpdb->get_var($wpdb->prepare("SELECT id FROM {$table} WHERE session_id = %s", $session_id));
        if ($exists) return true;

        $insert = [
            'session_id' => $session_id,
            'user_id' => $user['user_id'] ?? 0,
            'user_name' => $user['user_name'] ?? '',
            'user_ip' => $_SERVER['REMOTE_ADDR'] ?? '',
            'user_agent' => $_SERVER['HTTP_USER_AGENT'] ?? '',
            'status' => 'active',
            'last_activity' => current_time('mysql'),
            'created_at' => current_time('mysql')
        ];
        return (bool) $wpdb->insert($table, $insert);
    }

    private function update_session_activity(string $session_id): void {
        global $wpdb;
        $table = $wpdb->prefix . 'wp_live_chat_sessions';
        $wpdb->update($table, ['last_activity' => current_time('mysql')], ['session_id' => $session_id], ['%s'], ['%s']);
    }
}
