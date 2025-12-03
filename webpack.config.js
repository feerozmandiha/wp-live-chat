const path = require('path');
const MiniCssExtractPlugin = require('mini-css-extract-plugin');

module.exports = {
    entry: {
        'frontend': './assets/js/frontend.js',
        'admin': './assets/js/admin.js',
        'admin-chat': './assets/js/admin-chat.js',
        'frontend-style': './assets/css/frontend.scss',
        'admin-style': './assets/css/admin.scss'
    },
    output: {
        path: path.resolve(__dirname, 'build'),
        filename: 'js/[name].js'
    },
    module: {
        rules: [
            {
                test: /\.js$/,
                exclude: /node_modules/,
                use: {
                    loader: 'babel-loader',
                    options: {
                        presets: ['@wordpress/babel-preset-default']
                    }
                }
            },
            {
                test: /\.scss$/,
                use: [
                    MiniCssExtractPlugin.loader,
                    'css-loader',
                    'sass-loader'
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
        new MiniCssExtractPlugin({
            filename: 'css/[name].css'
        })
    ],
    externals: {
        jquery: 'jQuery'
    }
};