import './chat-widget';
import './chat-button';

// ثبت بلوک‌ها به صورت داینامیک
const { registerBlockType } = wp.blocks;
const { createElement } = wp.element;

// ایمپورت خودکار تمام بلوک‌ها
const blockContext = require.context('./', true, /\/block\.json$/);

blockContext.keys().forEach(blockPath => {
    const blockConfig = blockContext(blockPath);
    const blockName = blockConfig.name;
    
    if (blockName) {
        // پیدا کردن فایل‌های مرتبط
        const blockDir = blockPath.split('/').slice(0, -1).join('/');
        const editPath = `./${blockDir}/edit`;
        const savePath = `./${blockDir}/save`;
        
        try {
            const EditComponent = require(`./${blockDir}/edit.js`).default;
            const SaveComponent = require(`./${blockDir}/save.js`).default;
            
            registerBlockType(blockName, {
                ...blockConfig,
                edit: EditComponent,
                save: SaveComponent
            });
            
            console.log(`Block ${blockName} registered successfully`);
        } catch (error) {
            console.warn(`Failed to register block ${blockName}:`, error);
        }
    }
});