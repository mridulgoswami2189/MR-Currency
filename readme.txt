=== MR Multi-Currency for WooCommerce (Geo + Markup) ===
Contributors: mrwcmc
Tags: woocommerce, currency, multi-currency, exchange rates, geolocation, ecommerce
Requires at least: 6.0
Tested up to: 6.7
Requires PHP: 7.4
Stable tag: 0.1.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

A lightweight, functional-style WooCommerce add-on for automatic & manual FX rates, per-currency markup/rounding, geo-based defaults, and a clean switcher. HPOS compatible.

== Description ==

**MR Multi-Currency** lets your WooCommerce store display prices and totals in multiple currencies.

**Highlights**
* Auto-select currency by **geo-location** (Cloudflare → WooCommerce/MaxMind → ipinfo)
* **Automatic** FX rates (Frankfurter/ECB or exchangerate.host) with caching & cron
* **Manual rate overrides** per currency
* Per-currency **markup** (percent/fixed) & **rounding** rules
* Works across **catalog, variable products, cart, checkout, shipping, fixed coupons**, and admin orders
* Switcher: **dropdown (POST cookie write)** + **links** with param fallback and auto-clean URLs
* **HPOS compatible** (declared support for custom order tables)

No ORM, no classes, no custom DB tables — just small, testable functions and WordPress hooks.

== Installation ==

1. Upload the plugin to `wp-content/plugins/mr-multicurrency/`.
2. Activate **MR Multi-Currency** from **Plugins**.
3. (Recommended) In **WooCommerce → Settings → General**:
   * **Default customer location** → **Geolocate** (or **Geolocate (with page caching support)**).
   * Configure **MaxMind** key if using Woo geolocation.
4. If you’re behind **Cloudflare**, enable **IP Geolocation** so `HTTP_CF_IPCOUNTRY` is present.

== Frequently Asked Questions ==

= How do I add the currency switcher? =
Use the shortcode on any page or in a block:
```
[mrwcmc_switcher]                ; dropdown (POST) options like “€ EUR”, “₹ INR”, “$ USD”
[mrwcmc_switcher type="links"]   ; inline links (JS posts; no-JS uses ?mrwcmc= fallback)
[mrwcmc_switcher type="links" style="symbol_only"] ;Links with symbol only: → € ₹ $
[mrwcmc_switcher style="code_symbol"] ; Code then symbol → EUR €, INR ₹, USD $

```
Or in a theme template:
```
do_action('mrwcmc_switcher', ['type' => 'links']);
```

= How does the plugin choose a currency? =
1) Manual selection (POST dropdown or `?mrwcmc=XYZ` for no-JS)  
2) Existing cookie `mrwcmc_currency` (30 days)  
3) Geo → Country → Currency map  
4) WooCommerce base currency

= My prices don’t change when I switch currency. What should I check? =
* Ensure the target code (e.g., `EUR`) is listed in **Supported currencies**.
* Use the dropdown (POST) — it sets the cookie in a clean request.
* Clear Woo caches (**WooCommerce → Status → Tools** → Clear transients / Regenerate product lookup tables).
* Disable page cache/CDN temporarily (or configure a **vary** on the `mrwcmc_currency` cookie).

= The URL shows ?mrwcmc= but it disappears right away. Is that normal? =
Yes. The guard sets the cookie and **redirects to a clean URL** to avoid breaking other routes.

= Does it support variable products? =
Yes. The plugin varies Woo’s variation price **cache hash** by currency and converts both array and direct price reads.

= Is it HPOS compatible? =
Yes. The plugin declares compatibility with custom order tables and renders order meta in an HPOS-safe way.

= What about privacy? =
If you enable external geolocation (ipinfo), the visitor IP is sent to ipinfo.io. Mention this in your site’s privacy policy.

== Screenshots ==
1. Settings page
2. Frontend currency switcher
3. Order screen with snapshot

== Changelog ==

= 0.1.0 =
* Initial public version: settings, rates (auto/manual), geo (CF/WC/ipinfo), pricing hooks (simple/variable), switcher (POST + param guard), checkout snapshot, HPOS declaration.

== Upgrade Notice ==

= 0.1.0 =
First public release.

== Detailed Setup ==

= Quick Start =
1. Enable the plugin in **WooCommerce → Multi-Currency**.
2. Select **Supported currencies**.
3. Fill **Country → Currency map** (one per line, include `*=CUR` fallback).
4. Choose **Rate provider** and **Rate refresh** schedule.
5. Optional: **Manual rates**, **Markup**, **Rounding**.
6. Add a **switcher** via shortcode or theme hook.

= Example field values =
```
Country → Currency map:
US=USD
CA=CAD
GB=GBP
IN=INR
*=USD

Manual rates:
EUR=0.91
INR=84.30

Markup:
EUR=%:3.0
INR=fixed:5

Rounding (decimals):
JPY=0
INR=2
```

== Developer Notes ==

**Filters**
```
add_filter('mrwcmc_current_currency', function($cur){ return $cur; }, 10, 1);
add_filter('mrwcmc_effective_rate', function($rate, $cur){ return $rate; }, 10, 2);
add_filter('mrwcmc_price_converted', function($amount, $cur){ return $amount; }, 10, 2);
add_filter('mrwcmc_geo_country', function($country){ return $country; }, 10, 1);
```

**Helpers**
```
mrwcmc_get_option();             // merged options
mrwcmc_get_supported_currs();    // array of ISO codes
mrwcmc_set_currency_cookie('EUR');
```

**Order snapshot meta (`_mrwcmc_snapshot`)**
```
base_currency, order_currency, rates, provider, fetched_at, markup_raw, rounding_raw
```

== License ==

This program is free software: you can redistribute it and/or modify it under the terms of the **GNU General Public License v2 (or later)** as published by the Free Software Foundation.

