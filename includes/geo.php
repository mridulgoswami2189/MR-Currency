<?php
// File: wp-content/plugins/mr-multicurrency/includes/geo.php
if (!defined('ABSPATH')) {
    exit;
}

/**
 * Geo → Currency mapping (functional style)
 * - Detect shopper country (WooCommerce Geolocation if available, else common headers)
 * - Map Country → Currency using plugin settings (with * fallback)
 * - Set a cookie early so pricing filters pick it up
 * - Add Vary: Cookie for cache/CDN correctness
 *
 * Requires:
 * - mrwcmc_get_option(), mrwcmc_get_supported_currs() from other includes
 */

// ---------- Country detection ----------

if (!function_exists('mrwcmc_detect_country_uncached')) {
    function mrwcmc_detect_country_uncached(): string
    {
        // 1) Cloudflare header (very common)
        if (!empty($_SERVER['HTTP_CF_IPCOUNTRY']) && preg_match('/^[A-Z]{2}$/', $_SERVER['HTTP_CF_IPCOUNTRY'])) {
            return strtoupper($_SERVER['HTTP_CF_IPCOUNTRY']);
        }

        // 2) WooCommerce built-in geolocation (uses MaxMind / services as configured)
        if (class_exists('WC_Geolocation')) {
            $loc = WC_Geolocation::geolocate_ip();
            if (is_array($loc) && !empty($loc['country']) && preg_match('/^[A-Z]{2}$/', $loc['country'])) {
                return strtoupper($loc['country']);
            }
        }

        // 3) Server-provided GeoIP env vars (if any)
        foreach (['GEOIP_COUNTRY_CODE', 'HTTP_X_GEOIP_COUNTRY', 'HTTP_GEOIP_COUNTRY_CODE'] as $k) {
            if (!empty($_SERVER[$k]) && preg_match('/^[A-Z]{2}$/', $_SERVER[$k])) {
                return strtoupper($_SERVER[$k]);
            }
        }

        // Unknown
        return '';
    }
}

if (!function_exists('mrwcmc_get_client_ip')) {
    function mrwcmc_get_client_ip(): string
    {
        // Keep it simple; WooCommerce handles proxies internally when geolocating
        return isset($_SERVER['REMOTE_ADDR']) ? (string) $_SERVER['REMOTE_ADDR'] : '';
    }
}

if (!function_exists('mrwcmc_geolocate_country')) {
    function mrwcmc_geolocate_country(): string
    {
        $ip = mrwcmc_get_client_ip();
        $key = 'mrwcmc_cc_' . md5($ip ?: 'none');
        $cc = get_transient($key);
        if ($cc && preg_match('/^[A-Z]{2}$/', $cc)) {
            return $cc;
        }
        $cc = mrwcmc_detect_country_uncached();
        // Cache for a day to avoid repeated lookups
        set_transient($key, $cc, DAY_IN_SECONDS);
        return $cc;
    }
}

// ---------- Country → Currency mapping ----------

if (!function_exists('mrwcmc_currency_for_country')) {
    function mrwcmc_currency_for_country(string $country): string
    {
        $opt = function_exists('mrwcmc_get_option') ? mrwcmc_get_option() : [];
        $map = isset($opt['country_currency_map']) && is_array($opt['country_currency_map']) ? $opt['country_currency_map'] : [];
        $base = get_option('woocommerce_currency', 'USD');
        $country = strtoupper(trim($country));

        if ($country && isset($map[$country])) {
            return strtoupper($map[$country]);
        }
        if (isset($map['*'])) {
            return strtoupper($map['*']);
        }
        return strtoupper($base);
    }
}

// ---------- Early cookie setter (don’t override a user choice) ----------

if (!function_exists('mrwcmc_init_set_geo_currency')) {
    function mrwcmc_init_set_geo_currency()
    {
        if (is_admin()) return;

        // If user explicitly chose a currency via query, do nothing here.
        if (isset($_GET['currency']) || isset($_GET['mrwcmc_currency'])) return;

        // If a cookie already exists, don’t override.
        if (!empty($_COOKIE['mrwcmc_currency'])) return;

        // Resolve from geo
        $country = mrwcmc_geolocate_country();
        $desired = mrwcmc_currency_for_country($country);

        // Ensure it's supported
        $supported = function_exists('mrwcmc_get_supported_currs') ? mrwcmc_get_supported_currs() : [];
        if (empty($supported) || !in_array($desired, $supported, true)) return;

        // Set cookie (30 days)
        $expire   = time() + 30 * DAY_IN_SECONDS;
        $secure   = is_ssl();
        $httponly = true;
        $path     = defined('COOKIEPATH') && COOKIEPATH ? COOKIEPATH : '/';
        $domain   = defined('COOKIE_DOMAIN') ? COOKIE_DOMAIN : '';
        setcookie('mrwcmc_currency', $desired, $expire, $path, $domain, $secure, $httponly);
        // Make it visible immediately this request:
        $_COOKIE['mrwcmc_currency'] = $desired;
    }
    add_action('init', 'mrwcmc_init_set_geo_currency', 1);
}

// ---------- Cache/CDN correctness ----------

if (!function_exists('mrwcmc_send_vary_cookie_header')) {
    function mrwcmc_send_vary_cookie_header()
    {
        // Ensure caches vary on the currency cookie so prices don’t leak between users
        if (!headers_sent()) {
            header('Vary: Cookie', false);
        }
    }
    add_action('send_headers', 'mrwcmc_send_vary_cookie_header');
}
