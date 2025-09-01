<?php
// Drop this into includes/debug.php (or temporarily in geo.php)
if (!defined('ABSPATH')) {
    exit;
}

if (!function_exists('mrwcmc_debug_shortcode')) {
    function mrwcmc_debug_shortcode(): string
    {
        // Read GET param (read-only) without touching $_GET directly
        $param = '';
        foreach (['mrwcmc', 'mrwcmc_currency', 'currency'] as $k) {
            $v = filter_input(INPUT_GET, $k, FILTER_UNSAFE_RAW, FILTER_NULL_ON_FAILURE);
            if (is_string($v) && $v !== '') {
                $param = strtoupper(sanitize_text_field($v));
                break;
            }
        }

        // Read cookie safely (no direct $_COOKIE)
        $cookie_raw = filter_input(INPUT_COOKIE, 'mrwcmc_currency', FILTER_UNSAFE_RAW, FILTER_NULL_ON_FAILURE);
        $cookie = is_string($cookie_raw) ? strtoupper(substr(preg_replace('/[^A-Za-z]/', '', $cookie_raw), 0, 3)) : '(none)';

        $cc   = function_exists('mrwcmc_geolocate_country')     ? mrwcmc_geolocate_country()     : '';
        $map  = function_exists('mrwcmc_currency_for_country')  ? mrwcmc_currency_for_country($cc) : '';
        $curr = function_exists('mrwcmc_get_current_currency')  ? mrwcmc_get_current_currency()  : '';
        $supported = function_exists('mrwcmc_get_supported_currs')
            ? implode(',', array_map('strtoupper', mrwcmc_get_supported_currs()))
            : '(n/a)';
        $base = strtoupper(get_option('woocommerce_currency', 'USD'));

        $text  = "Detected country: " . ($cc   ?: '(unknown)') . "\n";
        $text .= "Mapped currency:  " . ($map  ?: '(none)')    . "\n";
        $text .= "Current currency: " . ($curr ?: '(base)')    . "\n";
        $text .= "Cookie:           " . ($cookie ?: '(none)')  . "\n";
        $text .= "Param:            " . ($param ?: '(none)')   . "\n";
        $text .= "Supported:        " . $supported             . "\n";
        $text .= "Base:             " . $base                  . "\n";

        return '<pre class="mrwcmc-debug" style="background:#f6f7f7;padding:10px;border:1px solid #ddd;">'
            . esc_html($text)
            . '</pre>';
    }
    add_shortcode('mrwcmc_debug', 'mrwcmc_debug_shortcode');
}
