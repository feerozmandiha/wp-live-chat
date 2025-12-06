<?php
namespace WP_Live_Chat;

class Blocks {
    
    public function init(): void {
        add_action('init', [$this, 'register_blocks']);
    }
    
    public function register_blocks(): void {
        if (!function_exists('register_block_type')) {
            return;
        }
        
        register_block_type('wp-live-chat/chat-widget', [
        'render_callback' => [$this, 'render_chat_widget_block']
        ]);

    }
    
    public function render_chat_widget_block($attributes): string {
        // This will be rendered via the frontend component
        return '<div class="wp-live-chat-block"></div>';
    }
}