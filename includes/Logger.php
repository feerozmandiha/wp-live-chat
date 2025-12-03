<?php
namespace WP_Live_Chat;

class Logger {
    
    private $log_file;
    private $enabled;
    
    public function init(): void {
        $upload_dir = wp_upload_dir();
        $this->log_file = $upload_dir['basedir'] . '/wp-live-chat/debug.log';
        $this->enabled = defined('WP_DEBUG') && WP_DEBUG;
        
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
}