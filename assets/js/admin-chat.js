(function($) {
    'use strict';

    class WPLiveChatAdmin {
        constructor() {
            this.config = window.wpLiveChatAdmin || {};
            this.pusher = null;
            this.currentSession = null;
            this.sessions = [];
            this.currentChannel = null;
            
            console.log('🚀 Admin Chat Initializing...', this.config);
            this.init();
        }

        // 🔥 **اصلاح: تبدیل متد init به async**
        async init() {
            this.bindEvents();
            await this.loadSessions(); // حالا می‌تواند await داشته باشد
            this.initPusher();
            
            // 🔥 **اصلاح: درخواست مجوز نوتیفیکیشن بدون await مشکل‌ساز**
            this.requestNotificationPermission();
        }

        // 🔥 **اضافه: متد جداگانه برای درخواست مجوز نوتیفیکیشن**
        requestNotificationPermission() {
            if ('Notification' in window && Notification.permission === 'default') {
                Notification.requestPermission().then(permission => {
                    console.log('Notification permission:', permission);
                }).catch(error => {
                    console.error('Error requesting notification permission:', error);
                });
            }
        }

        bindEvents() {
            $('#reload-sessions').on('click', () => this.loadSessions());
            $('#admin-send-button').on('click', () => this.sendMessage());
            
            $('#admin-message-input').on('keydown', (e) => {
                if (e.key === 'Enter' && !e.shiftKey) {
                    e.preventDefault();
                    this.sendMessage();
                }
            });
        }

        // 🔥 **اصلاح: متد loadSessions با استفاده از Promise**
        loadSessions() {
            console.log('📋 Loading sessions...');
            
            return new Promise((resolve, reject) => {
                $.ajax({
                    url: this.config.ajaxurl,
                    type: 'POST',
                    data: {
                        action: 'get_chat_sessions',
                        nonce: this.config.nonce
                    },
                    dataType: 'json',
                    timeout: 10000
                })
                .done((response) => {
                    console.log('📡 API Response:', response);

                    if (response.success) {
                        this.sessions = response.data;
                        this.renderSessions();
                        console.log('✅ Sessions loaded:', this.sessions.length);
                        resolve(response.data);
                    } else {
                        console.error('❌ API Error:', response.data);
                        this.showError('خطا در بارگذاری گفتگوها: ' + (response.data || 'خطای ناشناخته'));
                        reject(response.data);
                    }
                })
                .fail((error) => {
                    console.error('❌ Network Error:', error);
                    
                    if (error.responseText && error.responseText.includes('wpdberror')) {
                        this.showError('خطای دیتابیس - لطفا جداول چت را بررسی کنید');
                        console.error('📋 Database error in response:', error.responseText);
                    } else {
                        let errorMessage = 'خطا در ارتباط با سرور';
                        if (error.status === 500) {
                            errorMessage = 'خطای سرور (500) - لطفا error_log را بررسی کنید';
                        } else if (error.status === 403) {
                            errorMessage = 'دسترسی غیرمجاز';
                        } else if (error.statusText) {
                            errorMessage += ': ' + error.statusText;
                        }
                        this.showError(errorMessage);
                    }
                    reject(error);
                });
            });
        }

        renderSessions() {
            const container = $('#sessions-container');
            container.empty();

            if (this.sessions.length === 0) {
                container.html('<div class="no-sessions">هیچ گفتگوی فعالی وجود ندارد</div>');
                return;
            }

            this.sessions.forEach(session => {
                const lastMessage = session.last_message || {};
                
                // نمایش نام کاربر به صورت ایمن
                let userName = session.user_name || 'کاربر ناشناس';
                if (userName === 'undefined' || userName === 'مهمان') {
                    userName = 'کاربر مهمان';
                }
                
                const sessionElement = $(`
                    <div class="session-item" data-session-id="${session.session_id}">
                        <div class="session-user">
                            <strong>${this.escapeHtml(userName)}</strong>
                            ${session.user_email ? `<br><small>${session.user_email}</small>` : ''}
                            ${lastMessage.message_content ? `<br><small class="last-message">${this.truncateText(lastMessage.message_content, 30)}</small>` : ''}
                        </div>
                        <div class="session-info">
                            <small>پیام‌ها: ${session.message_count || 0}</small>
                            <br>
                            <small>آخرین فعالیت: ${this.formatTime(session.last_activity)}</small>
                        </div>
                        <div class="session-status">
                            <span class="status-dot ${session.status === 'active' ? 'online' : 'offline'}"></span>
                            ${session.unread_count > 0 ? 
                                `<span class="unread-badge">${session.unread_count}</span>` : 
                                ''
                            }
                        </div>
                    </div>
                `);

                sessionElement.on('click', () => this.selectSession(session));
                container.append(sessionElement);
            });
        }

        // 🔥 **اصلاح: متد selectSession به async**
        async selectSession(session) {
            console.log('🎯 Selecting session:', session);
            
            $('.session-item').removeClass('active');
            $(`.session-item[data-session-id="${session.session_id}"]`).addClass('active');
            
            this.currentSession = session;
            
            $('#current-session-name').text(session.user_name || 'کاربر ناشناس');
            $('#session-status').text(session.status === 'active' ? 'آنلاین' : 'آفلاین')
                            .removeClass('status-offline status-online')
                            .addClass(session.status === 'active' ? 'status-online' : 'status-offline');
            
            $('#admin-message-input').prop('disabled', false);
            $('#admin-send-button').prop('disabled', false);
            
            await this.loadSessionMessages(session.session_id);
            this.subscribeToSession(session.session_id);
        }

        loadSessionMessages(sessionId) {
            console.log('📨 Loading messages for session:', sessionId);
            
            return new Promise((resolve, reject) => {
                $.ajax({
                    url: this.config.ajaxurl,
                    type: 'POST',
                    data: {
                        action: 'get_session_messages',
                        nonce: this.config.nonce,
                        session_id: sessionId
                    },
                    dataType: 'json',
                    timeout: 10000
                })
                .done((response) => {
                    console.log('📨 Messages API Response:', response);

                    if (response.success) {
                        this.renderMessages(response.data);
                        console.log('✅ Messages loaded:', response.data.length);
                        resolve(response.data);
                    } else {
                        console.error('❌ API Error:', response.data);
                        this.showError('خطا در بارگذاری پیام‌ها: ' + response.data);
                        reject(response.data);
                    }
                })
                .fail((error) => {
                    console.error('❌ Network Error:', error);
                    
                    if (error.responseText && error.responseText.includes('wpdberror')) {
                        this.showError('خطای دیتابیس در بارگذاری پیام‌ها');
                    } else {
                        this.showError('خطا در بارگذاری پیام‌ها: ' + error.statusText);
                    }
                    reject(error);
                });
            });
        }

        renderMessages(messages) {
            const container = $('#admin-chat-messages');
            container.empty();

            $('.no-chat-selected').remove();

            if (!messages || messages.length === 0) {
                container.html('<div class="no-messages">هنوز پیامی رد و بدل نشده است</div>');
                return;
            }

            messages.forEach(message => {
                const messageClass = message.message_type === 'admin' ? 'message-admin-user' : 'message-user';
                const senderName = message.message_type === 'admin' ? 'پشتیبان' : (message.user_name || 'کاربر');
                
                const messageElement = $(`
                    <div class="message-admin ${messageClass}">
                        <div class="message-content">
                            <p>${this.escapeHtml(message.message_content)}</p>
                        </div>
                        <div class="message-time">
                            ${this.formatTime(message.created_at)}
                            <span class="sender-name">(${senderName})</span>
                        </div>
                    </div>
                `);

                container.append(messageElement);
            });

            container.scrollTop(container[0].scrollHeight);
        }

        // 🔥 **اصلاح: متد sendMessage به async**
        async sendMessage() {
            const messageInput = $('#admin-message-input');
            const message = messageInput.val().trim();

            if (!message || !this.currentSession) {
                return;
            }

            console.log('📤 Sending admin message:', message);

            // غیرفعال کردن دکمه
            $('#admin-send-button').prop('disabled', true).text('در حال ارسال...');

            try {
                const response = await $.ajax({
                    url: this.config.ajaxurl,
                    type: 'POST',
                    data: {
                        action: 'send_admin_message',
                        nonce: this.config.nonce,
                        message: message,
                        session_id: this.currentSession.session_id
                    },
                    dataType: 'json',
                    timeout: 10000
                });

                console.log('📤 Send Message Response:', response);

                if (response.success) {
                    console.log('✅ Admin message sent');
                    
                    // پاک کردن input
                    messageInput.val('');
                    
                    // بارگذاری مجدد پیام‌ها برای نمایش به‌روزرسانی شده
                    await this.loadSessionMessages(this.currentSession.session_id);
                    
                } else {
                    console.error('❌ Failed to send admin message:', response.data);
                    this.showError('خطا در ارسال پیام: ' + response.data);
                }
            } catch (error) {
                console.error('❌ Error sending message:', error);
                this.showError('خطا در ارسال پیام: ' + error.statusText);
            } finally {
                // فعال کردن دکمه
                $('#admin-send-button').prop('disabled', false).text('ارسال');
            }
        }

        addLocalMessage(messageData) {
            const container = $('#admin-chat-messages');
            const messageClass = messageData.message_type === 'admin' ? 'message-admin-user' : 'message-user';
            const senderName = messageData.message_type === 'admin' ? 'پشتیبان' : (messageData.user_name || 'کاربر');
            
            const messageElement = $(`
                <div class="message-admin ${messageClass}">
                    <div class="message-content">
                        <p>${this.escapeHtml(messageData.message_content)}</p>
                    </div>
                    <div class="message-time">
                        ${this.formatTime(messageData.created_at)}
                        <span class="sender-name">(${senderName})</span>
                    </div>
                </div>
            `);

            container.append(messageElement);
            container.scrollTop(container[0].scrollHeight);
        }

        // اضافه کردن این متد به کلاس
        checkInternetConnection() {
            if (!navigator.onLine) {
                this.showError('اتصال اینترنت برقرار نیست');
                return false;
            }
            return true;
        }

        initPusher() {
                    if (!this.config.pusherKey) {
                        console.warn('⚠️ Pusher key not configured');
                        this.showError('کلید Pusher تنظیم نشده است');
                        return;
                    }

            if (typeof Pusher === 'undefined') {
                console.error('❌ Pusher library not loaded');
                this.showError('کتابخانه Pusher بارگذاری نشد');
                return;
            }

            try {
                // استفاده از تنظیمات سازگار‌تر
                this.pusher = new Pusher(this.config.pusherKey, {
                    cluster: this.config.pusherCluster,
                    forceTLS: true,
                    authEndpoint: this.config.ajaxurl,
                    auth: {
                        params: {
                            action: 'auth_pusher_channel_admin',
                            nonce: this.config.nonce
                        }
                    },
                    // فعال کردن fallback به HTTP زمانی که WebSocket کار نمی‌کند
                    enabledTransports: ['ws', 'wss', 'xhr_streaming', 'xhr_polling'],
                    disabledTransports: ['sockjs']
                });

                console.log('✅ Pusher initialized for admin');

                // مانیتور وضعیت اتصال
                this.pusher.connection.bind('state_change', (states) => {
                    console.log('🔌 Admin Pusher State:', states.previous, '->', states.current);
                });

                this.pusher.connection.bind('connected', () => {
                    console.log('✅ Pusher connected successfully');
                });

                this.pusher.connection.bind('error', (error) => {
                    console.error('❌ Pusher connection error:', error);
                    this.showError('خطا در اتصال به Pusher: ' + error.message);
                });

            } catch (error) {
                console.error('❌ Pusher init error:', error);
                this.showError('خطا در راه‌اندازی Pusher: ' + error.message);
                
                // غیرفعال کردن قابلیت real-time به صورت موقت
                this.showError('اتصال real-time غیرفعال شد. چت به صورت عادی کار می‌کند.');
            }

             // 🔥 **اضافه: شنود کانال ادمین برای پیام‌های جدید کاربران**
            this.adminChannel = this.pusher.subscribe('admin-chat-channel');
            
            this.adminChannel.bind('new-user-message', (data) => {
                console.log('🔔 New user message received:', data);
                
                // اگر session مربوطه در حال نمایش است، پیام‌ها را رفرش کن
                if (this.currentSession && this.currentSession.session_id === data.session_id) {
                    this.loadSessionMessages(data.session_id);
                }
                
                    // اضافه کردن این بخش برای جلوگیری از بارگذاری مجدد پیام‌ها
                this.receivedMessageIds = new Set();
                // آپدیت لیست sessions
                this.loadSessions();
                
                // نمایش نوتیفیکیشن
                this.showNewMessageNotification(data);
            });
        }

        // 🔥 **اصلاح: بخش handle message در ادمین**
        handleNewMessage(data) {
            console.log('📨 New message in admin:', data);
            
            // بررسی تکراری نبودن
            if (this.isMessageDuplicate(data.message_id)) {
                console.log('⚠️ Duplicate message in admin, ignoring:', data.message_id);
                return;
            }
            
            // فقط اگر session مربوطه در حال نمایش است، رفرش کن
            if (this.currentSession && this.currentSession.session_id === data.session_id) {
                console.log('🔄 Refreshing messages for current session');
                this.loadSessionMessages(data.session_id);
            }
            
            // آپدیت لیست sessions
            this.loadSessions();
        }

        // 🔥 **اضافه: متد جدید برای بررسی تکراری بودن پیام در ادمین**
        isMessageDuplicate(messageId) {
            if (this.receivedMessageIds.has(messageId)) {
                return true;
            }
            
            // ذخیره ID پیام
            this.receivedMessageIds.add(messageId);
            
            // حفظ حداکثر 1000 ID
            if (this.receivedMessageIds.size > 1000) {
                const firstId = this.receivedMessageIds.values().next().value;
                this.receivedMessageIds.delete(firstId);
            }
            
            return false;
        }

        showNewMessageNotification(data) {
            if (!this.currentSession || this.currentSession.session_id !== data.session_id) {
                // نمایش نوتیفیکیشن برای sessionهای دیگر
                const notification = new Notification('پیام جدید از ' + data.user_name, {
                    body: data.message,
                    icon: '/wp-content/plugins/wp-live-chat/assets/images/icon.png'
                });
                
                notification.onclick = () => {
                    // انتخاب session مربوطه هنگام کلیک روی نوتیفیکیشن
                    const targetSession = this.sessions.find(s => s.session_id === data.session_id);
                    if (targetSession) {
                        this.selectSession(targetSession);
                    }
                };
            }
        }

        subscribeToSession(sessionId) {
            if (!this.pusher) {
                console.error('Pusher not initialized');
                return;
            }

            // unsubscribe از کانال قبلی
            if (this.currentChannel) {
                this.currentChannel.unbind_all();
                this.pusher.unsubscribe(this.currentChannel.name);
            }

            const channelName = `private-chat-${sessionId}`;
            
            try {
                this.currentChannel = this.pusher.subscribe(channelName);
                
                this.currentChannel.bind('pusher:subscription_succeeded', () => {
                    console.log('✅ Admin subscribed to session:', sessionId);
                });

                this.currentChannel.bind('pusher:subscription_error', (error) => {
                    console.error('❌ Subscription error:', error);
                    this.showError('خطا در اتصال به کانال چت');
                });

                this.currentChannel.bind('client-message', (data) => {
                    console.log('📨 New message received in admin:', data);
                    
                    // فقط پیام‌های کاربر را نمایش دهید (نه پیام‌های ادمین)
                    if (this.currentSession && 
                        this.currentSession.session_id === sessionId && 
                        data.type !== 'admin') {
                        
                        // بارگذاری مجدد پیام‌ها برای نمایش همه پیام‌ها
                        this.loadSessionMessages(sessionId);
                    }
                    
                    // آپدیت لیست sessions
                    this.loadSessions();
                });

            } catch (error) {
                console.error('❌ Subscription error:', error);
                this.showError('خطا در عضویت کانال: ' + error.message);
            }
        }
        // متد نمایش خطا
        showError(message) {
            console.error('💥 Error:', message);
            
            // نمایش خطا به کاربر
            const errorHtml = `
                <div class="notice notice-error inline" style="margin: 10px 0; padding: 10px;">
                    <p><strong>خطا:</strong> ${message}</p>
                </div>
            `;
            
            // حذف خطاهای قبلی
            $('.notice.notice-error').remove();
            $('.wrap').prepend(errorHtml);
            
            // حذف خودکار خطا بعد از 5 ثانیه
            setTimeout(() => {
                $('.notice.notice-error').fadeOut();
            }, 5000);
        }

        formatTime(timestamp) {
            if (!timestamp) return '--:--';
            
            try {
                const date = new Date(timestamp);
                return date.toLocaleTimeString('fa-IR', {
                    hour: '2-digit',
                    minute: '2-digit'
                });
            } catch (e) {
                return '--:--';
            }
        }

        truncateText(text, maxLength) {
            if (!text) return '';
            if (text.length <= maxLength) return text;
            return text.substring(0, maxLength) + '...';
        }

        escapeHtml(text) {
            if (!text) return '';
            const div = document.createElement('div');
            div.textContent = text;
            return div.innerHTML;
        }
    }

    // راه‌اندازی زمانی که DOM آماده است
    $(document).ready(function() {
        window.wpLiveChatAdminApp = new WPLiveChatAdmin();
    });

})(jQuery);