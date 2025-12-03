/**
 * build/js/frontend.js
 * نسخهٔ بهبود یافته با آلرت و جلوگیری از تکرار پیام
 */
(function(global, $) {
  'use strict';

  if (typeof $ === 'undefined') {
    console && console.error && console.error('jQuery required by WPLiveChatFrontend');
    return;
  }
  
  if (typeof global.wpLiveChat === 'undefined') {
    return;
  }

  class WPLiveChatFrontend {
    constructor(options = {}) {
      // تنظیمات
      this.ajaxurl = options.ajaxurl || '/wp-admin/admin-ajax.php';
      this.nonce = options.nonce || '';
      this.pusherKey = options.pusherKey || '';
      this.pusherCluster = options.pusherCluster || '';
      this.sessionId = options.sessionId || ('chat_' + this._uuid());
      this.currentUser = options.userData || options.currentUser || {};
      this.strings = options.strings || {};
      this.pusher = null;
        // اضافه کردن flag برای جلوگیری از بارگذاری چندباره
        this.isHistoryLoading = false;
        this.historyLoaded = false;

        // اضافه کردن تاریخچه پیام‌ها
      this.messageHistory = [];

      // DOM selectors
      this.selectors = {
        container: '#wp-live-chat-container',
        messages: '.chat-messages',
        toggle: '.chat-toggle',
        widget: '.chat-widget',
        userForm: '.user-info-form',
        phoneInput: '#wlch-phone',
        nameInput: '#wlch-name',
        saveInfoBtn: '#wlch-save-info',
        skipInfoBtn: '#wlch-skip-info',
        inputArea: '.chat-input-area',
        textarea: '#wlch-textarea',
        counter: '#wlch-counter',
        sendBtn: '#wlch-send-btn',
        closeBtn: '.chat-close',
        notificationBadge: '.notification-badge',
        typingIndicator: '.typing-indicator'
      };

      // محدودیت‌ها
      this.maxChars = 500;

      // وضعیت داخلی
      this.connected = false;
      this.unreadCount = 0;
      this.isTyping = false;
      this.lastMessageId = null;
      this.messageQueue = new Set(); // برای جلوگیری از تکرار پیام
      this.isSending = false;

      // شروع
      this.init();
    }

    // ---------- عمومی ----------
    init() {
      this.cacheElements();
       // اضافه کردن کلاس موقعیت به container
      this.$container.addClass('position-bottom-left');
      this.bindUI();
      this.loadHistoryAndScroll(); // تغییر این خط
      this.showUserForms();
      this.initPusher();
      this.updateCounter();
      this.setConnectedStatus('connecting');
    }

    cacheElements() {
      this.$container = $(this.selectors.container);
      this.$messages = this.$container.find(this.selectors.messages);
      this.$toggle = this.$container.find(this.selectors.toggle);
      this.$widget = this.$container.find(this.selectors.widget);
      this.$userForm = this.$container.find(this.selectors.userForm);
      this.$phoneInput = this.$container.find(this.selectors.phoneInput);
      this.$nameInput = this.$container.find(this.selectors.nameInput);
      this.$saveInfoBtn = this.$container.find(this.selectors.saveInfoBtn);
      this.$skipInfoBtn = this.$container.find(this.selectors.skipInfoBtn);
      this.$inputArea = this.$container.find(this.selectors.inputArea);
      this.$textarea = this.$container.find(this.selectors.textarea);
      this.$counter = this.$container.find(this.selectors.counter);
      this.$sendBtn = this.$container.find(this.selectors.sendBtn);
      this.$closeBtn = this.$container.find(this.selectors.closeBtn);
      this.$notif = this.$container.find(this.selectors.notificationBadge);
      this.$typingIndicator = this.$container.find(this.selectors.typingIndicator);
    }

    // ---------- مدیریت باز کردن چت ----------
    openChat() {
    this.$container.removeClass('wp-live-chat-hidden');
    this.unreadCount = 0;
    this.updateNotificationBadge(0);
    
    // اگر تاریخچه بارگذاری نشده، بارگذاری کن
    if (!this.historyLoaded && !this.isHistoryLoading) {
        this.isHistoryLoading = true;
        this.loadHistoryAndScroll();
    } else {
        // فقط اسکرول کن
        setTimeout(() => {
        this.scrollToBottom(true, true);
        this.$textarea.focus();
        }, 100);
    }
    
    // فرستادن event به Pusher برای اطلاع ادمین
    this.sendChatOpenedEvent();
    }

    // ---------- مدیریت بستن چت ----------
    closeChat() {
    this.$container.addClass('wp-live-chat-hidden');
    this.sendChatClosedEvent();
    }

    // ---------- ارسال event باز شدن چت ----------
    sendChatOpenedEvent() {
    if (!this.pusher || !this.connected) return;
    
    try {
        const channel = this.pusher.channel('chat-' + this.sessionId);
        if (channel) {
        channel.trigger('client-chat-opened', {
            user_id: this.currentUser.id || 0,
            user_name: this.currentUser.name || 'کاربر',
            timestamp: new Date().toISOString()
        });
        }
    } catch (e) {
        console.log('Chat opened event not sent');
    }
    }

    // ---------- ارسال event بسته شدن چت ----------
    sendChatClosedEvent() {
    if (!this.pusher || !this.connected) return;
    
    try {
        const channel = this.pusher.channel('chat-' + this.sessionId);
        if (channel) {
        channel.trigger('client-chat-closed', {
            user_id: this.currentUser.id || 0,
            timestamp: new Date().toISOString()
        });
        }
    } catch (e) {
        console.log('Chat closed event not sent');
    }
    }

    // ---------- Pusher ----------
    initPusher() {
      if (!this.pusherKey || typeof Pusher === 'undefined') {
        this.setConnectedStatus('offline');
        this.showAlert('سرویس چت در حال حاضر در دسترس نیست', 'error');
        return;
      }
      
      try {
        Pusher.logToConsole = false;
        
        this.pusher = new Pusher(this.pusherKey, {
          cluster: this.pusherCluster || 'mt1',
          forceTLS: true
        });

        const channelName = 'chat-' + this.sessionId;
        const channel = this.pusher.subscribe(channelName);

        // مدیریت وضعیت اتصال
        this.pusher.connection.bind('state_change', (states) => {
          console.log('Pusher state:', states.current);
          if (states.current === 'connected') {
            this.setConnectedStatus('online');
            this.showAlert('اتصال برقرار شد', 'success', 3000);
          } else if (states.current === 'disconnected' || states.current === 'failed') {
            this.setConnectedStatus('offline');
          }
        });

        // دریافت پیام‌های جدید
        channel.bind('new-message', (payload) => {
          this.onIncomingMessage(payload);
        });

        // دریافت وضعیت تایپ کردن ادمین
        channel.bind('admin-typing', () => {
          this.showTypingIndicator();
        });

        channel.bind('admin-stopped-typing', () => {
          this.hideTypingIndicator();
        });

        // اطلاع‌رسانی‌های ادمین
        const adminChannel = this.pusher.subscribe('admin-notifications');
        adminChannel.bind('admin-connected', () => {
          this.showAlert('پشتیبان آنلاین شد', 'info', 3000);
        });

      } catch (err) {
        console.warn('Pusher init error', err);
        this.setConnectedStatus('offline');
        this.showAlert('خطا در اتصال به سرویس چت', 'error');
      }
    }

    // ---------- مدیریت پیام‌های ورودی ----------
    onIncomingMessage(payload) {
        console.log('Incoming message from Pusher:', payload);
        
        // بررسی payload معتبر
        if (!payload || (!payload.message && !payload.message_content)) {
            console.error('Invalid payload:', payload);
            return;
        }
        
        // دریافت متن پیام
        const messageText = (payload.message || payload.message_content || '').trim();
        if (!messageText) {
            console.error('Empty message:', payload);
            return;
        }
        
        // تولید ID منحصر به فرد برای پیام
        const messageId = payload.id || this.generateMessageId(messageText, payload.timestamp);
        
        // بررسی تکراری بودن بر اساس ID
        if (this.messageQueue.has(messageId)) {
            console.log('Duplicate message ignored (by ID):', messageId);
            return;
        }
        
        // بررسی تکراری بودن بر اساس محتوا و زمان (برای پیام‌های بدون ID)
        if (this.isDuplicateMessage(messageText, payload.timestamp)) {
            console.log('Duplicate message ignored (by content):', messageText.substring(0, 50));
            return;
        }
        
        // بررسی آیا این پیام جایگزین یک پیام optimistic است؟
        const optimisticId = this.findOptimisticIdForMessage(messageText);
        if (optimisticId) {
            console.log('Replacing optimistic message:', optimisticId, 'with real message:', messageId);
            
            // حذف پیام optimistic
            const $optimisticMessage = this.$messages.find(`[data-message-id="${optimisticId}"]`);
            if ($optimisticMessage.length) {
                // تغییر وضعیت پیام optimistic به ارسال شده
                $optimisticMessage.removeClass('sending').addClass('sent');
                $optimisticMessage.find('.sending-status').text('✅ ارسال شد');
                
                // بعد از 1 ثانیه fade out و اضافه کردن پیام واقعی
                setTimeout(() => {
                    $optimisticMessage.fadeOut(300, () => {
                        $optimisticMessage.remove();
                        this.messageQueue.delete(optimisticId);
                        
                        // اضافه کردن پیام واقعی
                        this.addMessageToChat({
                            ...payload,
                            id: messageId
                        });
                    });
                }, 1000);
            } else {
                // اگر پیام optimistic پیدا نشد، مستقیم اضافه کن
                this.addMessageToChat({
                    ...payload,
                    id: messageId
                });
            }
        } else {
            // پیام جدید از ادمین یا سیستم
            this.addMessageToChat({
                ...payload,
                id: messageId
            });
        }
    }

    // تابع جدید برای تولید ID منحصر به فرد
    generateMessageId(messageText, timestamp) {
        const timePart = timestamp ? new Date(timestamp).getTime() : Date.now();
        const textHash = this.hashCode(messageText);
        return `msg_${timePart}_${textHash}`;
    }

        // تابع hash برای متن
    hashCode(str) {
        let hash = 0;
        for (let i = 0; i < str.length; i++) {
            const char = str.charCodeAt(i);
            hash = ((hash << 5) - hash) + char;
            hash = hash & hash; // Convert to 32bit integer
        }
        return Math.abs(hash).toString(16).substring(0, 8);
    }

    // تابع بهبود یافته برای بررسی تکراری بودن پیام
    isDuplicateMessage(messageText, timestamp) {
        const searchText = messageText.trim();
        if (!searchText) return false;
        
        const messageTime = timestamp ? new Date(timestamp).getTime() : Date.now();
        const timeThreshold = 5000; // 5 ثانیه
        
        // بررسی در تاریخچه پیام‌ها
        for (const msg of this.messageHistory) {
            if (msg.text === searchText) {
                const msgTime = new Date(msg.timestamp).getTime();
                const timeDiff = Math.abs(messageTime - msgTime);
                
                // اگر همان پیام در 5 ثانیه اخیر بود، تکراری است
                if (timeDiff < timeThreshold) {
                    return true;
                }
            }
        }
        
        // بررسی در پیام‌های نمایش داده شده
        const $existingMessages = this.$messages.find('.message:not([data-message-id^="temp_"])');
        for (let i = 0; i < $existingMessages.length; i++) {
            const $msg = $($existingMessages[i]);
            const existingText = $msg.find('.message-content p').text().trim();
            
            if (existingText === searchText) {
                const existingTime = $msg.data('timestamp');
                if (existingTime) {
                    const existingTimeMs = new Date(existingTime).getTime();
                    const timeDiff = Math.abs(messageTime - existingTimeMs);
                    
                    if (timeDiff < timeThreshold) {
                        return true;
                    }
                } else {
                    // اگر timestamp نداشت، باز هم احتمال تکراری بودن هست
                    return true;
                }
            }
        }
        
        return false;
    }

    // تابع جدید برای افزودن پیام به چت
    addMessageToChat(payload) {
    if (payload.id) {
        this.messageQueue.add(payload.id);
    }
    
    // حذف پیام خوش‌آمد اولیه اگر وجود داشت
    if (this.$messages.find('.welcome-message').length) {
        this.$messages.find('.welcome-message').remove();
    }
    
    // پنهان کردن indicator تایپ
    this.hideTypingIndicator();
    
    // رندر پیام
    const $message = this._renderMessage(payload);
    
    // اضافه کردن به تاریخچه پیام‌ها
    this.messageHistory.push({
        id: payload.id,
        text: payload.message || payload.message_content,
        timestamp: payload.timestamp || new Date().toISOString()
    });
    
    // محدود کردن تاریخچه
    if (this.messageHistory.length > 50) {
        this.messageHistory.shift();
    }
    
    // اسکرول به پایین
    this.scrollToBottom();
    
    // اگر پنل بسته است، شمارنده را افزایش بده
    const wasHidden = this.$container.hasClass('wp-live-chat-hidden');
    if (wasHidden && payload.type === 'admin') {
        this.unreadCount++;
        this.updateNotificationBadge(this.unreadCount);
        
        // نمایش نوتیفیکیشن برای پیام جدید
        if ('Notification' in window && Notification.permission === 'granted') {
        new Notification('پیام جدید از پشتیبان', {
            body: payload.message || payload.message_content,
            icon: '/wp-content/plugins/wp-live-chat/assets/images/icon.png'
        });
        }
    }
    
    return $message;
    }

    // تابع بهبود یافته برای پیدا کردن پیام optimistic
    findOptimisticIdForMessage(messageText) {
        const searchText = messageText.trim();
        let foundId = null;
        
        this.$messages.find('.message[data-message-id^="temp_"]').each(function() {
            const $msg = $(this);
            const msgText = $msg.find('.message-content p').text()
                .replace('⏳ در حال ارسال...', '')
                .replace('✅ ارسال شد', '')
                .replace('✅ ارسال شد (در انتظار دریافت از سرور...)', '')
                .trim();
            
            if (msgText === searchText) {
                foundId = $msg.data('message-id');
                return false; // break loop
            }
        });
        
        return foundId;
    }

    // اصلاح appendMessage برای اسکرول
    appendMessage(data) {
    // بررسی تکراری نبودن پیام
    if (data.id && this.messageQueue.has(data.id)) {
        return false;
    }
    
    if (data.id) {
        this.messageQueue.add(data.id);
    }
    
    // حذف پیام خوش‌آمد اولیه اگر وجود داشت
    if (this.$messages.find('.welcome-message').length) {
        this.$messages.find('.welcome-message').remove();
    }
    
    // پنهان کردن indicator تایپ
    this.hideTypingIndicator();
    
    // رندر پیام
    const $message = this._renderMessage(data);
    
    // اسکرول به پایین
    this.scrollToBottom();
    
    return true;
    }

    // در تابع _renderMessage برای انیمیشن‌های مختلف
    _renderMessage(entry) {
    if (!this.$messages) return null;
    
    const defaults = {
        id: '',
        message: '',
        user_name: 'کاربر',
        timestamp: new Date().toISOString(),
        type: 'user',
        status: 'sent'
    };
    
    const data = { ...defaults, ...entry };
    
    const time = this._formatTime(data.timestamp);
    let klass = 'user-message';
    let sender = data.user_name;
    let statusIcon = '';
    let animationClass = '';
    
    // تعیین نوع پیام و آیکون
    switch(data.type) {
        case 'admin':
        klass = 'admin-message';
        sender = '👨‍💼 پشتیبان';
        animationClass = 'slide-in-left';
        break;
        case 'system':
        klass = 'system-message';
        sender = '⚙️ سیستم';
        animationClass = 'slide-in-left';
        break;
        case 'user':
        sender = '👤 ' + sender;
        animationClass = 'slide-in-right';
        break;
    }
    
    // تعیین آیکون وضعیت
    switch(data.status) {
        case 'sending':
        statusIcon = '<span class="message-status sending">⏳</span>';
        klass += ' sending';
        break;
        case 'sent':
        statusIcon = '<span class="message-status sent">✓</span>';
        klass += ' sent';
        break;
        case 'delivered':
        statusIcon = '<span class="message-status delivered">✓✓</span>';
        break;
        case 'read':
        statusIcon = '<span class="message-status read">👁️</span>';
        break;
        case 'error':
        statusIcon = '<span class="message-status error">❌</span>';
        klass += ' error';
        break;
    }
    
    // اضافه کردن emoji بر اساس محتوا
    let messageContent = this._escapeHtml(data.message || data.message_content || '');
    messageContent = this._addEmojis(messageContent);
    messageContent = this._autoLink(messageContent);
    
    const $item = $(`
        <div class="message ${klass} ${animationClass}" data-message-id="${this._escapeAttr(data.id)}" data-timestamp="${this._escapeAttr(data.timestamp)}">
        <div class="message-header">
            <div class="message-sender">${sender}</div>
            <div class="message-time">${time} ${statusIcon}</div>
        </div>
        <div class="message-content">
            <p>${messageContent}</p>
        </div>
        </div>
    `);
    
    $item.hide().appendTo(this.$messages).fadeIn(300);
    
    // حذف کلاس انیمیشن بعد از اجرا
    setTimeout(() => {
        $item.removeClass(animationClass);
    }, 300);
    
    return $item;
    }

        // تابع جدید برای تبدیل لینک‌ها
    _autoLink(text) {
    const urlRegex = /(https?:\/\/[^\s]+)/g;
    return text.replace(urlRegex, function(url) {
        return '<a href="' + url + '" target="_blank" rel="noopener noreferrer" style="color: #007cba; text-decoration: underline;">' + url + '</a>';
    });
    }


    sendMessage(text) {
    const self = this;
    
    if (!text || !text.trim()) return;
    if (this.isSending) {
        this.showAlert('لطفاً صبر کنید...', 'info', 2000);
        return;
    }
    
    const originalText = text.trim();
    const messageId = 'temp_' + Date.now() + '_' + this.hashCode(originalText);
    
    // بررسی اگر همین پیام قبلاً ارسال شده
    if (this.messageQueue.has(messageId)) {
        this.showAlert('این پیام قبلاً ارسال شده است', 'info', 3000);
        return;
    }
    
    // تنظیم حالت ارسال
    this.isSending = true;
    this.$sendBtn.prop('disabled', true).html('<span class="send-icon">⏳</span> در حال ارسال...');
    
    // نمایش پیام به صورت optimistic
    const optimisticMessage = {
        id: messageId,
        message: originalText,
        user_name: this.currentUser.name || this.currentUser.display_name || 'شما',
        timestamp: new Date().toISOString(),
        type: 'user',
        status: 'sending'
    };
    
    const $optimisticMessage = this._renderMessage(optimisticMessage);
    this.messageQueue.add(messageId);
    
    // اضافه کردن به تاریخچه برای جلوگیری از تکرار
    this.messageHistory.push({
        id: messageId,
        text: originalText,
        timestamp: optimisticMessage.timestamp
    });
    
    // پاک کردن textarea
    this.$textarea.val('');
    this.updateCounter();
    this.scrollToBottom();
    
    // ارسال به سرور
    $.ajax({
        url: this.ajaxurl,
        type: 'POST',
        data: {
        action: 'send_chat_message',
        nonce: this.nonce,
        session_id: this.sessionId,
        message: originalText,
        user_name: this.currentUser.name || this.currentUser.display_name || '',
        user_id: this.currentUser.id || 0,
        temp_id: messageId // ارسال temp_id برای تطبیق در سرور
        },
        dataType: 'json',
        timeout: 10000
    })
    .done(function(response) {
        if (response && response.success) {
        // نمایش موفقیت
        self.showAlert('پیام شما با موفقیت ارسال شد', 'success', 3000);
        
        // تغییر وضعیت پیام optimistic
        if ($optimisticMessage) {
            $optimisticMessage.removeClass('sending').addClass('sent');
            $optimisticMessage.find('.message-status').text('✓').removeClass('sending').addClass('sent');
            $optimisticMessage.find('.sending-status').remove();
        }
        
        // اگر سرور message_id برگرداند، آن را ذخیره کن
        if (response.data && response.data.message_id) {
            const realMessageId = response.data.message_id;
            
            // حذف temp_id و اضافه کردن real_id
            self.messageQueue.delete(messageId);
            self.messageQueue.add(realMessageId);
            
            // به‌روزرسانی تاریخچه
            const msgIndex = self.messageHistory.findIndex(msg => msg.id === messageId);
            if (msgIndex !== -1) {
            self.messageHistory[msgIndex].id = realMessageId;
            }
            
            // به‌روزرسانی attribute در DOM
            if ($optimisticMessage) {
            $optimisticMessage.attr('data-message-id', realMessageId);
            }
        }
        
        } else {
        self.handleSendError($optimisticMessage, messageId, response ? response.data : 'خطا در ارسال پیام');
        }
    })
    .fail(function(jqXHR, textStatus, errorThrown) {
        self.handleSendError($optimisticMessage, messageId, 'خطا در ارتباط با سرور');
        console.error('Send message failed:', textStatus, errorThrown);
    })
    .always(function() {
        self.isSending = false;
        self.$sendBtn.prop('disabled', false).html('<span class="send-icon">✉️</span> ارسال');
    });
    }


    handleSendError($optimisticMessage, optimisticId, errorMessage) {
      // تغییر استایل پیام optimistic به خطا
      if ($optimisticMessage) {
        $optimisticMessage.addClass('message-error');
        $optimisticMessage.find('.message-content p').append(
          '<small style="display:block; color:#dc3232; margin-top:5px; font-style:italic;">⚠️ ' + errorMessage + '</small>'
        );
      }
      
      // حذف از صف
      this.messageQueue.delete(optimisticId);
      
      // نمایش خطا
      this.showAlert('خطا در ارسال پیام: ' + errorMessage, 'error', 5000);
      
      // فعال کردن دوباره textarea
      this.$textarea.focus();
    }

    // ---------- مدیریت تایپ کردن ----------
    sendTypingEvent(status) {
      if (!this.pusher || !this.connected) return;
      
      try {
        const channel = this.pusher.channel('chat-' + this.sessionId);
        if (channel) {
          if (status === 'typing') {
            channel.trigger('client-user-typing', {
              user_id: this.currentUser.id || 0,
              user_name: this.currentUser.name || 'کاربر'
            });
          } else if (status === 'stopped') {
            channel.trigger('client-user-stopped-typing', {
              user_id: this.currentUser.id || 0
            });
          }
        }
      } catch (e) {
        console.log('Typing event not sent (might need client events enabled)');
      }
    }

    showTypingIndicator() {
      if (this.$typingIndicator) {
        this.$typingIndicator.stop(true, true).fadeIn(300);
        this.scrollToBottom();
      }
    }

    hideTypingIndicator() {
      if (this.$typingIndicator) {
        this.$typingIndicator.stop(true, true).fadeOut(300);
      }
    }

    // ---------- مدیریت فرم کاربر ----------
    showUserForms() {
      if (this.currentUser && (this.currentUser.phone || this.currentUser.name)) {
        this.showInputArea(true);
        this.showUserInfoForm(false);
        this.showAlert('خوش آمدید ' + (this.currentUser.name || 'کاربر'), 'info', 3000);
        return;
      }
      
      this.showInputArea(false);
      this.showUserInfoForm(true);
    }

    showInputArea(visible) {
      if (!this.$inputArea) return;
      if (visible) {
        this.$inputArea.slideDown(300);
        setTimeout(() => this.$textarea.focus(), 350);
      } else {
        this.$inputArea.slideUp(300);
      }
    }

    showUserInfoForm(visible) {
      if (!this.$userForm) return;
      if (visible) {
        this.$userForm.slideDown(400);
        setTimeout(() => this.$phoneInput.focus(), 450);
      } else {
        this.$userForm.slideUp(300);
      }
    }

    // ---------- بارگذاری تاریخچه ----------
    loadHistory() {
      const self = this;
      
      this.$messages.html(`
        <div class="welcome-message">
          <p>${this._escapeHtml(this.strings.welcome || 'سلام! به پشتیبانی آنلاین خوش آمدید.')}</p>
        </div>
        <div class="loading-history" style="text-align:center; padding:20px; color:#666;">
          <div class="spinner"></div>
          <p>در حال بارگذاری تاریخچه...</p>
        </div>
      `);
      
      $.ajax({
        url: this.ajaxurl,
        type: 'POST',
        data: {
          action: 'get_chat_history',
          nonce: this.nonce,
          session_id: this.sessionId
        },
        dataType: 'json',
        timeout: 10000
      })
      .done(function(response) {
        self.$messages.find('.loading-history').remove();
        
        if (response && response.success && Array.isArray(response.data)) {
          if (response.data.length === 0) {
            // پیام خوش‌آمد باقی می‌ماند
          } else {
            self.$messages.find('.welcome-message').remove();
            
            response.data.forEach(function(message) {
              // جلوگیری از تکرار در تاریخچه
              if (!self.messageQueue.has(message.id)) {
                self.appendMessage({
                  id: message.id,
                  message: message.message_content,
                  user_name: message.user_name,
                  timestamp: message.created_at,
                  type: message.message_type
                });
              }
            });
          }
        } else {
          self.showAlert('خطا در بارگذاری تاریخچه', 'error');
        }
      })
      .fail(function() {
        self.$messages.find('.loading-history').remove();
        self.showAlert('خطا در بارگذاری تاریخچه', 'error');
      });
    }

    // ---------- UI Events ----------
    bindUI() {
    const self = this;

    // --- event handler اصلی برای toggle ---
    $(document).on('click', this.selectors.toggle, function() {
        const isHidden = self.$container.hasClass('wp-live-chat-hidden');
        if (isHidden) {
        self.$container.removeClass('wp-live-chat-hidden');
        self.unreadCount = 0;
        self.updateNotificationBadge(0);
        
        // بارگذاری تاریخچه و اسکرول
        setTimeout(() => {
            // فقط اگر تاریخچه بارگذاری نشده، بارگذاری کن
            if (self.messageHistory.length === 0) {
            self.loadHistoryAndScroll();
            } else {
            // فقط اسکرول کن
            self.scrollToBottom(true);
            }
            self.$textarea.focus();
        }, 100);
        } else {
        self.$container.addClass('wp-live-chat-hidden');
        }
    });

    // --- close button ---
    $(document).on('click', this.selectors.closeBtn, function() {
        self.$container.addClass('wp-live-chat-hidden');
    });

    // --- focus روی textarea وقتی روی widget کلیک می‌شود ---
    $(document).on('click', this.selectors.container + ' .chat-widget', function(e) {
        // فقط اگر روی پیام‌ها یا header کلیک نشده باشد
        if (!$(e.target).closest('.chat-messages, .chat-header').length) {
        setTimeout(() => self.$textarea.focus(), 50);
        }
    });

    // save info
    $(document).on('click', this.selectors.saveInfoBtn, function() {
        const phone = self.$phoneInput.val().trim();
        const name = self.$nameInput.val().trim();
        
        if (!phone && !name) {
        self.showAlert('لطفاً شماره یا نام را وارد کنید', 'error', 3000);
        return;
        }
        
        $(this).prop('disabled', true).html('<span class="btn-icon">⏳</span> در حال ارسال...');
        
        $.ajax({
        url: self.ajaxurl,
        type: 'POST',
        data: {
            action: 'save_user_info',
            nonce: self.nonce,
            session_id: self.sessionId,
            phone: phone,
            name: name,
            company: ''
        },
        dataType: 'json',
        timeout: 10000
        })
        .done(function(response) {
        if (response && response.success) {
            self.currentUser.phone = phone || self.currentUser.phone;
            self.currentUser.name = name || self.currentUser.name;
            
            self.showUserForms();
            self.showInputArea(true);
            
            self.showAlert('اطلاعات شما با موفقیت ثبت شد', 'success', 3000);
            
            // ارسال پیام خوش‌آمدگویی
            $.post(self.ajaxurl, {
            action: 'send_welcome_message',
            nonce: self.nonce,
            session_id: self.sessionId,
            user_name: self.currentUser.name
            });
            
        } else {
            self.showAlert('خطا در ذخیره اطلاعات', 'error', 4000);
        }
        })
        .fail(function() {
        self.showAlert('خطا در ارتباط با سرور', 'error', 4000);
        })
        .always(function() {
        $(this).prop('disabled', false).html('<span class="btn-icon">✓</span> ارسال اطلاعات');
        });
    });

    // skip info
    $(document).on('click', this.selectors.skipInfoBtn, function() {
        self.currentUser = self.currentUser || {};
        self.currentUser.name = self.currentUser.name || ('کاربر_' + Math.floor(Math.random()*9000 + 1000));
        self.showUserForms();
        self.showInputArea(true);
        self.showAlert('اطلاعات را بعداً می‌توانید تکمیل کنید', 'info', 3000);
    });

    // textarea events
    $(document).on('input', this.selectors.textarea, function() {
        self.updateCounter();
        
        // مدیریت event تایپ کردن
        const text = $(this).val().trim();
        if (!self.isTyping && text.length > 0) {
        self.isTyping = true;
        self.sendTypingEvent('typing');
        } else if (self.isTyping && text.length === 0) {
        self.isTyping = false;
        self.sendTypingEvent('stopped');
        }
    });
    
    // debounce برای تایپ کردن
    let typingTimeout;
    $(document).on('keyup', this.selectors.textarea, function() {
        clearTimeout(typingTimeout);
        typingTimeout = setTimeout(function() {
        if (self.isTyping) {
            self.isTyping = false;
            self.sendTypingEvent('stopped');
        }
        }, 1000);
    });

    // send button
    $(document).on('click', this.selectors.sendBtn, function() {
        const text = self.$textarea.val().trim();
        self.sendMessage(text);
    });

    // enter to send
    $(document).on('keydown', this.selectors.textarea, function(e) {
        if (e.key === 'Enter' && !e.shiftKey) {
        e.preventDefault();
        const text = $(this).val().trim();
        if (text && !self.$sendBtn.prop('disabled')) {
            self.sendMessage(text);
        }
        }
    });

    // auto-focus روی textarea وقتی input area نمایش داده می‌شود
    $(document).on('animationend', this.selectors.inputArea, function() {
        if ($(this).is(':visible')) {
        self.$textarea.focus();
        }
    });
    }

    // ---------- Alert System ----------
    showAlert(message, type = 'info', duration = 5000) {
      // حذف alertهای قبلی
      $('.alert-message').remove();
      
      const icons = {
        success: '✅',
        error: '❌',
        info: 'ℹ️',
        warning: '⚠️'
      };
      
      const $alert = $(`
        <div class="alert-message alert-${type}">
          <span class="alert-icon">${icons[type] || icons.info}</span>
          <div class="alert-content">
            <div class="alert-title">${type === 'success' ? 'موفقیت' : type === 'error' ? 'خطا' : 'توجه'}</div>
            <div class="alert-text">${this._escapeHtml(message)}</div>
          </div>
          <button class="alert-close">&times;</button>
        </div>
      `);
      
      $('body').append($alert);
      
      // بستن alert با کلیک
      $alert.find('.alert-close').on('click', function() {
        $alert.fadeOut(300, function() {
          $(this).remove();
        });
      });
      
      // بستن خودکار
      if (duration > 0) {
        setTimeout(function() {
          if ($alert.is(':visible')) {
            $alert.fadeOut(300, function() {
              $(this).remove();
            });
          }
        }, duration);
      }
      
      return $alert;
    }

    // ---------- Utility Methods ----------
    setConnectedStatus(state) {
      this.connected = (state === 'online');
      const $dot = this.$container.find('.status-dot');
      const $text = this.$container.find('.status-text');
      
      $dot.removeClass('connecting online offline').addClass(state);
      $text.text({
        'online': 'آنلاین',
        'offline': 'آفلاین',
        'connecting': 'در حال اتصال...'
      }[state] || state);
    }

    updateCounter() {
      if (!this.$textarea || !this.$counter || !this.$sendBtn) return;
      
      const val = this.$textarea.val() || '';
      const len = val.length;
      const remaining = this.maxChars - len;
      
      this.$counter.text(`${len}/${this.maxChars}`);
      
      if (len === 0) {
        this.$sendBtn.prop('disabled', true);
        this.$counter.removeClass('exceeded warning');
      } else if (len > this.maxChars) {
        this.$sendBtn.prop('disabled', true);
        this.$counter.addClass('exceeded');
      } else {
        this.$sendBtn.prop('disabled', false);
        this.$counter.removeClass('exceeded');
        
        // هشدار نزدیک شدن به محدودیت
        if (remaining <= 50) {
          this.$counter.addClass('warning');
        } else {
          this.$counter.removeClass('warning');
        }
      }
    }

    updateNotificationBadge(count) {
      this.unreadCount = count;
      if (!this.$notif) return;
      
      if (!count || count <= 0) {
        this.$notif.hide();
      } else {
        this.$notif.text(count > 9 ? '9+' : count).show();
      }
    }

    // تابع بهبود یافته اسکرول
    scrollToBottom(instant = false, force = false) {
    if (!this.$messages || !this.$messages.length) return;
    
    const messagesHeight = this.$messages[0].scrollHeight;
    const containerHeight = this.$messages.height();
    
    // فقط اگر نزدیک پایین هستیم یا force true است، اسکرول کنیم
    if (!force) {
        const currentScroll = this.$messages.scrollTop();
        const distanceFromBottom = messagesHeight - (currentScroll + containerHeight);
        
        // اگر بیشتر از 200px از پایین فاصله داریم و force نیست، اسکرول نکن
        if (distanceFromBottom > 200) {
        return;
        }
    }
    
    if (instant) {
        this.$messages.scrollTop(messagesHeight);
    } else {
        this.$messages.stop().animate({
        scrollTop: messagesHeight
        }, 300);
    }
    }

    // تابع بهبود یافته برای بارگذاری تاریخچه
    loadHistoryAndScroll(forceReload = false) {
    const self = this;
    
    // اگر قبلاً بارگذاری شده و forceReload false است، فقط اسکرول کن
    if (self.messageHistory.length > 0 && !forceReload) {
        self.scrollToBottom(true);
        return;
    }
    
    // نمایش loading فقط اگر پیامی نمایش داده نشده
    if (self.$messages.find('.message').length === 0) {
        self.$messages.html(`
        <div class="welcome-message">
            <p>${self._escapeHtml(self.strings.welcome || 'سلام! به پشتیبانی آنلاین خوش آمدید.')}</p>
        </div>
        <div class="loading-history" style="text-align:center; padding:20px; color:#666;">
            <div class="spinner"></div>
            <p>در حال بارگذاری تاریخچه...</p>
        </div>
        `);
    }
    
    $.ajax({
        url: self.ajaxurl,
        type: 'POST',
        data: {
        action: 'get_chat_history',
        nonce: self.nonce,
        session_id: self.sessionId
        },
        dataType: 'json',
        timeout: 10000
    })
    .done(function(response) {
        self.$messages.find('.loading-history').remove();
        
        if (response && response.success && Array.isArray(response.data)) {
        // پاک کردن فقط اگر تاریخچه جدیدی داریم
        if (response.data.length > 0 && forceReload) {
            self.$messages.find('.welcome-message').remove();
            self.$messages.find('.message').remove();
            self.messageQueue.clear();
            self.messageHistory = [];
        }
        
        if (response.data.length === 0) {
            // فقط اسکرول کن
            setTimeout(() => self.scrollToBottom(true), 100);
        } else {
            let newMessagesAdded = 0;
            
            response.data.forEach(function(message) {
            // جلوگیری از تکرار در تاریخچه
            if (!self.messageQueue.has(message.id)) {
                self.appendMessage({
                id: message.id,
                message: message.message_content,
                user_name: message.user_name,
                timestamp: message.created_at,
                type: message.message_type
                });
                
                // اضافه کردن به تاریخچه
                self.messageHistory.push({
                id: message.id,
                text: message.message_content,
                timestamp: message.created_at
                });
                
                newMessagesAdded++;
            }
            });
            
            // فقط اگر پیام جدیدی اضافه شد، اسکرول کن
            if (newMessagesAdded > 0) {
            setTimeout(() => self.scrollToBottom(true), 200);
            } else {
            self.scrollToBottom(true);
            }
        }
        } else {
        self.showAlert('خطا در بارگذاری تاریخچه', 'error');
        setTimeout(() => self.scrollToBottom(true), 100);
        }
    })
    .fail(function() {
        self.$messages.find('.loading-history').remove();
        self.showAlert('خطا در بارگذاری تاریخچه', 'error');
        setTimeout(() => self.scrollToBottom(true), 100);
    });
    }

    // ---------- Helper Methods ----------
    _formatTime(ts) {
      if (!ts) return '';
      
      try {
        let date;
        if (typeof ts === 'string') {
          // تبدیل MySQL datetime یا ISO string
          if (ts.includes('T')) {
            date = new Date(ts);
          } else {
            date = new Date(ts.replace(' ', 'T'));
          }
        } else {
          date = new Date(ts);
        }
        
        if (!isNaN(date.getTime())) {
          return date.toLocaleTimeString('fa-IR', {
            hour: '2-digit',
            minute: '2-digit',
            hour12: false
          });
        }
      } catch (e) {}
      
      return ts;
    }

    _addEmojis(text) {
      const emojiMap = {
        ':)': '😊',
        ':-)': '😊',
        ':(': '😞',
        ':-(': '😞',
        ':D': '😃',
        ':-D': '😃',
        ';)': '😉',
        ';-)': '😉',
        ':P': '😛',
        ':-P': '😛',
        ':O': '😮',
        ':-O': '😮',
        ':*': '😘',
        ':-*': '😘',
        '<3': '❤️',
        ':heart:': '❤️',
        ':like:': '👍',
        ':thumbsup:': '👍',
        ':thanks:': '🙏',
        ':ok:': '👌',
        '?:': '❓'
      };
      
      let result = text;
      for (const [key, emoji] of Object.entries(emojiMap)) {
        result = result.replace(new RegExp(this._escapeRegex(key), 'g'), emoji);
      }
      
      return result;
    }

    _escapeRegex(string) {
      return string.replace(/[.*+?^${}()|[\]\\]/g, '\\$&');
    }

    _escapeHtml(s) {
      return $('<div/>').text(s || '').html();
    }

    _escapeAttr(s) {
      return String(s || '').replace(/["'<>]/g, '');
    }

    _uuid() {
      return 'xxxxxxxx'.replace(/[x]/g, function() {
        return (Math.random() * 16 | 0).toString(16);
      });
    }
  }

  // Initialize
  $(function() {
    try {
      const frontend = new WPLiveChatFrontend(global.wpLiveChat || {});
      global._wpLiveChatFrontend = frontend;
      
      // اضافه کردن استایل برای spinner
      $('<style>')
        .text(`
          .loading-history .spinner {
            display: inline-block;
            width: 40px;
            height: 40px;
            border: 3px solid #f3f3f3;
            border-top: 3px solid #007cba;
            border-radius: 50%;
            animation: spin 1s linear infinite;
          }
          @keyframes spin {
            0% { transform: rotate(0deg); }
            100% { transform: rotate(360deg); }
          }
          .message-error {
            opacity: 0.7;
            border-color: #dc3232 !important;
          }
        `)
        .appendTo('head');
        
    } catch (err) {
      console.error('WPLiveChatFrontend init error', err);
    }
  });




})(window, jQuery);