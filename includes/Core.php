<?php
namespace WP_Live_Chat;

class Core {
    
    public function init(): void {
        add_action('wp_live_chat_loaded', [$this, 'on_loaded']);
        add_action('wp_live_chat_cleanup', [$this, 'cleanup_old_data']);
    }
    
    public function cleanup_old_data(): void {
        global $wpdb;
        
        $sessions_table = $wpdb->prefix . 'wp_live_chat_sessions';
        $messages_table = $wpdb->prefix . 'wp_live_chat_messages';
        
        $days = get_option('wp_live_chat_auto_cleanup', 30);
        $cutoff_date = date('Y-m-d H:i:s', strtotime("-{$days} days"));
        
        // Delete old sessions
        $wpdb->query($wpdb->prepare(
            "DELETE FROM {$sessions_table} 
            WHERE last_activity < %s 
            AND status != 'active'",
            $cutoff_date
        ));
        
        // Delete orphaned messages
        $wpdb->query("DELETE m FROM {$messages_table} m 
            LEFT JOIN {$sessions_table} s ON m.session_id = s.session_id 
            WHERE s.session_id IS NULL");
    }
    
    public function on_loaded(): void {
        $this->check_dependencies();
    }
    
    private function check_dependencies(): void {
        if (!class_exists('Pusher\Pusher')) {
            add_action('admin_notices', function() {
                ?>
                <div class="notice notice-error">
                    <p><?php _e('WP Live Chat: کتابخانه Pusher یافت نشد. لطفا composer install را اجرا کنید.', 'wp-live-chat'); ?></p>
                </div>
                <?php
            });
        }
    }
}