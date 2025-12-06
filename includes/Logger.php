<?php
namespace WP_Live_Chat;
defined('ABSPATH') || exit;

class Logger {
    private ?string $file = null;
    private bool $enabled = false;

    public function init(): void {
        $this->enabled = defined('WP_DEBUG') && WP_DEBUG;
        $upload = wp_upload_dir();
        $dir = $upload['basedir'] . '/wp-live-chat';
        wp_mkdir_p($dir);
        $this->file = $dir . '/debug.log';
    }

    private function write(string $level, string $msg): void {
        if (!$this->enabled || !$this->file) return;
        $line = sprintf("[%s] %s: %s\n", current_time('mysql'), strtoupper($level), $msg);
        file_put_contents($this->file, $line, FILE_APPEND | LOCK_EX);
    }

    public function info(string $m) { $this->write('info',$m); }
    public function warning(string $m) { $this->write('warning',$m); }
    public function error(string $m) { $this->write('error',$m); }
}
