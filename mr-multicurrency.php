<?php

/**
 * Plugin Name: MR Multi‑Currency
 * Description: Detects user geo-location and switches currency with auto/manual rates and per-currency markup.
 * Author: Mridul & Rohan
 * Version: 0.1.0
 * Requires at least: 6.0
 * Requires PHP: 8.3
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
 * Helpers
 *---------------------------------------------------------------------------*/
if (!function_exists('MRWCMC_wc_active')) {
    function MRWCMC_wc_active()
    {
        return class_exists('WooCommerce') || defined('WC_VERSION');
    }
}

if (!function_exists('MRWCMC_defaults')) {
    function MRWCMC_defaults()
    {
        $base = get_option('woocommerce_currency', 'USD');
        return array(
            'enabled'               => false,
            'base_currency'         => $base,
            'supported_currencies'  => array($base),
            'country_currency_map'  => array('*' => $base), // fallback
            'rate_provider'         => 'frankfurter',       // or exchangeratehost
            'rate_refresh'          => 'daily',             // manual|hourly|twicedaily|daily
            'manual_rates_raw'      => '',                  // lines: EUR=0.91
            'markup_raw'            => '',                  // lines: EUR=%:3.0  or  INR=fixed:5
            'rounding_raw'          => '',                  // lines: JPY=0
            'allow_user_switch'     => true,
        );
    }
}

/*-----------------------------------------------------------------------------
 * Activation: seed defaults
 *---------------------------------------------------------------------------*/
register_activation_hook(__FILE__, function () {
    if (!get_option('MRWCMC_settings')) {
        add_option('MRWCMC_settings', MRWCMC_defaults(), '', false);
    }
});

/*-----------------------------------------------------------------------------
 * Admin notice if WooCommerce is missing
 *---------------------------------------------------------------------------*/
add_action('admin_notices', function () {
    if (!current_user_can('activate_plugins')) return;
    if (!MRWCMC_wc_active()) {
        echo '<div class="notice notice-error"><p>' .
            esc_html__('WooCommerce Multi-Currency requires WooCommerce to be active.', 'wc-multicurrency') .
            '</p></div>';
    }
});

/*-----------------------------------------------------------------------------
 * Bootstrap
 *---------------------------------------------------------------------------*/
add_action('plugins_loaded', function () {
    if (!MRWCMC_wc_active()) return;

    // i18n
    load_plugin_textdomain('wc-multicurrency', false, dirname(plugin_basename(__FILE__)) . '/languages');

    // Future steps will add:
    // - admin settings (separate file)
    // - rate providers/caching
    // - geo detection + pricing filters
});

/**
 * Optionally load admin file later when it exists
 * (we’ll add includes/admin.php in the next step).
 */
add_action('init', function () {
    if (is_admin()) {
        $admin_file = MRWCMC_PATH . 'includes/admin.php';
        if (file_exists($admin_file)) {
            require_once $admin_file;
        }
    }
}, 0);
