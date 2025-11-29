<?php

namespace WP_Live_Chat;

class Blocks {
    
    public function init(): void {
        add_action('init', [$this, 'register_blocks']);
        add_action('enqueue_block_editor_assets', [$this, 'enqueue_block_assets']);
        add_action('wp_enqueue_scripts', [$this, 'enqueue_frontend_assets']);
    }
    
    public function register_blocks(): void {
        // ثبت بلوک‌ها به صورت داینامیک
        $blocks_dir = WP_LIVE_CHAT_PLUGIN_PATH . 'blocks/';
        
        if (is_dir($blocks_dir)) {
            $blocks = scandir($blocks_dir);
            
            foreach ($blocks as $block) {
                if ($block === '.' || $block === '..') {
                    continue;
                }
                
                $block_path = $blocks_dir . $block;
                $block_json = $block_path . '/block.json';
                
                if (is_dir($block_path) && file_exists($block_json)) {
                    register_block_type($block_path);
                }
            }
        }
    }
    
    public function enqueue_block_assets(): void {
        wp_enqueue_style(
            'wp-live-chat-blocks-editor',
            WP_LIVE_CHAT_PLUGIN_URL . 'assets/css/blocks-editor.css',
            ['wp-edit-blocks'],
            WP_LIVE_CHAT_VERSION
        );
    }
    
    public function enqueue_frontend_assets(): void {
        if (has_block('wp-live-chat/chat-widget') || has_block('wp-live-chat/chat-button')) {
            wp_enqueue_style(
                'wp-live-chat-blocks-frontend',
                WP_LIVE_CHAT_PLUGIN_URL . 'assets/css/blocks-frontend.css',
                [],
                WP_LIVE_CHAT_VERSION
            );
        }
    }
}