(function($){
    if (typeof wpLiveChatAdmin === 'undefined') return;
    const ajaxurl = wpLiveChatAdmin.ajaxurl;
    const nonce = wpLiveChatAdmin.nonce;
    const pusherKey = window.wpLiveChat && window.wpLiveChat.pusherKey;
    const pusherCluster = window.wpLiveChat && window.wpLiveChat.pusherCluster;

    let currentSession = null;
    let pusherInstance = null;
    let sessionChannel = null;

    function loadSessions(){
        $('#wlch-sessions-list').text('در حال بارگذاری...');
        $.post(ajaxurl, { action: 'wp_live_chat_get_sessions', nonce: nonce }, function(res){
            if (!res.success) {
                $('#wlch-sessions-list').text('خطا در بارگذاری جلسات');
                return;
            }
            const rows = res.data;
            if (!rows.length) { $('#wlch-sessions-list').html('<div>جلسه‌ای یافت نشد</div>'); return; }
            const $list = $('<ul/>');
            rows.forEach(function(s){
                const li = $('<li class="wlch-session-item" data-session="'+ s.session_id +'"></li>');
                li.text((s.user_name || 'کاربر') + ' — ' + (s.user_phone || '') + ' — ' + s.last_activity);
                li.on('click', function(){ openSession(s.session_id); });
                $list.append(li);
            });
            $('#wlch-sessions-list').empty().append($list);
        });
    }

    function openSession(sessionId){
        currentSession = sessionId;
        $('#wlch-chat-header').text('گفتگو: ' + sessionId);
        $('#wlch-messages').html('<div>در حال بارگذاری پیام‌ها...</div>');
        // unsubscribe از کانال قبلی
        if (pusherInstance && sessionChannel) {
            try { pusherInstance.unsubscribe('chat-' + sessionChannel.sessionId); } catch(e){}
            sessionChannel = null;
        }
        // subscribe به کانال جدید مخصوص این سشن
        if (pusherInstance) {
            sessionChannel = pusherInstance.subscribe('chat-' + sessionId);
            sessionChannel.bind('new-message', function(data){
                $('#wlch-messages').append(`<div class="wlch-msg"><strong>${escapeHtml(data.user_name)}:</strong> ${escapeHtml(data.message)}</div>`);
                $('#wlch-messages').scrollTop($('#wlch-messages')[0].scrollHeight);
            });
            // store for unsubscribe (helpful)
            sessionChannel.sessionId = sessionId;
        }

        $.post(ajaxurl, { action: 'wp_live_chat_get_messages', nonce: nonce, session_id: sessionId }, function(res){
            if (!res.success) {
                $('#wlch-messages').text('خطا در بارگذاری پیام‌ها');
                return;
            }
            $('#wlch-messages').empty();
            res.data.forEach(function(m){
                $('#wlch-messages').append(`<div class="wlch-msg"><strong>${escapeHtml(m.user_name)}:</strong> ${escapeHtml(m.message_content)}</div>`);
            });
            $('#wlch-messages').scrollTop($('#wlch-messages')[0].scrollHeight);
        });
    }

    function escapeHtml(s){ return $('<div/>').text(s).html(); }

    $(document).on('click', '#wlch-send-btn', function(){
        const val = $('#wlch-admin-message').val().trim();
        if (!currentSession) return alert('یک جلسه انتخاب کنید');
        if (!val) return;
        $.post(ajaxurl, { action: 'wp_live_chat_admin_send', nonce: nonce, session_id: currentSession, message: val }, function(res){
            if (!res.success) return alert('خطا در ارسال');
            $('#wlch-admin-message').val('');
            // پیام admin از سرور به کانال chat-{session} منتشر می‌شود و کاربر آن را دریافت می‌کند
            // همچنین admin خود می‌تواند آن را در UI ببیند (در صورت تمایل اضافه شود)
        });
    });

    function initPusher() {
        if (!pusherKey) return;
        try {
            pusherInstance = new Pusher(pusherKey, { cluster: pusherCluster });
            const adminChannel = pusherInstance.subscribe('admin-chat-channel');
            adminChannel.bind('message-sent', function(data){
                // refresh sessions list or highlight
                loadSessions();
            });
        } catch (e) {
            console && console.warn && console.warn('Pusher admin init error', e);
        }
    }

    $(function(){
        loadSessions();
        initPusher();
    });

})(jQuery);
