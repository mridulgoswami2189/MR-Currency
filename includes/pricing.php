<?php
if (!defined('ABSPATH')) {
    exit;
}

/**
 * Pricing engine (functional, no classes)
 * - Current currency from cookie (set via switcher/actions/guard)
 * - Conversion using effective rate (manual override > auto from provider)
 * - Per-currency markup (%, fixed)
 * - Per-currency rounding (decimals)
 * - Hooks into WooCommerce price getters + currency/decimals display
 */

// Ensure rate helpers are available (from includes/rates.php)
if (!function_exists('mrwcmc_get_effective_rate')) {
    $rates_file = defined('MRWCMC_PATH') ? MRWCMC_PATH . 'includes/rates.php' : null;
    if ($rates_file && file_exists($rates_file)) {
        require_once $rates_file;
    }
}

/*------------------------------------------------------------------------------
 * Options + Supported currencies
 *----------------------------------------------------------------------------*/

// mrwcmc_get_option() provided in common.php

if (!function_exists('mrwcmc_get_supported_currs')) {
    function mrwcmc_get_supported_currs(): array
    {
        $opt   = function_exists('mrwcmc_get_option') ? mrwcmc_get_option() : [];
        $currs = isset($opt['supported_currencies']) && is_array($opt['supported_currencies'])
            ? array_values(array_unique(array_map('strtoupper', $opt['supported_currencies'])))
            : [];
        $base  = strtoupper(get_option('woocommerce_currency', 'USD'));
        if (!in_array($base, $currs, true)) {
            array_unshift($currs, $base);
        }
        return $currs;
    }
}

/**
 * Current currency: cookie -> fallback base
 * (Param handling is done elsewhere: switcher/actions/guard. Avoid $_GET here to satisfy PHPCS.)
 */
if (!function_exists('mrwcmc_get_current_currency')) {
    function mrwcmc_get_current_currency(): string
    {
        $supported = function_exists('mrwcmc_get_supported_currs') ? mrwcmc_get_supported_currs() : [];
        $base      = strtoupper(get_option('woocommerce_currency', 'USD'));

        // Cookie (read via helper → sanitized)
        $cookie = function_exists('mrwcmc_read_cookie') ? mrwcmc_read_cookie('mrwcmc_currency') : '';
        if ($cookie !== '') {
            // Constrain to 3-letter ISO (A–Z only), uppercase
            $c = strtoupper(substr(preg_replace('/[^A-Za-z]/', '', $cookie), 0, 3));
            if ($c && in_array($c, $supported, true)) {
                $c = (string) apply_filters('mrwcmc_current_currency', $c);
                return $c;
            }
        }

        return (string) apply_filters('mrwcmc_current_currency', $base);
    }
}

/*------------------------------------------------------------------------------
 * Markup + Rounding parsing
 *----------------------------------------------------------------------------*/
if (!function_exists('mrwcmc_parse_markup_map')) {
    function mrwcmc_parse_markup_map(string $raw): array
    {
        $out = [];
        $raw = trim($raw);
        if ($raw === '') return $out;
        foreach (preg_split('/\r?\n/', $raw) as $line) {
            $line = trim($line);
            if ($line === '' || strpos($line, '=') === false) continue;
            list($cur, $spec) = array_map('trim', explode('=', $line, 2));
            $cur  = strtoupper(sanitize_text_field($cur));
            $spec = strtolower($spec);
            if (strpos($spec, '%:') === 0) {
                $val = floatval(str_replace(',', '.', substr($spec, 2)));
                $out[$cur] = ['type' => 'percent', 'value' => $val];
            } elseif (strpos($spec, 'fixed:') === 0) {
                $val = floatval(str_replace(',', '.', substr($spec, 6)));
                $out[$cur] = ['type' => 'fixed', 'value' => $val];
            }
        }
        return $out;
    }
}

if (!function_exists('mrwcmc_get_decimals_map')) {
    function mrwcmc_get_decimals_map(string $raw): array
    {
        $out = [];
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

/*------------------------------------------------------------------------------
 * Convert + markup + rounding
 *----------------------------------------------------------------------------*/
if (!function_exists('mrwcmc_convert_amount')) {
    function mrwcmc_convert_amount($amount, string $to_currency): float
    {
        $amount = (float) $amount;
        if ($amount == 0.0) return 0.0;

        $to   = strtoupper($to_currency);
        $base = strtoupper(get_option('woocommerce_currency', 'USD'));
        if ($to === $base) return $amount;

        // Rate
        $rate = function_exists('mrwcmc_get_effective_rate') ? (float) mrwcmc_get_effective_rate($to) : 0.0;
        if ($rate <= 0) return $amount; // fail-safe

        $converted = $amount * $rate;

        // Markup
        $opt = function_exists('mrwcmc_get_option') ? mrwcmc_get_option() : [];
        $map = mrwcmc_parse_markup_map(isset($opt['markup_raw']) ? (string)$opt['markup_raw'] : '');
        if (isset($map[$to])) {
            $m = $map[$to];
            if ($m['type'] === 'percent') {
                $converted *= (1 + (float)$m['value'] / 100.0);
            } elseif ($m['type'] === 'fixed') {
                $converted += (float)$m['value'];
            }
        }

        // Rounding (decimals)
        $dec_map  = mrwcmc_get_decimals_map(isset($opt['rounding_raw']) ? (string)$opt['rounding_raw'] : '');
        $decimals = isset($dec_map[$to]) ? (int)$dec_map[$to] : (int) wc_get_price_decimals();
        $converted = round($converted, $decimals);

        return (float) apply_filters('mrwcmc_price_converted', $converted, $to);
    }
}

/*------------------------------------------------------------------------------
 * WooCommerce integrations
 *----------------------------------------------------------------------------*/

// Current currency code on frontend (affects symbol etc.)
if (!function_exists('mrwcmc_filter_woocommerce_currency')) {
    function mrwcmc_filter_woocommerce_currency($currency)
    {
        $cur = function_exists('mrwcmc_get_current_currency') ? mrwcmc_get_current_currency() : '';
        return $cur ?: $currency;
    }
    add_filter('woocommerce_currency', 'mrwcmc_filter_woocommerce_currency', 9999);
}

// Adjust price decimals per currency
if (!function_exists('mrwcmc_filter_price_decimals')) {
    function mrwcmc_filter_price_decimals($decimals)
    {
        if (is_admin()) return $decimals;
        $opt = function_exists('mrwcmc_get_option') ? mrwcmc_get_option() : [];
        $map = mrwcmc_get_decimals_map(isset($opt['rounding_raw']) ? (string)$opt['rounding_raw'] : '');
        $cur = function_exists('mrwcmc_get_current_currency') ? mrwcmc_get_current_currency() : '';
        if ($cur && isset($map[$cur])) return (int) $map[$cur];
        return $decimals;
    }
    add_filter('woocommerce_price_num_decimals', 'mrwcmc_filter_price_decimals', 20);
}

// Convert product prices (simple + variable parts)
if (!function_exists('mrwcmc_convert_product_price')) {
    function mrwcmc_convert_product_price($price, $product)
    {
        static $guard = false;
        if ($guard) return $price;

        if (is_admin()) return $price;
        if ($price === '' || $price === null || !is_numeric($price)) return $price;

        $to   = function_exists('mrwcmc_get_current_currency') ? mrwcmc_get_current_currency() : '';
        $base = strtoupper(get_option('woocommerce_currency', 'USD'));
        if (!$to || strtoupper($to) === $base) return $price;

        $guard = true;
        $price = mrwcmc_convert_amount((float)$price, strtoupper($to));
        $guard = false;
        return $price;
    }
    add_filter('woocommerce_product_get_price',         'mrwcmc_convert_product_price', 9999, 2);
    add_filter('woocommerce_product_get_regular_price', 'mrwcmc_convert_product_price', 9999, 2);
    add_filter('woocommerce_product_get_sale_price',    'mrwcmc_convert_product_price', 9999, 2);
}

/*------------------------------------------------------------------------------
 * Variable products: convert variation price arrays + vary cache by currency
 *----------------------------------------------------------------------------*/

// Vary the variation prices cache by current currency (and decimals for safety)
if (!function_exists('mrwcmc_variation_prices_hash')) {
    function mrwcmc_variation_prices_hash($hash, $product, $display)
    {
        if (!is_array($hash)) $hash = (array) $hash;
        $cur = function_exists('mrwcmc_get_current_currency') ? mrwcmc_get_current_currency() : '';
        $dec = (int) apply_filters('woocommerce_price_num_decimals', wc_get_price_decimals());
        $hash['mrwcmc_currency'] = $cur ?: strtoupper(get_option('woocommerce_currency', 'USD'));
        $hash['mrwcmc_decimals'] = $dec;
        return $hash;
    }
    add_filter('woocommerce_get_variation_prices_hash', 'mrwcmc_variation_prices_hash', 20, 3);
}

// Convert variation price arrays
if (!function_exists('mrwcmc_convert_variation_prices_val')) {
    function mrwcmc_convert_variation_prices_val($price)
    {
        if ($price === '' || $price === null || !is_numeric($price)) return $price;
        if (is_admin()) return $price;

        $to   = function_exists('mrwcmc_get_current_currency') ? mrwcmc_get_current_currency() : '';
        $base = strtoupper(get_option('woocommerce_currency', 'USD'));
        if (!$to || strtoupper($to) === $base) return $price;

        return function_exists('mrwcmc_convert_amount')
            ? mrwcmc_convert_amount((float)$price, strtoupper($to))
            : (float)$price;
    }
}

if (!function_exists('mrwcmc_filter_variation_prices_price')) {
    function mrwcmc_filter_variation_prices_price($price, $variation, $product)
    {
        return mrwcmc_convert_variation_prices_val($price);
    }
    add_filter('woocommerce_variation_prices_price',         'mrwcmc_filter_variation_prices_price', 9999, 3);
    add_filter('woocommerce_variation_prices_regular_price', 'mrwcmc_filter_variation_prices_price', 9999, 3);
    add_filter('woocommerce_variation_prices_sale_price',    'mrwcmc_filter_variation_prices_price', 9999, 3);
}

// Convert direct variation get_* calls
if (!function_exists('mrwcmc_convert_variation_direct')) {
    function mrwcmc_convert_variation_direct($price, $product)
    {
        static $guard = false;
        if ($guard) return $price;
        $guard = true;
        $price = mrwcmc_convert_variation_prices_val($price);
        $guard = false;
        return $price;
    }
    add_filter('woocommerce_product_variation_get_price',         'mrwcmc_convert_variation_direct', 9999, 2);
    add_filter('woocommerce_product_variation_get_regular_price', 'mrwcmc_convert_variation_direct', 9999, 2);
    add_filter('woocommerce_product_variation_get_sale_price',    'mrwcmc_convert_variation_direct', 9999, 2);
}

/**
 * Currency symbol follows 'woocommerce_currency'.
 * If you need custom symbols, use:
 *
 * add_filter('woocommerce_currency_symbol', function($symbol, $code){
 *     return $symbol; // or override for $code
 * }, 10, 2);
 */

// Read a cookie safely without touching $_COOKIE directly
if (!function_exists('mrwcmc_read_cookie')) {
    function mrwcmc_read_cookie(string $name): string
    {
        // Use filter_input to avoid slashed superglobals; then sanitize
        $val = filter_input(INPUT_COOKIE, $name, FILTER_UNSAFE_RAW, FILTER_NULL_ON_FAILURE);
        if (!is_string($val) || $val === '') {
            return '';
        }
        $val = sanitize_text_field($val);
        return $val;
    }
}
