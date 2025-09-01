<?php
// File: wp-content/plugins/mr-multicurrency/includes/rates.php
if (!defined('ABSPATH')) {
    exit;
}

/**
 * Rates: providers + caching + effective rates (functional style)
 *
 * - Auto rates via Frankfurter (ECB) or exchangerate.host
 * - Manual rate overrides
 * - Transient caching + WP-Cron refresh based on settings
 * - Public helpers:
 *      mrwcmc_get_effective_rate( $currency, $force_refresh = false ) : float
 *      mrwcmc_get_effective_rates( array $currencies = [], $force_refresh = false ) : array
 */

/*------------------------------------------------------------------------------
 * Utilities
 *----------------------------------------------------------------------------*/

/**
 * Parse "manual rates" textarea into ['CUR' => float, ...]
 * Example lines:
 *   EUR=0.91
 *   INR=84.30
 */
if (!function_exists('mrwcmc_parse_manual_rates')) {
    function mrwcmc_parse_manual_rates(string $raw): array
    {
        $out = [];
        $raw = trim($raw);
        if ($raw === '') {
            return $out;
        }

        foreach (preg_split('/\r?\n/', $raw) as $line) {
            $line = trim($line);
            if ($line === '' || strpos($line, '=') === false) {
                continue;
            }

            list($cur, $rate) = array_map('trim', explode('=', $line, 2));
            $cur  = strtoupper(sanitize_text_field($cur));
            $rate = floatval(str_replace(',', '.', (string)$rate));
            if ($cur && $rate > 0) {
                $out[$cur] = $rate;
            }
        }
        return $out;
    }
}

/**
 * Supported currencies (ensures base is present)
 * (Defined here only if common.php didn't already define it.)
 */
if (!function_exists('mrwcmc_get_supported_currs')) {
    function mrwcmc_get_supported_currs(): array
    {
        $opt = function_exists('mrwcmc_get_option') ? mrwcmc_get_option() : [];
        $currs = isset($opt['supported_currencies']) && is_array($opt['supported_currencies'])
            ? array_values(array_unique(array_map('strtoupper', $opt['supported_currencies'])))
            : [];
        $base = get_option('woocommerce_currency', 'USD');
        if (!in_array($base, $currs, true)) {
            array_unshift($currs, strtoupper($base));
        }
        return $currs;
    }
}

/*------------------------------------------------------------------------------
 * Transient key + TTL
 *----------------------------------------------------------------------------*/
if (!function_exists('mrwcmc_rates_transient_key')) {
    function mrwcmc_rates_transient_key(): string
    {
        $base = strtoupper(get_option('woocommerce_currency', 'USD'));
        $opt  = function_exists('mrwcmc_get_option') ? mrwcmc_get_option() : [];
        $prov = isset($opt['rate_provider']) ? (string)$opt['rate_provider'] : 'frankfurter';
        return 'mrwcmc_rates_' . strtolower($base) . '_' . $prov;
    }
}

if (!function_exists('mrwcmc_rates_ttl')) {
    function mrwcmc_rates_ttl(): int
    {
        $opt = function_exists('mrwcmc_get_option') ? mrwcmc_get_option() : [];
        $s   = isset($opt['rate_refresh']) ? (string)$opt['rate_refresh'] : 'daily';
        if ($s === 'hourly')     return HOUR_IN_SECONDS;
        if ($s === 'twicedaily') return 12 * HOUR_IN_SECONDS;
        if ($s === 'daily')      return DAY_IN_SECONDS;
        // manual
        return 0;
    }
}

/*------------------------------------------------------------------------------
 * Provider fetchers
 *----------------------------------------------------------------------------*/
if (!function_exists('mrwcmc_fetch_from_frankfurter')) {
    function mrwcmc_fetch_from_frankfurter(string $base, array $symbols): array
    {
        // https://api.frankfurter.app/latest?from=USD&to=EUR,GBP
        $base    = strtoupper(trim($base));
        $symbols = array_values(array_unique(array_filter(array_map('strtoupper', $symbols), function ($c) use ($base) {
            return $c !== $base;
        })));
        if (empty($symbols)) {
            return [$base => 1.0];
        }

        $url = add_query_arg([
            'from' => $base,
            'to'   => implode(',', $symbols),
        ], 'https://api.frankfurter.app/latest');

        $res  = wp_remote_get($url, ['timeout' => 10]);
        if (is_wp_error($res)) {
            return [];
        }
        $code = (int) wp_remote_retrieve_response_code($res);
        if ($code !== 200) {
            return [];
        }

        $body  = json_decode(wp_remote_retrieve_body($res), true);
        $rates = (isset($body['rates']) && is_array($body['rates'])) ? $body['rates'] : [];

        $out = [];
        foreach ($symbols as $c) {
            if (isset($rates[$c]) && is_numeric($rates[$c])) {
                $out[$c] = (float) $rates[$c];
            }
        }
        return $out;
    }
}

if (!function_exists('mrwcmc_fetch_from_exchangeratehost')) {
    function mrwcmc_fetch_from_exchangeratehost(string $base, array $symbols): array
    {
        // https://api.exchangerate.host/latest?base=USD&symbols=EUR,GBP
        $base    = strtoupper(trim($base));
        $symbols = array_values(array_unique(array_filter(array_map('strtoupper', $symbols), function ($c) use ($base) {
            return $c !== $base;
        })));
        if (empty($symbols)) {
            return [$base => 1.0];
        }

        $url = add_query_arg([
            'base'    => $base,
            'symbols' => implode(',', $symbols),
        ], 'https://api.exchangerate.host/latest');

        $res  = wp_remote_get($url, ['timeout' => 10]);
        if (is_wp_error($res)) {
            return [];
        }
        $code = (int) wp_remote_retrieve_response_code($res);
        if ($code !== 200) {
            return [];
        }

        $body  = json_decode(wp_remote_retrieve_body($res), true);
        $rates = (isset($body['rates']) && is_array($body['rates'])) ? $body['rates'] : [];

        $out = [];
        foreach ($symbols as $c) {
            if (isset($rates[$c]) && is_numeric($rates[$c])) {
                $out[$c] = (float) $rates[$c];
            }
        }
        return $out;
    }
}

/*------------------------------------------------------------------------------
 * Refresh & get auto rates
 *----------------------------------------------------------------------------*/
if (!function_exists('mrwcmc_refresh_rates')) {
    function mrwcmc_refresh_rates(): array
    {
        $opt   = function_exists('mrwcmc_get_option') ? mrwcmc_get_option() : [];
        $base  = strtoupper(get_option('woocommerce_currency', 'USD'));
        $all   = function_exists('mrwcmc_get_supported_currs') ? mrwcmc_get_supported_currs() : [$base];
        $prov  = isset($opt['rate_provider']) ? (string)$opt['rate_provider'] : 'frankfurter';

        $symbols = array_values(array_unique(array_map('strtoupper', $all)));

        if ($prov === 'exchangeratehost') {
            $auto = mrwcmc_fetch_from_exchangeratehost($base, $symbols);
        } else {
            $auto = mrwcmc_fetch_from_frankfurter($base, $symbols);
        }

        // Ensure base is present
        $auto[$base] = 1.0;

        $payload = [
            'base'       => $base,
            'provider'   => $prov,
            'fetched_at' => time(),
            'rates'      => $auto,
        ];

        // Cache according to schedule (for 'manual' keep a short cache to avoid thrashing)
        $ttl = mrwcmc_rates_ttl();
        if ($ttl <= 0) {
            $ttl = 5 * MINUTE_IN_SECONDS;
        }
        set_transient(mrwcmc_rates_transient_key(), $payload, $ttl);

        /**
         * Action fires after rates are refreshed & cached.
         *
         * @param array $payload { base, provider, fetched_at, rates }
         */
        do_action('mrwcmc_rates_refreshed', $payload);

        return $payload;
    }
}

if (!function_exists('mrwcmc_get_auto_rates')) {
    function mrwcmc_get_auto_rates(bool $force_refresh = false): array
    {
        $key    = mrwcmc_rates_transient_key();
        $cached = get_transient($key);

        if ($force_refresh || !is_array($cached) || empty($cached['rates'])) {
            $cached = mrwcmc_refresh_rates();
        }
        return (is_array($cached)) ? $cached : ['base' => strtoupper(get_option('woocommerce_currency', 'USD')), 'provider' => 'frankfurter', 'fetched_at' => 0, 'rates' => []];
    }
}

/*------------------------------------------------------------------------------
 * Effective rate(s): manual overrides win
 *----------------------------------------------------------------------------*/
if (!function_exists('mrwcmc_get_effective_rate')) {
    function mrwcmc_get_effective_rate(string $currency, bool $force_refresh = false): float
    {
        $currency = strtoupper(trim($currency));
        if ($currency === '') {
            return 0.0;
        }

        $auto   = mrwcmc_get_auto_rates($force_refresh);
        $rates  = isset($auto['rates']) && is_array($auto['rates']) ? $auto['rates'] : [];
        $opt    = function_exists('mrwcmc_get_option') ? mrwcmc_get_option() : [];
        $manual = mrwcmc_parse_manual_rates(isset($opt['manual_rates_raw']) ? (string)$opt['manual_rates_raw'] : '');

        if (isset($manual[$currency]) && $manual[$currency] > 0) {
            return (float)$manual[$currency];
        }
        if (isset($rates[$currency])  && $rates[$currency]  > 0) {
            return (float)$rates[$currency];
        }

        $base = strtoupper(get_option('woocommerce_currency', 'USD'));
        if ($currency === $base) {
            return 1.0;
        }

        /**
         * Allow last-chance override if no rate is found.
         *
         * @param float  $rate
         * @param string $currency
         */
        return (float) apply_filters('mrwcmc_effective_rate_missing', 0.0, $currency);
    }
}

if (!function_exists('mrwcmc_get_effective_rates')) {
    function mrwcmc_get_effective_rates(array $currencies = [], bool $force_refresh = false): array
    {
        if (empty($currencies)) {
            $currencies = function_exists('mrwcmc_get_supported_currs') ? mrwcmc_get_supported_currs() : [get_option('woocommerce_currency', 'USD')];
        }
        $out = [];
        foreach ($currencies as $c) {
            $out[strtoupper($c)] = mrwcmc_get_effective_rate($c, $force_refresh);
        }
        /**
         * Filter to adjust the entire effective rate map at once.
         *
         * @param array $out
         */
        return (array) apply_filters('mrwcmc_effective_rates_map', $out);
    }
}

/*------------------------------------------------------------------------------
 * Cron scheduling (manual|hourly|twicedaily|daily)
 *----------------------------------------------------------------------------*/
if (!function_exists('mrwcmc_schedule_rates_cron')) {
    function mrwcmc_schedule_rates_cron(): void
    {
        $opt  = function_exists('mrwcmc_get_option') ? mrwcmc_get_option() : [];
        $freq = isset($opt['rate_refresh']) ? (string)$opt['rate_refresh'] : 'daily';

        // Clear any existing
        wp_clear_scheduled_hook('mrwcmc_cron_refresh_rates');

        if ($freq === 'manual') {
            return;
        }

        $interval = in_array($freq, ['hourly', 'twicedaily', 'daily'], true) ? $freq : 'daily';
        if (!wp_next_scheduled('mrwcmc_cron_refresh_rates')) {
            wp_schedule_event(time() + 30, $interval, 'mrwcmc_cron_refresh_rates');
        }
    }

    // Initial schedule on load
    add_action('plugins_loaded', 'mrwcmc_schedule_rates_cron');
    // Reschedule when settings change
    add_action('update_option_mrwcmc_settings', function ($old, $new) {
        mrwcmc_schedule_rates_cron();
    }, 10, 2);

    // The cron task
    add_action('mrwcmc_cron_refresh_rates', 'mrwcmc_refresh_rates');
}

/*------------------------------------------------------------------------------
 * Admin: "Refresh rates now" button + secure handler (nonce)
 *----------------------------------------------------------------------------*/

/**
 * Show a notice with a nonce-protected "Refresh rates now" button on our settings screen.
 */
if (!function_exists('mrwcmc_admin_refresh_notice')) {
    function mrwcmc_admin_refresh_notice(): void
    {
        if (!is_admin() || !current_user_can('manage_woocommerce')) {
            return;
        }

        $screen = function_exists('get_current_screen') ? get_current_screen() : null;
        if ($screen && strpos((string)$screen->id, 'mrwcmc') === false) {
            return;
        }

        $url = wp_nonce_url(
            admin_url('admin-post.php?action=mrwcmc_refresh_rates'),
            'mrwcmc_refresh_rates'
        );

        echo '<div class="notice notice-info"><p>'
            . '<a class="button" href="' . esc_url($url) . '">' . esc_html__('Refresh rates now', 'mr-multicurrency') . '</a>'
            . ' <span style="opacity:.7;margin-left:8px;">'
            . esc_html__('(Uses your selected provider and caches by schedule)', 'mr-multicurrency')
            . '</span></p></div>';
    }
    add_action('admin_notices', 'mrwcmc_admin_refresh_notice');
}

/**
 * Handle the refresh as an admin-post endpoint (nonce + capability).
 */
if (!function_exists('mrwcmc_admin_do_refresh_rates')) {
    function mrwcmc_admin_do_refresh_rates(): void
    {
        if (!current_user_can('manage_woocommerce')) {
            wp_die(esc_html__('Not allowed.', 'mr-multicurrency'));
        }
        // Verify nonce
        $nonce = isset($_GET['_wpnonce']) ? sanitize_text_field(wp_unslash($_GET['_wpnonce'])) : '';
        if (!wp_verify_nonce($nonce, 'mrwcmc_refresh_rates')) {
            wp_die(esc_html__('Nonce check failed.', 'mr-multicurrency'));
        }

        mrwcmc_refresh_rates();

        // Mark success for this user via a short-lived transient
        $user_id = get_current_user_id();
        if ($user_id) {
            set_transient('mrwcmc_refreshed_' . $user_id, time(), MINUTE_IN_SECONDS);
        }

        // Redirect back (no GET params needed)
        $back = wp_get_referer();
        if (!$back) {
            $back = admin_url('admin.php?page=mrwcmc');
        }

        wp_safe_redirect($back);
        exit;
    }
    add_action('admin_post_mrwcmc_refresh_rates', 'mrwcmc_admin_do_refresh_rates');
}

/**
 * Success notice after redirect (reads a harmless GET flag; no state change).
 */
if (!function_exists('mrwcmc_admin_refresh_success_notice')) {
    function mrwcmc_admin_refresh_success_notice(): void
    {
        if (!is_admin() || !current_user_can('manage_woocommerce')) {
            return;
        }

        $screen = function_exists('get_current_screen') ? get_current_screen() : null;
        if ($screen && strpos((string) $screen->id, 'mrwcmc') === false) {
            return;
        }

        $user_id = get_current_user_id();
        if (!$user_id) {
            return;
        }

        $key = 'mrwcmc_refreshed_' . $user_id;
        if (!get_transient($key)) {
            return;
        }

        // One-shot: clear the flag, then show notice
        delete_transient($key);

        echo '<div class="notice notice-success is-dismissible"><p>'
            . esc_html__('Exchange rates refreshed.', 'mr-multicurrency')
            . '</p></div>';
    }
    add_action('admin_notices', 'mrwcmc_admin_refresh_success_notice');
}
