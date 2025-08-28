<?php
if (!defined('ABSPATH')) {
    exit;
}

/** Helper: set currency cookie (30 days) */
if (!function_exists('mrwcmc_set_currency_cookie')) {
    function mrwcmc_set_currency_cookie(string $currency): bool
    {
        $supported = mrwcmc_get_supported_currs();
        $c = strtoupper(trim($currency));
        if (!in_array($c, $supported, true)) return false;

        $expire   = time() + 30 * DAY_IN_SECONDS;
        $secure   = is_ssl();
        $httponly = true;
        $path     = defined('COOKIEPATH') && COOKIEPATH ? COOKIEPATH : '/';
        $domain   = defined('COOKIE_DOMAIN') ? COOKIE_DOMAIN : '';

        setcookie('mrwcmc_currency', $c, $expire, $path, $domain, $secure, $httponly);
        $_COOKIE['mrwcmc_currency'] = $c; // immediate availability
        return true;
    }
}

/** REST: POST /wp-json/mrwcmc/v1/currency { "currency":"EUR" } */
if (!function_exists('mrwcmc_rest_set_currency')) {
    function mrwcmc_rest_set_currency(WP_REST_Request $request)
    {
        $params = $request->get_json_params();
        $cur = '';
        if (is_array($params) && isset($params['currency'])) {
            $cur = $params['currency'];
        } else {
            // Fallback to form/body param
            $cur = $request->get_param('currency');
        }
        $ok = mrwcmc_set_currency_cookie((string)$cur);
        if (!$ok) {
            return new WP_REST_Response(['ok' => false, 'error' => 'invalid_currency'], 400);
        }
        return new WP_REST_Response(['ok' => true], 200);
    }

    add_action('rest_api_init', function () {
        register_rest_route('mrwcmc/v1', '/currency', [
            'methods'  => 'POST',
            'callback' => 'mrwcmc_rest_set_currency',
            'permission_callback' => '__return_true', // public; sets only a cookie
        ]);
    });
}

/** No-JS fallback: admin-post POST (no query params on public URL) */
if (!function_exists('mrwcmc_admin_post_set_currency')) {
    function mrwcmc_admin_post_set_currency()
    {
        $cur = isset($_POST['currency']) ? sanitize_text_field($_POST['currency']) : '';
        mrwcmc_set_currency_cookie($cur);
        $redir = wp_get_referer();
        if (!$redir) $redir = home_url('/');
        wp_safe_redirect($redir);
        exit;
    }
    add_action('admin_post_nopriv_mrwcmc_set_currency', 'mrwcmc_admin_post_set_currency');
    add_action('admin_post_mrwcmc_set_currency', 'mrwcmc_admin_post_set_currency');
}
