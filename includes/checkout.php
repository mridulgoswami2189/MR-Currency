<?php
// File: wp-content/plugins/mr-multicurrency/includes/checkout.php
if (!defined('ABSPATH')) {
    exit;
}

/**
 * Cart & Checkout Integration (HPOS-compatible, functional style)
 *
 * Features:
 * - Convert fixed-amount coupons into the current currency
 * - Convert shipping rates (cost + taxes) for display/selection
 * - Freeze order currency and store a snapshot of rates/markup at checkout
 * - Render snapshot on the order admin screen (works with HPOS)
 *
 * Depends on helpers from other includes:
 * - mrwcmc_get_current_currency(), mrwcmc_convert_amount()    (pricing.php)
 * - mrwcmc_get_effective_rate(), mrwcmc_get_effective_rates() (rates.php)
 * - mrwcmc_get_auto_rates() (rates.php) — optional, for fetched_at/provider
 * - mrwcmc_get_option() (admin/pricing)
 */

/*------------------------------------------------------------------------------
 * Convert fixed-amount coupons (percentage coupons are unchanged)
 *----------------------------------------------------------------------------*/
if (!function_exists('mrwcmc_filter_coupon_amount')) {
    function mrwcmc_filter_coupon_amount($amount, $coupon)
    {
        if (is_admin()) return $amount;
        if (!is_numeric($amount) || $amount <= 0) return $amount;

        // Only convert fixed amounts
        $type = method_exists($coupon, 'get_discount_type') ? $coupon->get_discount_type() : '';
        $type = is_string($type) ? strtolower($type) : '';
        $is_fixed = in_array($type, ['fixed_cart', 'fixed_product'], true);
        if (!$is_fixed) return $amount;

        $to   = function_exists('mrwcmc_get_current_currency') ? mrwcmc_get_current_currency() : '';
        $base = get_option('woocommerce_currency', 'USD');
        if (!$to || strtoupper($to) === strtoupper($base)) return $amount;

        static $guard = false;
        if ($guard) return $amount;
        $guard = true;

        $converted = function_exists('mrwcmc_convert_amount')
            ? mrwcmc_convert_amount((float)$amount, $to)
            : $amount;

        $guard = false;
        return $converted;
    }
    add_filter('woocommerce_coupon_get_amount', 'mrwcmc_filter_coupon_amount', 9999, 2);
}

/*------------------------------------------------------------------------------
 * Convert shipping rates (cost + taxes)
 *----------------------------------------------------------------------------*/
if (!function_exists('mrwcmc_filter_package_rates')) {
    function mrwcmc_filter_package_rates($rates, $package)
    {
        if (is_admin()) return $rates;

        $to   = function_exists('mrwcmc_get_current_currency') ? mrwcmc_get_current_currency() : '';
        $base = get_option('woocommerce_currency', 'USD');
        if (!$to || strtoupper($to) === strtoupper($base)) return $rates;

        static $guard = false;
        if ($guard) return $rates;
        $guard = true;

        foreach ($rates as $rate_id => $rate) {
            if (isset($rate->cost) && is_numeric($rate->cost)) {
                $rate->cost = function_exists('mrwcmc_convert_amount')
                    ? mrwcmc_convert_amount((float)$rate->cost, $to)
                    : $rate->cost;
            }
            if (isset($rate->taxes) && is_array($rate->taxes)) {
                foreach ($rate->taxes as $tax_key => $tax_amount) {
                    if (is_numeric($tax_amount)) {
                        $rate->taxes[$tax_key] = function_exists('mrwcmc_convert_amount')
                            ? mrwcmc_convert_amount((float)$tax_amount, $to)
                            : $tax_amount;
                    }
                }
            }
        }

        $guard = false;
        return $rates;
    }
    add_filter('woocommerce_package_rates', 'mrwcmc_filter_package_rates', 9999, 2);
}

/*------------------------------------------------------------------------------
 * Freeze order currency & store snapshot on order creation
 *----------------------------------------------------------------------------*/
if (!function_exists('mrwcmc_checkout_freeze_order_meta')) {
    /**
     * Hooked into: woocommerce_checkout_create_order
     * @param WC_Order $order
     * @param array    $data
     */
    function mrwcmc_checkout_freeze_order_meta($order, $data)
    {
        if (!is_object($order) || !method_exists($order, 'update_meta_data')) return;

        $base    = strtoupper(get_option('woocommerce_currency', 'USD'));
        $current = function_exists('mrwcmc_get_current_currency')
            ? strtoupper(mrwcmc_get_current_currency())
            : $base;

        // Ensure the order records the shopper's currency (usually set already by currency filter)
        if (method_exists($order, 'set_currency')) {
            $order->set_currency($current);
        }

        // Build snapshot payload
        $rates_map = function_exists('mrwcmc_get_effective_rates') ? mrwcmc_get_effective_rates([]) : [];
        $provider  = 'frankfurter';
        $fetched   = time();

        if (function_exists('mrwcmc_get_auto_rates')) {
            $payload = mrwcmc_get_auto_rates(false); // ['base','provider','fetched_at','rates']
            if (is_array($payload)) {
                if (!empty($payload['provider']))   $provider = (string) $payload['provider'];
                if (!empty($payload['fetched_at'])) $fetched  = (int)    $payload['fetched_at'];
                // ensure we snapshot the actual effective rates including manual overrides
                if (empty($rates_map) && !empty($payload['rates']) && is_array($payload['rates'])) {
                    $rates_map = $payload['rates'];
                }
            }
        } elseif (function_exists('mrwcmc_get_option')) {
            $opt = mrwcmc_get_option();
            if (!empty($opt['rate_provider'])) $provider = (string) $opt['rate_provider'];
        }

        $meta = [
            'base_currency'  => $base,
            'order_currency' => $current,
            'rates'          => $rates_map,
            'provider'       => $provider,
            'fetched_at'     => $fetched,
        ];

        if (function_exists('mrwcmc_get_option')) {
            $opt = mrwcmc_get_option();
            $meta['markup_raw']   = $opt['markup_raw']   ?? '';
            $meta['rounding_raw'] = $opt['rounding_raw'] ?? '';
        }

        // Store snapshot JSON and the single rate used for the order currency
        $order->update_meta_data('_mrwcmc_snapshot', wp_json_encode($meta));
        if (function_exists('mrwcmc_get_effective_rate')) {
            $order->update_meta_data('_mrwcmc_rate_used', (float) mrwcmc_get_effective_rate($current));
        }
    }
    add_action('woocommerce_checkout_create_order', 'mrwcmc_checkout_freeze_order_meta', 10, 2);
}

/*------------------------------------------------------------------------------
 * Admin: render snapshot on the order screen (HPOS-safe)
 *----------------------------------------------------------------------------*/
if (!function_exists('mrwcmc_render_snapshot_in_admin')) {
    /**
     * Prints a small "Multi-Currency Snapshot" block under Order details.
     * Works for classic & HPOS screens via core Woo action.
     *
     * @param WC_Order $order
     */
    function mrwcmc_render_snapshot_in_admin($order)
    {
        if (!is_object($order) || !is_a($order, 'WC_Order')) return;

        // Read meta via CRUD (HPOS-safe)
        $snap_json = $order->get_meta('_mrwcmc_snapshot');
        if (!$snap_json) {
            echo '<div class="mrwcmc-admin-note"><em>' .
                esc_html__('No multi-currency snapshot recorded.', 'mr-multicurrency') .
                '</em></div>';
            return;
        }
        $snap = json_decode($snap_json, true);
        if (!is_array($snap)) {
            echo '<div class="mrwcmc-admin-note"><em>' .
                esc_html__('Snapshot unreadable.', 'mr-multicurrency') .
                '</em></div>';
            return;
        }

        $base  = isset($snap['base_currency'])  ? (string) $snap['base_currency']  : '—';
        $curr  = isset($snap['order_currency']) ? (string) $snap['order_currency'] : '—';
        $prov  = isset($snap['provider'])       ? (string) $snap['provider']       : '—';
        $when  = isset($snap['fetched_at'])     ? (int)    $snap['fetched_at']     : 0;
        $whenf = $when ? date_i18n(get_option('date_format') . ' ' . get_option('time_format'), $when) : '—';
        $rates = isset($snap['rates']) && is_array($snap['rates']) ? $snap['rates'] : [];

        echo '<div class="order_data_column mrwcmc-snapshot">';
        echo '<h3>' . esc_html__('Multi-Currency Snapshot', 'mr-multicurrency') . '</h3>';
        echo '<p><strong>' . esc_html__('Base → Order', 'mr-multicurrency') . ':</strong> ' .
            esc_html($base . ' → ' . $curr) . '</p>';
        echo '<p><strong>' . esc_html__('Provider', 'mr-multicurrency') . ':</strong> ' .
            esc_html($prov) . '</p>';
        echo '<p><strong>' . esc_html__('Fetched at', 'mr-multicurrency') . ':</strong> ' .
            esc_html($whenf) . '</p>';

        if (!empty($rates)) {
            echo '<p><strong>' . esc_html__('Rates at checkout', 'mr-multicurrency') . ':</strong></p>';
            echo '<ul style="margin:0 0 6px 16px;list-style:disc;">';
            foreach ($rates as $code => $rate) {
                // Format the scalar safely, then escape on output
                $rate_display = is_numeric($rate) ? (string) $rate : (string) $rate;

                printf(
                    '<li>%1$s = %2$s</li>',
                    esc_html($code),
                    esc_html($rate_display)
                );
            }
            echo '</ul>';
        }
        echo '</div>';
    }
    // Works on both classic and HPOS order admin UIs
    add_action('woocommerce_admin_order_data_after_order_details', 'mrwcmc_render_snapshot_in_admin', 20, 1);
}

/*------------------------------------------------------------------------------
 * (Optional) Add a tiny style for the admin snapshot block
 *----------------------------------------------------------------------------*/
if (!function_exists('mrwcmc_admin_inline_styles')) {
    function mrwcmc_admin_inline_styles($hook)
    {
        if ($hook !== 'post.php' && $hook !== 'post-new.php') return;
        $screen = get_current_screen();
        if (!$screen || $screen->id !== 'shop_order') return;
    }
    add_action('admin_enqueue_scripts', 'mrwcmc_admin_inline_styles');
}
