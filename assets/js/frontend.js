(function($) {
    'use strict';

    class WPLiveChat {
        constructor() {
            console.log('🚀 WP Live Chat Initializing...');
            
            this.config = window.wpLiveChat || {};
            this.pusher = null;
            this.channel = null;
            this.isConnected = false; // به طور پیش‌فرض فعال
            this.isOpen = false;
            this.unreadCount = 0;
            this.sessionId = this.config.sessionId;
            this.currentUser = this.config.currentUser;
            this.messageHistoryLoaded = false;
            
            console.log('Config loaded:', {
                hasPusherKey: !!this.config.pusherKey,
                hasPusherCluster: !!this.config.pusherCluster,
                sessionId: this.sessionId,
                currentUser: this.currentUser
            });
            
            this.init();
        }

        init() {
            console.log('🔧 Starting initialization...');
            
            try {
                this.createDOM();
                this.bindEvents();
                this.initPusher();
                this.startConnectionMonitor(); // این خط را اضافه کنید
                console.log('✅ Initialization completed successfully');
            } catch (error) {
                console.error('❌ Initialization failed:', error);
            }
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
            
            // رویدادهای toggle
            if (this.toggle) {
                this.toggle.addEventListener('click', () => this.openChat());
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
            console.log('Opening chat...');
            this.container.classList.remove('wp-live-chat-hidden');
            this.isOpen = true;
            this.unreadCount = 0;
            this.updateNotificationBadge();
            
            // بارگذاری تاریخچه پیام‌ها اگر قبلاً بارگذاری نشده
            if (!this.messageHistoryLoaded) {
                this.loadMessageHistory();
            } else {
                this.scrollToBottom();
            }
            
            if (this.textarea) {
                setTimeout(() => {
                    this.textarea.focus();
                }, 300);
            }
            
            console.log('✅ Chat opened');
        }

        closeChat() {
            console.log('Closing chat...');
            this.container.classList.add('wp-live-chat-hidden');
            this.isOpen = false;
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

        async sendMessage() {
            if (!this.textarea) return;
            
            const message = this.textarea.value.trim();
            
            if (!this.validateInput()) {
                console.log('Message validation failed');
                return;
            }

            console.log('Sending message:', message);

            if (this.sendButton) {
                this.sendButton.disabled = true;
                this.sendButton.textContent = 'در حال ارسال...';
            }

            try {
                // 1. ابتدا پیام را محلی نمایش دهید (فوری)
                const tempMessageId = 'temp_' + Date.now();
                this.displayMessage({
                    id: tempMessageId,
                    message: message,
                    user_id: this.currentUser.id,
                    user_name: this.currentUser.name,
                    timestamp: new Date().toISOString(),
                    type: 'user'
                });

                // 2. پاک کردن textarea (تجربه کاربری بهتر)
                this.textarea.value = '';
                this.updateCharCounter();
                this.validateInput();

                console.log('✅ Message displayed locally');

                // 3. ارسال به Pusher (بدون انتظار برای پاسخ سرور)
                if (this.channel && this.isConnected) {
                    this.channel.trigger('client-message', {
                        id: tempMessageId,
                        message: message,
                        user_id: this.currentUser.id,
                        user_name: this.currentUser.name,
                        session_id: this.sessionId,
                        timestamp: new Date().toISOString(),
                        type: 'user'
                    });
                    console.log('✅ Message sent via Pusher');
                }

                // 4. ارسال به سرور در پس‌زمینه (اگر خطا داد، مهم نیست)
                try {
                    const response = await $.ajax({
                        url: this.config.ajaxurl,
                        type: 'POST',
                        data: {
                            action: 'send_chat_message',
                            nonce: this.config.nonce,
                            message: message,
                            user_id: this.currentUser.id,
                            user_name: this.currentUser.name,
                            session_id: this.sessionId
                        },
                        dataType: 'json',
                        timeout: 5000 // 5 ثانیه timeout
                    });

                    if (response.success) {
                        console.log('✅ Message also saved to database');
                        // آپدیت ID پیام اگر نیاز بود
                        this.updateMessageId(tempMessageId, response.data.message_id);
                    }
                } catch (dbError) {
                    console.warn('⚠️ Database save failed, but message was sent via Pusher:', dbError);
                    // خطای دیتابیس را نادیده بگیریم - پیام از طریق Pusher ارسال شده
                }

            } catch (error) {
                console.error('❌ Send message error:', error);
                // فقط خطاهای جدی را نمایش دهید
                if (!error.status || error.status !== 200) {
                    this.showError('خطا در ارسال پیام');
                }
            } finally {
                if (this.sendButton) {
                    this.sendButton.disabled = false;
                    this.sendButton.textContent = this.config.strings.send;
                    this.validateInput();
                }
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
            
            const messageEl = this.createMessageElement(messageData);
            this.messagesContainer.appendChild(messageEl);
            
            if (shouldScroll) {
                this.scrollToBottom();
            }
        }

        createMessageElement(messageData) {
            const messageDiv = document.createElement('div');
            messageDiv.className = `message ${messageData.type}-message`;
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
                ${messageData.type === 'user' ? '<div class="message-status delivered">✓✓</div>' : ''}
            `;

            return messageDiv;
        }

        handleIncomingMessage(data) {
            this.displayMessage(data);
            
            if (!this.isOpen) {
                this.unreadCount++;
                this.updateNotificationBadge();
                
                // نمایش notification
                this.showDesktopNotification(data);
            }
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

        async loadMessageHistory() {
            console.log('📚 Loading message history for session:', this.sessionId);
            
            try {
                const response = await $.ajax({
                    url: this.config.ajaxurl,
                    type: 'POST',
                    data: {
                        action: 'get_chat_history',
                        nonce: this.config.nonce,
                        session_id: this.sessionId
                    },
                    dataType: 'json',
                    timeout: 10000
                });

                console.log('📚 History API Response:', response);

                if (response.success && response.data && Array.isArray(response.data)) {
                    console.log('📜 Raw messages data:', response.data);
                    this.renderMessageHistory(response.data);
                    this.messageHistoryLoaded = true;
                    console.log('✅ Message history loaded:', response.data.length);
                } else {
                    console.warn('⚠️ No message history found or invalid data');
                    this.messageHistoryLoaded = true;
                }
            } catch (error) {
                console.error('❌ Error loading message history:', error);
                this.messageHistoryLoaded = true;
            }
        }

        renderMessageHistory(messages) {
            if (!this.messagesContainer || !messages || messages.length === 0) {
                return;
            }

            console.log('🎨 Rendering message history:', messages.length);

            // پاک کردن پیام خوش‌آمدگویی
            const welcomeMessage = this.messagesContainer.querySelector('.welcome-message');
            if (welcomeMessage) {
                welcomeMessage.remove();
            }

            // نمایش تاریخچه پیام‌ها
            messages.forEach(message => {
                this.displayMessage({
                    id: message.id,
                    message: message.message_content,
                    user_id: message.user_id,
                    user_name: message.user_name,
                    timestamp: message.created_at,
                    type: message.message_type
                }, false); // false یعنی اسکرول نکن
            });

            // در انتها یک بار اسکرول به پایین
            this.scrollToBottom();
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