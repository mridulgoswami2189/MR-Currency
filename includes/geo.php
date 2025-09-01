<?php
// File: wp-content/plugins/mr-multicurrency/includes/geo.php
if (!defined('ABSPATH')) {
    exit;
}

/**
 * Geo → Currency mapping with ipinfo support
 * Providers:
 * - cloudflare: use HTTP_CF_IPCOUNTRY only
 * - wc: WooCommerce Geolocation / MaxMind
 * - ipinfo: ipinfo.io (optionally with token)
 * - auto (default): Cloudflare → WooCommerce → ipinfo (if token set)
 */

// --- ipinfo fetch ---
if (!function_exists('mrwcmc_ipinfo_country')) {
    function mrwcmc_ipinfo_country(string $ip = ''): string
    {
        $opt   = function_exists('mrwcmc_get_option') ? mrwcmc_get_option() : [];
        $token = isset($opt['ipinfo_token']) ? trim((string) $opt['ipinfo_token']) : '';

        // Validate IP if provided
        if ($ip !== '' && !filter_var($ip, FILTER_VALIDATE_IP)) {
            $ip = '';
        }

        $base  = 'https://ipinfo.io/';
        $path  = $ip ? rawurlencode($ip) . '/json' : 'json';
        $url   = $base . $path;
        if ($token !== '') {
            $url = add_query_arg(['token' => $token], $url);
        }

        $res = wp_remote_get($url, ['timeout' => 8]);
        if (is_wp_error($res)) {
            return '';
        }
        $code = (int) wp_remote_retrieve_response_code($res);
        if ($code !== 200) {
            return '';
        }

        $body = json_decode(wp_remote_retrieve_body($res), true);
        $cc   = isset($body['country']) ? strtoupper(trim((string) $body['country'])) : '';
        return preg_match('/^[A-Z]{2}$/', $cc) ? $cc : '';
    }
}

// --- simple CF header ---
if (!function_exists('mrwcmc_cloudflare_country')) {
    function mrwcmc_cloudflare_country(): string
    {
        $raw = filter_input(INPUT_SERVER, 'HTTP_CF_IPCOUNTRY', FILTER_UNSAFE_RAW, FILTER_NULL_ON_FAILURE);
        $cc  = is_string($raw) ? strtoupper(trim($raw)) : '';
        return preg_match('/^[A-Z]{2}$/', $cc) ? $cc : '';
    }
}

// --- WooCommerce geolocation ---
if (!function_exists('mrwcmc_wc_country')) {
    function mrwcmc_wc_country(): string
    {
        if (class_exists('WC_Geolocation')) {
            $loc = WC_Geolocation::geolocate_ip();
            if (is_array($loc) && !empty($loc['country'])) {
                $cc = strtoupper(trim((string) $loc['country']));
                if (preg_match('/^[A-Z]{2}$/', $cc)) {
                    return $cc;
                }
            }
        }
        return '';
    }
}

// --- IP helper ---
if (!function_exists('mrwcmc_get_client_ip')) {
    function mrwcmc_get_client_ip(): string
    {
        $raw = filter_input(INPUT_SERVER, 'REMOTE_ADDR', FILTER_UNSAFE_RAW, FILTER_NULL_ON_FAILURE);
        $ip  = is_string($raw) ? trim($raw) : '';
        return ($ip !== '' && filter_var($ip, FILTER_VALIDATE_IP)) ? $ip : '';
    }
}

// --- main detection (uncached) ---
if (!function_exists('mrwcmc_detect_country_uncached')) {
    function mrwcmc_detect_country_uncached(): string
    {
        $opt   = function_exists('mrwcmc_get_option') ? mrwcmc_get_option() : [];
        $prov  = isset($opt['geo_provider']) ? (string) $opt['geo_provider'] : 'auto';
        $ip    = mrwcmc_get_client_ip();

        if ($prov === 'cloudflare') {
            return mrwcmc_cloudflare_country();
        }
        if ($prov === 'wc') {
            return mrwcmc_wc_country();
        }
        if ($prov === 'ipinfo') {
            return mrwcmc_ipinfo_country($ip);
        }

        // auto: try Cloudflare → WooCommerce → ipinfo (only if token present)
        $cc = mrwcmc_cloudflare_country();
        if ($cc) {
            return $cc;
        }

        $cc = mrwcmc_wc_country();
        if ($cc) {
            return $cc;
        }

        if (!empty($opt['ipinfo_token'])) {
            $cc = mrwcmc_ipinfo_country($ip);
            if ($cc) {
                return $cc;
            }
        }

        // last resort: try common server vars
        foreach (['GEOIP_COUNTRY_CODE', 'HTTP_X_GEOIP_COUNTRY', 'HTTP_GEOIP_COUNTRY_CODE'] as $k) {
            $raw = filter_input(INPUT_SERVER, $k, FILTER_UNSAFE_RAW, FILTER_NULL_ON_FAILURE);
            $cc  = is_string($raw) ? strtoupper(trim($raw)) : '';
            if (preg_match('/^[A-Z]{2}$/', $cc)) {
                return $cc;
            }
        }
        return '';
    }
}

// --- cached wrapper ---
if (!function_exists('mrwcmc_geolocate_country')) {
    function mrwcmc_geolocate_country(): string
    {
        $ip  = mrwcmc_get_client_ip();
        $opt = function_exists('mrwcmc_get_option') ? mrwcmc_get_option() : [];
        $prov = isset($opt['geo_provider']) ? (string) $opt['geo_provider'] : 'auto';

        $key = 'mrwcmc_cc_' . md5(($ip ?: 'none') . '|' . $prov);
        $cc  = get_transient($key);
        if (is_string($cc) && preg_match('/^[A-Z]{2}$/', $cc)) {
            return $cc;
        }
        $cc = mrwcmc_detect_country_uncached();
        set_transient($key, $cc, DAY_IN_SECONDS);
        return $cc;
    }
}

// --- country → currency map ---
if (!function_exists('mrwcmc_currency_for_country')) {
    function mrwcmc_currency_for_country(string $country): string
    {
        $opt   = function_exists('mrwcmc_get_option') ? mrwcmc_get_option() : [];
        $map   = isset($opt['country_currency_map']) && is_array($opt['country_currency_map']) ? $opt['country_currency_map'] : [];
        $base  = strtoupper(get_option('woocommerce_currency', 'USD'));
        $cc    = strtoupper(trim($country));

        if ($cc !== '' && isset($map[$cc])) {
            return strtoupper((string) $map[$cc]);
        }
        if (isset($map['*'])) {
            return strtoupper((string) $map['*']);
        }
        return $base;
    }
}

// --- early cookie setter (don’t overwrite user choice) ---
if (!function_exists('mrwcmc_init_set_geo_currency')) {
    function mrwcmc_init_set_geo_currency(): void
    {
        if (is_admin()) {
            return;
        }

        // If user explicitly chose a currency via query, do nothing here (read-only gate).
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only check; no state change occurs here.
        $has_param = false;
        foreach (['mrwcmc', 'mrwcmc_currency', 'currency'] as $k) {
            $v = filter_input(INPUT_GET, $k, FILTER_UNSAFE_RAW, FILTER_NULL_ON_FAILURE);
            if (is_string($v) && $v !== '') {
                $has_param = true;
                break;
            }
        }
        if ($has_param) {
            return;
        }

        // If cookie already set, do nothing.
        $cookie = filter_input(INPUT_COOKIE, 'mrwcmc_currency', FILTER_UNSAFE_RAW, FILTER_NULL_ON_FAILURE);
        if (is_string($cookie) && $cookie !== '') {
            return;
        }

        $country = mrwcmc_geolocate_country();
        $desired = mrwcmc_currency_for_country($country);

        $supported = function_exists('mrwcmc_get_supported_currs') ? mrwcmc_get_supported_currs() : [];
        if (empty($supported) || !in_array($desired, $supported, true)) {
            return;
        }

        if (function_exists('mrwcmc_set_currency_cookie')) {
            mrwcmc_set_currency_cookie($desired);
        }
    }
    add_action('init', 'mrwcmc_init_set_geo_currency', 1);
}

// --- cache/CDN correctness ---
if (!function_exists('mrwcmc_send_vary_cookie_header')) {
    function mrwcmc_send_vary_cookie_header(): void
    {
        if (!headers_sent()) {
            header('Vary: Cookie', false);
        }
    }
    add_action('send_headers', 'mrwcmc_send_vary_cookie_header');
}
