<?php

/**
 * Plugin Name: MR Multi-Currency (Geo + Markup)
 * Description: Auto-geo currency, auto/manual FX rates, per-currency markup & rounding for WooCommerce. Functional-style, HPOS-compatible.
 * Author: Mridul and Rohan
 * Version: 0.1.0
 * Requires at least: 6.0
 * Requires PHP: 7.4
 * Text Domain: mr-multicurrency
 * Domain Path: /languages
 * WC tested up to: 9.0
 */

if (!defined('ABSPATH')) {
    exit;
}

/* Constants */
define('MRWCMC_VERSION', '0.1.0');
define('MRWCMC_FILE', __FILE__);
define('MRWCMC_PATH', plugin_dir_path(__FILE__));
define('MRWCMC_URL',  plugin_dir_url(__FILE__));

/* Defaults */
if (!function_exists('mrwcmc_defaults')) {
    function mrwcmc_defaults()
    {
        $base = get_option('woocommerce_currency', 'USD');
        return [
            'enabled'               => false,
            'base_currency'         => $base,
            'supported_currencies'  => [$base],
            'country_currency_map'  => ['*' => $base],
            'rate_provider'         => 'frankfurter',
            'rate_refresh'          => 'daily', // manual|hourly|twicedaily|daily
            'manual_rates_raw'      => '',
            'markup_raw'            => '',
            'rounding_raw'          => '',
            'allow_user_switch'     => true,
            'geo_provider'          => 'auto', // auto|ipinfo|wc|cloudflare
            'ipinfo_token'          => '',
        ];
    }
}

/* Activation */
register_activation_hook(__FILE__, function () {
    if (!get_option('mrwcmc_settings')) {
        add_option('mrwcmc_settings', mrwcmc_defaults(), '', false);
    }
});

/* HPOS compatibility */
add_action('before_woocommerce_init', function () {
    if (class_exists(\Automattic\WooCommerce\Utilities\FeaturesUtil::class)) {
        \Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility('custom_order_tables', __FILE__, true);
    }
});

/* i18n on init (WordPress 6.7+ requirement) */
add_action('init', function () {
    load_plugin_textdomain('mr-multicurrency', false, dirname(plugin_basename(__FILE__)) . '/languages');
});

/* Admin notice if Woo missing */
if (!function_exists('mrwcmc_wc_active')) {
    function mrwcmc_wc_active()
    {
        return class_exists('WooCommerce') || defined('WC_VERSION');
    }
}
add_action('admin_notices', function () {
    if (!current_user_can('activate_plugins')) return;
    if (!mrwcmc_wc_active()) {
        echo '<div class="notice notice-error"><p>' .
            esc_html__('MR Multi-Currency requires WooCommerce to be active.', 'mr-multicurrency') .
            '</p></div>';
    }
});

/* Load includes on init (safe timing) */
add_action('init', function () {
    foreach (
        [
            'includes/common.php',
            'includes/admin.php',
            'includes/rates.php',
            'includes/pricing.php',
            'includes/geo.php',
            'includes/checkout.php',
            'includes/switcher.php',
            'includes/guard.php',
            'includes/actions.php',
            'includes/tests.php', // only load if you really need it in prod
        ] as $rel
    ) {
        $p = MRWCMC_PATH . $rel;
        if (file_exists($p)) require_once $p;
    }
}, 0);
