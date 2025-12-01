(function($) {
    'use strict';

    class WPLiveChat {
        constructor() {
            console.log('🚀 WP Live Chat Initializing...');
            
            // بررسی وجود config
            if (!window.wpLiveChat) {
                console.error('❌ wpLiveChat config is missing!');
                return;
            }
            
            this.config = window.wpLiveChat;
            
            // بررسی فیلدهای ضروری config
            if (!this.config.sessionId) {
                console.error('❌ sessionId is missing in config!');
                return;
            }
            
            console.log('Config loaded successfully:', {
                hasSessionId: !!this.config.sessionId,
                hasAjaxUrl: !!this.config.ajaxurl,
                hasNonce: !!this.config.nonce
            });
            
            this.pusher = null;
            this.channel = null;
            this.isConnected = false;
            this.isOpen = false;
            this.unreadCount = 0;
            this.sessionId = this.config.sessionId;
            this.currentUser = this.config.currentUser || {};
            this.messageHistoryLoaded = false;
            this.userInfoSubmitted = (this.currentUser && this.currentUser.info_completed) || false;
            this.messageCount = 0;
            this.infoFormShown = false;
            this.currentInputType = null; // 'phone', 'name', null
            this.isWaitingForInput = false;
            
            this.init();
        }

        init() {
            console.log('🔧 Starting initialization...');
            
            try {
                this.createDOM();
                this.bindEvents();
                this.fixPointerEvents(); // اضافه کردن این خط
                this.initPusher();
                this.startConnectionMonitor();
                console.log('✅ Initialization completed successfully');
            } catch (error) {
                console.error('❌ Initialization failed:', error);
                this.showGlobalError('خطا در راه‌اندازی چت: ' + error.message);
            }
        }

            // اضافه کردن متد برای رفع مشکل pointer events
        fixPointerEvents() {
            if (!this.container) return;
            
            // اطمینان از اینکه وقتی چت بسته است، فقط toggle فعال باشد
            if (this.container.classList.contains('wp-live-chat-hidden')) {
                this.container.style.pointerEvents = 'none';
                
                // فعال کردن pointer events برای toggle
                if (this.toggle) {
                    this.toggle.style.pointerEvents = 'auto';
                    if (this.toggle.parentNode) {
                        this.toggle.parentNode.style.pointerEvents = 'auto';
                    }
                }
            } else {
                // وقتی چت باز است، همه چیز فعال
                this.container.style.pointerEvents = 'auto';
            }
        }

            // اضافه کردن این متدها به کلاس
        handleSystemMessage(messageData) {
            console.log('🔧 Handling system message:', messageData);
            
            if (messageData.requires_input) {
                this.currentInputType = messageData.input_type;
                this.isWaitingForInput = true;
                this.updateInputPlaceholder();
            }
        }

        updateInputPlaceholder() {
            if (!this.textarea) return;
            
            const placeholders = {
                phone: 'شماره موبایل خود را وارد کنید... (مثال: 09123456789)',
                name: 'نام و نام خانوادگی خود را وارد کنید...',
                default: 'پیام خود را تایپ کنید...'
            };
            
            this.textarea.placeholder = placeholders[this.currentInputType] || placeholders.default;
            
            // پاک کردن محتوای قبلی
            this.textarea.value = '';
            this.updateCharCounter();
            this.validateInput();
        }

        showGlobalError(message) {
            console.error('💥 Global Error:', message);
            const errorDiv = document.createElement('div');
            errorDiv.style.cssText = `
                position: fixed;
                top: 20px;
                right: 20px;
                background: #dc3232;
                color: white;
                padding: 15px;
                border-radius: 5px;
                z-index: 1000000;
                max-width: 300px;
            `;
            errorDiv.innerHTML = `
                <strong>خطا در چت:</strong>
                <p style="margin: 5px 0 0 0; font-size: 12px;">${message}</p>
            `;
            document.body.appendChild(errorDiv);
            
            // حذف خودکار بعد از 10 ثانیه
            setTimeout(() => {
                if (errorDiv.parentNode) {
                    errorDiv.parentNode.removeChild(errorDiv);
                }
            }, 10000);
        }

        createDOM() {
            console.log('Creating DOM elements...');
            
            this.container = document.getElementById('wp-live-chat-container');
            
            if (!this.container) {
                console.error('❌ Chat container not found in DOM');
                this.showError('عنصر چت در صفحه یافت نشد');
                return;
            }

            console.log('✅ Chat container found:', this.container);

            // تغییر موقعیت به چپ
            this.container.classList.remove('position-bottom-right');
            this.container.classList.add('position-bottom-left');

            this.widget = this.container.querySelector('.chat-widget');
            this.toggle = this.container.querySelector('.chat-toggle');
            this.messagesContainer = this.container.querySelector('.chat-messages');
            this.textarea = this.container.querySelector('.chat-input-area textarea');
            this.sendButton = this.container.querySelector('.send-button');
            this.charCounter = this.container.querySelector('.char-counter');
            this.statusIndicator = this.container.querySelector('.status-indicator');
            this.statusDot = this.container.querySelector('.status-dot');
            this.statusText = this.container.querySelector('.status-text');

            if (!this.widget) {
                console.error('❌ Chat widget not found');
            } else {
                console.log('✅ Chat widget found');
            }

            if (!this.toggle) {
                console.error('❌ Chat toggle not found');
            } else {
                console.log('✅ Chat toggle found');
            }

            this.updateCharCounter();
            this.validateInput();
            
            // اطمینان از نمایش اولیه
            this.container.classList.add('wp-live-chat-hidden');
            console.log('✅ DOM initialization completed');
        }

        bindEvents() {
            console.log('Binding events...');
            
            // رویدادهای toggle - با دیباگ بیشتر
            if (this.toggle) {
                console.log('✅ Toggle button found, adding click event');
                this.toggle.addEventListener('click', (e) => {
                    console.log('🎯 Toggle clicked!', e);
                    this.openChat();
                });
                
                // تست ساده برای اطمینان از کارکرد کلیک
                this.toggle.style.cursor = 'pointer';
            } else {
                console.error('❌ Toggle button not found!');
            }

            // رویدادهای بستن
            const closeButton = this.container.querySelector('.chat-close');
            if (closeButton) {
                closeButton.addEventListener('click', () => this.closeChat());
            }

            // رویدادهای input
            if (this.textarea) {
                this.textarea.addEventListener('input', () => {
                    this.updateCharCounter();
                    this.validateInput();
                });

                this.textarea.addEventListener('keydown', (e) => {
                    if (e.key === 'Enter' && !e.shiftKey) {
                        e.preventDefault();
                        this.sendMessage();
                    }
                });
            }

            // رویدادهای دکمه ارسال
            if (this.sendButton) {
                this.sendButton.addEventListener('click', () => this.sendMessage());
            }

            // کلیک خارج از ویجت
            document.addEventListener('click', (e) => {
                if (this.isOpen && !this.container.contains(e.target)) {
                    this.closeChat();
                }
            });

            // رویدادهای کیبورد
            document.addEventListener('keydown', (e) => {
                if (e.key === 'Escape' && this.isOpen) {
                    this.closeChat();
                }
            });

            console.log('✅ Events bound successfully');
        }

        initPusher() {
            console.log('🔄 Initializing Pusher...');
            
            if (!this.config.pusherKey || !this.config.pusherCluster) {
                console.error('❌ Pusher configuration missing');
                this.showError('تنظیمات چت کامل نیست');
                return;
            }

            if (typeof Pusher === 'undefined') {
                console.error('❌ Pusher library not loaded');
                this.showError('کتابخانه چت بارگذاری نشد');
                return;
            }

            try {
                const pusherConfig = {
                    cluster: this.config.pusherCluster,
                    authEndpoint: this.config.ajaxurl,
                    auth: {
                        params: {
                            action: 'auth_pusher_channel',
                            nonce: this.config.nonce
                        }
                    },
                    forceTLS: true,
                    enabledTransports: ['ws', 'wss']
                };

                console.log('🔧 Pusher config:', {
                    key: this.config.pusherKey,
                    cluster: this.config.pusherCluster,
                    authEndpoint: this.config.ajaxurl
                });

                this.pusher = new Pusher(this.config.pusherKey, pusherConfig);
                
                // رویدادهای اتصال با جزئیات بیشتر
                this.pusher.connection.bind('initialized', () => {
                    console.log('🔌 Pusher initialized');
                });

                this.pusher.connection.bind('connecting', () => {
                    console.log('🔄 Pusher connecting...');
                    this.setStatus('connecting');
                });

                this.pusher.connection.bind('connected', () => {
                    console.log('✅ Pusher connected successfully');
                    console.log('📡 Socket ID:', this.pusher.connection.socket_id);
                    this.isConnected = true;
                    this.setStatus('online');
                    this.subscribeToChannel();
                });

                this.pusher.connection.bind('disconnected', () => {
                    console.log('🔴 Pusher disconnected');
                    this.isConnected = false;
                    this.setStatus('offline');
                });

                this.pusher.connection.bind('error', (err) => {
                    console.error('❌ Pusher connection error:', err);
                    this.isConnected = false;
                    this.setStatus('offline');
                    
                    let errorMessage = 'خطا در اتصال به چت';
                    if (err.error) {
                        if (err.error.data) {
                            console.error('Error details:', err.error.data);
                            if (err.error.data.code === 4001) {
                                errorMessage = 'خطا در احراز هویت - لطفا تنظیمات را بررسی کنید';
                            } else if (err.error.data.code === 4003) {
                                errorMessage = 'کانال یافت نشد';
                            } else if (err.error.data.message) {
                                errorMessage = err.error.data.message;
                            }
                        }
                    }
                    this.showError(errorMessage);
                });

                this.pusher.connection.bind('state_change', (states) => {
                    console.log('🔄 Pusher state change:', states);
                });

            } catch (error) {
                console.error('❌ Pusher initialization error:', error);
                this.isConnected = false;
                this.setStatus('offline');
                this.showError('خطا در راه‌اندازی سیستم چت: ' + error.message);
            }
        }

                // به کلاس این متد را اضافه کنید
        startConnectionMonitor() {
            // بررسی دوره‌ای وضعیت اتصال
            setInterval(() => {
                if (this.pusher && this.channel) {
                    const shouldBeConnected = 
                        this.pusher.connection.state === 'connected' && 
                        this.channel.subscribed;
                    
                    if (shouldBeConnected && !this.isConnected) {
                        console.log('🔄 Connection monitor: Fixing connection status');
                        this.isConnected = true;
                        this.setStatus('online');
                        this.validateInput();
                    }
                }
            }, 5000); // هر 5 ثانیه بررسی کن
        }

        subscribeToChannel() {
            if (!this.pusher) {
                console.error('❌ Cannot subscribe: Pusher not initialized');
                return;
            }

            const channelName = `private-chat-${this.sessionId}`;
            console.log('📡 Subscribing to channel:', channelName);

            try {
                this.channel = this.pusher.subscribe(channelName);
                
                this.channel.bind('pusher:subscription_succeeded', () => {
                    console.log('✅ Channel subscription succeeded');
                    console.log('🔗 Channel:', this.channel);
                    this.isConnected = true;
                    this.validateInput();
                });

                this.channel.bind('pusher:subscription_error', (error) => {
                    console.error('❌ Channel subscription error:', error);
                    console.error('Error details:', error);
                    this.isConnected = true;
                    this.validateInput();
                    
                    let errorMessage = 'خطا در اتصال به کانال چت';
                    if (error.status === 403) {
                        errorMessage = 'خطا در احراز هویت کانال';
                    } else if (error.status === 404) {
                        errorMessage = 'کانال یافت نشد';
                    }
                    this.showError(errorMessage);
                });

                this.channel.bind('client-message', (data) => {
                    console.log('📨 New message received:', data);
                    this.handleIncomingMessage(data);
                });

            } catch (error) {
                console.error('❌ Channel subscription error:', error);
                this.isConnected = false;
                this.validateInput();
            }
        }

        setStatus(status) {
            const statusMap = {
                connecting: { text: 'در حال اتصال...', class: 'connecting' },
                online: { text: 'آنلاین', class: 'online' },
                offline: { text: 'آفلاین', class: 'offline' }
            };

            const statusInfo = statusMap[status] || statusMap.offline;
            
            if (this.statusDot) {
                this.statusDot.className = `status-dot ${statusInfo.class}`;
            }
            
            if (this.statusText) {
                this.statusText.textContent = statusInfo.text;
            }
        }

        openChat() {
            try {
                console.log('🎯 openChat() called');
                
                if (!this.container) {
                    console.error('❌ Container is null in openChat!');
                    return;
                }
                
                this.container.classList.remove('wp-live-chat-hidden');
                this.isOpen = true;
                this.unreadCount = 0;
                this.updateNotificationBadge();
                
                // فعال کردن pointer events وقتی چت باز است
                this.fixPointerEvents();
                
                console.log('✅ Chat opened successfully');
                
                // 🔥 **اصلاح: بررسی واقعی وضعیت اطلاعات کاربر**
                console.log('📊 User info check:', {
                    userInfoSubmitted: this.userInfoSubmitted,
                    currentUser: this.currentUser,
                    info_completed: this.currentUser.info_completed
                });
                
                // 🔥 **اصلاح: اگر اطلاعات کاربر کامل نیست یا فرم قبلاً نشان داده نشده، فرم را نمایش بده**
                if (!this.userInfoSubmitted && 
                    (!this.currentUser.info_completed || this.currentUser.info_completed === false) && 
                    !this.infoFormShown) {
                    
                    console.log('📝 User info incomplete, showing form');
                    
                    // ابتدا اطمینان حاصل کنیم که عناصر فرم وجود دارند
                    this.ensureFormElements();
                    
                    // پنهان کردن بخش چت
                    this.hideChatInterface();
                    
                    // نمایش فرم اطلاعات کاربر
                    this.showUserInfoForm();
                    
                    // 🔥 **اضافه: ارسال پیام سیستم برای درخواست اطلاعات**
                    this.requestUserInfoFromSystem();
                    
                } else {
                    console.log('💬 User info complete, showing chat interface');
                    this.showChatInterface();
                }
                
            } catch (error) {
                console.error('❌ Error in openChat:', error);
                this.showGlobalError('خطا در باز کردن چت: ' + error.message);
            }
        }

        
        // 🔥 **اضافه: متد جدید برای درخواست اطلاعات از سیستم**
        requestUserInfoFromSystem() {
            console.log('📨 Requesting user info from system...');
            
            // اگر هنوز اولین پیام ارسال نشده، سیستم پیام درخواست اطلاعات بفرستد
            if (this.messageCount === 0 && this.userInfoSubmitted === false) {
                console.log('📱 Sending system request for user info');
                
                // نمایش پیام سیستم
                const systemMessage = {
                    id: 'system_req_' + Date.now(),
                    message: '📱 لطفاً شماره موبایل خود را وارد کنید تا بتوانیم با شما در ارتباط باشیم:',
                    user_id: 0,
                    user_name: 'سیستم',
                    timestamp: new Date().toISOString(),
                    type: 'system',
                    requires_input: true,
                    input_type: 'phone'
                };
                
                this.handleSystemMessage(systemMessage);
                this.displayMessage(systemMessage);
            }
        }


        // 🔥 **اضافه: متد جدید برای اطمینان از وجود عناصر فرم**
        ensureFormElements() {
            console.log('🔍 Ensuring form elements exist...');
            
            // بررسی وجود فرم
            if (!this.container.querySelector('#user-info-form')) {
                console.error('❌ User info form not found in DOM!');
                
                // ایجاد فرم به صورت پویا اگر وجود ندارد
                this.createUserInfoForm();
            }
            
            // بررسی وجود عناصر فرم
            this.userInfoForm = this.container.querySelector('#user-info-form');
            this.contactInfoForm = this.container.querySelector('#contact-info-form');
            this.chatInputArea = this.container.querySelector('.chat-input-area');
            
            console.log('📋 Form elements check:', {
                userInfoForm: !!this.userInfoForm,
                contactInfoForm: !!this.contactInfoForm,
                chatInputArea: !!this.chatInputArea
            });
        }


        showUserInfoForm() {
            console.log('📝 Showing user info form');
            
            // پنهان کردن بخش چت
            this.hideChatInterface();
            
            // نمایش فرم اطلاعات کاربر
            const form = this.container.querySelector('#user-info-form');
            if (form) {
                form.style.display = 'block';
                this.infoFormShown = true;
                
                // پر کردن فرم با اطلاعات موجود
                const nameInput = form.querySelector('#user-name');
                const phoneInput = form.querySelector('#user-phone');
                const companyInput = form.querySelector('#user-company');
                
                if (this.currentUser.name && nameInput) {
                    nameInput.value = this.currentUser.name;
                }
                
                if (this.currentUser.phone && phoneInput) {
                    phoneInput.value = this.currentUser.phone;
                }
                
                if (this.currentUser.company && companyInput) {
                    companyInput.value = this.currentUser.company;
                }
            } else {
                console.error('❌ User info form not found!');
            }
        }

        // اصلاح متد showChatInterface برای بارگذاری صحیح تاریخچه
        showChatInterface() {
            console.log('💬 Showing chat interface');
            
            // مخفی کردن فرم
            const form = this.container.querySelector('#user-info-form');
            if (form) {
                form.style.display = 'none';
            }
            
            // نمایش بخش چت
            const inputArea = this.container.querySelector('.chat-input-area');
            if (inputArea) {
                inputArea.style.display = 'block';
            }
            
            // 🔥 **اصلاح: همیشه تاریخچه را بارگذاری کن، حتی اگر قبلاً بارگذاری شده**
            this.loadMessageHistory().then(() => {
                console.log('✅ Message history loaded successfully');
                this.scrollToBottom();
            }).catch(error => {
                console.error('❌ Error loading message history:', error);
                this.scrollToBottom();
            });
            
            if (this.textarea) {
                setTimeout(() => {
                    this.textarea.focus();
                }, 300);
            }
        }

        hideChatInterface() {
            const inputArea = this.container.querySelector('.chat-input-area');
            if (inputArea) {
                inputArea.style.display = 'none';
            }
        }

        setupInfoForm() {
            const form = this.container.querySelector('#contact-info-form');
            if (!form) return;

            form.addEventListener('submit', (e) => {
                e.preventDefault();
                this.submitUserInfo();
            });

            // اعتبارسنجی real-time
            const phoneInput = form.querySelector('#user-phone');
            const nameInput = form.querySelector('#user-name');

            if (phoneInput) {
                phoneInput.addEventListener('input', () => {
                    this.validatePhone(phoneInput.value);
                });
            }

            if (nameInput) {
                nameInput.addEventListener('input', () => {
                    this.validateName(nameInput.value);
                });
            }
        }

        validatePhone(phone) {
            const errorElement = document.getElementById('phone-error');
            const phoneRegex = /^09[0-9]{9}$/;
            
            if (!phone) {
                this.showError(errorElement, this.config.strings.phoneRequired);
                return false;
            }
            
            if (!phoneRegex.test(phone)) {
                this.showError(errorElement, this.config.strings.invalidPhone);
                return false;
            }
            
            this.hideError(errorElement);
            return true;
        }

        validateName(name) {
            const errorElement = document.getElementById('name-error');
            
            if (!name || name.trim().length < 2) {
                this.showError(errorElement, this.config.strings.nameRequired);
                return false;
            }
            
            this.hideError(errorElement);
            return true;
        }

        showError(element, message) {
            if (element) {
                element.textContent = message;
                element.style.display = 'block';
            }
        }

        hideError(element) {
            if (element) {
                element.textContent = '';
                element.style.display = 'none';
            }
        }

        // در متد submitUserInfo - اصلاح برای ذخیره‌سازی صحیح
        async submitUserInfo() {
            console.log('📤 Submitting user info...');
            
            const form = this.container.querySelector('#contact-info-form');
            if (!form) {
                console.error('❌ Contact info form not found!');
                return;
            }

            const formData = new FormData(form);
            const phone = formData.get('phone');
            const name = formData.get('name');
            const company = formData.get('company');

            console.log('📋 Form data:', { phone, name, company });

            // اعتبارسنجی نهایی
            if (!this.validatePhone(phone) || !this.validateName(name)) {
                console.log('❌ Form validation failed');
                return;
            }

            const submitBtn = form.querySelector('.submit-btn');
            if (submitBtn) {
                submitBtn.disabled = true;
                submitBtn.textContent = 'در حال ثبت...';
            }

            try {
                // 🔥 **اصلاح: استفاده از action جدید برای ذخیره‌سازی اطلاعات**
                const response = await $.ajax({
                    url: this.config.ajaxurl,
                    type: 'POST',
                    data: {
                        action: 'save_user_info', // این action باید در PHP تعریف شود
                        nonce: this.config.nonce,
                        phone: phone,
                        name: name,
                        company: company,
                        session_id: this.sessionId
                    },
                    dataType: 'json'
                });

                console.log('📤 Save user info response:', response);

                if (response.success) {
                    console.log('✅ User info saved successfully');
                    
                    // 🔥 **اصلاح: به‌روزرسانی صحیح وضعیت کاربر**
                    this.userInfoSubmitted = true;
                    this.currentUser = {
                        ...this.currentUser,
                        name: name,
                        phone: phone,
                        company: company,
                        info_completed: true
                    };
                    
                    // 🔥 **اصلاح: به‌روزرسانی config برای استفاده در پیام‌های بعدی**
                    this.config.currentUser = this.currentUser;
                    
                    console.log('👤 Updated user data:', this.currentUser);
                    
                    // نمایش رابط چت
                    this.showChatInterface();
                    
                    // نمایش پیام خوش‌آمدگویی
                    this.displayWelcomeMessage(name);
                    
                    // 🔥 **ارسال پیام خوش‌آمدگویی به سرور**
                    await this.sendWelcomeMessageToServer(name);
                    
                } else {
                    console.error('❌ Failed to save user info:', response.data);
                    this.showError('خطا در ثبت اطلاعات: ' + response.data);
                }

            } catch (error) {
                console.error('❌ Error saving user info:', error);
                this.showError('خطا در ارتباط با سرور: ' + error.message);
            } finally {
                if (submitBtn) {
                    submitBtn.disabled = false;
                    submitBtn.textContent = 'شروع گفتگو';
                }
            }
        }

      // 🔥 **اضافه: متد sendWelcomeMessageToServer**
        async sendWelcomeMessageToServer(userName) {
            try {
                const response = await $.ajax({
                    url: this.config.ajaxurl,
                    type: 'POST',
                    data: {
                        action: 'send_welcome_message',
                        nonce: this.config.nonce,
                        session_id: this.sessionId,
                        user_name: userName
                    },
                    dataType: 'json'
                });
                
                console.log('👋 Welcome message sent:', response);
            } catch (error) {
                console.error('❌ Error sending welcome message:', error);
            }
        }  

        displayWelcomeMessage(userName) {
            const welcomeMsg = `
                <div class="system-message">
                    <div class="message-content">
                        <p>سلام <strong>${this.escapeHtml(userName)}</strong>! خوش آمدید. چگونه می‌توانم کمک کنم؟</p>
                    </div>
                </div>
            `;
            
            if (this.messagesContainer) {
                this.messagesContainer.insertAdjacentHTML('beforeend', welcomeMsg);
                this.scrollToBottom();
            }
        }

        closeChat() {
            console.log('Closing chat...');
            this.container.classList.add('wp-live-chat-hidden');
            this.isOpen = false;

                    // غیرفعال کردن pointer events وقتی چت بسته است
            this.fixPointerEvents();
            console.log('✅ Chat closed');

        }

        updateCharCounter() {
            if (!this.charCounter || !this.textarea) return;
            
            const length = this.textarea.value.length;
            const maxLength = 500;
            
            this.charCounter.textContent = `${length}/${maxLength}`;
            
            this.charCounter.classList.remove('near-limit', 'exceeded');
            
            if (length > maxLength * 0.8) {
                this.charCounter.classList.add('near-limit');
            }
            
            if (length > maxLength) {
                this.charCounter.classList.add('exceeded');
            }
        }

        validateInput() {
            if (!this.textarea || !this.sendButton) return false;
            
            const message = this.textarea.value.trim();
            
            // فقط طول پیام را بررسی کنید - اتصال Pusher اجباری نباشد
            const isValid = message.length > 0 && message.length <= 500;
            
            this.sendButton.disabled = !isValid;
            
            // تغییر استایل برای حالت فعال/غیرفعال
            if (isValid) {
                this.sendButton.style.background = '#007cba';
                this.sendButton.style.cursor = 'pointer';
            } else {
                this.sendButton.style.background = '#ccc';
                this.sendButton.style.cursor = 'not-allowed';
            }
            
            console.log('Validation:', {
                messageLength: message.length,
                isValid: isValid,
                isConnected: this.isConnected
            });
            
            return isValid;
        }

        // در متد sendMessage - اصلاح برای جلوگیری از نمایش دوگانه
        async sendMessage() {
            // 🔥 **اضافه: بررسی اگر در حال ارسال هستیم، از ارسال مجدد جلوگیری کنیم**
            if (this.isSendingMessage) {
                console.log('⏳ Message already being sent, please wait...');
                return;
            }
            
            // اگر در حال دریافت اطلاعات هستیم، به جای پیام عادی اطلاعات را ذخیره کنیم
            if (this.isWaitingForInput && this.currentInputType) {
                await this.handleUserInput();
                return;
            }
            
            if (!this.textarea) return;
            
            const message = this.textarea.value.trim();
            
            if (!this.validateInput()) {
                console.log('Message validation failed');
                return;
            }

            // 🔥 **اضافه: علامت گذاری برای جلوگیری از ارسال همزمان**
            this.isSendingMessage = true;
            this.messageCount++;

            console.log('📤 Sending message:', message);

            if (this.sendButton) {
                this.sendButton.disabled = true;
                this.sendButton.textContent = 'در حال ارسال...';
            }

            try {
                // 🔥 **اصلاح: نمایش محلی پیام با flag مخصوص**
                const tempMessageId = 'temp_' + Date.now();
                const localMessageData = {
                    id: tempMessageId,
                    message: message,
                    user_id: this.currentUser.id,
                    user_name: this.currentUser.name || 'کاربر',
                    timestamp: new Date().toISOString(),
                    type: 'user',
                    isTemp: true,
                    isLocal: true // 🔥 اضافه کردن flag برای تشخیص پیام محلی
                };
                
                this.displayMessage(localMessageData, false); // عدم اسکرول فوری
                
                // پاک کردن textarea
                this.textarea.value = '';
                this.updateCharCounter();
                this.validateInput();

                console.log('✅ Message displayed locally (temp)');

                // ارسال به Pusher برای نمایش فوری در طرف دیگر
                if (this.channel && this.isConnected) {
                    this.channel.trigger('client-message', {
                        ...localMessageData,
                        isBroadcast: true // 🔥 علامت برای broadcast
                    });
                    console.log('✅ Message sent via Pusher (temp)');
                }

                // ارسال به سرور برای ذخیره دائمی
                const response = await $.ajax({
                    url: this.config.ajaxurl,
                    type: 'POST',
                    data: {
                        action: 'send_chat_message',
                        nonce: this.config.nonce,
                        message: message,
                        user_id: this.currentUser.id,
                        user_name: this.currentUser.name || 'کاربر',
                        session_id: this.sessionId
                    },
                    dataType: 'json',
                    timeout: 5000
                });

                console.log('📤 Server response:', response);

                if (response.success) {
                    console.log('✅ Message saved to database');
                    
                    // 🔥 **اصلاح: آپدیت پیام موقت با ID واقعی**
                    this.updateTempMessage(tempMessageId, response.data.message_id);
                    
                } else {
                    console.error('❌ Server error:', response.data);
                    // در صورت خطا، پیام موقت را علامت‌گذاری کنیم
                    this.markTempMessageAsFailed(tempMessageId);
                }

            } catch (error) {
                console.error('❌ Send message error:', error);
                this.markTempMessageAsFailed(tempMessageId);
            } finally {
                // 🔥 **اصلاح: بازنشانی flag ارسال**
                this.isSendingMessage = false;
                
                if (this.sendButton) {
                    this.sendButton.disabled = false;
                    this.sendButton.textContent = this.config.strings.send;
                    this.validateInput();
                }
                
                // اسکرول به پایین بعد از اتمام
                this.scrollToBottom();
            }
        }

        // 🔥 **اضافه: متد جدید برای علامت‌گذاری پیام موقت به عنوان ناموفق**
        markTempMessageAsFailed(tempId) {
            const messageElement = this.messagesContainer.querySelector(`[data-message-id="${tempId}"]`);
            if (messageElement) {
                messageElement.classList.add('failed-message');
                
                const statusDiv = document.createElement('div');
                statusDiv.className = 'message-status failed';
                statusDiv.textContent = '⚠️';
                statusDiv.title = 'ارسال ناموفق - لطفا دوباره تلاش کنید';
                messageElement.appendChild(statusDiv);
                
                console.log('⚠️ Temp message marked as failed:', tempId);
            }
        }

            // 🔥 **اضافه: متد جدید برای آپدیت پیام موقت**
        updateTempMessage(tempId, realId) {
            const messageElement = this.messagesContainer.querySelector(`[data-message-id="${tempId}"]`);
            if (messageElement) {
                // آپدیت ID
                messageElement.dataset.messageId = realId;
                
                // حذف کلاس temp
                messageElement.classList.remove('temp-message');
                
                // اضافه کردن علامت تحویل
                const statusDiv = document.createElement('div');
                statusDiv.className = 'message-status delivered';
                statusDiv.textContent = '✓✓';
                messageElement.appendChild(statusDiv);
                
                console.log('✅ Temp message updated with real ID:', realId);
            }
        }

        // 🔥 **اصلاح: متد handleUserInput به async**
        async handleUserInput() {
            if (!this.textarea) return;
            
            const inputValue = this.textarea.value.trim();
            
            if (!inputValue) {
                console.log('Input value is empty');
                return;
            }

            if (this.sendButton) {
                this.sendButton.disabled = true;
                this.sendButton.textContent = 'در حال ارسال...';
            }

            try {
                let response;
                
                if (this.currentInputType === 'phone') {
                    response = await this.savePhoneNumber(inputValue);
                } else if (this.currentInputType === 'name') {
                    response = await this.saveUserName(inputValue);
                }
                
                if (response && response.success) {
                    console.log('✅ User input saved successfully');
                    
                    // نمایش پیام کاربر به صورت محلی
                    this.displayUserInputMessage(inputValue);
                    
                    // ریست کردن حالت
                    this.currentInputType = null;
                    this.isWaitingForInput = false;
                    this.updateInputPlaceholder();
                    
                } else {
                    console.error('❌ Failed to save user input');
                    this.showError('خطا در ذخیره اطلاعات');
                }

            } catch (error) {
                console.error('❌ Error saving user input:', error);
                this.showError('خطا در ارتباط با سرور');
            } finally {
                if (this.sendButton) {
                    this.sendButton.disabled = false;
                    this.sendButton.textContent = this.config.strings.send;
                    this.validateInput();
                }
            }
        }

        saveUserName(name) {
            return new Promise((resolve, reject) => {
                $.ajax({
                    url: this.config.ajaxurl,
                    type: 'POST',
                    data: {
                        action: 'save_user_name',
                        nonce: this.config.nonce,
                        name: name,
                        session_id: this.sessionId
                    },
                    dataType: 'json'
                })
                .done(resolve)
                .fail(reject);
            });
        }

        savePhoneNumber(phone) {
            return new Promise((resolve, reject) => {
                $.ajax({
                    url: this.config.ajaxurl,
                    type: 'POST',
                    data: {
                        action: 'save_user_phone',
                        nonce: this.config.nonce,
                        phone: phone,
                        session_id: this.sessionId
                    },
                    dataType: 'json'
                })
                .done(resolve)
                .fail(reject);
            });
        }


        displayUserInputMessage(inputValue) {
            const messageData = {
                id: 'temp_input_' + Date.now(),
                message: inputValue,
                user_id: this.currentUser.id,
                user_name: this.currentUser.name,
                timestamp: new Date().toISOString(),
                type: 'user'
            };
            
            this.displayMessage(messageData);
        }  

        // اضافه کردن متدهای جدید برای مدیریت پیام‌های موقت
        replaceTempMessage(tempId, realId) {
            const messageElement = this.messagesContainer.querySelector(`[data-message-id="${tempId}"]`);
            if (messageElement) {
                messageElement.dataset.messageId = realId;
                messageElement.classList.remove('temp-message');
                console.log('✅ Temp message replaced with real ID:', realId);
            }
        }

        markMessageAsPermanent(tempId) {
            const messageElement = this.messagesContainer.querySelector(`[data-message-id="${tempId}"]`);
            if (messageElement) {
                messageElement.classList.remove('temp-message');
                console.log('✅ Temp message marked as permanent');
            }
        }

        // متد کمکی برای آپدیت ID پیام
        updateMessageId(tempId, realId) {
            const messageElement = this.messagesContainer.querySelector(`[data-message-id="${tempId}"]`);
            if (messageElement) {
                messageElement.dataset.messageId = realId;
            }
        }

        displayMessage(messageData, shouldScroll = true) {
            if (!this.messagesContainer) return;
            
            // 🔥 **اصلاح: بررسی تکراری بودن قبل از نمایش**
            if (this.isDuplicateMessage(messageData.id)) {
                console.log('⚠️ Duplicate message, not displaying:', messageData.id);
                return;
            }
            
            const messageEl = this.createMessageElement(messageData);
            this.messagesContainer.appendChild(messageEl);
            
            // ذخیره ID پیام
            this.saveMessageId(messageData.id);
            
            if (shouldScroll) {
                this.scrollToBottom();
            }
        }

        createMessageElement(messageData) {
            const messageDiv = document.createElement('div');
            messageDiv.className = `message ${messageData.type}-message`;
            
            // 🔥 **اضافه: کلاس‌های اضافی بر اساس نوع پیام**
            if (messageData.isTemp) {
                messageDiv.classList.add('temp-message');
            }
            if (messageData.isLocal) {
                messageDiv.classList.add('local-message');
            }
            if (messageData.isFromHistory) {
                messageDiv.classList.add('history-message');
            }
            
            messageDiv.dataset.messageId = messageData.id;

            const time = new Date(messageData.timestamp).toLocaleTimeString('fa-IR', {
                hour: '2-digit',
                minute: '2-digit'
            });

            // تشخیص نمایش صحیح نام فرستنده
            let displayName = messageData.user_name;
            if (messageData.type === 'admin') {
                displayName = 'پشتیبان';
            } else if (!displayName || displayName === 'undefined') {
                displayName = 'کاربر';
            }

            messageDiv.innerHTML = `
                <div class="message-header">
                    <span class="message-sender">${this.escapeHtml(displayName)}</span>
                    <span class="message-time">${time}</span>
                </div>
                <div class="message-content">
                    <p>${this.escapeHtml(messageData.message)}</p>
                </div>
            `;

            // اضافه کردن وضعیت تحویل برای پیام‌های کاربر
            if (messageData.type === 'user' && !messageData.isTemp) {
                const statusDiv = document.createElement('div');
                statusDiv.className = 'message-status delivered';
                statusDiv.textContent = '✓✓';
                messageDiv.appendChild(statusDiv);
            }

            return messageDiv;
        }

        handleIncomingMessage(data) {
            console.log('📨 New message received:', data);
            
            // 🔥 **اصلاح: بررسی جامع تکراری بودن**
            if (this.isDuplicateMessage(data.id)) {
                console.log('⚠️ Duplicate message detected, ignoring:', data.id);
                return;
            }
            
            // 🔥 **اصلاح: نادیده گرفتن پیام‌های broadcast از خود کاربر**
            if (data.type === 'user' && data.isBroadcast && data.user_id === this.currentUser.id) {
                console.log('📨 Ignoring self-broadcast message:', data.id);
                return;
            }
            
            // اگر پیام از خود کاربر است و موقت است
            if (data.type === 'user' && data.isTemp && data.user_id === this.currentUser.id) {
                console.log('📨 Ignoring own temp message:', data.id);
                return;
            }
            
            // اگر پیام سیستم است که نیاز به ورودی دارد
            if (data.type === 'system' && data.requires_input) {
                console.log('🔧 System message requires input:', data.input_type);
                this.handleSystemMessage(data);
            }
            
            // نمایش پیام
            this.displayMessage(data);
            
            // ذخیره ID پیام
            this.saveMessageId(data.id);
            
            // اعلان برای پیام‌های جدید وقتی چت بسته است
            if (!this.isOpen) {
                this.unreadCount++;
                this.updateNotificationBadge();
                this.showDesktopNotification(data);
            }
        }

        
        // 🔥 **اضافه: متد جدید برای ذخیره ID پیام**
        saveMessageId(messageId) {
            const key = `wp_live_chat_msg_${messageId}`;
            localStorage.setItem(key, '1');
            
            // پاک کردن پیام‌های قدیمی از localStorage (حفظ 100 پیام اخیر)
            this.cleanupMessageIds();
        }

        // 🔥 **اضافه: متد جدید برای پاکسازی IDهای قدیمی**
        cleanupMessageIds() {
            const keys = [];
            for (let i = 0; i < localStorage.length; i++) {
                const key = localStorage.key(i);
                if (key && key.startsWith('wp_live_chat_msg_')) {
                    keys.push(key);
                }
            }
            
            // اگر بیشتر از 100 پیام داریم، قدیمی‌ها را پاک کن
            if (keys.length > 100) {
                keys.sort().slice(0, keys.length - 100).forEach(key => {
                    localStorage.removeItem(key);
                });
            }
        }

        // 🔥 **اضافه: متد جدید برای بررسی تکراری بودن پیام**
        isDuplicateMessage(messageId) {
            // بررسی در localStorage برای جلوگیری از نمایش تکراری
            const key = `wp_live_chat_msg_${messageId}`;
            const seen = localStorage.getItem(key);
            
            if (seen) {
                return true;
            }
            
            // بررسی در DOM
            if (this.messagesContainer) {
                const existing = this.messagesContainer.querySelector(`[data-message-id="${messageId}"]`);
                if (existing) {
                    return true;
                }
            }
            
            return false;
        }

        showTypingIndicator(data) {
            // نمایش نشانگر تایپ کردن
            // می‌توانید بعداً پیاده‌سازی کنید
            console.log('User is typing:', data);
        }

        updateNotificationBadge() {
            if (!this.toggle) return;
            
            const badge = this.toggle.querySelector('.notification-badge');
            if (!badge) return;
            
            if (this.unreadCount > 0) {
                badge.textContent = this.unreadCount > 9 ? '9+' : this.unreadCount;
                badge.style.display = 'flex';
            } else {
                badge.textContent = '';
                badge.style.display = 'none';
            }
        }

        scrollToBottom() {
            if (!this.messagesContainer) return;
            
            this.messagesContainer.scrollTop = this.messagesContainer.scrollHeight;
        }

        // 🔥 **اصلاح: متد loadMessageHistory برای کارکرد بهتر**
        loadMessageHistory() {
            // اگر در حال بارگذاری هستیم، منتظر بمان
            if (this.messageHistoryLoading) {
                console.log('⏳ Message history is already loading...');
                return Promise.resolve();
            }
            
            console.log('📚 Loading message history for session:', this.sessionId);
            
            this.messageHistoryLoading = true;
            
            return new Promise((resolve, reject) => {
                $.ajax({
                    url: this.config.ajaxurl,
                    type: 'POST',
                    data: {
                        action: 'get_chat_history',
                        nonce: this.config.nonce,
                        session_id: this.sessionId,
                        force_reload: true // 🔥 اضافه کردن پارامتر برای اجباری کردن بارگذاری
                    },
                    dataType: 'json',
                    timeout: 10000
                })
                .done((response) => {
                    console.log('📚 History API Response:', response);

                    if (response.success && response.data && Array.isArray(response.data)) {
                        console.log('📜 Found messages in history:', response.data.length);
                        
                        // 🔥 **اصلاح: پاکسازی و نمایش تاریخچه جدید**
                        this.renderMessageHistory(response.data);
                        
                        this.messageHistoryLoaded = true;
                        console.log('✅ Message history loaded successfully');
                        resolve(response.data);
                    } else {
                        console.warn('⚠️ No message history found');
                        this.messageHistoryLoaded = true;
                        resolve([]);
                    }
                })
                .fail((error) => {
                    console.error('❌ Error loading message history:', error);
                    this.messageHistoryLoaded = true;
                    reject(error);
                })
                .always(() => {
                    this.messageHistoryLoading = false;
                });
            });
        }

        // 🔥 **اضافه: متد جدید برای پاک کردن پیام‌های موجود**
        clearExistingMessages() {
            if (!this.messagesContainer) return;
            
            // پاک کردن همه پیام‌ها به جز پیام خوش‌آمدگویی
            const messages = this.messagesContainer.querySelectorAll('.message:not(.welcome-message)');
            messages.forEach(message => {
                message.remove();
            });
            
            console.log(`🧹 Cleared ${messages.length} existing messages`);
        }

        renderMessageHistory(messages) {
            if (!this.messagesContainer) {
                console.error('❌ Messages container not found');
                return;
            }

            console.log('🎨 Rendering message history:', messages.length);

            // 🔥 **اصلاح: پاک کردن فقط پیام‌های غیرسیستم و غیرموقت**
            const messagesToRemove = this.messagesContainer.querySelectorAll(
                '.message:not(.system-message):not(.welcome-message)'
            );
            
            messagesToRemove.forEach(message => {
                // فقط پیام‌هایی که local نیستند را پاک کن
                if (!message.classList.contains('local-message')) {
                    message.remove();
                }
            });

            console.log(`🧹 Cleared ${messagesToRemove.length} old messages`);

            // اگر پیامی برای نمایش وجود ندارد
            if (!messages || messages.length === 0) {
                console.log('📭 No messages to display from history');
                return;
            }

            // نمایش تاریخچه پیام‌ها
            messages.forEach(message => {
                // 🔥 **اصلاح: بررسی تکراری نبودن پیام**
                if (!this.isDuplicateMessage(message.id)) {
                    this.displayMessage({
                        id: message.id,
                        message: message.message_content,
                        user_id: message.user_id,
                        user_name: message.user_name,
                        timestamp: message.created_at,
                        type: message.message_type,
                        isFromHistory: true // 🔥 علامت برای پیام تاریخچه
                    }, false);
                }
            });

            // اسکرول به پایین
            this.scrollToBottom();
            
            console.log(`✅ Rendered ${messages.length} messages from history`);
        }

        showError(message) {
            if (!this.messagesContainer) return;
            
            const errorDiv = document.createElement('div');
            errorDiv.className = 'chat-error';
            errorDiv.innerHTML = `
                <div class="error-icon">⚠️</div>
                <p class="error-message">${message}</p>
                <button class="retry-button">تلاش مجدد</button>
            `;

            errorDiv.querySelector('.retry-button').addEventListener('click', () => {
                errorDiv.remove();
                this.initPusher();
            });

            this.messagesContainer.appendChild(errorDiv);
        }

        showDesktopNotification(messageData) {
            // نمایش notification دسکتاپ
            if ('Notification' in window && Notification.permission === 'granted') {
                new Notification('پیام جدید', {
                    body: `${messageData.user_name}: ${messageData.message}`,
                    icon: '/wp-content/plugins/wp-live-chat/assets/images/icon.png'
                });
            }
        }

        escapeHtml(text) {
            const div = document.createElement('div');
            div.textContent = text;
            return div.innerHTML;
        }

        // متد کمکی برای فعال کردن دستی چت
        enableManually() {
            console.log('🔄 Manually enabling chat...');
            this.isConnected = true;
            this.setStatus('online');
            this.validateInput();
            console.log('✅ Chat manually enabled');
        }

        destroy() {
            if (this.pusher) {
                this.pusher.disconnect();
            }
            
            console.log('✅ Chat destroyed');
        }
    }

    // راه‌اندازی زمانی که DOM آماده است
    document.addEventListener('DOMContentLoaded', function() {
        // بررسی آیا ویجت چت در صفحه وجود دارد
        if (document.getElementById('wp-live-chat-container')) {
            window.wpLiveChatInstance = new WPLiveChat();
            console.log('🎉 WP Live Chat started successfully!');
        }
        
        // مدیریت دکمه‌های چت در بلوک‌ها
        document.querySelectorAll('.wp-live-chat-button').forEach(button => {
            button.addEventListener('click', function() {
                if (window.wpLiveChatInstance) {
                    window.wpLiveChatInstance.openChat();
                }
            });
        });
    });

    // برای دسترسی از طریق پنجره
    window.WPLiveChat = WPLiveChat;

})(jQuery);