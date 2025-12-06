<?php
namespace WP_Live_Chat;

class Conversation_Flow {
    
    private $session_id;
    private $current_step = 'welcome';
    private $steps = [];
    private $user_data = [];
    private $initialized = false;
    
    public function __construct($session_id) {
        $this->session_id = $session_id;
        $this->setup_steps();
        $this->load_user_data();
        $this->initialized = true;
    }
    
    private function setup_steps() {
        $this->steps = [
            'welcome' => [
                'message' => __('👋 سلام! به پشتیبانی آنلاین خوش آمدید. لطفاً سوال یا درخواست خود را بنویسید.', 'wp-live-chat'),
                'next_step' => 'first_message_received',
                'requires_input' => true,
                'input_type' => 'general_message',
                'input_placeholder' => __('پیام خود را تایپ کنید...', 'wp-live-chat')
            ],
            'first_message_received' => [
                'message' => __('✅ پیام شما دریافت شد. برای بهتر شدن خدمات، لطفاً شماره موبایل خود را وارد کنید:', 'wp-live-chat'),
                'next_step' => 'phone_received',
                'requires_input' => true,
                'input_type' => 'phone',
                'input_placeholder' => __('09xxxxxxxxx', 'wp-live-chat'),
                'validation' => [$this, 'validate_phone'],
                'input_hint' => __('شماره موبایل معتبر ایرانی (مثال: 09123456789)', 'wp-live-chat')
            ],
            'phone_received' => [
                'message' => __('✅ شماره شما ثبت شد. اکنون لطفاً نام خود یا نام شرکت را وارد کنید:', 'wp-live-chat'),
                'next_step' => 'name_received',
                'requires_input' => true,
                'input_type' => 'name',
                'input_placeholder' => __('نام شما یا شرکت', 'wp-live-chat'),
                'validation' => [$this, 'validate_name'],
                'input_hint' => __('حداقل 2 حرف و حداکثر 100 حرف', 'wp-live-chat')
            ],
            'name_received' => [
                'message' => __('✅ اطلاعات شما با موفقیت ثبت شد!', 'wp-live-chat'),
                'next_step' => 'check_admin_status',
                'requires_input' => false,
                'input_type' => null
            ],
            'check_admin_status' => [
                'message' => '',
                'next_step' => '',
                'requires_input' => false,
                'input_type' => null
            ],
            'waiting_for_admin' => [
                'message' => __('⏳ در حال حاضر پشتیبان آنلاین نیست. پیام شما ذخیره شد و به محض آنلاین شدن، پاسخ داده خواهد شد.', 'wp-live-chat'),
                'next_step' => 'admin_connected',
                'requires_input' => true,
                'input_type' => 'general_message',
                'input_placeholder' => __('پیام خود را تایپ کنید...', 'wp-live-chat')
            ],
            'admin_connected' => [
                'message' => __('👨‍💼 پشتیبان آنلاین شد. گفتگو را ادامه دهید.', 'wp-live-chat'),
                'next_step' => 'chat_active',
                'requires_input' => false,
                'input_type' => null
            ],
            'chat_active' => [
                'message' => '',
                'next_step' => 'chat_active',
                'requires_input' => true,
                'input_type' => 'general_message',
                'input_placeholder' => __('پیام خود را تایپ کنید...', 'wp-live-chat')
            ]
        ];
    }
    
    /**
     * بررسی آیا مرحله فعلی نیاز به ورودی کاربر دارد یا خیر
     */
    public function requires_input(?string $step = null): bool {
        if (!$this->initialized) return false;
        
        try {
            $step = $step ?? $this->get_current_step();
            
            if ($step === 'check_admin_status') {
                return false;
            }
            
            return $this->steps[$step]['requires_input'] ?? false;
        } catch (\Exception $e) {
            return false;
        }
    }

    public function get_full_state(): array {
        if (!$this->initialized) {
            return $this->get_default_state();
        }
        
        try {
            $current_step = $this->get_current_step();
            
            if ($current_step === 'check_admin_status') {
                if ($this->is_admin_online()) {
                    $current_step = 'chat_active';
                } else {
                    $current_step = 'waiting_for_admin';
                }
            }
            
            return [
                'current_step' => $current_step,
                'user_data' => $this->user_data,
                'requires_input' => $this->requires_input($current_step),
                'input_type' => $this->get_input_type($current_step),
                'input_placeholder' => $this->get_input_placeholder($current_step),
                'input_hint' => $this->get_input_hint($current_step),
                'message' => $this->get_step_message($current_step),
                'user_data_completed' => $this->user_data_completed(),
                'is_admin_online' => $this->is_admin_online(),
                'session_id' => $this->session_id,
                'timestamp' => current_time('timestamp')
            ];
        } catch (\Exception $e) {
            return $this->get_default_state();
        }
    }
    
    private function get_default_state(): array {
        return [
            'current_step' => 'welcome',
            'user_data' => [],
            'requires_input' => true,
            'input_type' => 'general_message',
            'input_placeholder' => __('پیام خود را تایپ کنید...', 'wp-live-chat'),
            'input_hint' => null,
            'message' => __('👋 سلام! به پشتیبانی آنلاین خوش آمدید.', 'wp-live-chat'),
            'user_data_completed' => false,
            'is_admin_online' => false,
            'session_id' => $this->session_id,
            'timestamp' => current_time('timestamp')
        ];
    }
    
    public function get_input_type(?string $step = null): ?string {
        if (!$this->initialized) return 'general_message';
        
        try {
            $step = $step ?? $this->get_current_step();
            
            if ($step === 'check_admin_status') {
                return null;
            }
            
            return $this->steps[$step]['input_type'] ?? 'general_message';
        } catch (\Exception $e) {
            return 'general_message';
        }
    }
        
    public function set_current_step(string $step): bool {
        if (!$this->initialized) return false;
        
        if (isset($this->steps[$step])) {
            $this->current_step = $step;
            $this->save_step();
            return true;
        }
        return false;
    }
    
    public function update_flow_state(array $state): bool {
        if (!$this->initialized) return false;
        
        try {
            if (isset($state['current_step']) && $this->set_current_step($state['current_step'])) {
                if (isset($state['user_data']) && is_array($state['user_data'])) {
                    $this->user_data = array_merge($this->user_data, $state['user_data']);
                    $this->save_user_data();
                }
                return true;
            }
            return false;
        } catch (\Exception $e) {
            return false;
        }
    }
    
    public function get_input_placeholder(?string $step = null): string {
        if (!$this->initialized) return __('پیام خود را تایپ کنید...', 'wp-live-chat');
        
        try {
            $step = $step ?? $this->get_current_step();
            return $this->steps[$step]['input_placeholder'] ?? __('پیام خود را تایپ کنید...', 'wp-live-chat');
        } catch (\Exception $e) {
            return __('پیام خود را تایپ کنید...', 'wp-live-chat');
        }
    }
    
    public function get_input_hint(?string $step = null): ?string {
        if (!$this->initialized) return null;
        
        try {
            $step = $step ?? $this->get_current_step();
            return $this->steps[$step]['input_hint'] ?? null;
        } catch (\Exception $e) {
            return null;
        }
    }

    public function get_step_message(?string $step = null): string {
        if (!$this->initialized) return __('👋 سلام! به پشتیبانی آنلاین خوش آمدید.', 'wp-live-chat');
        
        try {
            $step = $step ?? $this->get_current_step();
            
            if ($step === 'check_admin_status') {
                if ($this->is_admin_online()) {
                    $this->current_step = 'chat_active';
                    $this->save_step();
                    return __('👨‍💼 پشتیبان آنلاین است. گفتگو را ادامه دهید.', 'wp-live-chat');
                } else {
                    $this->current_step = 'waiting_for_admin';
                    $this->save_step();
                    return __('⏳ در حال حاضر پشتیبان آنلاین نیست. پیام شما ذخیره شد و به محض آنلاین شدن، پاسخ داده خواهد شد.', 'wp-live-chat');
                }
            }
            
            return $this->steps[$step]['message'] ?? '';
        } catch (\Exception $e) {
            return __('👋 سلام! به پشتیبانی آنلاین خوش آمدید.', 'wp-live-chat');
        }
    }
    
    public function get_current_step(): string {
        if (!$this->initialized) return 'welcome';
        
        try {
            if ($this->user_data_completed() && $this->current_step === 'name_received') {
                $this->current_step = 'check_admin_status';
                $this->save_step();
            }
            
            if ($this->current_step === 'check_admin_status') {
                if ($this->is_admin_online()) {
                    $new_step = 'chat_active';
                } else {
                    $new_step = 'waiting_for_admin';
                }
                
                if ($this->current_step !== $new_step) {
                    $this->current_step = $new_step;
                    $this->save_step();
                }
            }
            
            return $this->current_step;
        } catch (\Exception $e) {
            return 'welcome';
        }
    }
    
    public function process_input($input, $input_type = 'general_message') {
        if (!$this->initialized) {
            return [
                'success' => false,
                'message' => 'سیستم در حال حاضر در دسترس نیست.',
                'state' => $this->get_default_state()
            ];
        }
        
        try {
            $current_step = $this->current_step;
            
            if ($current_step === 'name_received' && $this->user_data_completed()) {
                $current_step = 'check_admin_status';
                $this->current_step = $current_step;
                $this->save_step();
            }
            
            $step_config = $this->steps[$current_step] ?? null;
            
            if (!$step_config) {
                return [
                    'success' => false, 
                    'message' => 'خطا: مرحله نامعتبر',
                    'state' => $this->get_full_state()
                ];
            }
            
            if (!$step_config['requires_input']) {
                return [
                    'success' => true,
                    'state' => $this->get_full_state(),
                    'message' => $this->get_step_message($current_step)
                ];
            }
            
            if (isset($step_config['validation']) && is_callable($step_config['validation'])) {
                $validation_result = call_user_func($step_config['validation'], $input);
                if (!$validation_result['valid']) {
                    return array_merge($validation_result, [
                        'state' => $this->get_full_state()
                    ]);
                }
            }
            
            $field_saved = false;
            switch ($step_config['input_type'] ?? 'general_message') {
                case 'phone':
                    $this->user_data['phone'] = $input;
                    $field_saved = true;
                    break;
                    
                case 'name':
                    $this->user_data['name'] = $input;
                    $field_saved = true;
                    break;
                    
                case 'general_message':
                    if (empty($this->user_data['first_message'])) {
                        $this->user_data['first_message'] = $input;
                    }
                    break;
            }
            
            if ($field_saved) {
                $this->save_user_data();
                
                if ($this->user_data_completed()) {
                    $this->update_session_user_info();
                }
            }
            
            $next_step = $step_config['next_step'] ?? $current_step;
            
            if ($current_step === 'phone_received' && !empty($this->user_data['phone'])) {
                $next_step = 'name_received';
            }
            
            if ($current_step === 'name_received' && !empty($this->user_data['name'])) {
                $next_step = 'check_admin_status';
            }
            
            $this->current_step = $next_step;
            $this->save_step();
            
            if ($next_step === 'check_admin_status') {
                $final_step = $this->get_current_step();
            } else {
                $final_step = $next_step;
            }
            
            $final_state = $this->get_full_state();
            
            return [
                'success' => true,
                'state' => $final_state,
                'message' => $this->get_step_message($final_step),
                'user_data' => $this->user_data,
                'next_step' => $final_step,
                'input_processed' => true,
                'field_type' => $step_config['input_type'] ?? 'general_message'
            ];
            
        } catch (\Exception $e) {
            return [
                'success' => false,
                'message' => 'خطا در پردازش: ' . $e->getMessage(),
                'state' => $this->get_default_state()
            ];
        }
    }
    
    private function log_user_data(string $field, string $value): void {
        try {
            $plugin = Plugin::get_instance();
            if ($plugin) {
                $logger = $plugin->get_service('logger');
                if ($logger) {
                    $logger->info('User data saved', [
                        'session_id' => $this->session_id,
                        'field' => $field,
                        'value_masked' => $field === 'phone' ? substr($value, 0, 3) . '****' . substr($value, -3) : substr($value, 0, 1) . '***',
                        'step' => $this->current_step
                    ]);
                }
            }
        } catch (\Exception $e) {
            // ignore logger errors
        }
    }
    
    public function validate_phone($phone) {
        $phone = trim($phone);
        $pattern = '/^09[0-9]{9}$/';
        
        if (empty($phone)) {
            return ['valid' => false, 'message' => 'شماره موبایل الزامی است'];
        }
        
        if (!preg_match($pattern, $phone)) {
            return ['valid' => false, 'message' => 'لطفاً شماره موبایل معتبر وارد کنید (مثال: 09123456789)'];
        }
        
        return ['valid' => true];
    }
    
    public function validate_name($name) {
        $name = trim($name);
        
        if (empty($name)) {
            return ['valid' => false, 'message' => 'نام الزامی است'];
        }
        
        if (strlen($name) < 2) {
            return ['valid' => false, 'message' => 'نام باید حداقل 2 حرف باشد'];
        }
        
        if (strlen($name) > 100) {
            return ['valid' => false, 'message' => 'نام نمی‌تواند بیشتر از 100 حرف باشد'];
        }
        
        return ['valid' => true];
    }
    
    private function load_user_data() {
        try {
            $transient_key = 'wp_live_chat_user_' . $this->session_id;
            $data = get_transient($transient_key);
            
            if ($data && is_array($data)) {
                $this->user_data = $data;
            }
            
            $step_key = 'wp_live_chat_step_' . $this->session_id;
            $step = get_transient($step_key);
            
            if ($step && isset($this->steps[$step])) {
                $this->current_step = $step;
            }
        } catch (\Exception $e) {
            // ignore transient errors
        }
    }
    
    private function save_user_data() {
        try {
            $transient_key = 'wp_live_chat_user_' . $this->session_id;
            set_transient($transient_key, $this->user_data, 7 * DAY_IN_SECONDS);
        } catch (\Exception $e) {
            // ignore transient errors
        }
    }
    
    private function save_step() {
        try {
            $step_key = 'wp_live_chat_step_' . $this->session_id;
            set_transient($step_key, $this->current_step, 7 * DAY_IN_SECONDS);
        } catch (\Exception $e) {
            // ignore transient errors
        }
    }
    
    private function user_data_completed() {
        return !empty($this->user_data['phone']) && !empty($this->user_data['name']);
    }
    
    private function update_session_user_info() {
        try {
            $plugin = Plugin::get_instance();
            if ($plugin) {
                $database = $plugin->get_service('database');
                if ($database) {
                    $success = $database->update_session_user_info(
                        $this->session_id,
                        $this->user_data['name'],
                        $this->user_data['phone'],
                        $this->user_data['company'] ?? ''
                    );
                    
                    if ($success) {
                        $this->notify_admin_user_info_updated();
                    }
                }
            }
        } catch (\Exception $e) {
            // ignore database errors
        }
    }
    
    private function notify_admin_user_info_updated() {
        try {
            $plugin = Plugin::get_instance();
            if ($plugin) {
                $pusher_service = $plugin->get_service('pusher_service');
                
                if ($pusher_service) {
                    $pusher_service->trigger('admin-notifications', 'user-info-completed', [
                        'session_id' => $this->session_id,
                        'user_name' => $this->user_data['name'],
                        'user_phone' => $this->user_data['phone'],
                        'timestamp' => current_time('mysql')
                    ]);
                }
            }
        } catch (\Exception $e) {
            // ignore notification errors
        }
    }
    
    public function is_admin_online() {
        // همیشه ابتدا از ساده‌ترین روش استفاده کن
        try {
            // روش 1: بررسی سریع با get_users
            $admins = get_users([
                'role' => 'administrator',
                'meta_key' => 'last_activity',
                'meta_value' => time() - 300,
                'meta_compare' => '>'
            ]);
            
            if (count($admins) > 0) {
                return true;
            }
            
            // روش 2: بررسی جدول custom اگر وجود دارد
            global $wpdb;
            $table_name = $wpdb->prefix . 'wp_live_chat_admin_sessions';
            
            // بررسی وجود جدول بدون استفاده از query مستقیم
            $tables = $wpdb->get_col("SHOW TABLES LIKE '{$table_name}'");
            
            if (empty($tables)) {
                return false; // جدول وجود ندارد
            }
            
            // اگر جدول وجود دارد، query بزن
            $count = $wpdb->get_var(
                $wpdb->prepare(
                    "SELECT COUNT(*) FROM {$table_name} WHERE status = 'online' AND last_activity > %s",
                    date('Y-m-d H:i:s', time() - 300)
                )
            );
            
            return $count > 0;
            
        } catch (\Exception $e) {
            // در صورت هرگونه خطا، false برگردان
            return false;
        }
    }
    
    public function get_user_data() {
        return $this->user_data;
    }
    
    public function reset_flow(): bool {
        try {
            $this->current_step = 'welcome';
            $this->user_data = [];
            $this->save_user_data();
            $this->save_step();
            
            return true;
        } catch (\Exception $e) {
            return false;
        }
    }
    
    public function get_debug_info(): array {
        return [
            'session_id' => $this->session_id,
            'current_step' => $this->current_step,
            'user_data' => [
                'has_phone' => !empty($this->user_data['phone']),
                'has_name' => !empty($this->user_data['name']),
                'has_first_message' => !empty($this->user_data['first_message']),
                'data_count' => count($this->user_data)
            ],
            'requires_input' => $this->requires_input(),
            'input_type' => $this->get_input_type(),
            'is_admin_online' => $this->is_admin_online(),
            'user_data_completed' => $this->user_data_completed(),
            'initialized' => $this->initialized
        ];
    }
    
    /**
     * بررسی سالم بودن کلاس
     */
    public function is_healthy(): bool {
        return $this->initialized && !empty($this->session_id);
    }
}