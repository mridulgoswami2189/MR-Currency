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

// --- helpers to read options ---

// if (!function_exists('mrwcmc_get_option')) {
//     function mrwcmc_get_option() : array {
//         $defaults = function_exists('mrwcmc_defaults') ? mrwcmc_defaults() : array();
//         $opt = get_option('mrwcmc_settings', $defaults);
//         if (!is_array($opt)) $opt = $defaults;
//         return array_merge($defaults, $opt);
//     }
// }

// --- ipinfo fetch ---
if (!function_exists('mrwcmc_ipinfo_country')) {
    function mrwcmc_ipinfo_country(string $ip = ''): string
    {
        $opt   = mrwcmc_get_option();
        $token = isset($opt['ipinfo_token']) ? trim($opt['ipinfo_token']) : '';
        // Build URL
        $base  = 'https://ipinfo.io/';
        $path  = $ip ? rawurlencode($ip) . '/json' : 'json';
        $url   = $base . $path;
        if ($token !== '') {
            $url = add_query_arg(['token' => $token], $url);
        }
        $res = wp_remote_get($url, ['timeout' => 8]);
        if (is_wp_error($res)) return '';
        $code = wp_remote_retrieve_response_code($res);
        if ($code !== 200) return '';
        $body = json_decode(wp_remote_retrieve_body($res), true);
        $cc   = isset($body['country']) ? strtoupper(trim($body['country'])) : '';
        return (preg_match('/^[A-Z]{2}$/', $cc)) ? $cc : '';
    }
}

// --- simple CF header ---
if (!function_exists('mrwcmc_cloudflare_country')) {
    function mrwcmc_cloudflare_country(): string
    {
        if (!empty($_SERVER['HTTP_CF_IPCOUNTRY']) && preg_match('/^[A-Z]{2}$/', $_SERVER['HTTP_CF_IPCOUNTRY'])) {
            return strtoupper($_SERVER['HTTP_CF_IPCOUNTRY']);
        }
        return '';
    }
}

// --- WooCommerce geolocation ---
if (!function_exists('mrwcmc_wc_country')) {
    function mrwcmc_wc_country(): string
    {
        if (class_exists('WC_Geolocation')) {
            $loc = WC_Geolocation::geolocate_ip();
            if (is_array($loc) && !empty($loc['country']) && preg_match('/^[A-Z]{2}$/', $loc['country'])) {
                return strtoupper($loc['country']);
            }
        }
        return '';
    }
}

// --- IP helper ---
if (!function_exists('mrwcmc_get_client_ip')) {
    function mrwcmc_get_client_ip(): string
    {
        return isset($_SERVER['REMOTE_ADDR']) ? (string) $_SERVER['REMOTE_ADDR'] : '';
    }
}

// --- main detection (uncached) ---
if (!function_exists('mrwcmc_detect_country_uncached')) {
    function mrwcmc_detect_country_uncached(): string
    {
        $opt   = mrwcmc_get_option();
        $prov  = $opt['geo_provider'] ?? 'auto';
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
        if ($cc) return $cc;

        $cc = mrwcmc_wc_country();
        if ($cc) return $cc;

        if (!empty($opt['ipinfo_token'])) {
            $cc = mrwcmc_ipinfo_country($ip);
            if ($cc) return $cc;
        }

        // last resort: try common server vars
        foreach (['GEOIP_COUNTRY_CODE', 'HTTP_X_GEOIP_COUNTRY', 'HTTP_GEOIP_COUNTRY_CODE'] as $k) {
            if (!empty($_SERVER[$k]) && preg_match('/^[A-Z]{2}$/', $_SERVER[$k])) {
                return strtoupper($_SERVER[$k]);
            }
        }
        return '';
    }
}

// --- cached wrapper ---
if (!function_exists('mrwcmc_geolocate_country')) {
    function mrwcmc_geolocate_country(): string
    {
        $ip = mrwcmc_get_client_ip();
        $key = 'mrwcmc_cc_' . md5(($ip ?: 'none') . '|' . maybe_serialize(mrwcmc_get_option()['geo_provider'] ?? 'auto'));
        $cc = get_transient($key);
        if ($cc && preg_match('/^[A-Z]{2}$/', $cc)) {
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
        $opt = mrwcmc_get_option();
        $map = isset($opt['country_currency_map']) && is_array($opt['country_currency_map']) ? $opt['country_currency_map'] : [];
        $base = get_option('woocommerce_currency', 'USD');
        $country = strtoupper(trim($country));
        if ($country && isset($map[$country])) return strtoupper($map[$country]);
        if (isset($map['*'])) return strtoupper($map['*']);
        return strtoupper($base);
    }
}

// --- early cookie setter (don’t overwrite user choice) ---
if (!function_exists('mrwcmc_init_set_geo_currency')) {
    function mrwcmc_init_set_geo_currency()
    {
        if (is_admin()) return;
        // If user explicitly chose a currency via query, do nothing here.
        if (isset($_GET['currency']) || isset($_GET['mrwcmc_currency']) || isset($_GET['mrwcmc'])) return;

        if (!empty($_COOKIE['mrwcmc_currency'])) return;

        $country = mrwcmc_geolocate_country();
        $desired = mrwcmc_currency_for_country($country);

        $supported = function_exists('mrwcmc_get_supported_currs') ? mrwcmc_get_supported_currs() : [];
        if (empty($supported) || !in_array($desired, $supported, true)) return;

        $expire   = time() + 30 * DAY_IN_SECONDS;
        $secure   = is_ssl();
        $httponly = true;
        $path     = defined('COOKIEPATH') && COOKIEPATH ? COOKIEPATH : '/';
        $domain   = defined('COOKIE_DOMAIN') ? COOKIE_DOMAIN : '';
        if (function_exists('mrwcmc_set_currency_cookie')) {
            mrwcmc_set_currency_cookie($desired);
        }
    }
    add_action('init', 'mrwcmc_init_set_geo_currency', 1);
}

// --- cache/CDN correctness ---
if (!function_exists('mrwcmc_send_vary_cookie_header')) {
    function mrwcmc_send_vary_cookie_header()
    {
        if (!headers_sent()) {
            header('Vary: Cookie', false);
        }
    }
    add_action('send_headers', 'mrwcmc_send_vary_cookie_header');
}
