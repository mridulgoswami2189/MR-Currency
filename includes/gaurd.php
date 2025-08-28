<?php
// ...inside mrwcmc_guard_strip_currency_param() just before wp_safe_redirect():
if (headers_sent()) {
    // Bail gracefully: do not try to redirect/send cookies if output already started
    return;
}
if (!defined('ABSPATH')) { exit; }

/**
 * If URL contains ?mrwcmc= (or legacy ?currency=/ ?mrwcmc_currency=),
 * set cookie then 302 to the same URL without that param(s).
 */
if (!function_exists('mrwcmc_guard_strip_currency_param')) {
    function mrwcmc_guard_strip_currency_param() {
        if (is_admin()) return;

        $param = null;
        foreach (['mrwcmc', 'mrwcmc_currency', 'currency'] as $key) {
            if (isset($_GET[$key]) && $_GET[$key] !== '') {
                $param = sanitize_text_field($_GET[$key]);
                break;
            }
        }
        if (!$param) return;

        // Set cookie
        $set = function($cur) {
            $supported = function_exists('mrwcmc_get_supported_currs') ? mrwcmc_get_supported_currs() : [];
            $c = strtoupper(trim($cur));
            if (!in_array($c, $supported, true)) return false;
            if (headers_sent()) return false; // don't try if output started
            $expire = time() + 30 * DAY_IN_SECONDS;
            $secure = is_ssl();
            $httponly = true;
            $path = defined('COOKIEPATH') && COOKIEPATH ? COOKIEPATH : '/';
            $domain = defined('COOKIE_DOMAIN') ? COOKIE_DOMAIN : '';
            setcookie('mrwcmc_currency', $c, $expire, $path, $domain, $secure, $httponly);
            $_COOKIE['mrwcmc_currency'] = $c;
            return true;
        };
        $ok = $set($param);

        // Redirect to clean URL (remove all currency params)
        if (!headers_sent()) {
            $clean = remove_query_arg(['mrwcmc','mrwcmc_currency','currency']);
            wp_safe_redirect($clean ?: home_url('/'), 302);
            exit;
        }
        // If headers already sent, just fall through; pricing will still use the param this request.
    }
    // Early, before template renders
    add_action('template_redirect', 'mrwcmc_guard_strip_currency_param', 0);
}
