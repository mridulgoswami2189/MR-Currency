<?php
if (!defined('ABSPATH')) {
    exit;
}

/**
 * Currency Switcher UI (POST → cookie) with param fallback.
 * Shortcode: [mrwcmc_switcher type="dropdown|links" style="symbol_code|code_symbol|symbol_only|code_only"]
 * Defaults: type=dropdown, style=symbol_code (e.g., "€ EUR")
 */

if (!function_exists('mrwcmc_switcher_render')) {
    function mrwcmc_switcher_render(array $args = []): string
    {
        $type  = isset($args['type'])  ? strtolower(trim((string) $args['type']))  : 'dropdown'; // 'dropdown'|'links'
        $style = isset($args['style']) ? strtolower(trim((string) $args['style'])) : 'symbol_code';

        $supported = function_exists('mrwcmc_get_supported_currs') ? mrwcmc_get_supported_currs() : [];
        if (empty($supported)) return '';

        $current = function_exists('mrwcmc_get_current_currency')
            ? mrwcmc_get_current_currency()
            : get_option('woocommerce_currency', 'USD');

        $action = admin_url('admin-post.php');

        $out = '<div class="mrwcmc-switcher">';

        if ($type === 'links') {
            foreach ($supported as $c) {
                $code   = strtoupper($c);
                $label  = function_exists('mrwcmc_format_currency_label') ? mrwcmc_format_currency_label($code, $style) : $code;
                $href   = add_query_arg('mrwcmc', $code); // no-JS hits guard
                $is_cur = ($code === strtoupper($current));
                $cls    = $is_cur ? ' is-current' : '';

                $out .= '<a class="mrwcmc-link' . esc_attr($cls) . '" href="' . esc_url($href) . '">'
                    . esc_html($label) . '</a> ';
            }

            // No-JS fallback: tiny POST form (handler redirects back via wp_get_referer())
            $out .= '<noscript>
                <form method="post" action="' . esc_url($action) . '">
                    <input type="hidden" name="action" value="mrwcmc_set_currency"/>' .
                wp_nonce_field('mrwcmc_switch_currency', '_wpnonce', true, false) .
                '<select name="currency">';
            foreach ($supported as $c) {
                $code  = strtoupper($c);
                $label = function_exists('mrwcmc_format_currency_label') ? mrwcmc_format_currency_label($code, $style) : $code;
                $sel   = selected($code, strtoupper($current), false);
                $out  .= '<option value="' . esc_attr($code) . '"' . $sel . '>' . esc_html($label) . '</option>';
            }
            $out .=     '</select>
                    <button type="submit">' . esc_html__('Switch', 'mr-multicurrency') . '</button>
                </form>
            </noscript>';
        } else {
            // Dropdown (auto-submits via enqueued JS)
            $out .= '<form class="mrwcmc-switcher-form" method="post" action="' . esc_url($action) . '">
    <label class="screen-reader-text" for="mrwcmc_currency_select">' . esc_html__('Select currency', 'mr-multicurrency') . '</label>
    <input type="hidden" name="action" value="mrwcmc_set_currency"/>' .
                wp_nonce_field('mrwcmc_switch_currency', '_wpnonce', true, false) .
                '<select id="mrwcmc_currency_select" name="currency">';
            foreach ($supported as $c) {
                $code  = strtoupper($c);
                $label = function_exists('mrwcmc_format_currency_label') ? mrwcmc_format_currency_label($code, $style) : $code;
                $sel   = selected($code, strtoupper($current), false);
                $out  .= '<option value="' . esc_attr($code) . '"' . $sel . '>' . esc_html($label) . '</option>';
            }
            $out .= '</select>
                <noscript><button type="submit">' . esc_html__('Switch', 'mr-multicurrency') . '</button></noscript>
            </form>';
        }

        $out .= '</div>';
        return $out;
    }

    add_shortcode('mrwcmc_switcher', function ($atts = []) {
        $atts = shortcode_atts([
            'type'  => 'dropdown',
            'style' => 'symbol_code',
        ], $atts, 'mrwcmc_switcher');
        return mrwcmc_switcher_render($atts);
    });
}

/** Frontend assets (CSS + tiny JS for auto-submit) */
add_action('wp_enqueue_scripts', function () {
    if (is_admin()) return;

    // CSS (versioned)
    $css_handle = 'mrwcmc-inline';
    $ver        = defined('MRWCMC_VERSION') ? MRWCMC_VERSION : '1';
    if (!wp_style_is($css_handle, 'registered')) {
        wp_register_style($css_handle, false, [], $ver);
    }
    wp_enqueue_style($css_handle);
    wp_add_inline_style($css_handle, '
        .mrwcmc-switcher { display:inline-flex; gap:.5rem; align-items:center; }
        .mrwcmc-switcher-form select { padding:.25rem .5rem; }
        .mrwcmc-link { text-decoration:none; border:1px solid rgba(0,0,0,.1); padding:.25rem .5rem; border-radius:4px; }
        .mrwcmc-link:hover { background:rgba(0,0,0,.05); }
        .mrwcmc-link.is-current { font-weight:600; text-decoration:underline; }
    ');

    // JS (versioned) – handles dropdown auto-submit
    $js_handle = 'mrwcmc-switcher';
    if (!wp_script_is($js_handle, 'registered')) {
        wp_register_script($js_handle, '', [], $ver, true); // empty src; we add inline below
    }
    wp_enqueue_script($js_handle);
    wp_add_inline_script($js_handle, '(function(){
        var f=document.querySelector(".mrwcmc-switcher-form");
        if(!f) return;
        var s=f.querySelector("#mrwcmc_currency_select");
        if(!s) return;
        s.addEventListener("change", function(){ try{ f.submit(); }catch(e){} });
    })();');
}, 20);

/** Theme convenience */
add_action('mrwcmc_switcher', function ($args = []) {
    $html = mrwcmc_switcher_render(is_array($args) ? $args : []);
    echo wp_kses_post($html);
}, 10, 1);
