<?php
// File: wp-content/plugins/mr-multicurrency/includes/switcher.php
if (!defined('ABSPATH')) {
    exit;
}

/**
 * Currency Switcher UI (functional style)
 * - Shortcode: [mrwcmc_switcher type="dropdown|links"]
 * - Renders a simple dropdown (auto-submit) or inline links
 * - Uses existing ?currency=XYZ handler from pricing.php (no extra endpoints)
 * - Minimal, theme-agnostic CSS (inline)
 *
 * Requires helpers from other includes:
 * - mrwcmc_get_supported_currs()
 * - mrwcmc_get_current_currency()
 */

// -----------------------------------------------------------------------------
// Helpers
// -----------------------------------------------------------------------------
if (!function_exists('mrwcmc_get_option')) {
    function mrwcmc_get_option(): array
    {
        $defaults = function_exists('mrwcmc_defaults') ? mrwcmc_defaults() : array();
        $opt = get_option('mrwcmc_settings', $defaults);
        if (!is_array($opt)) $opt = $defaults;
        return array_merge($defaults, $opt);
    }
}

if (!function_exists('mrwcmc_switcher_render')) {
    function mrwcmc_switcher_render(array $args = []): string
    {
        $type      = isset($args['type']) ? strtolower(trim($args['type'])) : 'dropdown'; // 'dropdown' | 'links'
        $supported = function_exists('mrwcmc_get_supported_currs') ? mrwcmc_get_supported_currs() : [];
        if (empty($supported)) return '';
        $current   = function_exists('mrwcmc_get_current_currency') ? mrwcmc_get_current_currency() : '';
        if (!$current) $current = get_option('woocommerce_currency', 'USD');

        $wrap_attrs = 'class="mrwcmc-switcher"';
        $out = '<div ' . $wrap_attrs . '>';

        if ($type === 'links') {
            foreach ($supported as $c) {
                $url = add_query_arg('currency', $c);
                $cls = $c === $current ? ' style="font-weight:600;text-decoration:underline;"' : '';
                $out .= '<a' . $cls . ' href="' . esc_url($url) . '">' . esc_html($c) . '</a> ';
            }
        } else {
            // dropdown (GET form that preserves existing query args except currency)
            $out .= '<form class="mrwcmc-switcher-form" method="get" action="">';
            // keep current query args
            foreach ($_GET as $k => $v) {
                if ($k === 'currency') continue;
                if (is_array($v)) continue; // keep simple
                $k = sanitize_key($k);
                $v = esc_attr($v);
                $out .= '<input type="hidden" name="' . $k . '" value="' . $v . '"/>';
            }
            $out .= '<label class="screen-reader-text" for="mrwcmc_currency_select">' . esc_html__('Select currency', 'mr-multicurrency') . '</label>';
            $out .= '<select id="mrwcmc_currency_select" name="currency" onchange="this.form.submit()">';
            foreach ($supported as $c) {
                $sel = selected($c, $current, false);
                $out .= '<option value="' . esc_attr($c) . '"' . $sel . '>' . esc_html($c) . '</option>';
            }
            $out .= '</select>';
            $out .= '<noscript><button type="submit">' . esc_html__('Switch', 'mr-multicurrency') . '</button></noscript>';
            $out .= '</form>';
        }

        $out .= '</div>';
        return $out;
    }
}

// -----------------------------------------------------------------------------
// Shortcode
// -----------------------------------------------------------------------------
if (!function_exists('mrwcmc_switcher_shortcode')) {
    function mrwcmc_switcher_shortcode($atts = [])
    {
        $atts = shortcode_atts([
            'type' => 'dropdown', // 'dropdown' or 'links'
        ], $atts, 'mrwcmc_switcher');
        return mrwcmc_switcher_render($atts);
    }
    add_shortcode('mrwcmc_switcher', 'mrwcmc_switcher_shortcode');
}

// -----------------------------------------------------------------------------
// Tiny inline CSS (frontend only)
// -----------------------------------------------------------------------------
if (!function_exists('mrwcmc_enqueue_switcher_css')) {
    function mrwcmc_enqueue_switcher_css()
    {
        if (is_admin()) return;
        // Register a handle for inline CSS without external file.
        $handle = 'mrwcmc-inline';
        if (!wp_style_is($handle, 'registered')) {
            wp_register_style($handle, false, [], null);
        }
        wp_enqueue_style($handle);
        $css = '
            .mrwcmc-switcher { display:inline-flex; gap:.5rem; align-items:center; }
            .mrwcmc-switcher-form select { padding:.25rem .5rem; }
            .mrwcmc-switcher a { text-decoration:none; border:1px solid rgba(0,0,0,.1); padding:.25rem .5rem; border-radius:4px; }
            .mrwcmc-switcher a:hover { background:rgba(0,0,0,.05); }
        ';
        wp_add_inline_style($handle, trim($css));
    }
    add_action('wp_enqueue_scripts', 'mrwcmc_enqueue_switcher_css');
}

// -----------------------------------------------------------------------------
// Convenience action for themes: do_action('mrwcmc_switcher', ['type'=>'links'])
// -----------------------------------------------------------------------------
if (!function_exists('mrwcmc_switcher_action')) {
    function mrwcmc_switcher_action($args = [])
    {
        echo mrwcmc_switcher_render(is_array($args) ? $args : []);
    }
    add_action('mrwcmc_switcher', 'mrwcmc_switcher_action', 10, 1);
}
