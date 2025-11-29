const defaultConfig = require('@wordpress/scripts/config/webpack.config');
const path = require('path');
const MiniCssExtractPlugin = require('mini-css-extract-plugin');

module.exports = {
    ...defaultConfig,
    entry: {
        'blocks': './blocks/index.js',
        'frontend': './assets/js/frontend.js',
        'admin': './assets/js/admin.js',
        'admin-chat': './assets/js/admin-chat.js',
        'frontend-style': './assets/css/frontend.scss',
        'admin-style': './assets/css/admin.scss',
        'blocks-editor': './blocks/chat-widget/editor.scss',
        'blocks-style': './blocks/chat-widget/style.scss'
    },
    output: {
        path: path.resolve(__dirname, 'build'),
        filename: 'js/[name].js'
    },
    module: {
        ...defaultConfig.module,
        rules: [
            ...defaultConfig.module.rules.filter(rule => 
                !rule.test || (!rule.test.test('.scss') && !rule.test.test('.css'))
            ),
            {
                test: /\.scss$/,
                use: [
                    {
                        loader: MiniCssExtractPlugin.loader,
                        options: {
                            publicPath: '../'
                        }
                    },
                    {
                        loader: 'css-loader',
                        options: {
                            sourceMap: true,
                            url: false
                        }
                    },
                    {
                        loader: 'sass-loader',
                        options: {
                            sourceMap: true,
                            sassOptions: {
                                outputStyle: 'compressed'
                            }
                        }
                    }
                ]
            },
            {
                test: /\.css$/,
                use: [
                    MiniCssExtractPlugin.loader,
                    'css-loader'
                ]
            }
        ]
    },
    plugins: [
        ...defaultConfig.plugins.filter(plugin => 
            plugin.constructor.name !== 'MiniCssExtractPlugin'
        ),
        new MiniCssExtractPlugin({
            filename: 'css/[name].css'
        })
    ]
};