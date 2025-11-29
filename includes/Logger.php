<?php

namespace WP_Live_Chat;

class Logger {
    
    private static $instance = null;
    private $log_file;
    private $enabled;
    
    public static function get_instance(): self {
        return new self();
    }
    
    public function __construct() {
        $upload_dir = wp_upload_dir();
        $this->log_file = $upload_dir['basedir'] . '/wp-live-chat/debug.log';
        $this->enabled = defined('WP_DEBUG') && WP_DEBUG;
        
        // ایجاد پوشه لاگ
        wp_mkdir_p(dirname($this->log_file));
    }
    
    public function log(string $level, string $message, array $context = []): void {
        if (!$this->enabled) {
            return;
        }
        
        $timestamp = current_time('Y-m-d H:i:s');
        $context_str = !empty($context) ? json_encode($context, JSON_UNESCAPED_UNICODE) : '';
        
        $log_entry = sprintf(
            "[%s] %s: %s %s\n",
            $timestamp,
            strtoupper($level),
            $message,
            $context_str
        );
        
        file_put_contents($this->log_file, $log_entry, FILE_APPEND | LOCK_EX);
    }
    
    public function info(string $message, array $context = []): void {
        $this->log('info', $message, $context);
    }
    
    public function error(string $message, array $context = []): void {
        $this->log('error', $message, $context);
    }
    
    public function warning(string $message, array $context = []): void {
        $this->log('warning', $message, $context);
    }
    
    public function debug(string $message, array $context = []): void {
        $this->log('debug', $message, $context);
    }
    
    public function get_logs(int $lines = 100): array {
        if (!file_exists($this->log_file)) {
            return [];
        }
        
        $content = file_get_contents($this->log_file);
        $log_entries = explode("\n", $content);
        $log_entries = array_filter($log_entries);
        
        return array_slice($log_entries, -$lines);
    }
    
    public function clear_logs(): bool {
        if (file_exists($this->log_file)) {
            return file_put_contents($this->log_file, '') !== false;
        }
        return true;
    }
}