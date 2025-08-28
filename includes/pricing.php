<?php
if (!defined('ABSPATH')) {
    exit;
}

/**
 * Pricing engine (functional, no classes)
 * - Current currency selection (param/cookie, fallback to base for now)
 * - Conversion using effective rate (manual override > auto from provider)
 * - Per-currency markup (%, fixed)
 * - Per-currency rounding (decimals)
 * - Hooks into WooCommerce price getters + currency/decimals display
 *
 *
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
if (!function_exists('mrwcmc_get_option')) {
    function mrwcmc_get_option(): array
    {
        $defaults = function_exists('mrwcmc_defaults') ? mrwcmc_defaults() : array();
        $opt = get_option('mrwcmc_settings', $defaults);
        if (!is_array($opt)) $opt = $defaults;
        return array_merge($defaults, $opt);
    }
}

if (!function_exists('mrwcmc_get_supported_currs')) {
    function mrwcmc_get_supported_currs(): array
    {
        $opt = mrwcmc_get_option();
        $currs = isset($opt['supported_currencies']) && is_array($opt['supported_currencies'])
            ? array_values(array_unique(array_map('strtoupper', $opt['supported_currencies'])))
            : [];
        $base = get_option('woocommerce_currency', 'USD');
        if (!in_array($base, $currs, true)) array_unshift($currs, $base);
        return $currs;
    }
}

/*------------------------------------------------------------------------------
 * Current currency (param/cookie → fallback base). Geo mapping in next file.
 *----------------------------------------------------------------------------*/
// Current currency (cookie only; geo.php sets it on first visit)
if (!function_exists('mrwcmc_get_current_currency')) {
    function mrwcmc_get_current_currency(): string
    {
        $opt       = mrwcmc_get_option();
        $supported = mrwcmc_get_supported_currs();
        $base      = get_option('woocommerce_currency', 'USD');

        // Only read cookie (no URL param). Geo sets it on first visit.
        if (!empty($_COOKIE['mrwcmc_currency'])) {
            $c = strtoupper(sanitize_text_field($_COOKIE['mrwcmc_currency']));
            if (in_array($c, $supported, true)) {
                /** Allow devs to override final currency */
                return apply_filters('mrwcmc_current_currency', $c);
            }
        }

        // Fallback: base (or geo.php will set cookie early)
        return apply_filters('mrwcmc_current_currency', $base);
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
            $cur = strtoupper(sanitize_text_field($cur));
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
        $amount = floatval($amount);
        if ($amount == 0.0) return 0.0;

        $to_currency = strtoupper($to_currency);
        $base = strtoupper(get_option('woocommerce_currency', 'USD'));
        if ($to_currency === $base) return $amount;

        // rate
        $rate = function_exists('mrwcmc_get_effective_rate') ? mrwcmc_get_effective_rate($to_currency) : 0.0;
        if ($rate <= 0) return $amount; // fail-safe: show base if no rate

        $converted = $amount * $rate;

        // markup
        $opt = mrwcmc_get_option();
        $map = mrwcmc_parse_markup_map(isset($opt['markup_raw']) ? $opt['markup_raw'] : '');
        if (isset($map[$to_currency])) {
            $m = $map[$to_currency];
            if ($m['type'] === 'percent') {
                $converted *= (1 + (float)$m['value'] / 100.0);
            } elseif ($m['type'] === 'fixed') {
                $converted += (float)$m['value'];
            }
        }

        // rounding (decimals)
        $dec_map = mrwcmc_get_decimals_map(isset($opt['rounding_raw']) ? $opt['rounding_raw'] : '');
        $decimals = isset($dec_map[$to_currency]) ? (int)$dec_map[$to_currency] : wc_get_price_decimals();
        $converted = round($converted, $decimals);

        return $converted;
    }
}

/*------------------------------------------------------------------------------
 * WooCommerce integrations
 *----------------------------------------------------------------------------*/

// Show the *current* currency code on frontend (we'll convert numbers below).
if (!function_exists('mrwcmc_filter_woocommerce_currency')) {
    function mrwcmc_filter_woocommerce_currency($currency)
    {
        if (is_admin()) return $currency;
        $cur = mrwcmc_get_current_currency();
        return $cur ?: $currency;
    }
    add_filter('woocommerce_currency', 'mrwcmc_filter_woocommerce_currency', 20);
}

// Adjust price decimals per currency
if (!function_exists('mrwcmc_filter_price_decimals')) {
    function mrwcmc_filter_price_decimals($decimals)
    {
        if (is_admin()) return $decimals;
        $opt = mrwcmc_get_option();
        $map = mrwcmc_get_decimals_map(isset($opt['rounding_raw']) ? $opt['rounding_raw'] : '');
        $cur = mrwcmc_get_current_currency();
        if ($cur && isset($map[$cur])) return (int)$map[$cur];
        return $decimals;
    }
    add_filter('woocommerce_price_num_decimals', 'mrwcmc_filter_price_decimals', 20);
}

// Convert product prices (simple + variable parts)
// Guard to avoid recursive conversions
if (!function_exists('mrwcmc_convert_product_price')) {
    function mrwcmc_convert_product_price($price, $product)
    {
        static $guard = false;
        if ($guard) return $price;

        if (is_admin()) return $price;
        if ($price === '' || $price === null) return $price;
        if (!is_numeric($price)) return $price;

        $to   = mrwcmc_get_current_currency();
        $base = get_option('woocommerce_currency', 'USD');
        if (!$to || strtoupper($to) === strtoupper($base)) return $price;

        $guard = true;
        $price = mrwcmc_convert_amount((float)$price, $to);
        $guard = false;
        return $price;
    }
    add_filter('woocommerce_product_get_price', 'mrwcmc_convert_product_price', 9999, 2);
    add_filter('woocommerce_product_get_regular_price', 'mrwcmc_convert_product_price', 9999, 2);
    add_filter('woocommerce_product_get_sale_price', 'mrwcmc_convert_product_price', 9999, 2);
}

/*------------------------------------------------------------------------------
 * Variable products: convert variation price arrays + make cache vary by currency
 *----------------------------------------------------------------------------*/

// 1) Vary the variation prices cache by current currency (and decimals for safety)
if (!function_exists('mrwcmc_variation_prices_hash')) {
    function mrwcmc_variation_prices_hash($hash, $product, $display)
    {
        if (!is_array($hash)) $hash = (array) $hash;
        $cur = function_exists('mrwcmc_get_current_currency') ? mrwcmc_get_current_currency() : '';
        $dec = (int) apply_filters('woocommerce_price_num_decimals', wc_get_price_decimals());
        $hash['mrwcmc_currency'] = $cur ?: get_option('woocommerce_currency', 'USD');
        $hash['mrwcmc_decimals'] = $dec;
        return $hash;
    }
    // WooCommerce filter name
    add_filter('woocommerce_get_variation_prices_hash', 'mrwcmc_variation_prices_hash', 20, 3);
}

// 2) Convert the variation price arrays used for min/max range and displays
if (!function_exists('mrwcmc_convert_variation_prices_val')) {
    function mrwcmc_convert_variation_prices_val($price)
    {
        if ($price === '' || $price === null || !is_numeric($price)) return $price;
        if (is_admin()) return $price;

        $to   = function_exists('mrwcmc_get_current_currency') ? mrwcmc_get_current_currency() : '';
        $base = get_option('woocommerce_currency', 'USD');
        if (!$to || strtoupper($to) === strtoupper($base)) return $price;

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
    add_filter('woocommerce_variation_prices_price', 'mrwcmc_filter_variation_prices_price', 9999, 3);
    add_filter('woocommerce_variation_prices_regular_price', 'mrwcmc_filter_variation_prices_price', 9999, 3);
    add_filter('woocommerce_variation_prices_sale_price', 'mrwcmc_filter_variation_prices_price', 9999, 3);
}

// 3) Also convert direct variation get_* calls (some themes/plugins read directly)
if (!function_exists('mrwcmc_convert_variation_direct')) {
    function mrwcmc_convert_variation_direct($price, $product)
    {
        // Guard against double-work
        static $guard = false;
        if ($guard) return $price;
        $guard = true;
        $price = mrwcmc_convert_variation_prices_val($price);
        $guard = false;
        return $price;
    }
    add_filter('woocommerce_product_variation_get_price', 'mrwcmc_convert_variation_direct', 9999, 2);
    add_filter('woocommerce_product_variation_get_regular_price', 'mrwcmc_convert_variation_direct', 9999, 2);
    add_filter('woocommerce_product_variation_get_sale_price', 'mrwcmc_convert_variation_direct', 9999, 2);
}


// Currency symbol automatically follows 'woocommerce_currency', so no extra filter
// necessary unless you want custom symbols per currency. If needed, uncomment:
//
// add_filter('woocommerce_currency_symbol', function($symbol, $code){
//     return $symbol; // or override for specific $code
// }, 10, 2);

/*------------------------------------------------------------------------------
 * Tiny currency switcher (optional): [mrwcmc_switcher]
 *----------------------------------------------------------------------------*/
if (!function_exists('mrwcmc_switcher_shortcode')) {
    function mrwcmc_switcher_shortcode($atts = [])
    {
        $supported = mrwcmc_get_supported_currs();
        if (empty($supported)) return '';
        $current = mrwcmc_get_current_currency();

        // Build links preserving current URL + adding ?currency=XYZ
        $out = '<div class="mrwcmc-switcher">';
        foreach ($supported as $c) {
            $url = add_query_arg('currency', $c);
            $cls = $c === $current ? ' style="font-weight:600;text-decoration:underline;"' : '';
            $out .= '<a' . $cls . ' href="' . esc_url($url) . '">' . esc_html($c) . '</a> ';
        }
        $out .= '</div>';
        return $out;
    }
    add_shortcode('mrwcmc_switcher', 'mrwcmc_switcher_shortcode');
}
