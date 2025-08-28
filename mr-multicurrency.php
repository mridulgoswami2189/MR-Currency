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

/*-----------------------------------------------------------------------------
 * Constants
 *---------------------------------------------------------------------------*/
define('MRWCMC_VERSION', '0.1.0');
define('MRWCMC_FILE', __FILE__);
define('MRWCMC_PATH', plugin_dir_path(__FILE__));
define('MRWCMC_URL', plugin_dir_url(__FILE__));

/*-----------------------------------------------------------------------------
 * Defaults
 *---------------------------------------------------------------------------*/
if (!function_exists('mrwcmc_defaults')) {
    function mrwcmc_defaults()
    {
        $base = get_option('woocommerce_currency', 'USD');
        return array(
            'enabled'               => false,
            'base_currency'         => $base,
            'supported_currencies'  => array($base),
            'country_currency_map'  => array('*' => $base),
            'rate_provider'         => 'frankfurter',   // or exchangeratehost
            'rate_refresh'          => 'daily',         // manual|hourly|twicedaily|daily
            'manual_rates_raw'      => '',
            'markup_raw'            => '',
            'rounding_raw'          => '',
            'allow_user_switch'     => true,
            'geo_provider'          => 'auto',          // auto|ipinfo|wc|cloudflare
            'ipinfo_token'          => '',
        );
    }
}

/*-----------------------------------------------------------------------------
 * Activation: seed defaults
 *---------------------------------------------------------------------------*/
register_activation_hook(__FILE__, function () {
    if (!get_option('mrwcmc_settings')) {
        add_option('mrwcmc_settings', mrwcmc_defaults(), '', false);
    }
});

/*-----------------------------------------------------------------------------
 * HPOS compatibility declaration
 *---------------------------------------------------------------------------*/
add_action('before_woocommerce_init', function () {
    if (class_exists(\Automattic\WooCommerce\Utilities\FeaturesUtil::class)) {
        \Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility('custom_order_tables', __FILE__, true);
        // Uncomment if you explicitly support Cart/Checkout Blocks:
        // \Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility('cart_checkout_blocks', __FILE__, true);
    }
});

/*-----------------------------------------------------------------------------
 * i18n — load translations at init (WordPress 6.7+ requirement)
 *---------------------------------------------------------------------------*/
add_action('init', function () {
    load_plugin_textdomain('mr-multicurrency', false, dirname(plugin_basename(__FILE__)) . '/languages');
});

/*-----------------------------------------------------------------------------
 * Admin: notice if WooCommerce missing (runs late enough)
 *---------------------------------------------------------------------------*/
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

/*-----------------------------------------------------------------------------
 * Load includes (no output here; just hooking functions)
 *---------------------------------------------------------------------------*/
add_action('plugins_loaded', function () {
    foreach (
        [
            'includes/common.php',
            'includes/admin.php',
            'includes/rates.php',
            'includes/pricing.php',
            'includes/geo.php',
            'includes/checkout.php',
            'includes/switcher.php',
            'includes/rest.php',
            'includes/guard.php',
            'includes/test.php',
        ] as $rel
    ) {
        $p = MRWCMC_PATH . $rel;
        if (file_exists($p)) require_once $p;
    }
}, 1);
