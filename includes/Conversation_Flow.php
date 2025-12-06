<?php
namespace WP_Live_Chat;

class Conversation_Flow {
    
    private $session_id;
    private $current_step = 'welcome';
    private $steps = [];
    private $user_data = [];
    private $debug_file;
    
    public function __construct($session_id) {
        $this->debug_log('🚀 CONSTRUCTOR STARTED - session_id: ' . $session_id);
        
        $this->session_id = $session_id;
        
        try {
            $this->debug_log('📝 Setting up steps...');
            $this->setup_steps();
            $this->debug_log('✅ Steps setup completed');
            
            $this->debug_log('📥 Loading user data...');
            $this->load_user_data();
            $this->debug_log('✅ User data loaded');
            
            $this->debug_log('🎉 Conversation_Flow constructed successfully');
            
        } catch (\Exception $e) {
            $this->debug_log('❌ CONSTRUCTOR ERROR: ' . $e->getMessage());
            $this->debug_log('📋 Stack trace: ' . $e->getTraceAsString());
        }
    }
    
    private function debug_log($message) {
        if (!defined('WP_DEBUG') || !WP_DEBUG) {
            return;
        }
        
        $log_file = WP_CONTENT_DIR . '/debug-wp-live-chat.log';
        $timestamp = date('Y-m-d H:i:s');
        $log_message = "[{$timestamp}] Conversation_Flow: {$message}\n";
        
        file_put_contents($log_file, $log_message, FILE_APPEND | LOCK_EX);
    }
    
    private function setup_steps() {
        $this->debug_log('📋 STEP 1: setup_steps() called');
        
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
        
        $this->debug_log('✅ setup_steps() completed - steps count: ' . count($this->steps));
    }
    
    public function requires_input(?string $step = null): bool {
        $this->debug_log('📞 requires_input() called - step: ' . ($step ?? 'null'));
        
        try {
            $step = $step ?? $this->get_current_step();
            
            if ($step === 'check_admin_status') {
                $this->debug_log('✅ requires_input() returning false for check_admin_status');
                return false;
            }
            
            $result = $this->steps[$step]['requires_input'] ?? false;
            $this->debug_log('✅ requires_input() result: ' . ($result ? 'true' : 'false'));
            
            return $result;
            
        } catch (\Exception $e) {
            $this->debug_log('❌ requires_input() ERROR: ' . $e->getMessage());
            return false;
        }
    }

    public function get_full_state(): array {
        $this->debug_log('📞 get_full_state() called');
        
        try {
            $current_step = $this->get_current_step();
            $this->debug_log('📝 Current step from get_current_step(): ' . $current_step);
            
            if ($current_step === 'check_admin_status') {
                $admin_online = $this->is_admin_online();
                $this->debug_log('👨‍💼 Admin online status: ' . ($admin_online ? 'true' : 'false'));
                
                if ($admin_online) {
                    $current_step = 'chat_active';
                } else {
                    $current_step = 'waiting_for_admin';
                }
                $this->debug_log('🔄 Updated current step: ' . $current_step);
            }
            
            $state = [
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
            
            $this->debug_log('✅ get_full_state() completed successfully');
            $this->debug_log('📊 State summary - step: ' . $current_step . ', requires_input: ' . ($state['requires_input'] ? 'true' : 'false'));
            
            return $state;
            
        } catch (\Exception $e) {
            $this->debug_log('❌ get_full_state() ERROR: ' . $e->getMessage());
            $this->debug_log('📋 Stack trace: ' . $e->getTraceAsString());
            
            return $this->get_default_state();
        }
    }
    
    private function get_default_state(): array {
        $this->debug_log('📞 get_default_state() called (fallback)');
        
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
        $this->debug_log('📞 get_input_type() called - step: ' . ($step ?? 'null'));
        
        try {
            $step = $step ?? $this->get_current_step();
            $this->debug_log('📝 Using step: ' . $step);
            
            if ($step === 'check_admin_status') {
                $this->debug_log('✅ get_input_type() returning null for check_admin_status');
                return null;
            }
            
            $result = $this->steps[$step]['input_type'] ?? 'general_message';
            $this->debug_log('✅ get_input_type() result: ' . ($result ?? 'null'));
            
            return $result;
            
        } catch (\Exception $e) {
            $this->debug_log('❌ get_input_type() ERROR: ' . $e->getMessage());
            return 'general_message';
        }
    }
        
    public function set_current_step(string $step): bool {
        $this->debug_log('📞 set_current_step() called - step: ' . $step);
        
        try {
            if (isset($this->steps[$step])) {
                $this->current_step = $step;
                $this->debug_log('📝 Current step set to: ' . $step);
                
                $this->save_step();
                $this->debug_log('✅ Step saved to transient');
                
                return true;
            }
            
            $this->debug_log('⚠️ set_current_step() failed - step not found: ' . $step);
            return false;
            
        } catch (\Exception $e) {
            $this->debug_log('❌ set_current_step() ERROR: ' . $e->getMessage());
            return false;
        }
    }
    
    public function update_flow_state(array $state): bool {
        $this->debug_log('📞 update_flow_state() called');
        $this->debug_log('📊 State data: ' . json_encode($state));
        
        try {
            if (isset($state['current_step']) && $this->set_current_step($state['current_step'])) {
                $this->debug_log('✅ Current step updated');
                
                if (isset($state['user_data']) && is_array($state['user_data'])) {
                    $this->user_data = array_merge($this->user_data, $state['user_data']);
                    $this->save_user_data();
                    $this->debug_log('✅ User data merged and saved');
                }
                
                return true;
            }
            
            $this->debug_log('⚠️ update_flow_state() failed');
            return false;
            
        } catch (\Exception $e) {
            $this->debug_log('❌ update_flow_state() ERROR: ' . $e->getMessage());
            return false;
        }
    }
    
    public function get_input_placeholder(?string $step = null): string {
        $this->debug_log('📞 get_input_placeholder() called - step: ' . ($step ?? 'null'));
        
        try {
            $step = $step ?? $this->get_current_step();
            $placeholder = $this->steps[$step]['input_placeholder'] ?? __('پیام خود را تایپ کنید...', 'wp-live-chat');
            
            $this->debug_log('✅ get_input_placeholder() result: ' . $placeholder);
            return $placeholder;
            
        } catch (\Exception $e) {
            $this->debug_log('❌ get_input_placeholder() ERROR: ' . $e->getMessage());
            return __('پیام خود را تایپ کنید...', 'wp-live-chat');
        }
    }
    
    public function get_input_hint(?string $step = null): ?string {
        $this->debug_log('📞 get_input_hint() called - step: ' . ($step ?? 'null'));
        
        try {
            $step = $step ?? $this->get_current_step();
            $hint = $this->steps[$step]['input_hint'] ?? null;
            
            $this->debug_log('✅ get_input_hint() result: ' . ($hint ?? 'null'));
            return $hint;
            
        } catch (\Exception $e) {
            $this->debug_log('❌ get_input_hint() ERROR: ' . $e->getMessage());
            return null;
        }
    }

    public function get_step_message(?string $step = null): string {
        $this->debug_log('📞 get_step_message() called - step: ' . ($step ?? 'null'));
        
        try {
            $step = $step ?? $this->get_current_step();
            $this->debug_log('📝 Using step: ' . $step);
            
            if ($step === 'check_admin_status') {
                $admin_online = $this->is_admin_online();
                $this->debug_log('👨‍💼 Admin online in get_step_message: ' . ($admin_online ? 'true' : 'false'));
                
                if ($admin_online) {
                    $this->current_step = 'chat_active';
                    $this->save_step();
                    $message = __('👨‍💼 پشتیبان آنلاین است. گفتگو را ادامه دهید.', 'wp-live-chat');
                } else {
                    $this->current_step = 'waiting_for_admin';
                    $this->save_step();
                    $message = __('⏳ در حال حاضر پشتیبان آنلاین نیست. پیام شما ذخیره شد و به محض آنلاین شدن، پاسخ داده خواهد شد.', 'wp-live-chat');
                }
                
                $this->debug_log('✅ get_step_message() for check_admin_status: ' . $message);
                return $message;
            }
            
            $message = $this->steps[$step]['message'] ?? '';
            $this->debug_log('✅ get_step_message() result: ' . $message);
            
            return $message;
            
        } catch (\Exception $e) {
            $this->debug_log('❌ get_step_message() ERROR: ' . $e->getMessage());
            return __('👋 سلام! به پشتیبانی آنلاین خوش آمدید.', 'wp-live-chat');
        }
    }
    
    public function get_current_step(): string {
        $this->debug_log('📞 get_current_step() called');
        $this->debug_log('📝 Initial current_step: ' . $this->current_step);
        
        try {
            if ($this->user_data_completed() && $this->current_step === 'name_received') {
                $this->debug_log('📝 User data completed, moving to check_admin_status');
                $this->current_step = 'check_admin_status';
                $this->save_step();
            }
            
            if ($this->current_step === 'check_admin_status') {
                $admin_online = $this->is_admin_online();
                $this->debug_log('👨‍💼 Admin online status: ' . ($admin_online ? 'true' : 'false'));
                
                if ($admin_online) {
                    $new_step = 'chat_active';
                } else {
                    $new_step = 'waiting_for_admin';
                }
                
                if ($this->current_step !== $new_step) {
                    $this->debug_log('🔄 Changing step from ' . $this->current_step . ' to ' . $new_step);
                    $this->current_step = $new_step;
                    $this->save_step();
                }
            }
            
            $this->debug_log('✅ get_current_step() returning: ' . $this->current_step);
            return $this->current_step;
            
        } catch (\Exception $e) {
            $this->debug_log('❌ get_current_step() ERROR: ' . $e->getMessage());
            return 'welcome';
        }
    }
    
    public function process_input($input, $input_type = 'general_message') {
        $this->debug_log('📞 process_input() called');
        $this->debug_log('📝 Input: ' . substr($input, 0, 50) . '...');
        $this->debug_log('📝 Input type: ' . $input_type);
        $this->debug_log('📝 Current step before processing: ' . $this->current_step);
        
        try {
            $current_step = $this->current_step;
            
            if ($current_step === 'name_received' && $this->user_data_completed()) {
                $this->debug_log('📝 User data completed in name_received, moving to check_admin_status');
                $current_step = 'check_admin_status';
                $this->current_step = $current_step;
                $this->save_step();
            }
            
            $step_config = $this->steps[$current_step] ?? null;
            
            if (!$step_config) {
                $this->debug_log('❌ Step config not found for: ' . $current_step);
                return [
                    'success' => false, 
                    'message' => 'خطا: مرحله نامعتبر',
                    'state' => $this->get_full_state()
                ];
            }
            
            $this->debug_log('📋 Step config found: ' . json_encode($step_config));
            
            if (!$step_config['requires_input']) {
                $this->debug_log('📝 Step does not require input');
                return [
                    'success' => true,
                    'state' => $this->get_full_state(),
                    'message' => $this->get_step_message($current_step)
                ];
            }
            
            if (isset($step_config['validation']) && is_callable($step_config['validation'])) {
                $this->debug_log('🔍 Running validation...');
                $validation_result = call_user_func($step_config['validation'], $input);
                
                if (!$validation_result['valid']) {
                    $this->debug_log('❌ Validation failed: ' . ($validation_result['message'] ?? ''));
                    return array_merge($validation_result, [
                        'state' => $this->get_full_state()
                    ]);
                }
                $this->debug_log('✅ Validation passed');
            }
            
            $field_saved = false;
            $this->debug_log('💾 Saving data for input type: ' . ($step_config['input_type'] ?? 'general_message'));
            
            switch ($step_config['input_type'] ?? 'general_message') {
                case 'phone':
                    $this->user_data['phone'] = $input;
                    $field_saved = true;
                    $this->debug_log('📱 Phone saved: ' . substr($input, 0, 3) . '****' . substr($input, -3));
                    break;
                    
                case 'name':
                    $this->user_data['name'] = $input;
                    $field_saved = true;
                    $this->debug_log('👤 Name saved: ' . substr($input, 0, 1) . '***');
                    break;
                    
                case 'general_message':
                    if (empty($this->user_data['first_message'])) {
                        $this->user_data['first_message'] = $input;
                        $this->debug_log('💬 First message saved');
                    }
                    break;
            }
            
            if ($field_saved) {
                $this->save_user_data();
                $this->debug_log('✅ User data saved to transient');
                
                if ($this->user_data_completed()) {
                    $this->update_session_user_info();
                    $this->debug_log('✅ Session user info updated in database');
                }
            }
            
            $next_step = $step_config['next_step'] ?? $current_step;
            $this->debug_log('➡️ Next step from config: ' . $next_step);
            
            if ($current_step === 'phone_received' && !empty($this->user_data['phone'])) {
                $next_step = 'name_received';
                $this->debug_log('🔄 Overriding next step to name_received (phone received)');
            }
            
            if ($current_step === 'name_received' && !empty($this->user_data['name'])) {
                $next_step = 'check_admin_status';
                $this->debug_log('🔄 Overriding next step to check_admin_status (name received)');
            }
            
            $this->current_step = $next_step;
            $this->save_step();
            $this->debug_log('📝 Current step updated to: ' . $next_step);
            
            if ($next_step === 'check_admin_status') {
                $final_step = $this->get_current_step();
                $this->debug_log('🔄 Final step after admin check: ' . $final_step);
            } else {
                $final_step = $next_step;
            }
            
            $final_state = $this->get_full_state();
            
            $this->debug_log('✅ process_input() completed successfully');
            $this->debug_log('📊 Final state step: ' . $final_state['current_step']);
            
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
            $this->debug_log('❌ process_input() ERROR: ' . $e->getMessage());
            $this->debug_log('📋 Stack trace: ' . $e->getTraceAsString());
            
            return [
                'success' => false,
                'message' => 'خطا در پردازش: ' . $e->getMessage(),
                'state' => $this->get_default_state()
            ];
        }
    }
    
    private function log_user_data(string $field, string $value): void {
        $this->debug_log('📞 log_user_data() called - field: ' . $field);
        
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
                    $this->debug_log('✅ Logged user data via logger service');
                } else {
                    $this->debug_log('⚠️ Logger service not available');
                }
            } else {
                $this->debug_log('⚠️ Plugin instance not available');
            }
        } catch (\Exception $e) {
            $this->debug_log('❌ log_user_data() ERROR: ' . $e->getMessage());
        }
    }
    
    public function validate_phone($phone) {
        $this->debug_log('📞 validate_phone() called - phone: ' . $phone);
        
        $phone = trim($phone);
        $pattern = '/^09[0-9]{9}$/';
        
        if (empty($phone)) {
            $this->debug_log('❌ Phone validation failed: empty');
            return ['valid' => false, 'message' => 'شماره موبایل الزامی است'];
        }
        
        if (!preg_match($pattern, $phone)) {
            $this->debug_log('❌ Phone validation failed: invalid format');
            return ['valid' => false, 'message' => 'لطفاً شماره موبایل معتبر وارد کنید (مثال: 09123456789)'];
        }
        
        $this->debug_log('✅ Phone validation passed');
        return ['valid' => true];
    }
    
    public function validate_name($name) {
        $this->debug_log('📞 validate_name() called - name: ' . $name);
        
        $name = trim($name);
        
        if (empty($name)) {
            $this->debug_log('❌ Name validation failed: empty');
            return ['valid' => false, 'message' => 'نام الزامی است'];
        }
        
        if (strlen($name) < 2) {
            $this->debug_log('❌ Name validation failed: too short (length: ' . strlen($name) . ')');
            return ['valid' => false, 'message' => 'نام باید حداقل 2 حرف باشد'];
        }
        
        if (strlen($name) > 100) {
            $this->debug_log('❌ Name validation failed: too long (length: ' . strlen($name) . ')');
            return ['valid' => false, 'message' => 'نام نمی‌تواند بیشتر از 100 حرف باشد'];
        }
        
        $this->debug_log('✅ Name validation passed (length: ' . strlen($name) . ')');
        return ['valid' => true];
    }
    
    private function load_user_data() {
        $this->debug_log('📞 load_user_data() called');
        
        try {
            $transient_key = 'wp_live_chat_user_' . $this->session_id;
            $this->debug_log('🔑 User transient key: ' . $transient_key);
            
            $data = get_transient($transient_key);
            
            if ($data && is_array($data)) {
                $this->user_data = $data;
                $this->debug_log('✅ User data loaded from transient - count: ' . count($data));
            } else {
                $this->debug_log('⚠️ No user data found in transient');
            }
            
            $step_key = 'wp_live_chat_step_' . $this->session_id;
            $this->debug_log('🔑 Step transient key: ' . $step_key);
            
            $step = get_transient($step_key);
            $this->debug_log('📝 Step from transient: ' . ($step ?: 'null'));
            
            if ($step && isset($this->steps[$step])) {
                $this->current_step = $step;
                $this->debug_log('✅ Step loaded from transient: ' . $step);
            } else {
                $this->debug_log('⚠️ Using default step: welcome');
            }
            
        } catch (\Exception $e) {
            $this->debug_log('❌ load_user_data() ERROR: ' . $e->getMessage());
        }
    }
    
    private function save_user_data() {
        $this->debug_log('📞 save_user_data() called');
        
        try {
            $transient_key = 'wp_live_chat_user_' . $this->session_id;
            set_transient($transient_key, $this->user_data, 7 * DAY_IN_SECONDS);
            $this->debug_log('✅ User data saved to transient - key: ' . $transient_key);
            
        } catch (\Exception $e) {
            $this->debug_log('❌ save_user_data() ERROR: ' . $e->getMessage());
        }
    }
    
    private function save_step() {
        $this->debug_log('📞 save_step() called - step: ' . $this->current_step);
        
        try {
            $step_key = 'wp_live_chat_step_' . $this->session_id;
            set_transient($step_key, $this->current_step, 7 * DAY_IN_SECONDS);
            $this->debug_log('✅ Step saved to transient - key: ' . $step_key);
            
        } catch (\Exception $e) {
            $this->debug_log('❌ save_step() ERROR: ' . $e->getMessage());
        }
    }
    
    private function user_data_completed() {
        $completed = !empty($this->user_data['phone']) && !empty($this->user_data['name']);
        $this->debug_log('📞 user_data_completed() called - result: ' . ($completed ? 'true' : 'false'));
        $this->debug_log('📊 Has phone: ' . (!empty($this->user_data['phone']) ? 'yes' : 'no'));
        $this->debug_log('📊 Has name: ' . (!empty($this->user_data['name']) ? 'yes' : 'no'));
        
        return $completed;
    }
    
    private function update_session_user_info() {
        $this->debug_log('📞 update_session_user_info() called');
        
        try {
            $plugin = Plugin::get_instance();
            if ($plugin) {
                $database = $plugin->get_service('database');
                if ($database) {
                    $this->debug_log('✅ Database service available');
                    
                    $success = $database->update_session_user_info(
                        $this->session_id,
                        $this->user_data['name'],
                        $this->user_data['phone'],
                        $this->user_data['company'] ?? ''
                    );
                    
                    if ($success) {
                        $this->debug_log('✅ Session user info updated in database');
                        $this->notify_admin_user_info_updated();
                    } else {
                        $this->debug_log('⚠️ Failed to update session user info in database');
                    }
                } else {
                    $this->debug_log('⚠️ Database service not available');
                }
            } else {
                $this->debug_log('⚠️ Plugin instance not available');
            }
        } catch (\Exception $e) {
            $this->debug_log('❌ update_session_user_info() ERROR: ' . $e->getMessage());
        }
    }
    
    private function notify_admin_user_info_updated() {
        $this->debug_log('📞 notify_admin_user_info_updated() called');
        
        try {
            $plugin = Plugin::get_instance();
            if ($plugin) {
                $pusher_service = $plugin->get_service('pusher_service');
                
                if ($pusher_service) {
                    $this->debug_log('✅ Pusher service available');
                    
                    $pusher_service->trigger('admin-notifications', 'user-info-completed', [
                        'session_id' => $this->session_id,
                        'user_name' => $this->user_data['name'],
                        'user_phone' => $this->user_data['phone'],
                        'timestamp' => current_time('mysql')
                    ]);
                    
                    $this->debug_log('✅ Admin notification sent via Pusher');
                } else {
                    $this->debug_log('⚠️ Pusher service not available');
                }
            } else {
                $this->debug_log('⚠️ Plugin instance not available');
            }
        } catch (\Exception $e) {
            $this->debug_log('❌ notify_admin_user_info_updated() ERROR: ' . $e->getMessage());
        }
    }
    
    public function is_admin_online() {
        $this->debug_log('📞 is_admin_online() called');
        
        try {
            // روش 1: بررسی سریع با get_users
            $this->debug_log('🔍 Checking admins via get_users...');
            $admins = get_users([
                'role' => 'administrator',
                'meta_key' => 'last_activity',
                'meta_value' => time() - 300,
                'meta_compare' => '>'
            ]);
            
            $this->debug_log('👨‍💼 Admin count via get_users: ' . count($admins));
            
            if (count($admins) > 0) {
                $this->debug_log('✅ Admin online via get_users');
                return true;
            }
            
            // روش 2: بررسی جدول custom
            $this->debug_log('🔍 Checking custom admin sessions table...');
            global $wpdb;
            $table_name = $wpdb->prefix . 'wp_live_chat_admin_sessions';
            
            // بررسی وجود جدول
            $this->debug_log('📋 Table name: ' . $table_name);
            $tables = $wpdb->get_col("SHOW TABLES LIKE '{$table_name}'");
            
            if (empty($tables)) {
                $this->debug_log('⚠️ Admin sessions table does not exist');
                return false;
            }
            
            $this->debug_log('✅ Admin sessions table exists');
            
            $count = $wpdb->get_var(
                $wpdb->prepare(
                    "SELECT COUNT(*) FROM {$table_name} WHERE status = 'online' AND last_activity > %s",
                    date('Y-m-d H:i:s', time() - 300)
                )
            );
            
            $this->debug_log('👨‍💼 Admin count in custom table: ' . $count);
            
            $result = $count > 0;
            $this->debug_log('✅ is_admin_online() result: ' . ($result ? 'true' : 'false'));
            
            return $result;
            
        } catch (\Exception $e) {
            $this->debug_log('❌ is_admin_online() ERROR: ' . $e->getMessage());
            $this->debug_log('📋 Stack trace: ' . $e->getTraceAsString());
            return false;
        }
    }
    
    public function get_user_data() {
        $this->debug_log('📞 get_user_data() called - data count: ' . count($this->user_data));
        return $this->user_data;
    }
    
    public function reset_flow(): bool {
        $this->debug_log('📞 reset_flow() called');
        
        try {
            $this->current_step = 'welcome';
            $this->user_data = [];
            $this->save_user_data();
            $this->save_step();
            
            $this->debug_log('✅ Flow reset successfully');
            return true;
            
        } catch (\Exception $e) {
            $this->debug_log('❌ reset_flow() ERROR: ' . $e->getMessage());
            return false;
        }
    }
    
    public function get_debug_info(): array {
        $this->debug_log('📞 get_debug_info() called');
        
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
            'user_data_completed' => $this->user_data_completed()
        ];
    }
    
    /**
     * بررسی سالم بودن کلاس
     */
    public function is_healthy(): bool {
        $healthy = !empty($this->session_id) && !empty($this->steps);
        $this->debug_log('📞 is_healthy() called - result: ' . ($healthy ? 'true' : 'false'));
        
        return $healthy;
    }
}