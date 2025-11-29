const defaultConfig = require('@wordpress/scripts/config/webpack.config');
const path = require('path');

module.exports = {
    ...defaultConfig,
    entry: {
        'blocks': './blocks/index.js',
        'frontend': './assets/js/frontend.js',
        'admin': './assets/js/admin.js',
        'frontend-style': './assets/css/frontend.scss',
        'admin-style': './assets/css/admin.scss',
        'blocks-style': './blocks/chat-widget/style.scss'
    },
    output: {
        path: path.resolve(__dirname, 'build'),
        filename: 'js/[name].js'
    }
};
