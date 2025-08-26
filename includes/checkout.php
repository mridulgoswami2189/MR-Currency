<?php
// File: wp-content/plugins/mr-multicurrency/includes/checkout.php
if (!defined('ABSPATH')) {
    exit;
}

/**
 * Cart & checkout integration (functional style)
 * - Convert fixed-amount coupons to current currency
 * - Convert shipping rates (cost + taxes) for display/selection
 * - Freeze order currency + store rates snapshot in order meta
 *
 * Requires:
 * - mrwcmc_get_current_currency(), mrwcmc_convert_amount() from pricing.php
 * - mrwcmc_get_effective_rates() from rates.php
 */

// -----------------------------------------------------------------------------
// Convert fixed-amount coupons
// -----------------------------------------------------------------------------
if (!function_exists('mrwcmc_filter_coupon_amount')) {
    function mrwcmc_filter_coupon_amount($amount, $coupon)
    {
        if (is_admin()) return $amount;
        if (!is_numeric($amount) || $amount <= 0) return $amount;

        // Only convert fixed amounts; percentage coupons stay the same
        $type = method_exists($coupon, 'get_discount_type') ? $coupon->get_discount_type() : '';
        $type = is_string($type) ? strtolower($type) : '';
        $is_fixed = in_array($type, ['fixed_cart', 'fixed_product'], true);
        if (!$is_fixed) return $amount;

        $to = function_exists('mrwcmc_get_current_currency') ? mrwcmc_get_current_currency() : '';
        $base = get_option('woocommerce_currency', 'USD');
        if (!$to || strtoupper($to) === strtoupper($base)) return $amount;

        // Guard against recursive/filter loops
        static $guard = false;
        if ($guard) return $amount;
        $guard = true;

        $conv = function_exists('mrwcmc_convert_amount') ? mrwcmc_convert_amount((float)$amount, $to) : $amount;

        $guard = false;
        return $conv;
    }
    add_filter('woocommerce_coupon_get_amount', 'mrwcmc_filter_coupon_amount', 9999, 2);
}

// -----------------------------------------------------------------------------
// Convert shipping rates (cost + taxes)
// -----------------------------------------------------------------------------
if (!function_exists('mrwcmc_filter_package_rates')) {
    function mrwcmc_filter_package_rates($rates, $package)
    {
        if (is_admin()) return $rates;

        $to = function_exists('mrwcmc_get_current_currency') ? mrwcmc_get_current_currency() : '';
        $base = get_option('woocommerce_currency', 'USD');
        if (!$to || strtoupper($to) === strtoupper($base)) return $rates;

        static $guard = false;
        if ($guard) return $rates;
        $guard = true;

        foreach ($rates as $rate_id => $rate) {
            // Cost
            if (isset($rate->cost) && is_numeric($rate->cost)) {
                $rate->cost = function_exists('mrwcmc_convert_amount') ? mrwcmc_convert_amount((float)$rate->cost, $to) : $rate->cost;
            }
            // Taxes array (indexed by tax rate IDs)
            if (isset($rate->taxes) && is_array($rate->taxes)) {
                foreach ($rate->taxes as $tax_key => $tax_amount) {
                    if (is_numeric($tax_amount)) {
                        $rate->taxes[$tax_key] = function_exists('mrwcmc_convert_amount') ? mrwcmc_convert_amount((float)$tax_amount, $to) : $tax_amount;
                    }
                }
            }
        }

        $guard = false;
        return $rates;
    }
    add_filter('woocommerce_package_rates', 'mrwcmc_filter_package_rates', 9999, 2);
}

// -----------------------------------------------------------------------------
// Freeze order currency & snapshot rates on order creation
// -----------------------------------------------------------------------------
if (!function_exists('mrwcmc_checkout_freeze_order_meta')) {
    function mrwcmc_checkout_freeze_order_meta($order, $data)
    {
        if (!is_object($order)) return;

        $base   = strtoupper(get_option('woocommerce_currency', 'USD'));
        $orderc = function_exists('mrwcmc_get_current_currency') ? strtoupper(mrwcmc_get_current_currency()) : $base;

        // Ensure order currency is the current one (usually already set via filter)
        if (method_exists($order, 'set_currency')) {
            $order->set_currency($orderc);
        }

        // Snapshot effective rates for audit
        $rates = function_exists('mrwcmc_get_effective_rates') ? mrwcmc_get_effective_rates([]) : [];
        $meta  = [
            'base_currency'   => $base,
            'order_currency'  => $orderc,
            'rates'           => $rates,
            'provider'        => (function_exists('mrwcmc_get_option') ? mrwcmc_get_option()['rate_provider'] ?? 'frankfurter' : 'frankfurter'),
            'fetched_at'      => (int) (get_transient('mrwcmc_rates_' . strtolower($base) . '_' . ((function_exists('mrwcmc_get_option') ? mrwcmc_get_option()['rate_provider'] ?? 'frankfurter' : 'frankfurter')))['fetched_at'] ?? time()),
        ];

        // Markup + decimals snapshot (useful to reproduce totals later)
        if (function_exists('mrwcmc_get_option')) {
            $opt = mrwcmc_get_option();
            $meta['markup_raw']   = $opt['markup_raw']   ?? '';
            $meta['rounding_raw'] = $opt['rounding_raw'] ?? '';
        }

        $order->update_meta_data('_mrwcmc_snapshot', wp_json_encode($meta));

        // Optional: store the rate used for the order currency (1.0 for base)
        if (function_exists('mrwcmc_get_effective_rate')) {
            $order->update_meta_data('_mrwcmc_rate_used', (float) mrwcmc_get_effective_rate($orderc));
        }
    }
    add_action('woocommerce_checkout_create_order', 'mrwcmc_checkout_freeze_order_meta', 10, 2);
}

// -----------------------------------------------------------------------------
// (Optional) Show snapshot on order admin screen
// -----------------------------------------------------------------------------
if (!function_exists('mrwcmc_admin_order_meta_box')) {
    function mrwcmc_admin_order_meta_box()
    {
        add_meta_box(
            'mrwcmc_order_meta',
            __('Multi-Currency Snapshot', 'mr-multicurrency'),
            'mrwcmc_render_order_meta_box',
            'shop_order',
            'side',
            'default'
        );
    }
    add_action('add_meta_boxes', 'mrwcmc_admin_order_meta_box');

    function mrwcmc_render_order_meta_box($post)
    {
        $snap_json = get_post_meta($post->ID, '_mrwcmc_snapshot', true);
        if (!$snap_json) {
            echo '<p>' . esc_html__('No snapshot recorded.', 'mr-multicurrency') . '</p>';
            return;
        }
        $snap = json_decode($snap_json, true);
        if (!is_array($snap)) {
            echo '<p>' . esc_html__('Snapshot unreadable.', 'mr-multicurrency') . '</p>';
            return;
        }

        echo '<p><strong>' . esc_html__('Base → Order', 'mr-multicurrency') . ':</strong> ' .
            esc_html(($snap['base_currency'] ?? '—') . ' → ' . ($snap['order_currency'] ?? '—')) . '</p>';

        if (!empty($snap['rates']) && is_array($snap['rates'])) {
            echo '<p><strong>' . esc_html__('Rates', 'mr-multicurrency') . ':</strong></p><ul style="margin:0 0 6px 12px;">';
            foreach ($snap['rates'] as $cur => $rate) {
                echo '<li>' . esc_html($cur . ' = ' . $rate) . '</li>';
            }
            echo '</ul>';
        }

        if (!empty($snap['provider'])) {
            echo '<p><strong>' . esc_html__('Provider', 'mr-multicurrency') . ':</strong> ' . esc_html($snap['provider']) . '</p>';
        }
    }
}
