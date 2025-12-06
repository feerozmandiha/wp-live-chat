/**
 * assets/js/frontend.js
 * WP Live Chat - Frontend (نسخهٔ یکپارچه و اصلاح‌شده)
 *
 * ویژگی‌ها:
 * - مدیریت پیوستهٔ Pusher (single instance)
 * - exponential backoff reconnect و polling fallback
 * - جلوگیری از پیام‌های تکراری
 * - ping دوره‌ای برای نگهداری اتصال وب‌سوکت
 * - AJAX actionهای هماهنگ با سرور (wp_live_chat_*)
 *
 * توجه: نیاز است که window.wpLiveChatFrontend در صفحه تعریف شده باشد:
 * window.wpLiveChatFrontend = {
 *   ajaxurl: '/wp-admin/admin-ajax.php',
 *   nonce: '...',
 *   pusherKey: '...',
 *   pusherCluster: 'mt1',
 *   sessionId: '...'
 *   strings: { /* optional localized strings */ 


(function ($, window) {
  'use strict';

  // Helper: safe console
  const log = window.console && window.console.log ? window.console.log.bind(console) : function () {};

  class WPLiveChatFrontend {
    constructor(config) {
      this.config = config || (window.wpLiveChatFrontend || {});
      // required values
      this.ajaxurl = this.config.ajaxurl || '/wp-admin/admin-ajax.php';
      this.nonce = this.config.nonce || '';
      this.pusherKey = this.config.pusherKey || '';
      this.pusherCluster = this.config.pusherCluster || 'mt1';
      this.sessionId = this.config.sessionId || null;

      // internal state
      this.pusher = null;
      this.pusherChannel = null;
      this.pusherPresence = null;
      this.connected = false;
      this.retryCount = 0;
      this.maxRetries = 6;
      this.pollingInterval = null;
      this.isPolling = false;
      this.pollIntervalMs = 10000; // polling every 10s in fallback
      this.messageQueue = new Set(); // for dedupe
      this.lastMessageId = null;
      this.pingInterval = null;
      this.maxMessageQueueSize = 1000; // limit memory
      this.adminOnline = false;
      this.unreadCount = 0;

      // DOM selectors (ensure these IDs exist in your theme/plugin markup)
      this.selectors = {
        messagesContainer: '#wlchat-messages',
        inputSelector: '#wlchat-input',
        sendButton: '#wlchat-send',
        statusIndicator: '#wlchat-status',
        unreadBadge: '#wlchat-unread-badge'
      };

      this.init();
    }

    init() {
      this.bindUI();
      // try to init pusher first
      this.initPusher();
      // fallback: if no pusher key or connection fails, fallback to polling (handled by initPusher)
    }

    bindUI() {
      const self = this;
      $(document).on('click', this.selectors.sendButton, function (e) {
        e.preventDefault();
        self.sendMessage();
      });

      $(document).on('keydown', this.selectors.inputSelector, function (e) {
        if (e.key === 'Enter' && !e.shiftKey) {
          e.preventDefault();
          self.sendMessage();
        }
      });

      // optional: clicking the unread badge will open chat and mark as read
      $(document).on('click', this.selectors.unreadBadge, function () {
        self.markAllMessagesRead();
      });
    }

    initPusher() {
      // If no pusher key, immediately use polling fallback
      if (!this.pusherKey || typeof Pusher === 'undefined') {
        log('Pusher unavailable - switching to polling fallback.');
        this.initPollingFallback();
        return;
      }

      // cleanup old instance if exists
      if (this.pusher) {
        try { this.cleanupPusher(); } catch (e) { console.warn(e); }
      }

      try {
        // create new Pusher instance
        this.pusher = new Pusher(this.pusherKey, {
          cluster: this.pusherCluster,
          forceTLS: true,
          authEndpoint: this.ajaxurl,
          auth: {
            params: {
              action: 'pusher_auth',
              nonce: this.nonce,
              session_id: this.sessionId
            }
          },
          activityTimeout: 120000,
          pongTimeout: 30000,
          disableStats: true
        });

        const channelName = this.sessionId ? `private-chat-${this.sessionId}` : null;
        if (channelName) {
          this.pusherChannel = this.pusher.subscribe(channelName);
          this.pusherChannel.bind('new-message', (data) => this.onIncomingMessage(data));
          this.pusherChannel.bind('message-read', (data) => this.onMessageReadEvent(data));
          this.pusherChannel.bind('pusher:subscription_error', (err) => {
            console.error('Subscription error (channel):', err);
            // If subscription fails, fallback to polling
            this.initPollingFallback();
          });
        }

        // optional: admin notifications / presence
        try {
          this.pusherPresence = this.pusher.subscribe('presence-chat');
          this.pusherPresence.bind('pusher:subscription_succeeded', (members) => {
            // you may want to detect admin presence here
            // e.g. members count or custom member data
            this.updateAdminPresence(members);
          });
        } catch (e) {
          // ignore if presence channel isn't available
        }

        // bind connection events
        this.pusher.connection.bind('state_change', (states) => {
          log('Pusher state change:', states.current);
          if (states.current === 'connected') {
            this.onConnected();
          } else if (states.current === 'disconnected' || states.current === 'failed') {
            this.onDisconnected();
          } else if (states.current === 'unavailable') {
            // degrade gracefully to polling if provider says unavailable
            this.initPollingFallback();
          }
        });

        this.pusher.connection.bind('error', (err) => {
          console.error('Pusher connection error:', err);
          // try reconnect logic
          if (err && (err.error || err.type)) {
            this.attemptReconnect();
          }
        });
      } catch (error) {
        console.error('Error initializing Pusher:', error);
        this.initPollingFallback();
      }
    }

    onConnected() {
      this.connected = true;
      this.retryCount = 0;
      this.setConnectedStatus('online');
      this.startPing();
      // If we used polling previously, stop it
      if (this.pollingInterval) {
        clearInterval(this.pollingInterval);
        this.pollingInterval = null;
        this.isPolling = false;
      }
      // optionally fetch missed messages via AJAX
      this.fetchRecentMessages();
    }

    onDisconnected() {
      this.connected = false;
      this.setConnectedStatus('offline');
      this.attemptReconnect();
    }

    attemptReconnect() {
      if (this.retryCount >= this.maxRetries) {
        console.warn('Max reconnect attempts reached. Switching to polling fallback.');
        this.initPollingFallback();
        return;
      }
      this.retryCount++;
      const delay = Math.min(1000 * Math.pow(2, this.retryCount), 30000); // exponential backoff
      log(`Reconnect attempt #${this.retryCount} in ${delay}ms`);
      setTimeout(() => {
        try {
          this.initPusher();
        } catch (e) {
          console.warn('Reconnect initPusher failed', e);
          this.initPollingFallback();
        }
      }, delay);
    }

    startPing() {
      // Keep the websocket alive by client-side pings (if server supports client events)
      if (this.pingInterval) clearInterval(this.pingInterval);
      try {
        this.pingInterval = setInterval(() => {
          try {
            if (this.pusher && this.pusherChannel && this.pusher.connection.state === 'connected') {
              // client event (may require enabling on server)
              // use a safe event name starting with client-
              try {
                this.pusherChannel.trigger('client-ping', { session_id: this.sessionId, ts: Date.now() });
              } catch (e) {
                // some Pusher setups disallow client events - ignore
              }
            }
          } catch (e) { console.warn('Ping error', e); }
        }, 45000);
      } catch (e) { console.warn(e); }
    }

    initPollingFallback() {
      // cleanup pusher resources
      this.cleanupPusher();
      this.setConnectedStatus('offline');
      this.showAlert(this._t('chat_fallback_polling', 'ارتباط لحظه‌ای قطع شد — حالت پس‌زمینه فعال شد'), 'warning', 6000);

      if (this.pollingInterval) clearInterval(this.pollingInterval);
      this.pollingInterval = setInterval(() => {
        if (!this.isPolling) this.pollForNewMessages();
      }, this.pollIntervalMs);
      // initial immediate poll
      this.pollForNewMessages();
    }

    pollForNewMessages() {
      if (this.isPolling) return;
      this.isPolling = true;

      const self = this;
      $.ajax({
        url: this.ajaxurl,
        type: 'POST',
        dataType: 'json',
        data: {
          action: 'wp_live_chat_poll_messages',
          nonce: this.nonce,
          session_id: this.sessionId,
          last_message_id: this.lastMessageId
        },
        timeout: 10000
      }).done(function (res) {
        if (res && res.success && res.data && Array.isArray(res.data.messages)) {
          const msgs = res.data.messages;
          msgs.forEach((m) => self.onIncomingMessage(m));
          if (msgs.length) {
            const last = msgs[msgs.length - 1];
            self.lastMessageId = last.id || self.lastMessageId;
          }
        }
      }).fail(function (err) {
        console.error('Polling error', err);
      }).always(function () {
        self.isPolling = false;
      });
    }

    fetchRecentMessages() {
      const self = this;
      $.ajax({
        url: this.ajaxurl,
        type: 'POST',
        dataType: 'json',
        data: {
          action: 'wp_live_chat_get_messages',
          nonce: this.nonce,
          session_id: this.sessionId,
          since_id: this.lastMessageId
        },
        timeout: 10000
      }).done(function (res) {
        if (res && res.success && Array.isArray(res.data)) {
          res.data.forEach(m => self.onIncomingMessage(m));
          if (res.data.length) {
            const last = res.data[res.data.length - 1];
            self.lastMessageId = last.id || self.lastMessageId;
          }
        }
      }).fail(function (err) {
        console.warn('fetchRecentMessages failed', err);
      });
    }

    onIncomingMessage(payload) {
      // normalize payload: allow different keys
      if (!payload) return;
      const messageText = payload.message || payload.message_content || payload.text || '';
      if (!messageText || typeof messageText !== 'string') return;

      // determine message id (must be stable). If server provides id use it; otherwise generate from content+ts
      const messageId = payload.id || payload.message_id || this.generateMessageId(messageText, payload.created_at || payload.timestamp);

      // dedupe
      if (this.messageQueue.has(messageId)) {
        // already processed
        return;
      }
      this.messageQueue.add(messageId);
      // cap queue size
      if (this.messageQueue.size > this.maxMessageQueueSize) {
        // remove oldest item(s)
        const it = this.messageQueue.values();
        const first = it.next().value;
        this.messageQueue.delete(first);
      }

      // append to UI
      const msg = {
        id: messageId,
        text: messageText,
        user_name: payload.user_name || payload.sender_name || this._t('user', 'کاربر'),
        created_at: payload.created_at || payload.timestamp || new Date().toISOString(),
        type: payload.type || (payload.from === 'admin' ? 'admin' : 'user')
      };
      this.appendMessage(msg);

      // update lastMessageId if numeric id provided
      if (payload.id || payload.message_id) {
        this.lastMessageId = payload.id || payload.message_id;
      }

      // unread handling: if admin not online, increase unread badge
      if (!this.adminOnline) {
        this.unreadCount++;
        this.updateUnreadBadge();
      }
    }

    appendMessage(msg) {
      try {
        const $container = $(this.selectors.messagesContainer);
        if ($container.length === 0) return;

        const classType = msg.type === 'admin' ? 'wlchat-admin' : 'wlchat-user';
        const timeStr = this.formatTime(msg.created_at);

        const html = `<div class="wlchat-message ${classType}" data-wlchat-id="${this.escapeHtml(msg.id)}">
                        <div class="wlchat-message-header">
                          <span class="wlchat-name">${this.escapeHtml(msg.user_name)}</span>
                          <span class="wlchat-time">${this.escapeHtml(timeStr)}</span>
                        </div>
                        <div class="wlchat-message-body">${this.escapeHtml(msg.text)}</div>
                      </div>`;
        $container.append(html);
        // scroll to bottom
        $container.stop().animate({ scrollTop: $container[0].scrollHeight }, 250);
      } catch (e) {
        console.warn('appendMessage error', e);
      }
    }

    sendMessage() {
      const $input = $(this.selectors.inputSelector);
      if ($input.length === 0) return;
      const message = $input.val();
      if (!message || !message.trim()) return;

      const payload = {
        action: 'wp_live_chat_send_message',
        nonce: this.nonce,
        session_id: this.sessionId,
        message: message
      };

      const $btn = $(this.selectors.sendButton);
      $btn.prop('disabled', true).addClass('sending');

      $.ajax({
        url: this.ajaxurl,
        type: 'POST',
        dataType: 'json',
        data: payload,
        timeout: 10000
      }).done((res) => {
        if (res && res.success) {
          // message accepted by server. server should also push it back through pusher so dedupe will avoid duplicate.
          $input.val('');
          // optimistic UI: add message as admin (if applicable) or local
          const localMsg = {
            id: res.data && res.data.id ? res.data.id : this.generateMessageId(message, Date.now()),
            text: message,
            user_name: this._t('you', 'شما'),
            created_at: new Date().toISOString(),
            type: 'admin'
          };
          this.onIncomingMessage({ id: localMsg.id, message: localMsg.text, user_name: localMsg.user_name, created_at: localMsg.created_at, type: 'admin' });
        } else {
          this.showAlert(this._t('send_failed', 'ارسال پیام با خطا مواجه شد'), 'error', 4000);
        }
      }).fail((err) => {
        console.error('sendMessage ajax error', err);
        this.showAlert(this._t('send_failed', 'ارسال پیام با خطا مواجه شد'), 'error', 4000);
      }).always(() => {
        $btn.prop('disabled', false).removeClass('sending');
      });
    }

    markAllMessagesRead() {
      // mark session as read on server
      $.ajax({
        url: this.ajaxurl,
        type: 'POST',
        dataType: 'json',
        data: {
          action: 'wp_live_chat_mark_read',
          nonce: this.nonce,
          session_id: this.sessionId
        },
        timeout: 8000
      }).done((res) => {
        if (res && res.success) {
          this.unreadCount = 0;
          this.updateUnreadBadge();
        }
      }).fail((err) => {
        console.warn('markAllMessagesRead failed', err);
      });
    }

    onMessageReadEvent(data) {
      // handle server push that some messages were read (e.g., by admin)
      if (!data) return;
      if (data.message_id) {
        const $msg = $(`[data-wlchat-id="${this.escapeHtml(data.message_id)}"]`);
        $msg.addClass('wlchat-read');
      }
      if (data.session_read) {
        this.unreadCount = 0;
        this.updateUnreadBadge();
      }
    }

    updateAdminPresence(members) {
      // if you encode admin presence in members, detect it here
      // this.adminOnline = (members && members.count && members.count > 0);
      // fallback: if presence not used, adminOnline stays false until pushed by admin channel
      // For safety, keep adminOnline false by default
      // optionally call setConnectedStatus
    }

    updateUnreadBadge() {
      const $badge = $(this.selectors.unreadBadge);
      if ($badge.length === 0) return;
      if (this.unreadCount > 0) {
        $badge.text(this.unreadCount).show();
      } else {
        $badge.hide().text('');
      }
    }

    markMessageRead(messageId) {
      if (!messageId) return;
      $.post(this.ajaxurl, { action: 'wp_live_chat_mark_message_read', nonce: this.nonce, message_id: messageId })
        .done((res) => {
          // optionally update UI
          $(`[data-wlchat-id="${this.escapeHtml(messageId)}"]`).addClass('wlchat-read');
        }).fail((err) => {
          console.warn('markMessageRead failed', err);
        });
    }

    cleanupPusher() {
      // clear intervals
      if (this.pingInterval) { clearInterval(this.pingInterval); this.pingInterval = null; }
      // stop polling
      if (this.pollingInterval) { clearInterval(this.pollingInterval); this.pollingInterval = null; this.isPolling = false; }

      // unsubscribe channels and disconnect
      try {
        if (this.pusher) {
          try {
            if (this.pusherChannel) {
              this.pusher.unsubscribe(this.pusherChannel.name || (`private-chat-${this.sessionId}`));
              this.pusherChannel = null;
            }
          } catch (e) { /* ignore */ }
          try {
            if (this.pusherPresence) {
              this.pusher.unsubscribe('presence-chat');
              this.pusherPresence = null;
            }
          } catch (e) { /* ignore */ }
          try { this.pusher.disconnect(); } catch (e) { /* ignore */ }
        }
      } catch (e) {
        console.warn('cleanupPusher error', e);
      } finally {
        this.pusher = null;
        this.connected = false;
      }
    }

    generateMessageId(text, ts) {
      // create a reasonably unique id from content+timestamp
      const time = ts ? (typeof ts === 'number' ? ts : Date.parse(ts) || Date.now()) : Date.now();
      let base = `${text}-${time}`;
      // simple hash (djb2)
      let hash = 5381;
      for (let i = 0; i < base.length; i++) hash = ((hash << 5) + hash) + base.charCodeAt(i);
      return 'wlm-' + (hash >>> 0).toString(36);
    }

    setConnectedStatus(status) {
      const $stat = $(this.selectors.statusIndicator);
      if ($stat.length === 0) return;
      $stat.removeClass('status-online status-offline status-warning');
      if (status === 'online') {
        $stat.addClass('status-online').text(this._t('online', 'آنلاین'));
      } else if (status === 'offline') {
        $stat.addClass('status-offline').text(this._t('offline', 'آفلاین'));
      } else {
        $stat.addClass('status-warning').text(status);
      }
    }

    showAlert(message, type = 'info', timeout = 4000) {
      // Simple temporary toast; customize to your UI
      const id = 'wlchat-toast-' + Date.now();
      const $toast = $(`<div id="${id}" class="wlchat-toast wlchat-toast-${type}">${this.escapeHtml(message)}</div>`);
      $('body').append($toast);
      $toast.hide().fadeIn(200);
      setTimeout(() => $toast.fadeOut(200, () => $toast.remove()), timeout);
    }

    formatTime(iso) {
      if (!iso) return '';
      try {
        const d = new Date(iso);
        if (isNaN(d.getTime())) return iso;
        return d.toLocaleTimeString('fa-IR', { hour: '2-digit', minute: '2-digit', hour12: false });
      } catch (e) {
        return iso;
      }
    }

    _t(key, fallback) {
      if (this.config.strings && typeof this.config.strings[key] !== 'undefined') return this.config.strings[key];
      return fallback || key;
    }

    escapeHtml(text) {
      if (text === null || text === undefined) return '';
      return String(text)
        .replace(/&/g, '&amp;')
        .replace(/"/g, '&quot;')
        .replace(/'/g, '&#039;')
        .replace(/</g, '&lt;')
        .replace(/>/g, '&gt;');
    }
  }

  // auto-init if config available
  $(function () {
    try {
      const cfg = window.wpLiveChatFrontend || null;
      if (!cfg) {
        log('wpLiveChatFrontend config not found. frontend.js not initialized.');
        return;
      }
      window.wpLiveChatFrontendApp = new WPLiveChatFrontend(cfg);
      log('WPLiveChatFrontend initialized');
    } catch (e) {
      console.error('Failed to initialize WPLiveChatFrontend:', e);
    }
  });

})(jQuery, window);
