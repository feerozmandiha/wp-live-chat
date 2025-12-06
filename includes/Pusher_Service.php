<?php
namespace WP_Live_Chat;
defined('ABSPATH') || exit;

use Pusher\Pusher;
use Throwable;

class Pusher_Service {
    private ?Pusher $pusher = null;
    private bool $connected = false;
    private array $config = [];

    public function init(): void {
        add_action('wp_loaded', [$this, 'setup']);
    }

    private function load_config(): void {
        $this->config = [
            'app_id' => get_option('wp_live_chat_pusher_app_id', ''),
            'key' => get_option('wp_live_chat_pusher_key', ''),
            'secret' => get_option('wp_live_chat_pusher_secret', ''),
            'cluster' => get_option('wp_live_chat_pusher_cluster', 'mt1'),
            'useTLS' => true,
        ];
    }

    public function setup(): void {
        $this->load_config();
        $logger = Plugin::get_instance()->get_service('logger');

        if (!class_exists('\Pusher\Pusher')) {
            $logger?->warning('Pusher library missing.');
            $this->connected = false;
            return;
        }

        if (empty($this->config['app_id']) || empty($this->config['key']) || empty($this->config['secret'])) {
            $logger?->warning('Pusher config incomplete.');
            $this->connected = false;
            return;
        }

        try {
            $options = [
                'cluster' => $this->config['cluster'],
                'useTLS' => $this->config['useTLS'] ?? true,
            ];
            $this->pusher = new Pusher(
                $this->config['key'],
                $this->config['secret'],
                $this->config['app_id'],
                $options
            );
            $this->connected = true;
            $logger?->info('Pusher initialized.');
        } catch (Throwable $e) {
            $this->connected = false;
            $logger?->error('Pusher init failed: ' . $e->getMessage());
        }
    }

    public function is_connected(): bool {
        return $this->connected && $this->pusher !== null;
    }

    public function trigger(string $channel, string $event, array $data): bool {
        $logger = Plugin::get_instance()->get_service('logger');
        if (!$this->is_connected()) {
            $logger?->warning("Pusher trigger attempted while disconnected: {$channel} {$event}");
            return false;
        }
        try {
            $this->pusher->trigger($channel, $event, $data);
            return true;
        } catch (Throwable $e) {
            $logger?->error('Pusher trigger failed: ' . $e->getMessage());
            return false;
        }
    }

    public function authorizeChannel(string $channel, string $socket_id) {
        // wrapper for Pusher authorizeChannel
        if (!$this->is_connected()) return false;
        try {
            return $this->pusher->authorizeChannel($channel, $socket_id);
        } catch (Throwable $e) {
            Plugin::get_instance()->get_service('logger')?->error('authorizeChannel error: ' . $e->getMessage());
            return false;
        }
    }

    public function test_connection(): array {
        if (!$this->is_connected()) return ['success' => false, 'message' => 'not_connected'];
        try {
            $channels = $this->pusher->get_channels();
            return ['success' => true, 'message' => 'ok', 'details' => $channels];
        } catch (Throwable $e) {
            return ['success' => false, 'message' => $e->getMessage()];
        }
    }
}
