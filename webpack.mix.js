const mix = require('laravel-mix');

/*
 |--------------------------------------------------------------------------
 | Mix Asset Management
 |--------------------------------------------------------------------------
 |
 | Mix provides a clean, fluent API for defining some Webpack build steps
 | for your Laravel applications. By default, we are compiling the CSS
 | file for the application as well as bundling up all the JS files.
 |
 */


mix.styles([
    'resources/assets/plugins/pace/pace-theme-flash.css',
    'resources/assets/pages/css/pages.css',
    'resources/assets/css/style.css',
    'resources/assets/css/all.min.css',
], 'public/css/pages.min.css');
// Compile all JS file from the theme
mix.scripts([
    'resources/assets/plugins/pace/pace.min.js',
    'resources/assets/plugins/liga.js',
    'resources/assets/plugins/modernizr.custom.js',
    'resources/assets/plugins/popper/umd/popper.min.js',
    'resources/assets/plugins/classie/classie.js',
], 'public/js/pages.min.js');

mix.copy('resources/assets/favicon', 'public');
mix.copy('resources/assets/pages/img', 'public/img');
mix.copy('resources/assets/icons', 'public/img/icons');
mix.copy('resources/assets/img', 'public/assets/img');
mix.copy('resources/assets/webfonts', 'public/webfonts');
mix.copy('resources/assets/pages/fonts', 'public/fonts');
mix.copy('resources/css/custom.css', 'public/css');
mix.js('resources/js/app.js', 'public/js').vue()
    .postCss('resources/css/app.css', 'public/css', [
        require('postcss-import'),
        require('tailwindcss'),
    ])
    .webpackConfig(require('./webpack.config'));

if (mix.inProduction()) {
    mix.version();
}
mix.disableNotifications()
