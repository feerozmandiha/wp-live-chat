<?php
namespace WP_Live_Chat;


class Conversation_Flow {
    
    private $session_id;
    private $current_step = 'welcome';
    private $steps = [];
    private $user_data = [];
    
    public function __construct($session_id) {
        $this->session_id = $session_id;
        $this->load_user_data();
        $this->setup_steps();
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
     *
     * @param string|null $step نام مرحله (اگر null باشد از مرحله فعلی استفاده می‌کند)
     * @return bool
     */
    public function requires_input(?string $step = null): bool {
        $step = $step ?? $this->get_current_step();
        return $this->steps[$step]['requires_input'] ?? false;
    }
    
    /**
     * دریافت نوع ورودی مورد نیاز برای مرحله فعلی
     *
     * @param string|null $step نام مرحله (اگر null باشد از مرحله فعلی استفاده می‌کند)
     * @return string|null 'phone', 'name', 'general_message' یا null
     */
    public function get_input_type(?string $step = null): ?string {
        $step = $step ?? $this->get_current_step();
        return $this->steps[$step]['input_type'] ?? null;
    }
    
    /**
     * دریافت placeholder مناسب برای فیلد ورودی
     *
     * @param string|null $step نام مرحله
     * @return string
     */
    public function get_input_placeholder(?string $step = null): string {
        $step = $step ?? $this->get_current_step();
        return $this->steps[$step]['input_placeholder'] ?? __('پیام خود را تایپ کنید...', 'wp-live-chat');
    }
    
    /**
     * دریافت hint راهنما برای ورودی
     *
     * @param string|null $step نام مرحله
     * @return string|null
     */
    public function get_input_hint(?string $step = null): ?string {
        $step = $step ?? $this->get_current_step();
        return $this->steps[$step]['input_hint'] ?? null;
    }
    
    /**
     * دریافت پیام مرحله
     *
     * @param string|null $step نام مرحله
     * @return string
     */
    public function get_step_message(?string $step = null): string {
        $step = $step ?? $this->get_current_step();
        
        // اگر مرحله check_admin_status است، وضعیت ادمین را بررسی کن
        if ($step === 'check_admin_status') {
            return $this->get_admin_status_message();
        }
        
        return $this->steps[$step]['message'] ?? '';
    }
    
    /**
     * دریافت پیام وضعیت ادمین
     *
     * @return string
     */
    private function get_admin_status_message(): string {
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
    
    public function get_current_step(): string {
        // اگر کاربر اطلاعاتش را کامل کرده و در مرحله name_received است
        if ($this->user_data_completed() && $this->current_step === 'name_received') {
            return 'check_admin_status';
        }
        
        // اگر در مرحله waiting_for_admin هستیم و ادمین آنلاین است
        if ($this->current_step === 'waiting_for_admin' && $this->is_admin_online()) {
            return 'admin_connected';
        }
        
        // اگر در مرحله chat_active هستیم و ادمین آفلاین است
        if ($this->current_step === 'chat_active' && !$this->is_admin_online()) {
            return 'waiting_for_admin';
        }
        
        // در غیر این صورت مرحله فعلی را برگردان
        return $this->current_step;
    }
    
    public function process_input($input, $input_type = 'general_message') {
        
            error_log("=== CONVERSATION FLOW DEBUG ===");
            error_log("Session ID: " . $this->session_id);
            error_log("Current Step: " . $this->current_step);
            error_log("Input Type: " . $input_type);
            error_log("Input: " . substr($input, 0, 50));

        $current_step = $this->current_step; // استفاده از current_step نه get_current_step()
        $step_config = $this->steps[$current_step] ?? null;
        
        if (!$step_config) {
            return ['success' => false, 'message' => 'خطا در پردازش'];
        }
        
        // اگر مرحله نیاز به ورودی ندارد
        if (!$step_config['requires_input']) {
            return [
                'success' => true,
                'next_step' => $step_config['next_step'],
                'message' => $this->get_step_message($step_config['next_step']),
                'user_data' => $this->user_data
            ];
        }
        
        // بررسی validation
        if (isset($step_config['validation']) && is_callable($step_config['validation'])) {
            $validation_result = call_user_func($step_config['validation'], $input);
            if (!$validation_result['valid']) {
                return $validation_result;
            }
        }
        
        // ذخیره اطلاعات بر اساس نوع input
        switch ($step_config['input_type'] ?? 'general_message') {
            case 'phone':
                $this->user_data['phone'] = $input;
                break;
            case 'name':
                $this->user_data['name'] = $input;
                break;
            case 'general_message':
                // ذخیره اولین پیام کاربر
                if (empty($this->user_data['first_message'])) {
                    $this->user_data['first_message'] = $input;
                }
                break;
        }
        
        // ذخیره اطلاعات کاربر
        $this->save_user_data();
        
        // رفتن به مرحله بعدی
        // بعد از ذخیره اطلاعات، مرحله را به روز کن
        $this->current_step = $step_config['next_step'] ?? $current_step;
        
        // اگر اطلاعات کاربر کامل شد، به check_admin_status برو
        if ($this->user_data_completed() && $this->current_step === 'name_received') {
            $this->current_step = 'check_admin_status';
        }        
        
        $this->save_step();
        
        // بروزرسانی اطلاعات کاربر در دیتابیس
        $this->update_session_user_info();
        
        return [
            'success' => true,
            'next_step' => $this->current_step,
            'message' => $this->get_step_message($this->current_step),
            'user_data' => $this->user_data,
            'requires_input' => $this->requires_input($this->current_step),
            'input_type' => $this->get_input_type($this->current_step),
            'input_placeholder' => $this->get_input_placeholder($this->current_step),
            'input_hint' => $this->get_input_hint($this->current_step)
        ];
    }
    
    /**
     * ثبت لاگ برای اطلاعات کاربر
     *
     * @param string $field نام فیلد
     * @param string $value مقدار
     */
    private function log_user_data(string $field, string $value): void {
        $logger = Plugin::get_instance()->get_service('logger');
        if ($logger) {
            $logger->info('User data saved', [
                'session_id' => $this->session_id,
                'field' => $field,
                'value_masked' => $field === 'phone' ? substr($value, 0, 3) . '****' . substr($value, -3) : substr($value, 0, 1) . '***',
                'step' => $this->current_step
            ]);
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
        $transient_key = 'wp_live_chat_user_' . $this->session_id;
        $data = get_transient($transient_key);
        
        if ($data && is_array($data)) {
            $this->user_data = $data;
        }
        
        $step_key = 'wp_live_chat_step_' . $this->session_id;
        $this->current_step = get_transient($step_key) ?: 'welcome';
    }
    
    private function save_user_data() {
        $transient_key = 'wp_live_chat_user_' . $this->session_id;
        set_transient($transient_key, $this->user_data, 7 * DAY_IN_SECONDS);
    }
    
    private function save_step() {
        $step_key = 'wp_live_chat_step_' . $this->session_id;
        set_transient($step_key, $this->current_step, 7 * DAY_IN_SECONDS);
    }
    
    private function user_data_completed() {
        return !empty($this->user_data['phone']) && !empty($this->user_data['name']);
    }
    
    private function update_session_user_info() {
        if (!empty($this->user_data['phone']) && !empty($this->user_data['name'])) {
            $database = Plugin::get_instance()->get_service('database');
            if ($database) {
                $success = $database->update_session_user_info(
                    $this->session_id,
                    $this->user_data['name'],
                    $this->user_data['phone'],
                    $this->user_data['company'] ?? ''
                );
                
                if ($success) {
                    // ارسال نوتیفیکیشن به ادمین
                    $this->notify_admin_user_info_updated();
                }
            }
        }
    }
    
    private function notify_admin_user_info_updated() {
        $pusher_service = Plugin::get_instance()->get_service('pusher_service');
        
        if ($pusher_service) {
            $pusher_service->trigger('admin-notifications', 'user-info-completed', [
                'session_id' => $this->session_id,
                'user_name' => $this->user_data['name'],
                'user_phone' => $this->user_data['phone'],
                'timestamp' => current_time('mysql')
            ]);
        }
    }
    
    public function is_admin_online() {
        // بررسی آنلاین بودن ادمین‌ها
        $admin_status = get_option('wp_live_chat_admin_online', false);
        
        // اگر در تنظیمات غیرفعال شده باشد
        if (!$admin_status) {
            return false;
        }
        
        // بررسی وجود ادمین آنلاین در سیستم
        global $wpdb;
        $table_name = $wpdb->prefix . 'wp_live_chat_admin_sessions';
        
        // بررسی وجود جدول
        $table_exists = $wpdb->get_var($wpdb->prepare(
            "SHOW TABLES LIKE %s", 
            $table_name
        ));
        
        if (!$table_exists) {
            // اگر جدول وجود ندارد، از روش ساده استفاده کن
            return $this->check_admin_online_simple();
        }
        
        $count = $wpdb->get_var(
            $wpdb->prepare(
                "SELECT COUNT(*) FROM $table_name WHERE status = 'online' AND last_activity > %s",
                date('Y-m-d H:i:s', time() - 300) // 5 دقیقه اخیر
            )
        );
        
        return $count > 0;
    }
    
    private function check_admin_online_simple() {
        // روش ساده برای وقتی که جدول admin sessions وجود ندارد
        // می‌توانیم از تعداد ادمین‌های لاگین کرده در 5 دقیقه اخیر استفاده کنیم
        $admins = get_users([
            'role' => 'administrator',
            'meta_query' => [[
                'key' => 'wp_live_chat_last_activity',
                'value' => time() - 300,
                'compare' => '>',
                'type' => 'NUMERIC'
            ]]
        ]);
        
        return count($admins) > 0;
    }
    
    public function get_user_data() {
        return $this->user_data;
    }
    
    /**
     * ریست کردن flow به حالت اولیه
     *
     * @return bool
     */
    public function reset_flow(): bool {
        $this->current_step = 'welcome';
        $this->user_data = [];
        $this->save_user_data();
        $this->save_step();
        
        // لاگ ریست
        $logger = Plugin::get_instance()->get_service('logger');
        if ($logger) {
            $logger->info('Conversation flow reset', [
                'session_id' => $this->session_id
            ]);
        }
        
        return true;
    }
    
    /**
     * دریافت تمام اطلاعات flow برای دیباگ
     *
     * @return array
     */
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
            'user_data_completed' => $this->user_data_completed()
        ];
    }
}