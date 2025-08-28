<?php
if (!defined('ABSPATH')) {
    exit;
}

/**
 * Currency Switcher UI (no URL params)
 * - Shortcode: [mrwcmc_switcher type="dropdown|links"]
 * - Uses REST POST /wp-json/mrwcmc/v1/currency to set cookie, then reload
 * - Includes no-JS fallback via admin-post POST
 */

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
        $type      = isset($args['type']) ? strtolower(trim($args['type'])) : 'dropdown'; // 'dropdown'|'links'
        $supported = function_exists('mrwcmc_get_supported_currs') ? mrwcmc_get_supported_currs() : [];
        if (empty($supported)) return '';
        $current   = function_exists('mrwcmc_get_current_currency') ? mrwcmc_get_current_currency() : '';
        if (!$current) $current = get_option('woocommerce_currency', 'USD');

        $out = '<div class="mrwcmc-switcher">';

        if ($type === 'links') {
            foreach ($supported as $c) {
                $cls = $c === $current ? ' style="font-weight:600;text-decoration:underline;"' : '';
                $out .= '<a href="#" data-mrwcmc-currency="' . esc_attr($c) . '"' . $cls . '>' . esc_html($c) . '</a> ';
            }
            $out .= '<noscript><form method="post" action="' . esc_url(admin_url('admin-post.php')) . '"><input type="hidden" name="action" value="mrwcmc_set_currency"/><select name="currency">';
            foreach ($supported as $c) {
                $sel = selected($c, $current, false);
                $out .= '<option value="' . esc_attr($c) . '"' . $sel . '>' . esc_html($c) . '</option>';
            }
            $out .= '</select> <button type="submit">' . esc_html__('Switch', 'mr-multicurrency') . '</button></form></noscript>';
        } else {
            $out .= '<form class="mrwcmc-switcher-form" onsubmit="return false">';
            $out .= '<label class="screen-reader-text" for="mrwcmc_currency_select">' . esc_html__('Select currency', 'mr-multicurrency') . '</label>';
            $out .= '<select id="mrwcmc_currency_select" name="currency">';
            foreach ($supported as $c) {
                $sel = selected($c, $current, false);
                $out .= '<option value="' . esc_attr($c) . '"' . $sel . '>' . esc_html($c) . '</option>';
            }
            $out .= '</select>';
            $out .= '<noscript><form method="post" action="' . esc_url(admin_url('admin-post.php')) . '"><input type="hidden" name="action" value="mrwcmc_set_currency"/><input type="hidden" name="currency" value="' . esc_attr($current) . '"/><button type="submit">' . esc_html__('Switch', 'mr-multicurrency') . '</button></form></noscript>';
            $out .= '</form>';
        }

        $out .= '</div>';
        return $out;
    }
}

if (!function_exists('mrwcmc_switcher_shortcode')) {
    function mrwcmc_switcher_shortcode($atts = [])
    {
        $atts = shortcode_atts(['type' => 'dropdown'], $atts, 'mrwcmc_switcher');
        return mrwcmc_switcher_render($atts);
    }
    add_shortcode('mrwcmc_switcher', 'mrwcmc_switcher_shortcode');
}

/** CSS (unchanged) */
if (!function_exists('mrwcmc_enqueue_switcher_css')) {
    function mrwcmc_enqueue_switcher_css()
    {
        if (is_admin()) return;
        $handle = 'mrwcmc-inline';
        if (!wp_style_is($handle, 'registered')) {
            wp_register_style($handle, false, [], null);
        }
        wp_enqueue_style($handle);
        wp_add_inline_style($handle, '
            .mrwcmc-switcher { display:inline-flex; gap:.5rem; align-items:center; }
            .mrwcmc-switcher-form select { padding:.25rem .5rem; }
            .mrwcmc-switcher a { text-decoration:none; border:1px solid rgba(0,0,0,.1); padding:.25rem .5rem; border-radius:4px; }
            .mrwcmc-switcher a:hover { background:rgba(0,0,0,.05); }
        ');
    }
    add_action('wp_enqueue_scripts', 'mrwcmc_enqueue_switcher_css');
}

/** JS: talks to REST and reloads (no URL params) */
// enqueue script
if (!function_exists('mrwcmc_enqueue_switcher_js')) {
    function mrwcmc_enqueue_switcher_js()
    {
        if (is_admin()) return;
        $h = 'mrwcmc-switcher-js';
        wp_register_script($h, '', [], null, true);
        wp_enqueue_script($h);
        wp_localize_script($h, 'MRWCMC', [
            'endpoint'  => esc_url_raw(rest_url('mrwcmc/v1/currency')),
            'adminPost' => esc_url_raw(admin_url('admin-post.php')),
        ]);
        $js = <<<JS
(function(){
    function postAdmin(cur){
        var f=document.createElement('form');
        f.method='POST'; f.action=MRWCMC.adminPost;
        var a=document.createElement('input'); a.type='hidden'; a.name='action'; a.value='mrwcmc_set_currency'; f.appendChild(a);
        var b=document.createElement('input'); b.type='hidden'; b.name='currency'; b.value=String(cur).toUpperCase(); f.appendChild(b);
        document.body.appendChild(f); f.submit();
    }
    function setCurrency(cur){
        if(!cur) return;
        var payload = JSON.stringify({currency:String(cur).toUpperCase()});
        try{
            fetch(MRWCMC.endpoint, { method:'POST', headers:{'Content-Type':'application/json'}, body:payload })
            .then(function(r){ if(!r.ok) throw new Error('bad'); return r.json(); })
            .then(function(d){ if(!d || d.ok!==true) throw new Error('bad'); location.reload(); })
            .catch(function(){ postAdmin(cur); });
        }catch(e){ postAdmin(cur); }
    }
    document.addEventListener('change', function(e){
        if (e.target && e.target.id === 'mrwcmc_currency_select') { setCurrency(e.target.value); }
    });
    document.addEventListener('click', function(e){
        var a = e.target.closest && e.target.closest('[data-mrwcmc-currency]');
        if (a){ e.preventDefault(); setCurrency(a.getAttribute('data-mrwcmc-currency')); }
    });
})();
JS;
        wp_add_inline_script($h, $js);
    }
    add_action('wp_enqueue_scripts', 'mrwcmc_enqueue_switcher_js');
}


/** Theme convenience action remains */
if (!function_exists('mrwcmc_switcher_action')) {
    function mrwcmc_switcher_action($args = [])
    {
        echo mrwcmc_switcher_render(is_array($args) ? $args : []);
    }
    add_action('mrwcmc_switcher', 'mrwcmc_switcher_action', 10, 1);
}
