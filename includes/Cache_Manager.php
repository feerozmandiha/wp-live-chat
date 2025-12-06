<?php
namespace WP_Live_Chat;

class Cache_Manager {
    
    private $cache_group = 'wp_live_chat';
    private $cache_time = 300; // 5 دقیقه (کاهش از 1 ساعت)
    
    public function get(string $key) {
        return wp_cache_get($key, $this->cache_group);
    }
    
    public function set(string $key, $data, int $expiration = null): bool {
        $expiration = $expiration ?: $this->cache_time;
        return wp_cache_set($key, $data, $this->cache_group, $expiration);
    }
    
    // کش کردن جلسات فعال (کاهش بار دیتابیس)
    public function get_active_sessions(): array {
        $cache_key = 'active_sessions_' . get_current_user_id();
        $sessions = $this->get($cache_key);
        
        if ($sessions === false) {
            $database = Plugin::get_instance()->get_service('database');
            $sessions = $database->get_active_sessions();
            $this->set($cache_key, $sessions, 60); // فقط 1 دقیقه کش
        }
        
        return $sessions;
    }
    
    // کش کردن پیام‌ها
    public function get_session_messages(string $session_id): array {
        $cache_key = "session_messages_{$session_id}";
        $messages = $this->get($cache_key);
        
        if ($messages === false) {
            $database = Plugin::get_instance()->get_service('database');
            $messages = $database->get_session_messages($session_id);
            $this->set($cache_key, $messages, 120); // 2 دقیقه کش
        }
        
        return $messages;
    }
    
    public function invalidate_session_cache(string $session_id): void {
        $this->delete("session_messages_{$session_id}");
        $this->delete('active_sessions_' . get_current_user_id());
    }
    
    public function delete(string $key): bool {
        return wp_cache_delete($key, $this->cache_group);
    }
}