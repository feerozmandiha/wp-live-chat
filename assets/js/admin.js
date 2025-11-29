(function($) {
    'use strict';

    class WPLiveChatAdmin {
        constructor() {
            this.config = window.wpLiveChatAdmin || {};
            this.init();
        }

        init() {
            this.bindEvents();
        }

        bindEvents() {
            $('#test-pusher-connection').on('click', () => this.testConnection());
            
            // اعتبارسنجی فیلدها
            $('input[type="text"], input[type="password"]').on('blur', function() {
                $(this).trigger('validate');
            });
        }

        async testConnection() {
            const $button = $('#test-pusher-connection');
            const $result = $('#test-result');
            
            $button.prop('disabled', true).text(this.config.testing);
            $result.removeClass('success error').html('').show();

            try {
                const response = await $.ajax({
                    url: this.config.ajaxurl,
                    type: 'POST',
                    data: {
                        action: 'test_pusher_connection',
                        nonce: this.config.nonce
                    },
                    dataType: 'json'
                });

                if (response.success) {
                    $result.addClass('success').html(`
                        <div class="notice notice-success inline">
                            <p>${response.data.message}</p>
                        </div>
                    `);
                } else {
                    throw new Error(response.data.message);
                }

            } catch (error) {
                $result.addClass('error').html(`
                    <div class="notice notice-error inline">
                        <p>${error.message || this.config.error}</p>
                    </div>
                `);
            } finally {
                $button.prop('disabled', false).text('تست اتصال به Pusher');
            }
        }
    }

    // راه‌اندازی زمانی که DOM آماده است
    $(document).ready(function() {
        window.wpLiveChatAdminInstance = new WPLiveChatAdmin();
    });

})(jQuery);