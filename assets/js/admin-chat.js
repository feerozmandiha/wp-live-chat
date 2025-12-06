/**
 * assets/js/admin-chat.js
 * WP Live Chat - Admin Panel (نسخهٔ کامل و یکپارچه)
 *
 * نیازمندی‌ها:
 * window.wpLiveChatAdmin باید در صفحهٔ ادمین وجود داشته باشد و شامل:
 * {
 *   ajaxurl: '/wp-admin/admin-ajax.php',
 *   nonce: '...',
 *   pusherKey: '...',
 *   pusherCluster: 'mt1',
 *   strings: { /* optional localized strings */ 

(function($) {
    'use strict';

    class WPLiveChatAdmin {
        constructor() {
            this.config = window.wpLiveChatAdmin || {};
            this.ajaxurl = this.config.ajaxurl || '/wp-admin/admin-ajax.php';
            this.nonce = this.config.nonce || '';
            this.pusherKey = this.config.pusherKey || '';
            this.pusherCluster = this.config.pusherCluster || 'mt1';

            // state
            this.pusher = null;
            this.channel = null;
            this.sessions = [];
            this.currentSession = null;
            this.isLoading = false;
            this.retryCount = 0;
            this.maxRetries = 6;
            this.reconnectTimer = null;
            this.messageQueue = new Set(); // dedupe message ids
            this.pingInterval = null;
            this.sessionsRefreshInterval = null;

            // selectors
            this.selectors = {
                sessionsList: '#sessions-list',
                refreshBtn: '#refresh-sessions',
                messagesContainer: '#admin-chat-messages',
                sendButton: '#admin-send-button',
                messageInput: '#admin-message-input',
                sessionTitle: '#current-session-title',
                sessionStatus: '#session-status'
            };

            this.init();
        }

        init() {
            this.bindEvents();
            this.initPusher();
            this.loadSessions();

            // periodic refresh of sessions (to surface new unread quickly)
            this.sessionsRefreshInterval = setInterval(() => this.loadSessions(false), 30000);

            // global AJAX error handling in admin panel (optional)
            $(document).ajaxError((event, jqxhr, settings, thrownError) => {
                console.warn('Admin AJAX error', settings.url, thrownError);
            });
        }

        bindEvents() {
            const self = this;
            $(document).on('click', this.selectors.refreshBtn, function() {
                self.loadSessions(true);
            });

            $(document).on('click', this.selectors.sendButton, function() {
                self.sendMessage();
            });

            $(document).on('keydown', this.selectors.messageInput, function(e) {
                if (e.key === 'Enter' && !e.shiftKey) {
                    e.preventDefault();
                    self.sendMessage();
                }
            });
        }

        /********** Pusher init / cleanup / reconnect **********/
        initPusher() {
            if (!this.pusherKey || typeof Pusher === 'undefined') {
                console.warn('Pusher unavailable for admin panel — polling fallback will be used if implemented on server');
                return;
            }

            // cleanup existing
            if (this.pusher) {
                try { this.cleanupPusher(); } catch (e) { console.warn(e); }
            }

            try {
                this.pusher = new Pusher(this.pusherKey, {
                    cluster: this.pusherCluster,
                    forceTLS: true,
                    authEndpoint: this.ajaxurl,
                    auth: {
                        params: {
                            action: 'pusher_auth',
                            nonce: this.nonce
                        }
                    },
                    activityTimeout: 120000,
                    pongTimeout: 30000,
                    disableStats: true
                });

                // subscribe to a global admin channel for notifications
                const adminChannelName = 'admin-notifications';
                this.channel = this.pusher.subscribe(adminChannelName);

                // new chat notification (someone started a new chat)
                this.channel.bind('new-chat', (data) => this.handleNewChatNotification(data));

                // optionally other admin events: admin-online/admin-offline etc
                this.channel.bind('admin-connected', (data) => {
                    this.showToast(this._t('admin_connected', 'کاربر پشتیبانی متصل شد'), 'info');
                });

                // connection lifecycle
                this.pusher.connection.bind('state_change', (states) => {
                    console.log('Pusher state (admin):', states.current);
                    if (states.current === 'connected') {
                        this.retryCount = 0;
                    } else if (states.current === 'disconnected' || states.current === 'failed') {
                        this.attemptReconnect();
                    } else if (states.current === 'unavailable') {
                        // server/provider problem - rely on polling fallback if available
                        this.showToast(this._t('connection_unavailable', 'اتصال اعلان‌ها در دسترس نیست'), 'warning');
                    }
                });

                this.pusher.connection.bind('error', (err) => {
                    console.error('Pusher admin error', err);
                    // try reconnect strategy
                    this.attemptReconnect();
                });

            } catch (err) {
                console.error('Failed to initialize Pusher (admin):', err);
            }
        }

        cleanupPusher() {
            try {
                if (this.pusher) {
                    try {
                        if (this.channel) {
                            this.pusher.unsubscribe(this.channel.name || 'admin-notifications');
                            this.channel = null;
                        }
                    } catch (e) { console.warn(e); }

                    try { this.pusher.disconnect(); } catch (e) { console.warn(e); }
                }
            } finally {
                this.pusher = null;
            }
        }

        attemptReconnect() {
            if (!this.pusherKey) return;
            if (this.retryCount >= this.maxRetries) {
                console.warn('Admin: max reconnect attempts reached');
                return;
            }
            this.retryCount++;
            const delay = Math.min(1000 * Math.pow(2, this.retryCount), 30000);
            clearTimeout(this.reconnectTimer);
            this.reconnectTimer = setTimeout(() => this.initPusher(), delay);
        }

        /********** Sessions (list) **********/
        async loadSessions(disableButton = true) {
            if (this.isLoading) return;
            this.isLoading = true;

            const $btn = $(this.selectors.refreshBtn);
            if (disableButton && $btn.length) {
                $btn.prop('disabled', true).text(this._t('loading', 'در حال بارگذاری...'));
            }

            try {
                const resp = await $.ajax({
                    url: this.ajaxurl,
                    type: 'POST',
                    dataType: 'json',
                    timeout: 10000,
                    data: {
                        action: 'wp_live_chat_get_sessions',
                        nonce: this.nonce
                    }
                });

                if (resp && resp.success) {
                    this.sessions = resp.data || [];
                    this.renderSessions();
                } else {
                    console.warn('loadSessions response error', resp);
                    this.showToast(this._t('load_sessions_error', 'خطا در بارگذاری جلسات'), 'error');
                }
            } catch (err) {
                console.error('loadSessions ajax error', err);
                this.showToast(this._t('load_sessions_error', 'خطا در بارگذاری جلسات'), 'error');
            } finally {
                this.isLoading = false;
                if (disableButton && $btn.length) {
                    $btn.prop('disabled', false).text(this._t('refresh', 'بروزرسانی'));
                }
            }
        }

        renderSessions() {
            const $container = $(this.selectors.sessionsList);
            if ($container.length === 0) return;
            $container.empty();

            if (!this.sessions || this.sessions.length === 0) {
                $container.html(`<div class="no-sessions">${this._t('no_sessions', 'هیچ گفتگویی وجود ندارد')}</div>`);
                return;
            }

            this.sessions.forEach(session => {
                const hasUnread = session.unread_count && session.unread_count > 0;
                const lastActivity = session.last_activity || session.updated_at || '';
                const sessionHtml = $(`
                    <div class="session-item ${hasUnread ? 'has-unread' : ''}" data-session-id="${this.escapeHtml(session.session_id)}">
                        <div class="session-left">
                            <div class="session-user">${this.escapeHtml(session.user_name || session.user_email || 'کاربر')}</div>
                            <div class="session-meta">${this.escapeHtml(session.user_agent || '')}</div>
                        </div>
                        <div class="session-right">
                            <div class="session-last">${this.escapeHtml(this.formatTime(lastActivity))}</div>
                            ${hasUnread ? `<span class="unread-badge">${session.unread_count}</span>` : ''}
                        </div>
                    </div>
                `);

                sessionHtml.on('click', () => this.selectSession(session));
                $container.append(sessionHtml);
            });
        }

        /********** Session select / messages **********/
        async selectSession(session) {
            if (!session || !session.session_id) return;

            // UI active class
            $('.session-item').removeClass('active');
            $(`.session-item[data-session-id="${this.escapeHtml(session.session_id)}"]`).addClass('active');

            this.currentSession = session;
            $(this.selectors.sessionTitle).text(session.user_name || 'کاربر');
            $(this.selectors.sessionStatus)
                .removeClass('status-online status-offline')
                .addClass(session.status === 'active' ? 'status-online' : 'status-offline')
                .text(session.status === 'active' ? this._t('online', 'آنلاین') : this._t('offline', 'آفلاین'));

            // mark as read on server if unread_count > 0
            if (session.unread_count && session.unread_count > 0) {
                await this.markSessionAsRead(session.session_id);
            }

            // load messages
            await this.loadSessionMessages(session.session_id);

            // subscribe to session-specific channel (to receive new messages)
            this.subscribeToSession(session.session_id);
        }

        async loadSessionMessages(sessionId) {
            if (!sessionId) return;
            try {
                const resp = await $.ajax({
                    url: this.ajaxurl,
                    type: 'POST',
                    dataType: 'json',
                    timeout: 10000,
                    data: {
                        action: 'wp_live_chat_get_messages',
                        nonce: this.nonce,
                        session_id: sessionId
                    }
                });

                if (resp && resp.success) {
                    this.renderMessages(resp.data || []);
                } else {
                    console.warn('loadSessionMessages response error', resp);
                    this.showToast(this._t('load_messages_error', 'خطا در بارگذاری پیام‌ها'), 'error');
                }
            } catch (err) {
                console.error('loadSessionMessages ajax error', err);
                this.showToast(this._t('load_messages_error', 'خطا در بارگذاری پیام‌ها'), 'error');
            }
        }

        renderMessages(messages) {
            const $container = $(this.selectors.messagesContainer);
            if ($container.length === 0) return;
            $container.empty();

            if (!messages || messages.length === 0) {
                $container.html(`<div class="no-messages">${this._t('no_messages', 'هنوز پیامی رد و بدل نشده است')}</div>`);
                return;
            }

            messages.forEach(msg => {
                const type = msg.message_type === 'admin' || msg.from === 'admin' ? 'admin' : 'user';
                const messageId = msg.id || msg.message_id || this.generateMessageId(msg.message_content || msg.message || '', msg.created_at || msg.timestamp);
                if (this.messageQueue.has(messageId)) {
                    // skip duplicates
                    return;
                }
                this.messageQueue.add(messageId);
                if (this.messageQueue.size > 1000) {
                    // trim queue
                    const it = this.messageQueue.values();
                    this.messageQueue.delete(it.next().value);
                }

                const html = `
                    <div class="message ${type}" data-message-id="${this.escapeHtml(messageId)}">
                        <div class="message-header">
                            <span class="sender">${type === 'admin' ? this._t('support', 'پشتیبانی') : this.escapeHtml(msg.user_name || 'کاربر')}</span>
                            <span class="time">${this.escapeHtml(this.formatTime(msg.created_at || msg.timestamp || ''))}</span>
                        </div>
                        <div class="message-body">${this.escapeHtml(msg.message_content || msg.message || '')}</div>
                    </div>
                `;
                $container.append(html);
            });

            $container.stop().animate({ scrollTop: $container[0].scrollHeight }, 200);
        }

        async sendMessage() {
            if (!this.currentSession || !this.currentSession.session_id) {
                this.showToast(this._t('select_session', 'لطفاً ابتدا یک گفتگو را انتخاب کنید'), 'error');
                return;
            }

            const $input = $(this.selectors.messageInput);
            const message = $input.val() ? $input.val().trim() : '';
            if (!message) { this.showToast(this._t('empty_message', 'پیامی وارد نشده'), 'error'); return; }

            const $btn = $(this.selectors.sendButton);
            $btn.prop('disabled', true).text(this._t('sending', 'در حال ارسال...'));

            try {
                const resp = await $.ajax({
                    url: this.ajaxurl,
                    type: 'POST',
                    dataType: 'json',
                    timeout: 10000,
                    data: {
                        action: 'wp_live_chat_send_admin_message',
                        nonce: this.nonce,
                        session_id: this.currentSession.session_id,
                        message: message
                    }
                });

                if (resp && resp.success) {
                    // optimistic: clear input and append admin message locally (server will also push it)
                    $input.val('');
                    this.addLocalMessage({
                        message_content: message,
                        message_type: 'admin',
                        created_at: new Date().toISOString(),
                        user_name: this._t('support', 'پشتیبانی'),
                        id: resp.data && resp.data.id ? resp.data.id : undefined
                    });

                    // refresh sessions list so last activity / unread counts update
                    this.loadSessions(false);
                } else {
                    console.warn('sendMessage response error', resp);
                    this.showToast(this._t('send_failed', 'ارسال پیام با خطا مواجه شد'), 'error');
                }
            } catch (err) {
                console.error('sendMessage ajax error', err);
                this.showToast(this._t('send_failed', 'ارسال پیام با خطا مواجه شد'), 'error');
            } finally {
                $btn.prop('disabled', false).text(this._t('send', 'ارسال'));
            }
        }

        addLocalMessage(msg) {
            const $container = $(this.selectors.messagesContainer);
            if ($container.length === 0) return;

            const messageId = msg.id || this.generateMessageId(msg.message_content || msg.message || '', msg.created_at || Date.now());
            if (this.messageQueue.has(messageId)) return;
            this.messageQueue.add(messageId);

            const html = `
                <div class="message admin" data-message-id="${this.escapeHtml(messageId)}">
                    <div class="message-header">
                        <span class="sender">${this.escapeHtml(msg.user_name || this._t('support', 'پشتیبانی'))}</span>
                        <span class="time">${this.escapeHtml(this.formatTime(msg.created_at))}</span>
                    </div>
                    <div class="message-body">${this.escapeHtml(msg.message_content || msg.message || '')}</div>
                </div>
            `;
            $container.append(html);
            $container.stop().animate({ scrollTop: $container[0].scrollHeight }, 150);
        }

        /********** mark read **********/
        async markSessionAsRead(sessionId) {
            if (!sessionId) return;
            try {
                const resp = await $.ajax({
                    url: this.ajaxurl,
                    type: 'POST',
                    dataType: 'json',
                    data: {
                        action: 'wp_live_chat_mark_read',
                        nonce: this.nonce,
                        session_id: sessionId
                    },
                    timeout: 8000
                });

                if (resp && resp.success) {
                    // remove unread badge from UI
                    $(`.session-item[data-session-id="${this.escapeHtml(sessionId)}"]`).removeClass('has-unread').find('.unread-badge').remove();
                    // update local sessions data
                    const s = this.sessions.find(ss => ss.session_id === sessionId);
                    if (s) { s.unread_count = 0; }
                } else {
                    console.warn('markSessionAsRead response error', resp);
                }
            } catch (err) {
                console.warn('markSessionAsRead ajax error', err);
            }
        }

        /********** subscribe to session channel **********/
        subscribeToSession(sessionId) {
            // unsubscribe any previous session-specific channel (we only keep admin global channel for notifications in this file)
            // Note: if you want to subscribe to private-chat-{sessionId} for real-time messages, do it here.
            if (!this.pusher) return;

            // unsubscribe any previously subscribed session channel if stored
            if (this.sessionChannel) {
                try { this.pusher.unsubscribe(this.sessionChannel.name); } catch (e) { console.warn(e); }
                this.sessionChannel = null;
            }

            try {
                const channelName = `private-chat-${sessionId}`;
                this.sessionChannel = this.pusher.subscribe(channelName);
                this.sessionChannel.bind('new-message', (data) => {
                    // if current session matches, reload messages or append
                    if (this.currentSession && this.currentSession.session_id === sessionId) {
                        // append directly or refresh messages from server
                        // prefer append to reduce latency; server should send stable id
                        this.handleIncomingSessionMessage(data);
                    } else {
                        // if it's for a different session, refresh sessions list to show unread badge
                        this.loadSessions(false);
                    }
                });

                this.sessionChannel.bind('pusher:subscription_error', (err) => {
                    console.error('session subscription error', err);
                });
            } catch (err) {
                console.warn('subscribeToSession failed', err);
            }
        }

        handleIncomingSessionMessage(data) {
            if (!data) return;
            const text = data.message || data.message_content || data.text || '';
            if (!text) return;

            const messageId = data.id || data.message_id || this.generateMessageId(text, data.created_at || data.timestamp || Date.now());
            if (this.messageQueue.has(messageId)) return;
            this.messageQueue.add(messageId);

            // append to messages UI
            const appended = {
                id: messageId,
                message_content: text,
                user_name: data.user_name || data.sender || this._t('user', 'کاربر'),
                message_type: data.type || 'user',
                created_at: data.created_at || data.timestamp || new Date().toISOString()
            };
            this.addLocalMessage(appended);

            // optional: play sound or show notification
            this.showToast(this._t('new_message', 'پیام جدید از کاربر'), 'info');

            // refresh sessions to reflect unread and last activity
            this.loadSessions(false);
        }

        handleNewChatNotification(data) {
            // new chat started: refresh sessions and show notification
            this.loadSessions(false);
            this.showToast(this._t('new_chat', 'گفتگوی جدید'), 'info');

            // optional browser notification
            if ('Notification' in window && Notification.permission === 'granted') {
                new Notification(this._t('new_chat', 'گفتگوی جدید'), {
                    body: data && data.user_name ? data.user_name : '',
                    icon: this.config.icon || ''
                });
            }
        }

        /********** utilities **********/
        generateMessageId(text, ts) {
            const time = ts ? (typeof ts === 'number' ? ts : Date.parse(ts) || Date.now()) : Date.now();
            const base = `${text}-${time}`;
            let hash = 5381;
            for (let i = 0; i < base.length; i++) hash = ((hash << 5) + hash) + base.charCodeAt(i);
            return 'wlm-' + (hash >>> 0).toString(36);
        }

        formatTime(ts) {
            if (!ts) return '';
            try {
                const d = new Date(ts);
                if (isNaN(d.getTime())) return ts;
                return d.toLocaleString('fa-IR', { hour: '2-digit', minute: '2-digit' });
            } catch (e) { return ts; }
        }

        escapeHtml(s) {
            if (s === null || s === undefined) return '';
            return String(s)
                .replace(/&/g, '&amp;')
                .replace(/"/g, '&quot;')
                .replace(/'/g, '&#039;')
                .replace(/</g, '&lt;')
                .replace(/>/g, '&gt;');
        }

        showToast(msg, type='info', timeout=5000) {
            // simple admin toast: you can replace with WP admin notices or custom UI
            const $container = $('#wl-admin-toast-area');
            if ($container.length === 0) {
                $('body').append('<div id="wl-admin-toast-area" style="position:fixed;z-index:9999;right:20px;bottom:20px;"></div>');
            }
            const $toast = $(`<div class="wl-admin-toast wl-admin-toast-${type}">${this.escapeHtml(msg)}</div>`);
            $('#wl-admin-toast-area').append($toast);
            $toast.hide().fadeIn(150);
            setTimeout(() => $toast.fadeOut(200, function(){ $(this).remove(); }), timeout);
        }

        _t(key, fallback) {
            if (this.config.strings && typeof this.config.strings[key] !== 'undefined') return this.config.strings[key];
            return fallback || key;
        }
    }

    // Initialize when document ready and config exists
    $(document).ready(function() {
        if (!window.wpLiveChatAdmin) {
            console.warn('wpLiveChatAdmin config missing; admin-chat.js will not initialize.');
            return;
        }
        try {
            window.wpLiveChatAdminApp = new WPLiveChatAdmin();
            console.log('WPLiveChatAdmin initialized');
        } catch (e) {
            console.error('Failed to initialize WPLiveChatAdmin:', e);
        }
    });

})(jQuery);
