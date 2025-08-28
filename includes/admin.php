<?php
// File: wp-content/plugins/mr-multicurrency/includes/admin.php
if (!defined('ABSPATH')) {
    exit;
}

//
// ---- Settings API (functional style) -----------------------------------------
//


// if (!function_exists('mrwcmc_get_option')) {
//     function mrwcmc_get_option() : array {
//         $defaults = function_exists('mrwcmc_defaults') ? mrwcmc_defaults() : array();
//         $opt = get_option('mrwcmc_settings', $defaults);
//         if (!is_array($opt)) $opt = $defaults;
//         return array_merge($defaults, $opt);
//     }
// }
if (!function_exists('mrwcmc_parse_key_pairs')) {
    function mrwcmc_parse_key_pairs(string $raw): array
    {
        $out = [];
        $raw = trim($raw);
        if ($raw === '') return $out;
        $lines = preg_split('/\r?\n/', $raw);
        foreach ($lines as $line) {
            $line = trim($line);
            if ($line === '' || strpos($line, '=') === false) continue;
            list($k, $v) = array_map('trim', explode('=', $line, 2));
            $k = strtoupper(sanitize_text_field($k));
            $v = strtoupper(sanitize_text_field($v));
            if ($k && $v) $out[$k] = $v;
        }
        return $out;
    }
}

if (!function_exists('mrwcmc_admin_add_menu')) {
    function mrwcmc_admin_add_menu()
    {
        add_submenu_page(
            'woocommerce',
            __('Multi-Currency', 'mr-multicurrency'),
            __('Multi-Currency', 'mr-multicurrency'),
            'manage_woocommerce',
            'mrwcmc',
            'mrwcmc_render_settings_page'
        );
    }
    add_action('admin_menu', 'mrwcmc_admin_add_menu');
}

if (!function_exists('mrwcmc_register_settings')) {
    function mrwcmc_register_settings()
    {
        register_setting(
            'mrwcmc_settings_group',
            'mrwcmc_settings',
            ['sanitize_callback' => 'mrwcmc_sanitize_settings']
        );

        add_settings_section(
            'mrwcmc_main',
            __('General Settings', 'mr-multicurrency'),
            function () {
                echo '<p>' . esc_html__('Configure basic multi-currency behavior. Conversion logic will be added next.', 'mr-multicurrency') . '</p>';
            },
            'mrwcmc'
        );

        add_settings_field('enabled', __('Enable multi-currency', 'mr-multicurrency'), 'mrwcmc_field_enabled', 'mrwcmc', 'mrwcmc_main');
        add_settings_field('base_currency', __('Base currency (WooCommerce)', 'mr-multicurrency'), 'mrwcmc_field_base_currency', 'mrwcmc', 'mrwcmc_main');
        add_settings_field('supported_currencies', __('Supported currencies', 'mr-multicurrency'), 'mrwcmc_field_supported_currencies', 'mrwcmc', 'mrwcmc_main');
        add_settings_field('country_currency_map', __('Country → Currency map', 'mr-multicurrency'), 'mrwcmc_field_country_map', 'mrwcmc', 'mrwcmc_main');
        add_settings_field('rate_provider', __('Rate provider', 'mr-multicurrency'), 'mrwcmc_field_rate_provider', 'mrwcmc', 'mrwcmc_main');
        add_settings_field('rate_refresh', __('Rate refresh schedule', 'mr-multicurrency'), 'mrwcmc_field_rate_refresh', 'mrwcmc', 'mrwcmc_main');
        add_settings_field('manual_rates_raw', __('Manual rates (optional)', 'mr-multicurrency'), 'mrwcmc_field_manual_rates', 'mrwcmc', 'mrwcmc_main');
        add_settings_field('markup_raw', __('Per-currency markup', 'mr-multicurrency'), 'mrwcmc_field_markup', 'mrwcmc', 'mrwcmc_main');
        add_settings_field('rounding_raw', __('Rounding (decimals)', 'mr-multicurrency'), 'mrwcmc_field_rounding', 'mrwcmc', 'mrwcmc_main');
        add_settings_field('allow_user_switch', __('Allow user currency switcher', 'mr-multicurrency'), 'mrwcmc_field_allow_switch', 'mrwcmc', 'mrwcmc_main');
        add_settings_field('geo_provider', __('Geo provider', 'mr-multicurrency'), 'mrwcmc_field_geo_provider', 'mrwcmc', 'mrwcmc_main');
        add_settings_field('ipinfo_token', __('ipinfo token (optional)', 'mr-multicurrency'), 'mrwcmc_field_ipinfo_token', 'mrwcmc', 'mrwcmc_main');
    }
    add_action('admin_init', 'mrwcmc_register_settings');
}

if (!function_exists('mrwcmc_sanitize_settings')) {
    function mrwcmc_sanitize_settings($input): array
    {
        $output = mrwcmc_get_option();

        // Enabled
        $output['enabled'] = !empty($input['enabled']);

        // Base currency from WooCommerce
        $output['base_currency'] = sanitize_text_field(get_option('woocommerce_currency', 'USD'));

        // Valid WooCommerce currencies
        $valid = function_exists('get_woocommerce_currencies') ? array_keys(get_woocommerce_currencies()) : [];

        // Supported currencies (validated, uppercased, ensure base present)
        $supported = isset($input['supported_currencies']) && is_array($input['supported_currencies'])
            ? array_map('sanitize_text_field', $input['supported_currencies'])
            : [];
        $supported = array_values(array_unique(array_map('strtoupper', $supported)));
        $supported = array_values(array_intersect($supported, $valid));
        if (!in_array($output['base_currency'], $supported, true)) {
            array_unshift($supported, strtoupper($output['base_currency']));
        }
        if (empty($supported)) {
            $supported = [strtoupper($output['base_currency'])];
        }

        // Country → Currency map from textarea
        $map_raw = isset($input['country_currency_map_raw']) ? wp_unslash($input['country_currency_map_raw']) : '';
        $map     = mrwcmc_parse_key_pairs($map_raw); // loose parse
        $clean   = [];
        $mappedCurrencies = [];

        if (!empty($map) && is_array($map)) {
            foreach ($map as $country => $cur) {
                $country = strtoupper(trim($country));
                $cur     = strtoupper(trim($cur));

                // allow '*' fallback, otherwise require 2-letter country
                if ($country !== '*' && !preg_match('/^[A-Z]{2}$/', $country)) {
                    continue;
                }
                // currency must be a valid Woo currency code
                if (!in_array($cur, $valid, true)) {
                    continue;
                }
                $clean[$country] = $cur;
                if ($country !== '*') {
                    $mappedCurrencies[$cur] = true;
                }
            }
        }

        // Ensure wildcard fallback exists
        if (!isset($clean['*'])) {
            $clean['*'] = strtoupper($output['base_currency']);
        }
        $output['country_currency_map'] = $clean;

        // OPTIONAL: auto-add currencies used in mapping to Supported (prevents “geo didn’t switch”)
        if (!empty($mappedCurrencies)) {
            $supported = array_values(array_unique(array_merge($supported, array_keys($mappedCurrencies))));
        }
        $output['supported_currencies'] = $supported;

        // Rate provider
        $provider = isset($input['rate_provider']) ? sanitize_text_field($input['rate_provider']) : 'frankfurter';
        $output['rate_provider'] = in_array($provider, ['frankfurter', 'exchangeratehost'], true) ? $provider : 'frankfurter';

        // Rate refresh
        $refresh = isset($input['rate_refresh']) ? sanitize_text_field($input['rate_refresh']) : 'daily';
        $output['rate_refresh'] = in_array($refresh, ['manual', 'hourly', 'twicedaily', 'daily'], true) ? $refresh : 'daily';

        // Geo provider + ipinfo token (NEW)
        $geo_provider = isset($input['geo_provider']) ? sanitize_text_field($input['geo_provider']) : 'auto';
        $output['geo_provider'] = in_array($geo_provider, ['auto', 'ipinfo', 'wc', 'cloudflare'], true) ? $geo_provider : 'auto';
        $output['ipinfo_token'] = isset($input['ipinfo_token']) ? sanitize_text_field($input['ipinfo_token']) : '';

        // Raw textareas (stored as-is; parsed elsewhere)
        $output['manual_rates_raw'] = isset($input['manual_rates_raw']) ? sanitize_textarea_field($input['manual_rates_raw']) : '';
        $output['markup_raw']       = isset($input['markup_raw']) ? sanitize_textarea_field($input['markup_raw']) : '';
        $output['rounding_raw']     = isset($input['rounding_raw']) ? sanitize_textarea_field($input['rounding_raw']) : '';

        // Allow user switch
        $output['allow_user_switch'] = !empty($input['allow_user_switch']);

        return $output;
    }
}

//
// ---- Fields ------------------------------------------------------------------
//

if (!function_exists('mrwcmc_render_settings_page')) {
    function mrwcmc_render_settings_page()
    {
        if (!current_user_can('manage_woocommerce')) return;
?>
        <div class="wrap">
            <h1><?php echo esc_html__('WooCommerce Multi-Currency', 'mr-multicurrency'); ?></h1>
            <form method="post" action="options.php">
                <?php settings_fields('mrwcmc_settings_group'); ?>
                <?php do_settings_sections('mrwcmc'); ?>
                <?php submit_button(); ?>
            </form>
            <hr />
            <h2><?php echo esc_html__('Tips', 'mr-multicurrency'); ?></h2>
            <p><?php echo esc_html__('Define supported currencies, map countries to default currencies, and (optionally) set manual rates, markup, and rounding. Conversion logic and rate fetching will be added next.', 'mr-multicurrency'); ?></p>
        </div>
    <?php
    }
}

if (!function_exists('mrwcmc_field_enabled')) {
    function mrwcmc_field_enabled()
    {
        $opt = mrwcmc_get_option();
    ?>
        <label>
            <input type="checkbox" name="mrwcmc_settings[enabled]" value="1" <?php checked($opt['enabled']); ?> />
            <?php echo esc_html__('Enable multi-currency pricing', 'mr-multicurrency'); ?>
        </label>
    <?php
    }
}

if (!function_exists('mrwcmc_field_base_currency')) {
    function mrwcmc_field_base_currency()
    {
        $base = get_option('woocommerce_currency', 'USD');
        $symbol = function_exists('get_woocommerce_currency_symbol') ? get_woocommerce_currency_symbol($base) : '';
        echo '<code>' . esc_html($base) . '</code> ' . esc_html($symbol);
        echo '<p class="description">' . esc_html__('Base currency is controlled in WooCommerce → Settings → General.', 'mr-multicurrency') . '</p>';
    }
}

if (!function_exists('mrwcmc_field_supported_currencies')) {
    function mrwcmc_field_supported_currencies()
    {
        $opt = mrwcmc_get_option();
        $currencies = function_exists('get_woocommerce_currencies') ? get_woocommerce_currencies() : [];
        if (empty($currencies)) {
            echo '<em>' . esc_html__('WooCommerce not found or no currencies available.', 'mr-multicurrency') . '</em>';
            return;
        }
    ?>
        <select multiple size="10" style="min-width:260px" name="mrwcmc_settings[supported_currencies][]">
            <?php foreach ($currencies as $code => $label): ?>
                <option value="<?php echo esc_attr($code); ?>" <?php selected(in_array($code, (array)$opt['supported_currencies'], true)); ?>>
                    <?php echo esc_html($code . ' — ' . $label); ?>
                </option>
            <?php endforeach; ?>
        </select>
        <p class="description"><?php echo esc_html__('Hold Ctrl/Cmd to select multiple. Include your base currency.', 'mr-multicurrency'); ?></p>
    <?php
    }
}

if (!function_exists('mrwcmc_field_country_map')) {
    function mrwcmc_field_country_map()
    {
        $opt = mrwcmc_get_option();
        $raw = '';
        if (!empty($opt['country_currency_map']) && is_array($opt['country_currency_map'])) {
            foreach ($opt['country_currency_map'] as $k => $v) {
                $raw .= $k . '=' . $v . "\n";
            }
        }
    ?>
        <textarea name="mrwcmc_settings[country_currency_map_raw]" rows="6" style="width:480px" placeholder="IN=INR
US=USD
GB=GBP
*=USD"><?php echo esc_textarea(trim($raw)); ?></textarea>
        <p class="description"><?php echo esc_html__('One mapping per line: CC=CUR (ISO country → ISO currency). Use *=CUR for fallback.', 'mr-multicurrency'); ?></p>
    <?php
    }
}

if (!function_exists('mrwcmc_field_rate_provider')) {
    function mrwcmc_field_rate_provider()
    {
        $opt = mrwcmc_get_option();
    ?>
        <select name="mrwcmc_settings[rate_provider]">
            <option value="frankfurter" <?php selected($opt['rate_provider'], 'frankfurter'); ?>><?php echo esc_html__('Frankfurter (ECB)', 'mr-multicurrency'); ?></option>
            <option value="exchangeratehost" <?php selected($opt['rate_provider'], 'exchangeratehost'); ?>><?php echo esc_html__('exchangerate.host', 'mr-multicurrency'); ?></option>
        </select>
        <p class="description"><?php echo esc_html__('Both are free/no-key sources. We’ll add more later.', 'mr-multicurrency'); ?></p>
    <?php
    }
}

if (!function_exists('mrwcmc_field_rate_refresh')) {
    function mrwcmc_field_rate_refresh()
    {
        $opt = mrwcmc_get_option();
    ?>
        <select name="mrwcmc_settings[rate_refresh]">
            <option value="manual" <?php selected($opt['rate_refresh'], 'manual'); ?>><?php echo esc_html__('Manual', 'mr-multicurrency'); ?></option>
            <option value="hourly" <?php selected($opt['rate_refresh'], 'hourly'); ?>><?php echo esc_html__('Hourly', 'mr-multicurrency'); ?></option>
            <option value="twicedaily" <?php selected($opt['rate_refresh'], 'twicedaily'); ?>><?php echo esc_html__('Twice Daily', 'mr-multicurrency'); ?></option>
            <option value="daily" <?php selected($opt['rate_refresh'], 'daily'); ?>><?php echo esc_html__('Daily', 'mr-multicurrency'); ?></option>
        </select>
    <?php
    }
}

if (!function_exists('mrwcmc_field_manual_rates')) {
    function mrwcmc_field_manual_rates()
    {
        $opt = mrwcmc_get_option();
    ?>
        <textarea name="mrwcmc_settings[manual_rates_raw]" rows="6" style="width:480px" placeholder="EUR=0.91
INR=84.30
GBP=0.78"><?php echo esc_textarea($opt['manual_rates_raw']); ?></textarea>
        <p class="description"><?php echo esc_html__('Lines of CUR=rate relative to base currency. These override auto rates for listed currencies.', 'mr-multicurrency'); ?></p>
    <?php
    }
}

if (!function_exists('mrwcmc_field_markup')) {
    function mrwcmc_field_markup()
    {
        $opt = mrwcmc_get_option();
    ?>
        <textarea name="mrwcmc_settings[markup_raw]" rows="6" style="width:480px" placeholder="EUR=%:3.0
INR=fixed:5"><?php echo esc_textarea($opt['markup_raw']); ?></textarea>
        <p class="description"><?php echo esc_html__('Per-currency extra amount. Use CUR=%:X or CUR=fixed:X (X is number). Applied after conversion.', 'mr-multicurrency'); ?></p>
    <?php
    }
}

if (!function_exists('mrwcmc_field_rounding')) {
    function mrwcmc_field_rounding()
    {
        $opt = mrwcmc_get_option();
    ?>
        <textarea name="mrwcmc_settings[rounding_raw]" rows="4" style="width:480px" placeholder="JPY=0
INR=2"><?php echo esc_textarea($opt['rounding_raw']); ?></textarea>
        <p class="description"><?php echo esc_html__('Decimals per currency (e.g., JPY=0). Step rounding can be added later.', 'mr-multicurrency'); ?></p>
    <?php
    }
}

if (!function_exists('mrwcmc_field_allow_switch')) {
    function mrwcmc_field_allow_switch()
    {
        $opt = mrwcmc_get_option();
    ?>
        <label>
            <input type="checkbox" name="mrwcmc_settings[allow_user_switch]" value="1" <?php checked($opt['allow_user_switch']); ?> />
            <?php echo esc_html__('Allow shoppers to switch currency (shortcode/widget coming next).', 'mr-multicurrency'); ?>
        </label>
    <?php
    }
}
// Geo provider select
if (!function_exists('mrwcmc_field_geo_provider')) {
    function mrwcmc_field_geo_provider()
    {
        $opt = mrwcmc_get_option();
        $val = isset($opt['geo_provider']) ? $opt['geo_provider'] : 'auto';
    ?>
        <select name="mrwcmc_settings[geo_provider]">
            <option value="auto" <?php selected($val, 'auto'); ?>>
                <?php echo esc_html__('Auto (Cloudflare → WooCommerce → ipinfo)', 'mr-multicurrency'); ?>
            </option>
            <option value="ipinfo" <?php selected($val, 'ipinfo'); ?>>
                <?php echo esc_html__('ipinfo (token recommended)', 'mr-multicurrency'); ?>
            </option>
            <option value="wc" <?php selected($val, 'wc'); ?>>
                <?php echo esc_html__('WooCommerce Geolocation / MaxMind', 'mr-multicurrency'); ?>
            </option>
            <option value="cloudflare" <?php selected($val, 'cloudflare'); ?>>
                <?php echo esc_html__('Cloudflare header only', 'mr-multicurrency'); ?>
            </option>
        </select>
        <p class="description">
            <?php echo esc_html__('Choose where we read the visitor country from.', 'mr-multicurrency'); ?>
        </p>
    <?php
    }
}

// ipinfo token text box
if (!function_exists('mrwcmc_field_ipinfo_token')) {
    function mrwcmc_field_ipinfo_token()
    {
        $opt = mrwcmc_get_option();
        $val = isset($opt['ipinfo_token']) ? $opt['ipinfo_token'] : '';
    ?>
        <input type="text" name="mrwcmc_settings[ipinfo_token]" style="width:320px"
            value="<?php echo esc_attr($val); ?>" placeholder="eg. 1234567890abcdef" />
        <p class="description">
            <?php echo esc_html__('Optional but recommended for production. Create a token at ipinfo.io and paste it here.', 'mr-multicurrency'); ?>
        </p>
<?php
    }
}
