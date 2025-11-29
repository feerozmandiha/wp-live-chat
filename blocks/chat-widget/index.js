import { registerBlockType } from '@wordpress/blocks';
import { 
    useBlockProps, 
    InspectorControls,
    ColorPalette,
    BlockControls,
    AlignmentToolbar 
} from '@wordpress/block-editor';
import { 
    PanelBody, 
    SelectControl, 
    TextControl, 
    ToggleControl,
    RangeControl,
    __experimentalInputControl as InputControl
} from '@wordpress/components';
import { __ } from '@wordpress/i18n';
import { useState, useEffect } from '@wordpress/element';

import './editor.scss';
import './style.scss';

const Edit = ({ attributes, setAttributes }) => {
    const { 
        position, 
        title, 
        subtitle, 
        onlineStatus, 
        backgroundColor, 
        textColor,
        animation,
        showContactButtons,
        whatsappNumber,
        phoneNumber
    } = attributes;

    const blockProps = useBlockProps({
        className: `wp-live-chat-widget-editor position-${position} animation-${animation}`
    });

    const [isOnline, setIsOnline] = useState(onlineStatus);

    useEffect(() => {
        setAttributes({ onlineStatus: isOnline });
    }, [isOnline]);

    const positionOptions = [
        { label: 'پایین سمت راست', value: 'bottom-right' },
        { label: 'پایین سمت چپ', value: 'bottom-left' },
        { label: 'بالا سمت راست', value: 'top-right' },
        { label: 'بالا سمت چپ', value: 'top-left' }
    ];

    const animationOptions = [
        { label: 'بدون انیمیشن', value: 'none' },
        { label: 'پرش', value: 'bounce' },
        { label: 'تپش', value: 'pulse' },
        { label: 'شناور', value: 'float' }
    ];

    return (
        <>
            <BlockControls>
                <AlignmentToolbar
                    value={position}
                    onChange={(value) => setAttributes({ position: value || 'bottom-right' })}
                />
            </BlockControls>

            <InspectorControls>
                <PanelBody title={__('تنظیمات عمومی', 'wp-live-chat')} initialOpen={true}>
                    <SelectControl
                        label={__('موقعیت ویجت', 'wp-live-chat')}
                        value={position}
                        options={positionOptions}
                        onChange={(value) => setAttributes({ position: value })}
                    />
                    
                    <SelectControl
                        label={__('انیمیشن', 'wp-live-chat')}
                        value={animation}
                        options={animationOptions}
                        onChange={(value) => setAttributes({ animation: value })}
                    />
                    
                    <TextControl
                        label={__('عنوان', 'wp-live-chat')}
                        value={title}
                        onChange={(value) => setAttributes({ title: value })}
                        help={__('عنوانی که در هدر ویجت نمایش داده می‌شود', 'wp-live-chat')}
                    />
                    
                    <TextControl
                        label={__('زیرعنوان', 'wp-live-chat')}
                        value={subtitle}
                        onChange={(value) => setAttributes({ subtitle: value })}
                        help={__('متن توضیحی زیر عنوان', 'wp-live-chat')}
                    />
                    
                    <ToggleControl
                        label={__('وضعیت آنلاین', 'wp-live-chat')}
                        checked={isOnline}
                        onChange={setIsOnline}
                        help={__('نمایش وضعیت آنلاین/آفلاین', 'wp-live-chat')}
                    />
                </PanelBody>

                <PanelBody title={__('تنظیمات ظاهری', 'wp-live-chat')} initialOpen={false}>
                    <div className="color-controls">
                        <p><strong>{__('رنگ زمینه', 'wp-live-chat')}</strong></p>
                        <ColorPalette
                            value={backgroundColor}
                            onChange={(value) => setAttributes({ backgroundColor: value })}
                        />
                        
                        <p><strong>{__('رنگ متن', 'wp-live-chat')}</strong></p>
                        <ColorPalette
                            value={textColor}
                            onChange={(value) => setAttributes({ textColor: value })}
                        />
                    </div>
                </PanelBody>

                <PanelBody title={__('راه‌های ارتباطی', 'wp-live-chat')} initialOpen={false}>
                    <ToggleControl
                        label={__('نمایش دکمه‌های ارتباطی', 'wp-live-chat')}
                        checked={showContactButtons}
                        onChange={(value) => setAttributes({ showContactButtons: value })}
                        help={__('نمایش دکمه‌های واتساپ و تماس در پایین چت', 'wp-live-chat')}
                    />
                    
                    {showContactButtons && (
                        <>
                            <TextControl
                                label={__('شماره واتساپ', 'wp-live-chat')}
                                value={whatsappNumber}
                                onChange={(value) => setAttributes({ whatsappNumber: value })}
                                help={__('شماره واتساپ با کد کشور (مثال: 989124533878)', 'wp-live-chat')}
                                placeholder="989124533878"
                            />
                            
                            <TextControl
                                label={__('شماره تماس', 'wp-live-chat')}
                                value={phoneNumber}
                                onChange={(value) => setAttributes({ phoneNumber: value })}
                                help={__('شماره تماس برای دکمه تماس', 'wp-live-chat')}
                                placeholder="09124533878"
                            />
                        </>
                    )}
                </PanelBody>
            </InspectorControls>

            <div {...blockProps}>
                <div 
                    className="chat-widget-preview"
                    style={{
                        backgroundColor: backgroundColor,
                        color: textColor
                    }}
                >
                    <div className="chat-preview-header">
                        <div className="chat-preview-title">
                            <h4>{title || __('چت آنلاین', 'wp-live-chat')}</h4>
                            <div className={`status-indicator ${isOnline ? 'online' : 'offline'}`}>
                                <span className="status-dot"></span>
                                <span className="status-text">
                                    {isOnline ? __('آنلاین', 'wp-live-chat') : __('آفلاین', 'wp-live-chat')}
                                </span>
                            </div>
                        </div>
                        <button className="chat-preview-close">×</button>
                    </div>
                    
                    <div className="chat-preview-body">
                        <div className="welcome-message">
                            <p>{subtitle || __('ما آنلاین هستیم، سوال خود را بپرسید', 'wp-live-chat')}</p>
                        </div>
                        
                        <div className="chat-preview-messages">
                            <div className="message user-message">
                                <div className="message-content">
                                    <p>{__('سلام، سوالی دارم...', 'wp-live-chat')}</p>
                                </div>
                                <div className="message-time">12:30</div>
                            </div>
                            
                            <div className="message admin-message">
                                <div className="message-content">
                                    <p>{__('سلام! چگونه می‌توانم کمک کنم؟', 'wp-live-chat')}</p>
                                </div>
                                <div className="message-time">12:31</div>
                            </div>
                        </div>
                    </div>
                    
                    <div className="chat-preview-input">
                        <textarea 
                            placeholder={__('پیام خود را تایپ کنید...', 'wp-live-chat')}
                            rows="2"
                        ></textarea>
                        <button className="send-button-preview">
                            {__('ارسال', 'wp-live-chat')}
                        </button>
                    </div>

                    {/* بخش راه‌های ارتباطی */}
                    {showContactButtons && (
                        <div className="salenoo-chat-alternatives">
                            <small>{__('راه‌های دیگر تماس:', 'wp-live-chat')}</small>
                            <div className="salenoo-contact-buttons">
                                <a className="salenoo-contact-btn salenoo-contact-wa" 
                                   href={`https://wa.me/${whatsappNumber || '989124533878'}`} 
                                   target="_blank" 
                                   rel="noopener noreferrer">
                                    <svg width="18" height="18" viewBox="0 0 24 24" fill="none">
                                        <path d="M20.52 3.48C18.09 1.05 14.88 0 11.69 0 5.77 0 .98 4.79 .98 10.71c0 1.89.5 3.73 1.45 5.33L0 24l8.33-2.46c1.48.41 3.03.63 4.58.63 5.91 0 10.7-4.79 10.7-10.71 0-3.19-1.05-6.4-2.99-8.31z" fill="#25D366"/>
                                        <path d="M17.45 14.21c-.34-.17-2.02-.99-2.34-1.1-.32-.11-.55-.17-.78.17-.23.34-.9 1.1-1.1 1.33-.2.23-.39.26-.73.09-.34-.17-1.44-.53-2.74-1.68-1.01-.9-1.69-2.01-1.89-2.35-.2-.34-.02-.52.15-.69.15-.15.34-.39.51-.59.17-.2.23-.34.34-.56.11-.23 0-.43-.02-.6-.02-.17-.78-1.88-1.07-2.58-.28-.68-.57-.59-.78-.6-.2-.01-.43-.01-.66-.01-.23 0-.6.09-.92.43-.32.34-1.22 1.19-1.22 2.9 0 1.71 1.25 3.37 1.42 3.6.17.23 2.46 3.75 5.96 5.12 3.5 1.37 3.5.92 4.13.86.63-.05 2.02-.82 2.31-1.63.29-.8.29-1.49.2-1.63-.09-.15-.32-.23-.66-.4z" fill="#fff"/>
                                    </svg>
                                    <span>{__('واتساپ', 'wp-live-chat')}</span>
                                </a>
                                <a className="salenoo-contact-btn salenoo-contact-call" 
                                   href={`tel:${phoneNumber || '09124533878'}`}>
                                    <svg width="16" height="16" viewBox="0 0 24 24" fill="none">
                                        <path d="M22 16.92v3a2 2 0 0 1-2.18 2 19.86 19.86 0 0 1-8.63-3.07 19.5 19.5 0 0 1-6-6 19.86 19.86 0 0 1-3.07-8.63A2 2 0 0 1 4.09 2h3a2 2 0 0 1 2 1.72c.12.99.38 1.95.76 2.84a2 2 0 0 1-.45 2.11L8.91 10.91a16 16 0 0 0 6 6l1.24-1.24a2 2 0 0 1 2.11-.45c.89.38 1.85.64 2.84.76A2 2 0 0 1 22 16.92z" fill="#0066cc"/>
                                    </svg>
                                    <span>{__('تماس', 'wp-live-chat')}</span>
                                </a>
                            </div>
                        </div>
                    )}
                </div>
                
                <div className="editor-help-text">
                    <p>{__('ویجت چت در پیش‌نمایش ادیتور نمایش داده می‌شود. در صفحه اصلی به صورت پاپ‌آپ نمایش داده خواهد شد.', 'wp-live-chat')}</p>
                </div>
            </div>
        </>
    );
};

const Save = ({ attributes }) => {
    const { 
        position, 
        title, 
        subtitle, 
        onlineStatus, 
        backgroundColor, 
        textColor, 
        animation,
        showContactButtons,
        whatsappNumber,
        phoneNumber
    } = attributes;
    
    const blockProps = useBlockProps.save({
        className: `wp-live-chat-widget position-${position} animation-${animation}`,
        'data-attributes': JSON.stringify({
            title,
            subtitle,
            onlineStatus,
            backgroundColor,
            textColor,
            showContactButtons,
            whatsappNumber: whatsappNumber || '989124533878',
            phoneNumber: phoneNumber || '09124533878'
        })
    });

    return (
        <div {...blockProps}>
            {/* محتوای داینامیک در فرانت‌اند رندر می‌شود */}
        </div>
    );
};

registerBlockType('wp-live-chat/chat-widget', {
    title: __('ویجت چت زنده', 'wp-live-chat'),
    description: __('ویجت چت آنلاین برای ارتباط مستقیم با کاربران', 'wp-live-chat'),
    icon: 'format-chat',
    category: 'widgets',
    attributes: {
        position: {
            type: 'string',
            default: 'bottom-left'
        },
        title: {
            type: 'string',
            default: 'چت آنلاین'
        },
        subtitle: {
            type: 'string',
            default: 'ما آنلاین هستیم، سوال خود را بپرسید'
        },
        onlineStatus: {
            type: 'boolean',
            default: true
        },
        backgroundColor: {
            type: 'string',
            default: '#007cba'
        },
        textColor: {
            type: 'string',
            default: '#ffffff'
        },
        animation: {
            type: 'string',
            default: 'pulse'
        },
        showContactButtons: {
            type: 'boolean',
            default: true
        },
        whatsappNumber: {
            type: 'string',
            default: '989124533878'
        },
        phoneNumber: {
            type: 'string',
            default: '09124533878'
        }
    },
    edit: Edit,
    save: Save
});