<?php
// File: wp-content/plugins/mr-multicurrency/includes/rates.php
if (!defined('ABSPATH')) {
    exit;
}

/**
 * STEP: Rate providers + caching (functional style)
 * - Auto rates from Frankfurter (ECB) or exchangerate.host
 * - Manual rate overrides
 * - Transient caching + WP-Cron refresh based on settings
 * - Public helpers: mrwcmc_get_effective_rate(), mrwcmc_get_effective_rates()
 */

/*------------------------------------------------------------------------------
 * Utilities
 *----------------------------------------------------------------------------*/

// if (!function_exists('mrwcmc_get_option')) {
//     function mrwcmc_get_option() : array {
//         $defaults = function_exists('mrwcmc_defaults') ? mrwcmc_defaults() : array();
//         $opt = get_option('mrwcmc_settings', $defaults);
//         if (!is_array($opt)) $opt = $defaults;
//         return array_merge($defaults, $opt);
//     }
// }

if (!function_exists('mrwcmc_parse_manual_rates')) {
    function mrwcmc_parse_manual_rates(string $raw): array
    {
        $out = [];
        $raw = trim($raw);
        if ($raw === '') return $out;
        foreach (preg_split('/\r?\n/', $raw) as $line) {
            $line = trim($line);
            if ($line === '' || strpos($line, '=') === false) continue;
            list($cur, $rate) = array_map('trim', explode('=', $line, 2));
            $cur  = strtoupper(sanitize_text_field($cur));
            $rate = floatval(str_replace(',', '.', $rate));
            if ($cur && $rate > 0) $out[$cur] = $rate;
        }
        return $out;
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
 * Transient key + TTL based on schedule
 *----------------------------------------------------------------------------*/
if (!function_exists('mrwcmc_rates_transient_key')) {
    function mrwcmc_rates_transient_key(): string
    {
        $base = get_option('woocommerce_currency', 'USD');
        $opt  = mrwcmc_get_option();
        $prov = isset($opt['rate_provider']) ? $opt['rate_provider'] : 'frankfurter';
        return 'mrwcmc_rates_' . strtolower($base) . '_' . $prov;
    }
}

if (!function_exists('mrwcmc_rates_ttl')) {
    function mrwcmc_rates_ttl(): int
    {
        $opt = mrwcmc_get_option();
        $s = isset($opt['rate_refresh']) ? $opt['rate_refresh'] : 'daily';
        if ($s === 'hourly')      return HOUR_IN_SECONDS;
        if ($s === 'twicedaily')  return 12 * HOUR_IN_SECONDS;
        if ($s === 'daily')       return DAY_IN_SECONDS;
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
        $symbols = array_values(array_unique(array_filter($symbols, function ($c) use ($base) {
            return strtoupper($c) !== strtoupper($base);
        })));
        $url = add_query_arg([
            'from' => $base,
            'to'   => implode(',', $symbols),
        ], 'https://api.frankfurter.app/latest');
        $res = wp_remote_get($url, ['timeout' => 10]);
        if (is_wp_error($res)) return [];
        $code = wp_remote_retrieve_response_code($res);
        if ($code !== 200) return [];
        $body = json_decode(wp_remote_retrieve_body($res), true);
        $rates = isset($body['rates']) && is_array($body['rates']) ? $body['rates'] : [];
        $out = [];
        foreach ($symbols as $c) {
            if (isset($rates[$c]) && is_numeric($rates[$c])) {
                $out[$c] = floatval($rates[$c]);
            }
        }
        return $out;
    }
}

if (!function_exists('mrwcmc_fetch_from_exchangeratehost')) {
    function mrwcmc_fetch_from_exchangeratehost(string $base, array $symbols): array
    {
        // https://api.exchangerate.host/latest?base=USD&symbols=EUR,GBP
        $symbols = array_values(array_unique(array_filter($symbols, function ($c) use ($base) {
            return strtoupper($c) !== strtoupper($base);
        })));
        $url = add_query_arg([
            'base'    => $base,
            'symbols' => implode(',', $symbols),
        ], 'https://api.exchangerate.host/latest');
        $res = wp_remote_get($url, ['timeout' => 10]);
        if (is_wp_error($res)) return [];
        $code = wp_remote_retrieve_response_code($res);
        if ($code !== 200) return [];
        $body = json_decode(wp_remote_retrieve_body($res), true);
        $rates = isset($body['rates']) && is_array($body['rates']) ? $body['rates'] : [];
        $out = [];
        foreach ($symbols as $c) {
            if (isset($rates[$c]) && is_numeric($rates[$c])) {
                $out[$c] = floatval($rates[$c]);
            }
        }
        return $out;
    }
}

/*------------------------------------------------------------------------------
 * Fetch/merge/store auto rates
 *----------------------------------------------------------------------------*/
if (!function_exists('mrwcmc_refresh_rates')) {
    function mrwcmc_refresh_rates(): array
    {
        $opt  = mrwcmc_get_option();
        $base = get_option('woocommerce_currency', 'USD');
        $all  = mrwcmc_get_supported_currs();

        $provider = isset($opt['rate_provider']) ? $opt['rate_provider'] : 'frankfurter';
        $symbols  = array_values(array_unique($all));
        $auto = [];

        if ($provider === 'exchangeratehost') {
            $auto = mrwcmc_fetch_from_exchangeratehost($base, $symbols);
        } else {
            $auto = mrwcmc_fetch_from_frankfurter($base, $symbols);
        }

        // Always include base at 1
        $auto[strtoupper($base)] = 1.0;

        $payload = [
            'base'      => strtoupper($base),
            'provider'  => $provider,
            'fetched_at' => time(),
            'rates'     => $auto,
        ];

        // Cache in transient (respect schedule TTL; for manual, keep short cache 5 min to avoid thrash)
        $ttl = mrwcmc_rates_ttl();
        if ($ttl <= 0) {
            $ttl = 5 * MINUTE_IN_SECONDS;
        }
        set_transient(mrwcmc_rates_transient_key(), $payload, $ttl);

        return $payload;
    }
}

if (!function_exists('mrwcmc_get_auto_rates')) {
    function mrwcmc_get_auto_rates(bool $force_refresh = false): array
    {
        $key = mrwcmc_rates_transient_key();
        $cached = get_transient($key);
        if ($force_refresh || !is_array($cached) || empty($cached['rates'])) {
            $cached = mrwcmc_refresh_rates();
        }
        return is_array($cached) ? $cached : ['rates' => []];
    }
}

/*------------------------------------------------------------------------------
 * Effective rate(s): manual overrides win over auto
 *----------------------------------------------------------------------------*/
if (!function_exists('mrwcmc_get_effective_rate')) {
    function mrwcmc_get_effective_rate(string $currency, bool $force_refresh = false): float
    {
        $currency = strtoupper(trim($currency));
        $auto = mrwcmc_get_auto_rates($force_refresh);
        $rates = isset($auto['rates']) ? $auto['rates'] : [];
        $opt = mrwcmc_get_option();
        $manual = mrwcmc_parse_manual_rates(isset($opt['manual_rates_raw']) ? $opt['manual_rates_raw'] : '');
        if (isset($manual[$currency]) && $manual[$currency] > 0) return (float)$manual[$currency];
        if (isset($rates[$currency]) && $rates[$currency] > 0)   return (float)$rates[$currency];
        // Fallback: if asking for base currency, return 1.0
        $base = strtoupper(get_option('woocommerce_currency', 'USD'));
        if ($currency === $base) return 1.0;
        return 0.0; // unknown
    }
}

if (!function_exists('mrwcmc_get_effective_rates')) {
    function mrwcmc_get_effective_rates(array $currencies = [], bool $force_refresh = false): array
    {
        if (empty($currencies)) $currencies = mrwcmc_get_supported_currs();
        $out = [];
        foreach ($currencies as $c) {
            $out[strtoupper($c)] = mrwcmc_get_effective_rate($c, $force_refresh);
        }
        return $out;
    }
}

/*------------------------------------------------------------------------------
 * Cron: schedule based on settings (manual/hourly/twicedaily/daily)
 *----------------------------------------------------------------------------*/
if (!function_exists('mrwcmc_schedule_rates_cron')) {
    function mrwcmc_schedule_rates_cron()
    {
        $opt = mrwcmc_get_option();
        $freq = isset($opt['rate_refresh']) ? $opt['rate_refresh'] : 'daily';

        // Clear any existing schedules
        wp_clear_scheduled_hook('mrwcmc_cron_refresh_rates');

        if ($freq === 'manual') return;

        $interval = in_array($freq, ['hourly', 'twicedaily', 'daily'], true) ? $freq : 'daily';
        if (!wp_next_scheduled('mrwcmc_cron_refresh_rates')) {
            wp_schedule_event(time() + 30, $interval, 'mrwcmc_cron_refresh_rates');
        }
    }

    add_action('plugins_loaded', 'mrwcmc_schedule_rates_cron'); // initial schedule
    add_action('update_option_mrwcmc_settings', function ($old, $new) {
        mrwcmc_schedule_rates_cron();
    }, 10, 2);
    add_action('mrwcmc_cron_refresh_rates', 'mrwcmc_refresh_rates');
}

/*------------------------------------------------------------------------------
 * Admin: simple "Refresh now" action (optional)
 * - Visit: /wp-admin/admin.php?page=mrwcmc&mrwcmc_refresh=1&_wpnonce=XYZ
 *----------------------------------------------------------------------------*/
if (!function_exists('mrwcmc_admin_maybe_refresh_now')) {
    function mrwcmc_admin_maybe_refresh_now()
    {
        if (!is_admin() || !current_user_can('manage_woocommerce')) return;
        if (!isset($_GET['page']) || $_GET['page'] !== 'mrwcmc') return;

        if (isset($_GET['mrwcmc_refresh']) && $_GET['mrwcmc_refresh'] === '1') {
            check_admin_referer('mrwcmc_refresh_rates');
            mrwcmc_refresh_rates();
            add_action('admin_notices', function () {
                echo '<div class="notice notice-success is-dismissible"><p>' .
                    esc_html__('Exchange rates refreshed.', 'mr-multicurrency') .
                    '</p></div>';
            });
        }

        // Tiny inline link under the title (doesn’t require editing admin.php)
        add_action('admin_notices', function () {
            if (!isset($_GET['page']) || $_GET['page'] !== 'mrwcmc') return;
            $url = wp_nonce_url(add_query_arg(['mrwcmc_refresh' => '1']), 'mrwcmc_refresh_rates');
            echo '<div class="notice notice-info"><p>' .
                '<a class="button" href="' . esc_url($url) . '">' . esc_html__('Refresh rates now', 'mr-multicurrency') . '</a>' .
                ' <span style="opacity:.7;margin-left:8px;">' . esc_html__('(Uses your selected provider and caches by schedule)', 'mr-multicurrency') . '</span>' .
                '</p></div>';
        });
    }
    add_action('admin_init', 'mrwcmc_admin_maybe_refresh_now');
}
