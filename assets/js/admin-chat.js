/**
 * assets/js/admin-chat.js
 * نسخهٔ اصلاح‌شده: مدیریت اتصال، علامت‌گذاری خوانده‌شده، unsubscribe درست
 */
(function($) {
    'use strict';

    class WPLiveChatAdmin {
        constructor() {
            this.config = window.wpLiveChatAdmin || {};
            this.currentSession = null;
            this.sessions = [];
            this.pusher = null;
            this.channel = null;

            this.isLoading = false;
            this.retryCount = 0;
            this.maxRetries = 5;
            this.reconnectTimer = null;

            this.init();
        }

        init() {
            this.bindEvents();
            this.initPusher();
            this.loadSessions();

            $(document).ajaxError((event, jqxhr, settings, thrownError) => {
                console.error('AJAX Error:', thrownError, settings.url);
                this.showError('خطا در ارتباط با سرور');
            });

            // هر 30 ثانیه لیست جلسات را بروزرسانی جزئی کن
            this.sessionsRefreshInterval = setInterval(() => {
                this.loadSessions(false); // false => بدون disable دکمه
            }, 30000);
        }

        bindEvents() {
            $('#refresh-sessions').on('click', () => this.loadSessions(true));
            $('#admin-send-button').on('click', () => this.sendMessage());

            $('#admin-message-input').on('keydown', (e) => {
                if (e.key === 'Enter' && !e.shiftKey) {
                    e.preventDefault();
                    this.sendMessage();
                }
            });
        }

        initPusher() {
            if (!this.config.pusherKey || typeof Pusher === 'undefined') {
                console.warn('Pusher not configured for admin panel');
                return;
            }

            // cleanup if an instance already exists (prevent duplicates)
            if (this.pusher) {
                try { this.cleanupPusher(); } catch (e) { console.warn(e); }
            }

            try {
                this.pusher = new Pusher(this.config.pusherKey, {
                    cluster: this.config.pusherCluster || 'mt1',
                    forceTLS: true,
                    authEndpoint: this.config.ajaxurl,
                    auth: {
                        params: {
                            action: 'pusher_auth',
                            nonce: this.config.nonce
                        }
                    },
                    activityTimeout: 120000,
                    pongTimeout: 30000,
                    disableStats: true
                });

                const adminChannel = this.pusher.subscribe('admin-notifications');
                adminChannel.bind('new-chat', (data) => this.handleNewChatNotification(data));

                this.pusher.connection.bind('state_change', (states) => {
                    console.log('Pusher connection state (admin):', states.current);
                    if (states.current === 'disconnected' || states.current === 'failed') {
                        this.attemptReconnect();
                    }
                });

            } catch (error) {
                console.error('Error initializing admin Pusher:', error);
                this.showError('خطا در راه‌اندازی اعلان‌ها');
            }
        }

        cleanupPusher() {
            try {
                if (this.pusher) {
                    // unsubscribe from any admin channels
                    try {
                        this.pusher.unsubscribe('admin-notifications');
                    } catch(e){}
                    // disconnect
                    try {
                        this.pusher.disconnect();
                    } catch(e){}
                }
            } finally {
                this.pusher = null;
            }
        }

        attemptReconnect() {
            if (!this.config.pusherKey) return;
            if (this.retryCount >= this.maxRetries) {
                console.warn('Admin: max reconnect attempts reached');
                return;
            }
            this.retryCount++;
            const delay = Math.min(1000 * Math.pow(2, this.retryCount), 30000);
            console.log(`Admin: reconnect attempt ${this.retryCount} in ${delay}ms`);
            clearTimeout(this.reconnectTimer);
            this.reconnectTimer = setTimeout(() => this.initPusher(), delay);
        }

        // loadSessions(optionalDisableButton)
        async loadSessions(disableButton = true) {
            if (this.isLoading) return;
            this.isLoading = true;
            if (disableButton) $('#refresh-sessions').prop('disabled', true).text('در حال بارگذاری...');

            try {
                const response = await $.ajax({
                    url: this.config.ajaxurl,
                    type: 'POST',
                    data: {
                        action: 'wp_live_chat_get_sessions', // هماهنگ با PHP handlers
                        nonce: this.config.nonce
                    },
                    dataType: 'json',
                    timeout: 10000
                });

                if (response.success) {
                    this.sessions = response.data || [];
                    this.renderSessions();
                    this.retryCount = 0;
                } else {
                    throw new Error(response.data || 'خطا در دریافت جلسات');
                }
            } catch (error) {
                console.error('Error loading sessions:', error);
                this.showError('خطا در بارگذاری جلسات');
                if (this.retryCount < this.maxRetries) {
                    this.retryCount++;
                    setTimeout(() => this.loadSessions(false), 2000);
                }
            } finally {
                this.isLoading = false;
                if (disableButton) $('#refresh-sessions').prop('disabled', false).text('بروزرسانی');
            }
        }

        renderSessions() {
            const container = $('#sessions-list');
            container.empty();

            if (!this.sessions || this.sessions.length === 0) {
                container.html('<div class="no-sessions">' + (this.config.strings?.noActiveChats || 'هیچ گفتگویی وجود ندارد') + '</div>');
                return;
            }

            this.sessions.forEach(session => {
                const hasUnread = session.unread_count && session.unread_count > 0;
                const sessionElement = $(`
                    <div class="session-item ${hasUnread ? 'has-unread' : ''}" data-session-id="${session.session_id}">
                        <div class="session-info">
                            <div class="session-user">
                                <strong>${this.escapeHtml(session.user_name || 'کاربر')}</strong>
                                ${session.user_phone ? `<div class="session-phone">${this.escapeHtml(session.user_phone)}</div>` : ''}
                            </div>
                            <div class="session-meta">
                                <span class="message-count">${session.message_count || 0} پیام</span>
                                <span class="last-activity">${this.formatTime(session.last_activity)}</span>
                            </div>
                        </div>
                        ${hasUnread ? `<span class="unread-badge">${session.unread_count}</span>` : ''}
                    </div>
                `);

                sessionElement.on('click', () => this.selectSession(session));
                container.append(sessionElement);
            });
        }

        async selectSession(session) {
            // UI: فعال کردن سشن انتخابی و حذف کلاسِ unread
            $('.session-item').removeClass('active');
            $(`.session-item[data-session-id="${session.session_id}"]`).addClass('active').removeClass('has-unread');

            this.currentSession = session;

            // علامت گذاری خوانده شده در سرور (در صورت وجود unread)
            if (session.unread_count && session.unread_count > 0) {
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
                const response = await $.ajax({
                    url: this.config.ajaxurl,
                    type: 'POST',
                    data: {
                        action: 'wp_live_chat_mark_read',
                        nonce: this.config.nonce,
                        session_id: sessionId
                    },
                    dataType: 'json'
                });

                if (response.success) {
                    $(`.session-item[data-session-id="${sessionId}"]`).find('.unread-badge').remove();
                    // optional: update local sessions data
                    const s = this.sessions.find(ss => ss.session_id === sessionId);
                    if (s) { s.unread_count = 0; s.has_unread = false; }
                } else {
                    console.warn('mark read response error', response);
                }

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
                        action: 'wp_live_chat_get_messages',
                        nonce: this.config.nonce,
                        session_id: sessionId
                    },
                    dataType: 'json',
                    timeout: 10000
                });

                if (response.success) {
                    this.renderMessages(response.data || []);
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
                const type = message.message_type === 'admin' ? 'admin' : 'user';
                const messageElement = $(`
                    <div class="message ${type}">
                        <div class="message-header">
                            <span class="message-sender">${type === 'admin' ? '👨‍💼 پشتیبان' : '👤 ' + this.escapeHtml(message.user_name || 'کاربر')}</span>
                            <span class="message-time">${this.formatTime(message.created_at)}</span>
                        </div>
                        <div class="message-content"><p>${this.escapeHtml(message.message_content)}</p></div>
                    </div>
                `);
                container.append(messageElement);
            });

            container.scrollTop(container[0].scrollHeight);
        }

        async sendMessage() {
            if (!this.currentSession) { this.showError('لطفاً ابتدا یک گفتگو را انتخاب کنید'); return; }
            const input = $('#admin-message-input');
            const message = input.val().trim();
            if (!message) { this.showError('لطفاً پیامی وارد کنید'); return; }

            const $sendBtn = $('#admin-send-button');
            $sendBtn.prop('disabled', true).text('در حال ارسال...');

            try {
                const response = await $.ajax({
                    url: this.config.ajaxurl,
                    type: 'POST',
                    data: {
                        action: 'wp_live_chat_send_admin_message',
                        nonce: this.config.nonce,
                        session_id: this.currentSession.session_id,
                        message: message
                    },
                    dataType: 'json',
                    timeout: 10000
                });

                if (response.success) {
                    input.val('');
                    // اضافه‌کردن موقت به UI
                    this.addLocalMessage({
                        message_content: message,
                        user_name: 'پشتیبان',
                        message_type: 'admin',
                        created_at: new Date().toISOString()
                    });
                    this.showSuccess('پیام با موفقیت ارسال شد');
                    // بروزرسانی لیست جلسات برای نشان دادن آخرین فعالیت
                    this.loadSessions(false);
                } else {
                    throw new Error(response.data || 'خطا در ارسال پیام');
                }
            } catch (error) {
                console.error('Error sending message:', error);
                this.showError('خطا در ارسال پیام');
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
                    <div class="message-content"><p>${this.escapeHtml(messageData.message_content)}</p></div>
                </div>
            `);
            container.append(messageElement);
            container.scrollTop(container[0].scrollHeight);
        }

        subscribeToSession(sessionId) {
            // unsubscribe قبلی
            try {
                if (this.channel && this.pusher) {
                    try { this.pusher.unsubscribe(this.channel.name); } catch(e){ console.warn(e); }
                    this.channel = null;
                }
            } catch(e){ console.warn(e); }

            if (!this.pusher) return;

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

                this.channel.bind('pusher:subscription_error', (err) => {
                    console.error('subscription error admin channel', err);
                });

            } catch (error) {
                console.error('Error subscribing to session channel:', error);
            }
        }

        handleNewChatNotification(data) {
            this.loadSessions(false);
            if ('Notification' in window && Notification.permission === 'granted') {
                new Notification('چت جدید', {
                    body: `کاربر جدید: ${data.user_name}`,
                    icon: '/wp-content/plugins/wp-live-chat/assets/images/icon.png'
                });
            }
            this.showNotification('چت جدید', `کاربر جدید: ${data.user_name}`);
        }

        showError(message) { this.showMessage(message, 'error'); }
        showSuccess(message) { this.showMessage(message, 'success'); }
        showMessage(message, type = 'info') {
            const $container = $('<div class="admin-message-alert"></div>').addClass(`alert-${type}`).text(message).prependTo('#chat-admin-app');
            setTimeout(() => $container.fadeOut(300, () => $container.remove()), 5000);
        }
        showNotification(title, body) {
            const $notification = $(`<div class="notification-toast"><div class="notification-title">${title}</div><div class="notification-body">${body}</div></div>`).appendTo('body');
            setTimeout(() => $notification.fadeOut(300, () => $notification.remove()), 5000);
        }

        formatTime(timestamp) {
            if (!timestamp) return '--:--';
            try {
                const date = new Date(timestamp);
                if (isNaN(date.getTime())) return timestamp;
                return date.toLocaleTimeString('fa-IR', { hour: '2-digit', minute: '2-digit', hour12: false });
            } catch (e) { return '--:--'; }
        }

        escapeHtml(text) {
            const div = document.createElement('div');
            div.textContent = text;
            return div.innerHTML;
        }
    }

    $(document).ready(function() {
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
