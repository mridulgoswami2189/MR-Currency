<?php
if (!defined('ABSPATH')) {
    exit;
}

/**
 * Handle currency switch (public endpoint).
 * Expects:
 *  - POST currency   : ISO code (e.g. EUR)
 *  - POST _wpnonce   : from wp_nonce_field('mrwcmc_switch_currency')
 *  - POST redirect_to: optional URL to go back to (sanitized)
 *
 * Forms must include:
 *   wp_nonce_field('mrwcmc_switch_currency', '_wpnonce', true, false)
 */
if (!function_exists('mrwcmc_handle_set_currency')) {
    function mrwcmc_handle_set_currency()
    {
        // --- Nonce verification (read via filter_input; no direct $_POST) ---
        $nonce_raw = filter_input(INPUT_POST, '_wpnonce', FILTER_UNSAFE_RAW, FILTER_NULL_ON_FAILURE);
        $nonce     = is_string($nonce_raw) ? sanitize_text_field($nonce_raw) : '';
        if (!wp_verify_nonce($nonce, 'mrwcmc_switch_currency')) {
            $back = wp_get_referer();
            if (!$back) {
                $back = home_url('/');
            }
            wp_safe_redirect($back);
            exit;
        }

        // --- Read & sanitize currency (no direct $_POST) ---
        $cur_raw = filter_input(INPUT_POST, 'currency', FILTER_UNSAFE_RAW, FILTER_NULL_ON_FAILURE);
        $cur_raw = is_string($cur_raw) ? sanitize_text_field($cur_raw) : '';
        // Constrain to 3 letters, uppercase
        $cur     = strtoupper(substr(preg_replace('/[^A-Za-z]/', '', $cur_raw), 0, 3));

        if ($cur && function_exists('mrwcmc_get_supported_currs')) {
            $supported = mrwcmc_get_supported_currs();
            if (!in_array($cur, $supported, true)) {
                $cur = '';
            }
        }

        if ($cur !== '' && function_exists('mrwcmc_set_currency_cookie')) {
            mrwcmc_set_currency_cookie($cur);
        }

        // --- Resolve safe redirect target (no direct $_POST) ---
        $redir_raw = filter_input(INPUT_POST, 'redirect_to', FILTER_UNSAFE_RAW, FILTER_NULL_ON_FAILURE);
        $redir     = (is_string($redir_raw) && $redir_raw !== '') ? esc_url_raw($redir_raw) : wp_get_referer();
        if (!$redir || !wp_validate_redirect($redir, false)) {
            $redir = home_url('/');
        }

        wp_safe_redirect($redir);
        exit;
    }

    add_action('admin_post_nopriv_mrwcmc_set_currency', 'mrwcmc_handle_set_currency');
    add_action('admin_post_mrwcmc_set_currency',        'mrwcmc_handle_set_currency');
}
