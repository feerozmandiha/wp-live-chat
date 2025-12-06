<?php
namespace WP_Live_Chat;
defined('ABSPATH') || exit;

class Cache_Manager {
    private string $group = 'wp_live_chat';
    public function init(): void {}

    public function get(string $k) { return wp_cache_get($k,$this->group); }
    public function set(string $k,$v,int $exp=300) { return wp_cache_set($k,$v,$this->group,$exp); }
    public function delete(string $k) { return wp_cache_delete($k,$this->group); }
}
