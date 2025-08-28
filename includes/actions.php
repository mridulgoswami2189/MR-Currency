<?php
if (!defined('ABSPATH')) {
    exit;
}

if (!function_exists('mrwcmc_handle_set_currency')) {
    function mrwcmc_handle_set_currency()
    {
        $cur   = isset($_POST['currency']) ? sanitize_text_field($_POST['currency']) : '';
        $redir = isset($_POST['redirect_to']) ? esc_url_raw($_POST['redirect_to']) : wp_get_referer();
        if (!$redir) $redir = home_url('/');

        // Try to set the cookie
        if (function_exists('mrwcmc_set_currency_cookie')) {
            mrwcmc_set_currency_cookie($cur);
        }

        // Always bounce back (no output before this)
        if (!headers_sent()) {
            wp_safe_redirect($redir);
            exit;
        }
    }
    add_action('admin_post_nopriv_mrwcmc_set_currency', 'mrwcmc_handle_set_currency');
    add_action('admin_post_mrwcmc_set_currency',        'mrwcmc_handle_set_currency');
}
