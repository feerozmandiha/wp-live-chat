(function($) {
    'use strict';

    class WPLiveChatAdmin {
        constructor() {
            this.config = window.wpLiveChatAdmin || {};
            this.currentSession = null;
            this.sessions = [];
            this.pusher = null;
            this.channel = null;
            
            // اضافه کردن متغیرهای جدید
            this.isLoading = false;
            this.retryCount = 0;
            this.maxRetries = 3;
            
            this.init();
        }

        init() {
            this.bindEvents();
            this.initPusher();
            this.loadSessions();
            
            // افزودن event listener برای مدیریت خطاها
            $(document).ajaxError((event, jqxhr, settings, thrownError) => {
                console.error('AJAX Error:', thrownError, settings.url);
                this.showError('خطا در ارتباط با سرور');
            });
        }

        bindEvents() {
            $('#refresh-sessions').on('click', () => this.loadSessions());
            $('#admin-send-button').on('click', () => this.sendMessage());
            
            $('#admin-message-input').on('keydown', (e) => {
                if (e.key === 'Enter' && !e.shiftKey) {
                    e.preventDefault();
                    this.sendMessage();
                }
            });
            
            // اضافه کردن event برای رفرش خودکار هر 30 ثانیه
            setInterval(() => {
                if (this.currentSession) {
                    this.loadSessionMessages(this.currentSession.session_id);
                }
            }, 30000);
        }

        initPusher() {
        if (!this.config.pusherKey || typeof Pusher === 'undefined') {
            this.showError('سرویس Pusher پیکربندی نشده است');
            return;
        }

        try {
            this.pusher = new Pusher(this.config.pusherKey, {
            cluster: this.config.pusherCluster,
            forceTLS: true,
            authEndpoint: this.config.ajaxurl,
            auth: {
                params: {
                action: 'pusher_auth',
                nonce: this.config.nonce
                }
            }
            });

            const adminChannel = this.pusher.subscribe('admin-notifications');
            adminChannel.bind('new-chat', (data) => {
            this.handleNewChatNotification(data);
            });

            this.pusher.connection.bind('state_change', (states) => {
            console.log('Pusher connection state:', states.current);
            });

        } catch (error) {
            this.showError('خطا در راه‌اندازی Pusher');
        }
        }

        async loadSessions() {
            if (this.isLoading) return;
            
            this.isLoading = true;
            $('#refresh-sessions').prop('disabled', true).text('در حال بارگذاری...');
            
            try {
                const response = await $.ajax({
                    url: this.config.ajaxurl,
                    type: 'POST',
                    data: {
                        action: 'get_chat_sessions',
                        nonce: this.config.nonce
                    },
                    dataType: 'json',
                    timeout: 10000
                });

                if (response.success) {
                    this.sessions = response.data;
                    this.renderSessions();
                    this.retryCount = 0; // ریست کردن شمارشگر تلاش مجدد
                } else {
                    throw new Error(response.data || 'خطا در دریافت جلسات');
                }
            } catch (error) {
                console.error('Error loading sessions:', error);
                this.showError('خطا در بارگذاری جلسات: ' + error.message);
                
                // تلاش مجدد
                if (this.retryCount < this.maxRetries) {
                    this.retryCount++;
                    setTimeout(() => this.loadSessions(), 2000);
                }
            } finally {
                this.isLoading = false;
                $('#refresh-sessions').prop('disabled', false).text('بروزرسانی');
            }
        }

        renderSessions() {
            const container = $('#sessions-list');
            container.empty();

            if (this.sessions.length === 0) {
                container.html('<div class="no-sessions">' + this.config.strings.noActiveChats + '</div>');
                return;
            }

            this.sessions.forEach(session => {
                const sessionElement = $(`
                    <div class="session-item" data-session-id="${session.session_id}">
                        <div class="session-info">
                            <div class="session-user">
                                <strong>${this.escapeHtml(session.user_name || 'کاربر')}</strong>
                                ${session.user_phone ? `<div class="session-phone">${session.user_phone}</div>` : ''}
                            </div>
                            <div class="session-meta">
                                <span class="message-count">${session.message_count || 0} پیام</span>
                                <span class="last-activity">${this.formatTime(session.last_activity)}</span>
                            </div>
                        </div>
                        ${session.unread_count > 0 ? 
                            `<span class="unread-badge">${session.unread_count}</span>` : 
                            ''
                        }
                    </div>
                `);

                sessionElement.on('click', () => this.selectSession(session));
                container.append(sessionElement);
            });
        }

        async selectSession(session) {
            $('.session-item').removeClass('active has-unread');
            $(`.session-item[data-session-id="${session.session_id}"]`)
                .addClass('active')
                .removeClass('has-unread');
            
            this.currentSession = session;

                        // علامت گذاری پیام‌ها به عنوان خوانده شده
            if (session.unread_count > 0) {
                await this.markSessionAsRead(session.session_id);
            }
            
            $('#current-session-title').text(session.user_name || 'کاربر');
            $('#session-status').text(session.status === 'active' ? 'آنلاین' : 'آفلاین')
                .removeClass('status-offline status-online')
                .addClass(session.status === 'active' ? 'status-online' : 'status-offline');
            
            $('#admin-chat-input').show();
            $('#admin-message-input').focus();
            
            await this.loadSessionMessages(session.session_id);
            this.subscribeToSession(session.session_id);
        }

        async markSessionAsRead(sessionId) {
            try {
                await $.ajax({
                    url: this.config.ajaxurl,
                    type: 'POST',
                    data: {
                        action: 'mark_session_as_read',
                        nonce: this.config.nonce,
                        session_id: sessionId
                    }
                });
                
                // به‌روزرسانی UI
                $(`.session-item[data-session-id="${sessionId}"]`)
                    .find('.unread-badge')
                    .remove();
                    
            } catch (error) {
                console.error('Error marking session as read:', error);
            }
        } 

        async loadSessionMessages(sessionId) {
            try {
                const response = await $.ajax({
                    url: this.config.ajaxurl,
                    type: 'POST',
                    data: {
                        action: 'get_session_messages',
                        nonce: this.config.nonce,
                        session_id: sessionId
                    },
                    dataType: 'json',
                    timeout: 10000
                });

                if (response.success) {
                    this.renderMessages(response.data);
                } else {
                    throw new Error(response.data || 'خطا در دریافت پیام‌ها');
                }
            } catch (error) {
                console.error('Error loading messages:', error);
                this.showError('خطا در بارگذاری پیام‌ها');
            }
        }

        renderMessages(messages) {
            const container = $('#admin-chat-messages');
            container.empty();

            if (!messages || messages.length === 0) {
                container.html('<div class="no-messages">هنوز پیامی رد و بدل نشده است</div>');
                return;
            }

            messages.forEach(message => {
                const messageElement = $(`
                    <div class="message ${message.message_type === 'admin' ? 'admin' : 'user'}">
                        <div class="message-header">
                            <span class="message-sender">
                                ${message.message_type === 'admin' ? 
                                    '👨‍💼 پشتیبان' : 
                                    '👤 ' + this.escapeHtml(message.user_name || 'کاربر')}
                            </span>
                            <span class="message-time">${this.formatTime(message.created_at)}</span>
                        </div>
                        <div class="message-content">
                            <p>${this.escapeHtml(message.message_content)}</p>
                        </div>
                    </div>
                `);

                container.append(messageElement);
            });

            // اسکرول به پایین
            container.scrollTop(container[0].scrollHeight);
        }

        async sendMessage() {
            if (!this.currentSession) {
                this.showError('لطفاً ابتدا یک گفتگو را انتخاب کنید');
                return;
            }

            const input = $('#admin-message-input');
            const message = input.val().trim();

            if (!message) {
                this.showError('لطفاً پیامی وارد کنید');
                return;
            }

            // غیرفعال کردن دکمه در حین ارسال
            const $sendBtn = $('#admin-send-button');
            $sendBtn.prop('disabled', true).text('در حال ارسال...');

            try {
                const response = await $.ajax({
                    url: this.config.ajaxurl,
                    type: 'POST',
                    data: {
                        action: 'send_admin_message', // اصلاح شده: send_admin_message
                        nonce: this.config.nonce,
                        session_id: this.currentSession.session_id,
                        message: message
                    },
                    dataType: 'json',
                    timeout: 10000
                });

                if (response.success) {
                    input.val('');
                    
                    // افزودن پیام به لیست محلی
                    this.addLocalMessage({
                        message_content: message,
                        user_name: 'پشتیبان',
                        message_type: 'admin',
                        created_at: new Date().toISOString()
                    });
                    
                    // نمایش موفقیت
                    this.showSuccess('پیام با موفقیت ارسال شد');
                } else {
                    throw new Error(response.data || 'خطا در ارسال پیام');
                }
            } catch (error) {
                console.error('Error sending message:', error);
                this.showError('خطا در ارسال پیام: ' + error.message);
            } finally {
                $sendBtn.prop('disabled', false).text('ارسال');
            }
        }

        addLocalMessage(messageData) {
            const container = $('#admin-chat-messages');
            
            const messageElement = $(`
                <div class="message admin">
                    <div class="message-header">
                        <span class="message-sender">👨‍💼 پشتیبان</span>
                        <span class="message-time">${this.formatTime(messageData.created_at)}</span>
                    </div>
                    <div class="message-content">
                        <p>${this.escapeHtml(messageData.message_content)}</p>
                    </div>
                </div>
            `);

            container.append(messageElement);
            container.scrollTop(container[0].scrollHeight);
        }

        subscribeToSession(sessionId) {
            if (!this.pusher) return;

            if (this.channel) {
                try {
                this.pusher.unsubscribe(this.channel.name);
                } catch (e) {}
            }

            const channelName = `private-chat-${sessionId}`;

            try {
                this.channel = this.pusher.subscribe(channelName);

                this.channel.bind('new-message', (data) => {
                if (this.currentSession && this.currentSession.session_id === sessionId) {
                    this.loadSessionMessages(sessionId);
                }
                if (data.type === 'user') {
                    this.showNotification('پیام جدید', `کاربر: ${data.user_name || ''}`);
                }
                });

                this.channel.bind('client-message', (data) => {
                if (this.currentSession && this.currentSession.session_id === sessionId) {
                    this.loadSessionMessages(sessionId);
                }
                this.showNotification('پیام جدید', `کاربر: ${data.user_name || ''}`);
                });

                this.channel.bind('pusher:subscription_succeeded', () => {
                console.log('Subscribed to channel:', channelName);
                });

                this.channel.bind('pusher:subscription_error', (error) => {
                console.error('Subscription error:', error);
                });

            } catch (error) {
                this.showError('خطا در اتصال به کانال چت');
            }
        }

        handleNewChatNotification(data) {
            // بارگذاری مجدد لیست جلسات
            this.loadSessions();
            
            // نمایش نوتیفیکیشن دسکتاپ
            if ('Notification' in window && Notification.permission === 'granted') {
                new Notification('چت جدید', {
                    body: `کاربر جدید: ${data.user_name}`,
                    icon: '/wp-content/plugins/wp-live-chat/assets/images/icon.png'
                });
            }
            
            // نمایش نوتیفیکیشن در صفحه
            this.showNotification('چت جدید', `کاربر جدید: ${data.user_name}`);
        }

        // متدهای کمکی جدید
        showError(message) {
            this.showMessage(message, 'error');
        }

        showSuccess(message) {
            this.showMessage(message, 'success');
        }

        showMessage(message, type = 'info') {
            const $container = $('<div class="admin-message-alert"></div>')
                .addClass(`alert-${type}`)
                .text(message)
                .prependTo('#chat-admin-app');
            
            setTimeout(() => $container.fadeOut(300, () => $container.remove()), 5000);
        }

        showNotification(title, body) {
            const $notification = $(`
                <div class="notification-toast">
                    <div class="notification-title">${title}</div>
                    <div class="notification-body">${body}</div>
                </div>
            `).appendTo('body');
            
            setTimeout(() => $notification.fadeOut(300, () => $notification.remove()), 5000);
        }

        formatTime(timestamp) {
            if (!timestamp) return '--:--';
            
            try {
                const date = new Date(timestamp);
                if (isNaN(date.getTime())) {
                    // اگر timestamp معتبر نیست، از روش جایگزین استفاده کن
                    return timestamp;
                }
                
                return date.toLocaleTimeString('fa-IR', {
                    hour: '2-digit',
                    minute: '2-digit',
                    hour12: false
                });
            } catch (e) {
                return '--:--';
            }
        }

        escapeHtml(text) {
            const div = document.createElement('div');
            div.textContent = text;
            return div.innerHTML;
        }
    }

    // Initialize on document ready
    $(document).ready(function() {
        // بررسی وجود config
        if (!window.wpLiveChatAdmin) {
            console.error('WP Live Chat Admin configuration not found');
            return;
        }
        
        try {
            window.wpLiveChatAdminApp = new WPLiveChatAdmin();
            console.log('WP Live Chat Admin initialized successfully');
        } catch (error) {
            console.error('Failed to initialize WP Live Chat Admin:', error);
        }
    });

})(jQuery);