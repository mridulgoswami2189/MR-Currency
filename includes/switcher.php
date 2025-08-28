<?php
if (!defined('ABSPATH')) {
    exit;
}

/**
 * Currency Switcher UI (POST → cookie) with param fallback.
 * - Shortcode: [mrwcmc_switcher type="dropdown|links" style="symbol_code|code_symbol|symbol_only|code_only"]
 * - Defaults: type=dropdown, style=symbol_code (e.g., "€ EUR")
 */

if (!function_exists('mrwcmc_switcher_render')) {
    function mrwcmc_switcher_render(array $args = []): string
    {
        $type  = isset($args['type'])  ? strtolower(trim($args['type']))  : 'dropdown'; // 'dropdown'|'links'
        $style = isset($args['style']) ? strtolower(trim($args['style'])) : 'symbol_code';

        $supported = function_exists('mrwcmc_get_supported_currs') ? mrwcmc_get_supported_currs() : [];
        if (empty($supported)) return '';

        $current = function_exists('mrwcmc_get_current_currency')
            ? mrwcmc_get_current_currency()
            : get_option('woocommerce_currency', 'USD');

        $action = esc_url(admin_url('admin-post.php'));
        $scheme = is_ssl() ? 'https://' : 'http://';
        $redir  = esc_url($scheme . $_SERVER['HTTP_HOST'] . $_SERVER['REQUEST_URI']);

        $out = '<div class="mrwcmc-switcher">';

        if ($type === 'links') {
            foreach ($supported as $c) {
                $label = function_exists('mrwcmc_format_currency_label')
                    ? mrwcmc_format_currency_label($c, $style)
                    : $c;
                $href = esc_url(add_query_arg('mrwcmc', $c)); // JS can intercept to POST; no-JS hits guard
                $cls  = $c === $current ? ' style="font-weight:600;text-decoration:underline;"' : '';
                $out .= '<a href="' . $href . '" data-mrwcmc-currency="' . esc_attr($c) . '"' . $cls . '>'
                    . esc_html($label) . '</a> ';
            }
            // No-JS fallback:
            $out .= '<noscript>
                <form method="post" action="' . $action . '">
                    <input type="hidden" name="action" value="mrwcmc_set_currency"/>
                    <input type="hidden" name="redirect_to" value="' . $redir . '"/>
                    <select name="currency">';
            foreach ($supported as $c) {
                $label = function_exists('mrwcmc_format_currency_label') ? mrwcmc_format_currency_label($c, $style) : $c;
                $sel   = selected($c, $current, false);
                $out  .= '<option value="' . esc_attr($c) . '"' . $sel . '>' . esc_html($label) . '</option>';
            }
            $out .=     '</select>
                    <button type="submit">' . esc_html__('Switch', 'mr-multicurrency') . '</button>
                </form>
            </noscript>';
        } else {
            // Dropdown (POST submit on change)
            $out .= '<form class="mrwcmc-switcher-form" method="post" action="' . $action . '">
                <label class="screen-reader-text" for="mrwcmc_currency_select">' . esc_html__('Select currency', 'mr-multicurrency') . '</label>
                <input type="hidden" name="action" value="mrwcmc_set_currency"/>
                <input type="hidden" name="redirect_to" value="' . $redir . '"/>
                <select id="mrwcmc_currency_select" name="currency">';
            foreach ($supported as $c) {
                $label = function_exists('mrwcmc_format_currency_label') ? mrwcmc_format_currency_label($c, $style) : $c;
                $sel   = selected($c, $current, false);
                $out  .= '<option value="' . esc_attr($c) . '"' . $sel . '>' . esc_html($label) . '</option>';
            }
            $out .= '</select>
                <noscript><button type="submit">' . esc_html__('Switch', 'mr-multicurrency') . '</button></noscript>
            </form>
            <script>
            (function(){
                var f=document.querySelector(".mrwcmc-switcher-form");
                if(!f) return;
                var s=f.querySelector("#mrwcmc_currency_select");
                if(!s) return;
                s.addEventListener("change", function(){ try{ f.submit(); }catch(e){} });
            })();
            </script>';
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

/** Tiny CSS */
add_action('wp_enqueue_scripts', function () {
    if (is_admin()) return;
    $handle = 'mrwcmc-inline';
    if (!wp_style_is($handle, 'registered')) wp_register_style($handle, false, [], null);
    wp_enqueue_style($handle);
    wp_add_inline_style($handle, '
        .mrwcmc-switcher { display:inline-flex; gap:.5rem; align-items:center; }
        .mrwcmc-switcher-form select { padding:.25rem .5rem; }
        .mrwcmc-switcher a { text-decoration:none; border:1px solid rgba(0,0,0,.1); padding:.25rem .5rem; border-radius:4px; }
        .mrwcmc-switcher a:hover { background:rgba(0,0,0,.05); }
    ');
});

/** Theme convenience */
add_action('mrwcmc_switcher', function ($args = []) {
    echo mrwcmc_switcher_render(is_array($args) ? $args : []);
}, 10, 1);
