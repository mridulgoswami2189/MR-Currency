<?php
if (!defined('ABSPATH')) {
    exit;
}

/**
 * If URL contains ?mrwcmc= (or legacy ?currency=/ ?mrwcmc_currency=),
 * set cookie then 302 to same URL without the param(s).
 */
// Convert all product prices
if (!function_exists('mrwcmc_convert_product_price')) {
    function mrwcmc_convert_product_price($price, $product)
    {
        if ($price === '' || $price === null || !is_numeric($price)) return $price;
        $to   = mrwcmc_get_current_currency();
        $base = get_option('woocommerce_currency', 'USD');
        if (strtoupper($to) === strtoupper($base)) return $price;
        return function_exists('mrwcmc_convert_amount') ? mrwcmc_convert_amount((float)$price, $to) : $price;
    }
    add_filter('woocommerce_product_get_price', 'mrwcmc_convert_product_price', 9999, 2);
    add_filter('woocommerce_product_get_regular_price', 'mrwcmc_convert_product_price', 9999, 2);
    add_filter('woocommerce_product_get_sale_price', 'mrwcmc_convert_product_price', 9999, 2);
}

// Variation arrays + cache hash
if (!function_exists('mrwcmc_variation_prices_hash')) {
    function mrwcmc_variation_prices_hash($hash, $product, $display)
    {
        if (!is_array($hash)) $hash = (array) $hash;
        $hash['mrwcmc_currency'] = mrwcmc_get_current_currency();
        $hash['mrwcmc_decimals'] = (int) apply_filters('woocommerce_price_num_decimals', wc_get_price_decimals());
        return $hash;
    }
    add_filter('woocommerce_get_variation_prices_hash', 'mrwcmc_variation_prices_hash', 9999, 3);
}
if (!function_exists('mrwcmc_filter_variation_prices_price')) {
    function mrwcmc_filter_variation_prices_price($price)
    {
        return (is_numeric($price)) ? mrwcmc_convert_product_price($price, null) : $price;
    }
    add_filter('woocommerce_variation_prices_price', 'mrwcmc_filter_variation_prices_price', 9999);
    add_filter('woocommerce_variation_prices_regular_price', 'mrwcmc_filter_variation_prices_price', 9999);
    add_filter('woocommerce_variation_prices_sale_price', 'mrwcmc_filter_variation_prices_price', 9999);
}
if (!function_exists('mrwcmc_convert_variation_direct')) {
    function mrwcmc_convert_variation_direct($price)
    {
        return (is_numeric($price)) ? mrwcmc_convert_product_price($price, null) : $price;
    }
    add_filter('woocommerce_product_variation_get_price', 'mrwcmc_convert_variation_direct', 9999);
    add_filter('woocommerce_product_variation_get_regular_price', 'mrwcmc_convert_variation_direct', 9999);
    add_filter('woocommerce_product_variation_get_sale_price', 'mrwcmc_convert_variation_direct', 9999);
}
