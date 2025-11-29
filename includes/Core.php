<?php

namespace WP_Live_Chat;

class Core {
    
    public function init(): void {
        // هسته اصلی سیستم - می‌تواند بعداً گسترش یابد
        add_action('wp_live_chat_loaded', [$this, 'on_loaded']);
        add_action('wp_live_chat_cleanup', [$this, 'cleanup_old_data']);

    }

    public function cleanup_old_data(): void {
        /** @var Database $database */
        $database = Plugin::get_instance()->get_service('database');
        $deleted_count = $database->cleanup_old_sessions(30); // حذف sessions قدیمی‌تر از 30 روز
        
        if ($deleted_count > 0) {
            error_log("WP Live Chat: Cleaned up $deleted_count old sessions");
        }
    }
    
    public function on_loaded(): void {
        // اقدامات پس از بارگذاری کامل افزونه
        $this->check_dependencies();
    }
    
    private function check_dependencies(): void {
        if (!class_exists('Pusher\\Pusher')) {
            add_action('admin_notices', function() {
                echo '<div class="error"><p>WP Live Chat: کتابخانه Pusher یافت نشد.</p></div>';
            });
        }
    }
}