<?php
// Add in includes/geo.php (or a small mu-plugin) temporarily:
add_shortcode('mrwcmc_debug', function () {
    $cc   = function_exists('mrwcmc_geolocate_country') ? mrwcmc_geolocate_country() : '';
    $map  = function_exists('mrwcmc_currency_for_country') ? mrwcmc_currency_for_country($cc) : '';
    $curr = function_exists('mrwcmc_get_current_currency') ? mrwcmc_get_current_currency() : '';
    $cookie = isset($_COOKIE['mrwcmc_currency']) ? $_COOKIE['mrwcmc_currency'] : '(none)';
    return '<pre style="background:#f6f7f7;padding:10px;border:1px solid #ddd">' .
        'Detected country: ' . esc_html($cc ?: '(unknown)') . "\n" .
        'Mapped currency:  ' . esc_html($map ?: '(none)') . "\n" .
        'Current currency: ' . esc_html($curr ?: '(base)') . "\n" .
        'Cookie:           ' . esc_html($cookie) .
        '</pre>';
});
