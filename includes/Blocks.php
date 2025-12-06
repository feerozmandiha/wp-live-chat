<?php
namespace WP_Live_Chat;
defined('ABSPATH') || exit;

class Blocks {
    public function init(): void {
        add_action('init', [$this, 'register']);
    }

    public function register(): void {
        if (!function_exists('register_block_type')) return;
        register_block_type('wp-live-chat/chat-widget', ['render_callback' => [$this,'render']]);
    }

    public function render($attrs): string {
        // خروجی پایه؛ frontend.js مسئول رندر نهایی است
        return '<div id="wp-live-chat-root" class="wp-live-chat-root"></div>';
    }
}
