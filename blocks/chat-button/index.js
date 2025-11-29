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
    TextControl, 
    SelectControl,
    ToggleControl,
    Button,
    Dashicon
} from '@wordpress/components';
import { __ } from '@wordpress/i18n';

import './editor.scss';

const Edit = ({ attributes, setAttributes }) => {
    const { 
        buttonText, 
        buttonStyle, 
        buttonSize, 
        backgroundColor, 
        textColor, 
        icon,
        align 
    } = attributes;

    const blockProps = useBlockProps({
        className: `wp-live-chat-button-wrapper align-${align}`,
        style: { textAlign: align }
    });

    const buttonStyles = [
        { label: 'اصلی', value: 'primary' },
        { label: 'ثانویه', value: 'secondary' },
        { label: 'ساده', value: 'outline' },
        { label: 'متن ساده', value: 'text' }
    ];

    const buttonSizes = [
        { label: 'کوچک', value: 'small' },
        { label: 'متوسط', value: 'medium' },
        { label: 'بزرگ', value: 'large' }
    ];

    const iconOptions = [
        { label: 'بدون آیکون', value: 'none' },
        { label: 'چت', value: 'chat' },
        { label: 'پیام', value: 'email' },
        { label: 'تلفن', value: 'phone' },
        { label: 'سوال', value: 'help' }
    ];

    const getIcon = (iconName) => {
        const icons = {
            chat: 'format-chat',
            email: 'email',
            phone: 'phone',
            help: 'editor-help'
        };
        return icons[iconName] || 'format-chat';
    };

    return (
        <>
            <BlockControls>
                <AlignmentToolbar
                    value={align}
                    onChange={(value) => setAttributes({ align: value })}
                />
            </BlockControls>

            <InspectorControls>
                <PanelBody title={__('تنظیمات دکمه', 'wp-live-chat')} initialOpen={true}>
                    <TextControl
                        label={__('متن دکمه', 'wp-live-chat')}
                        value={buttonText}
                        onChange={(value) => setAttributes({ buttonText: value })}
                    />
                    
                    <SelectControl
                        label={__('استایل دکمه', 'wp-live-chat')}
                        value={buttonStyle}
                        options={buttonStyles}
                        onChange={(value) => setAttributes({ buttonStyle: value })}
                    />
                    
                    <SelectControl
                        label={__('سایز دکمه', 'wp-live-chat')}
                        value={buttonSize}
                        options={buttonSizes}
                        onChange={(value) => setAttributes({ buttonSize: value })}
                    />
                    
                    <SelectControl
                        label={__('آیکون', 'wp-live-chat')}
                        value={icon}
                        options={iconOptions}
                        onChange={(value) => setAttributes({ icon: value })}
                    />
                </PanelBody>

                <PanelBody title={__('رنگ‌ها', 'wp-live-chat')} initialOpen={false}>
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
                </PanelBody>
            </InspectorControls>

            <div {...blockProps}>
                <button 
                    className={`wp-live-chat-button style-${buttonStyle} size-${buttonSize}`}
                    style={{
                        backgroundColor: buttonStyle !== 'outline' && buttonStyle !== 'text' ? backgroundColor : 'transparent',
                        color: textColor,
                        borderColor: buttonStyle === 'outline' ? backgroundColor : 'transparent'
                    }}
                >
                    {icon !== 'none' && (
                        <Dashicon icon={getIcon(icon)} />
                    )}
                    {buttonText || __('شروع چت', 'wp-live-chat')}
                </button>
            </div>
        </>
    );
};

const Save = ({ attributes }) => {
    const { 
        buttonText, 
        buttonStyle, 
        buttonSize, 
        backgroundColor, 
        textColor, 
        icon,
        align 
    } = attributes;

    const blockProps = useBlockProps.save({
        className: `wp-live-chat-button-wrapper align-${align}`,
        style: { textAlign: align }
    });

    const getIconClass = () => {
        return icon !== 'none' ? `has-icon icon-${icon}` : '';
    };

    return (
        <div {...blockProps}>
            <button 
                className={`wp-live-chat-button style-${buttonStyle} size-${buttonSize} ${getIconClass()}`}
                data-attributes={JSON.stringify({
                    buttonText,
                    buttonStyle,
                    backgroundColor,
                    textColor,
                    icon
                })}
            >
                {icon !== 'none' && (
                    <span className="button-icon"></span>
                )}
                {buttonText || __('شروع چت', 'wp-live-chat')}
            </button>
        </div>
    );
};

registerBlockType('wp-live-chat/chat-button', {
    edit: Edit,
    save: Save
});