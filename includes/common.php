<?php
if (!defined('ABSPATH')) {
    exit;
}

/**
 * Common helpers shared across the plugin.
 * Keep this file free of output and safe to load early.
 */

/*-----------------------------------------------------------------------------
 * Options (request-scoped cache + refresh hook)
 *---------------------------------------------------------------------------*/
if (!function_exists('mrwcmc_get_option')) {
    /**
     * Get merged options (defaults + saved). Cached per request.
     *
     * @param bool $refresh  Force-refresh the cached value (used after updates)
     */
    function mrwcmc_get_option(bool $refresh = false): array
    {
        static $cached = null;

        if ($refresh || $cached === null) {
            $defaults = function_exists('mrwcmc_defaults') ? mrwcmc_defaults() : array();
            $saved    = get_option('mrwcmc_settings', array());
            if (!is_array($saved)) $saved = array();
            // merge (saved wins); ensure base keys exist
            $cached = array_merge($defaults, $saved);
        }

        return $cached;
    }
}

// Bust the request cache whenever the option changes
if (!function_exists('mrwcmc__refresh_options_cache')) {
    function mrwcmc__refresh_options_cache()
    {
        mrwcmc_get_option(true);
    }
    add_action('update_option_mrwcmc_settings', 'mrwcmc__refresh_options_cache', 10, 2);
}

/*-----------------------------------------------------------------------------
 * Supported currencies (ensures base is present, uppercased, unique)
 *---------------------------------------------------------------------------*/
if (!function_exists('mrwcmc_get_supported_currs')) {
    function mrwcmc_get_supported_currs(): array
    {
        $opt   = mrwcmc_get_option();
        $currs = isset($opt['supported_currencies']) && is_array($opt['supported_currencies'])
            ? $opt['supported_currencies'] : array();
        $currs = array_values(array_unique(array_map('strtoupper', $currs)));
        $base  = strtoupper(get_option('woocommerce_currency', 'USD'));
        if (!in_array($base, $currs, true)) array_unshift($currs, $base);
        return $currs;
    }
}

/*-----------------------------------------------------------------------------
 * Parsers
 *---------------------------------------------------------------------------*/
if (!function_exists('mrwcmc_parse_key_pairs')) {
    // Generic "KEY=VAL" (uppercase keys) lines parser
    function mrwcmc_parse_key_pairs(string $raw): array
    {
        $out = array();
        $raw = trim((string)$raw);
        if ($raw === '') return $out;
        foreach (preg_split('/\r?\n/', $raw) as $line) {
            $line = trim($line);
            if ($line === '' || strpos($line, '=') === false) continue;
            list($k, $v) = array_map('trim', explode('=', $line, 2));
            $k = strtoupper(sanitize_text_field($k));
            $v = strtoupper(sanitize_text_field($v));
            if ($k !== '' && $v !== '') $out[$k] = $v;
        }
        return $out;
    }
}

if (!function_exists('mrwcmc_parse_manual_rates')) {
    // Lines: CUR=rate (float), relative to base currency
    function mrwcmc_parse_manual_rates(string $raw): array
    {
        $out = array();
        $raw = trim($raw);
        if ($raw === '') return $out;
        foreach (preg_split('/\r?\n/', $raw) as $line) {
            $line = trim($line);
            if ($line === '' || strpos($line, '=') === false) continue;
            list($cur, $rate) = array_map('trim', explode('=', $line, 2));
            $cur  = strtoupper(sanitize_text_field($cur));
            $rate = (float) str_replace(',', '.', $rate);
            if ($cur && $rate > 0) $out[$cur] = $rate;
        }
        return $out;
    }
}

if (!function_exists('mrwcmc_parse_markup_map')) {
    // Lines: CUR=%:X  or  CUR=fixed:X
    function mrwcmc_parse_markup_map(string $raw): array
    {
        $out = array();
        $raw = trim($raw);
        if ($raw === '') return $out;
        foreach (preg_split('/\r?\n/', $raw) as $line) {
            $line = trim($line);
            if ($line === '' || strpos($line, '=') === false) continue;
            list($cur, $spec) = array_map('trim', explode('=', $line, 2));
            $cur = strtoupper(sanitize_text_field($cur));
            $spec = strtolower($spec);
            if (strpos($spec, '%:') === 0) {
                $out[$cur] = array('type' => 'percent', 'value' => (float) str_replace(',', '.', substr($spec, 2)));
            } elseif (strpos($spec, 'fixed:') === 0) {
                $out[$cur] = array('type' => 'fixed', 'value' => (float) str_replace(',', '.', substr($spec, 6)));
            }
        }
        return $out;
    }
}

if (!function_exists('mrwcmc_get_decimals_map')) {
    // Lines: CUR=N (decimals per currency)
    function mrwcmc_get_decimals_map(string $raw): array
    {
        $out = array();
        $raw = trim($raw);
        if ($raw === '') return $out;
        foreach (preg_split('/\r?\n/', $raw) as $line) {
            $line = trim($line);
            if ($line === '' || strpos($line, '=') === false) continue;
            list($cur, $dec) = array_map('trim', explode('=', $line, 2));
            $cur = strtoupper(sanitize_text_field($cur));
            $dec = (int) preg_replace('/[^0-9]/', '', $dec);
            $out[$cur] = max(0, $dec);
        }
        return $out;
    }
}

/*-----------------------------------------------------------------------------
 * Cookie setter (shared by switcher/guard/geo)
 *---------------------------------------------------------------------------*/
if (!function_exists('mrwcmc_set_currency_cookie')) {
    function mrwcmc_set_currency_cookie(string $currency): bool
    {
        $supported = mrwcmc_get_supported_currs();
        $c = strtoupper(trim($currency));
        if (!in_array($c, $supported, true)) return false;
        if (headers_sent()) return false;

        $expire   = time() + 30 * DAY_IN_SECONDS;
        $secure   = is_ssl();
        $httponly = true;
        $path     = defined('COOKIEPATH') && COOKIEPATH ? COOKIEPATH : '/';
        $domain   = defined('COOKIE_DOMAIN') ? COOKIE_DOMAIN : '';

        setcookie('mrwcmc_currency', $c, $expire, $path, $domain, $secure, $httponly);
        $_COOKIE['mrwcmc_currency'] = $c;
        return true;
    }
}
// SAFE WooCommerce currency list (only after init; otherwise empty)
if (!function_exists('mrwcmc_wc_currencies')) {
    function mrwcmc_wc_currencies(): array
    {
        static $cache = null;
        if ($cache !== null) return $cache;

        $cache = [];
        if (function_exists('get_woocommerce_currencies') && did_action('init')) {
            // This function uses the 'woocommerce' text domain internally.
            // Calling it before `init` would trigger the WP 6.7 notice.
            $cache = (array) get_woocommerce_currencies();
        }
        return $cache;
    }
}

// Valid codes helper (keys only)
if (!function_exists('mrwcmc_valid_currency_codes')) {
    function mrwcmc_valid_currency_codes(): array
    {
        $list = mrwcmc_wc_currencies();
        return array_keys($list);
    }
}
