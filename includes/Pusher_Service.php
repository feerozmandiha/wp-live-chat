<?php

namespace WP_Live_Chat;

use Exception; // این خط را اضافه کنید


class Pusher_Service {
    
    private $pusher = null;
    private $is_connected = false;
    private $config = null;
    
    public function init(): void {
        add_action('wp_live_chat_loaded', [$this, 'setup_pusher']);
    }
    
    private function get_config(): array {
        if (is_null($this->config)) {
            $this->config = [
                'app_id' => get_option('wp_live_chat_pusher_app_id', ''),
                'key'    => get_option('wp_live_chat_pusher_key', ''),
                'secret' => get_option('wp_live_chat_pusher_secret', ''),
                'cluster' => get_option('wp_live_chat_pusher_cluster', 'mt1'),
                'useTLS' => true,
                'encrypted' => true
            ];
        }
        
        return $this->config;
    }
    
    private function has_valid_config(): bool {
        $config = $this->get_config();
        return !empty($config['app_id']) && 
               !empty($config['key']) && 
               !empty($config['secret']) &&
               !empty($config['cluster']);
    }
    
    public function setup_pusher(): void {
        if (!$this->has_valid_config()) {
            $missing = [];
            $config = $this->get_config();
            if (empty($config['app_id'])) $missing[] = 'App ID';
            if (empty($config['key'])) $missing[] = 'Key';
            if (empty($config['secret'])) $missing[] = 'Secret';
            if (empty($config['cluster'])) $missing[] = 'Cluster';
            
            $this->log_error('Pusher credentials missing: ' . implode(', ', $missing));
            return;
        }
        
        try {
            $config = $this->get_config();
            
            $this->log_info('Initializing Pusher with config: ' . json_encode([
                'app_id' => $config['app_id'],
                'key' => $config['key'],
                'cluster' => $config['cluster'],
                'has_secret' => !empty($config['secret'])
            ]));
            
            $this->pusher = new \Pusher\Pusher(
                $config['key'],
                $config['secret'],
                $config['app_id'],
                [
                    'cluster' => $config['cluster'],
                    'useTLS' => $config['useTLS'],
                    'encrypted' => $config['encrypted']
                ]
            );
            
            $this->is_connected = true;
            $this->log_info('Pusher connection established successfully');
            
        } catch (\Exception $e) {
            $this->is_connected = false;
            $this->log_error('Pusher connection failed: ' . $e->getMessage());
        }
    }
    
    public function trigger(string $channel, string $event, array $data): bool {
        if (!$this->is_connected()) {
            $this->log_error('Cannot trigger event: Pusher not connected');
            return false;
        }
        
        try {
            $this->log_info("Triggering event: {$event} on channel: {$channel}");
            $result = $this->pusher->trigger($channel, $event, $data);
            $this->log_info("Event triggered successfully");
            return true;
        } catch (\Exception $e) {
            $this->log_error('Pusher trigger failed: ' . $e->getMessage());
            return false;
        }
    }
    
    public function authenticate_channel(string $channel_name, string $socket_id) {
        if (!$this->is_connected()) {
            $this->log_error('Pusher not connected for channel authentication');
            return false;
        }

        try {
            // برای کانال‌های private باید احراز هویت انجام شود
            if (strpos($channel_name, 'private-') === 0) {
                $auth = $this->pusher->socket_auth($channel_name, $socket_id);
                
                $this->log_info('Channel authenticated: ' . $channel_name);
                return $auth;
            }
            
            // برای کانال‌های public نیاز به احراز هویت نیست
            return ['auth' => ''];
            
        } catch (Exception $e) {
            $this->log_error('Channel authentication failed: ' . $e->getMessage());
            return false;
        }
    }
    
    public function is_connected(): bool {
        return $this->is_connected && !is_null($this->pusher);
    }
    
    public function get_pusher_config(): array {
        return $this->get_config();
    }
    
    public function get_pusher_instance(): ?\Pusher\Pusher {
        return $this->pusher;
    }
    
    private function log_error(string $message): void {
        if (defined('WP_DEBUG') && WP_DEBUG) {
            error_log('WP Live Chat Error: ' . $message);
        }
    }
    
    private function log_info(string $message): void {
        if (defined('WP_DEBUG') && WP_DEBUG) {
            error_log('WP Live Chat Info: ' . $message);
        }
    }
}