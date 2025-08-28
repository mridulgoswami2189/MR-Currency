# MR Multi-Currency for WooCommerce (Geo + Markup)

A lightweight, **functional-style** WooCommerce add-on that:

- Auto-selects shopper currency by **geo-location** (Cloudflare → WooCommerce/MaxMind → ipinfo).
- Converts prices via **automatic** FX rates (Frankfurter/ECB or exchangerate.host) with caching & cron.
- Supports **manual rate overrides**, **per-currency markup** (percent/fixed), and **rounding** rules.
- Works across **catalog, variable products, cart, checkout, shipping, fixed coupons**, and admin orders.
- Lets users switch currency with a **dropdown/links switcher** (POST cookie write) with **param fallback**.
- **HPOS compatible** (declares custom order tables support).

> Pure WordPress hooks & small functions — no classes, no custom DB tables.

---

## Table of Contents

- [Requirements](#requirements)
- [Installation](#installation)
- [Folder Structure](#folder-structure)
- [Quick Start](#quick-start)
- [How It Works](#how-it-works)
- [Settings Reference](#settings-reference)
- [Shortcodes & Theme Hooks](#shortcodes--theme-hooks)
- [Admin & Orders](#admin--orders)
- [Cookies, Caching & CDN](#cookies-caching--cdn)
- [Compatibility Notes](#compatibility-notes)
- [Troubleshooting](#troubleshooting)
- [Developer API](#developer-api)
- [Security Notes](#security-notes)
- [Roadmap](#roadmap)
- [Changelog](#changelog)
- [License](#license)
- [Credits](#credits)

---

## Requirements

- WordPress **6.0+**
- WooCommerce **7.0+** (tested with 9.x)
- PHP **7.4+** (8.1/8.2 OK)
- If using WooCommerce geolocation: **MaxMind** key configured in Woo settings

---

## Installation

1. Copy the plugin folder to: `wp-content/plugins/mr-multicurrency/`
2. Activate **MR Multi-Currency** in **Plugins**.
3. (Recommended) In **WooCommerce → Settings → General**:
   - **Default customer location** → **Geolocate** (or **Geolocate (with page caching support)**).
   - Configure **MaxMind** key if using Woo geolocation.
4. If you’re behind **Cloudflare**, enable **IP Geolocation** so the `HTTP_CF_IPCOUNTRY` header is present.

---

## Folder Structure

```
mr-multicurrency/
├─ mr-multicurrency.php          # bootstrap (HPOS declare, i18n on init, includes)
└─ includes/
   ├─ common.php                 # shared helpers (options, parsing, cookie setter, utils)
   ├─ admin.php                  # settings page (Settings API)
   ├─ rates.php                  # rate providers, transients, cron refresh
   ├─ pricing.php                # conversion, markup, rounding, currency filter & price hooks
   ├─ geo.php                    # geo detection (Cloudflare/WC/ipinfo), first-visit cookie
   ├─ checkout.php               # shipping/coupon conversion, order snapshot (HPOS-safe)
   ├─ switcher.php               # [mrwcmc_switcher] (POST → cookie) + link fallback
   ├─ guard.php                  # strip ?mrwcmc=... param, set cookie, redirect clean
   └─ actions.php                # admin-post handler (mrwcmc_set_currency)
```

---

## Quick Start

1) **WooCommerce → Multi-Currency**  
   - ✅ **Enable multi-currency**  
   - **Supported currencies** → include all you’ll offer  
   - **Country → Currency map** (one per line):
     ```text
     US=USD
     CA=CAD
     GB=GBP
     IN=INR
     *=USD
     ```
   - **Rate provider** → `Frankfurter (ECB)` or `exchangerate.host`  
   - **Rate refresh** → `Daily` (or Hourly/Twice Daily)

2) *(Optional)* **Manual rates** (override auto):
   ```text
   EUR=0.91
   INR=84.30
   ```

3) *(Optional)* **Markup** (per currency):
   ```text
   EUR=%:3.0
   INR=fixed:5
   ```

4) *(Optional)* **Rounding (decimals)**:
   ```text
   JPY=0
   INR=2
   ```

5) *(Optional)* **Geo provider**  
   - **Geo provider** → `Auto` (Cloudflare → Woo → ipinfo)  
   - **ipinfo token** (free tier token recommended)

6) Add a **switcher** to a page or header:
   ```text
   [mrwcmc_switcher]                # dropdown (posts & reloads)
   [mrwcmc_switcher type="links"]   # inline links (JS posts; no-JS uses ?mrwcmc= fallback)
   ```

---

## How It Works

**Selection precedence**
1. **Manual selection** via POST (dropdown) or **query param** `?mrwcmc=XYZ` (links/no-JS)  
2. Existing **cookie** `mrwcmc_currency` (30 days)  
3. **Geo**: resolve country → map to currency (if supported)  
4. WooCommerce **base currency**

**Rates**
- Pulls from chosen provider; cached per schedule.
- **Manual rates** override auto rates for listed codes.
- Conversion applies **markup** and **rounding** per currency.

**Variable products**
- Cache hash varies by current currency.
- Variation price arrays & direct reads are converted.

---

## Settings Reference

| Setting | Key | Notes |
|---|---|---|
| Enable multi-currency | `enabled` | Master switch |
| Supported currencies | `supported_currencies[]` | Uppercase ISO-4217 |
| Country → Currency map | `country_currency_map_raw` | Lines `CC=CUR`; include fallback `*=CUR` |
| Rate provider | `rate_provider` | `frankfurter` \| `exchangeratehost` |
| Rate refresh | `rate_refresh` | `manual` \| `hourly` \| `twicedaily` \| `daily` |
| Manual rates | `manual_rates_raw` | Lines `CUR=rate` (relative to base) |
| Markup | `markup_raw` | `CUR=%:X` or `CUR=fixed:X` |
| Rounding (decimals) | `rounding_raw` | `CUR=N` (e.g., `JPY=0`) |
| Allow user switch | `allow_user_switch` | Enables switcher UI |
| Geo provider | `geo_provider` | `auto` \| `ipinfo` \| `wc` \| `cloudflare` |
| ipinfo token | `ipinfo_token` | Optional token for ipinfo API |

> The plugin stores options in `mrwcmc_settings`. Defaults are merged safely.

---

## Shortcodes & Theme Hooks

**Shortcodes**
- `[mrwcmc_switcher]` → dropdown (auto-submit via POST to `admin-post.php?action=mrwcmc_set_currency`)
- `[mrwcmc_switcher type="links"]` → inline links (JS intercepts to POST; no-JS falls back to `?mrwcmc=`)

**Theme hook**
```php
do_action('mrwcmc_switcher', ['type' => 'links']); // or omit to use dropdown
```

**Optional debug shortcode (if enabled in your build)**
```text
[mrwcmc_debug]
```

---

## Admin & Orders

- On checkout, the plugin **freezes** the order currency and stores a snapshot in `_mrwcmc_snapshot`:
  - `base_currency`, `order_currency`, `rates`, `provider`, `fetched_at`, and raw markup/rounding strings.
- Snapshot rendered on the order screen (HPOS-safe, no meta box dependency).
- Fixed-amount coupons & shipping costs are converted to the current currency.

---

## Cookies, Caching & CDN

- Cookie name: **`mrwcmc_currency`** (path: `COOKIEPATH` and `SITECOOKIEPATH`).
- We send **`Vary: Cookie`** via `send_headers`.  
  Configure your page cache/CDN to **vary** on `mrwcmc_currency` or bypass cache for pages with dynamic prices.
- The switcher uses **POST** to set the cookie (clean headers) and a **guard** that strips any `?mrwcmc=XYZ` param after setting the cookie (clean URLs).

---

## Compatibility Notes

- **HPOS**: Declared compatible via `FeaturesUtil::declare_compatibility('custom_order_tables', ...)`.
- **Woo Blocks / Store API**: Currency filter is applied globally; totals/prices respect the selected currency.
- **Variable products**: We vary Woo’s variation price cache by currency and convert arrays & direct reads.
- **Translations**: Loaded on `init` (WordPress 6.7+ requirement). Avoid calling Woo functions that trigger `woocommerce` textdomain **before** `init`.

---

## Troubleshooting

**Currency doesn’t change**
- Ensure the target code (e.g., `EUR`) is in **Supported currencies**.
- Use dropdown (POST) to avoid early header issues; links fallback relies on guard.
- Clear Woo caches: **WooCommerce → Status → Tools** → *Clear transients* & *Regenerate product lookup tables*.
- Disable cache/CDN for a test (or vary on `mrwcmc_currency`).

**Param shows but cookie doesn’t update**
- Another plugin/theme may have output before our guard runs; use the dropdown POST (which sets the cookie in a clean request).
- Confirm there’s **only one** `mrwcmc_set_currency_cookie()` in `includes/common.php` and it calls `setcookie()`.

**White page/no error**
- Check `wp-content/debug.log`.
- Add a mu-plugin logging fatal on shutdown:
  ```php
  add_action('shutdown', function (){ $e=error_get_last(); if($e && in_array($e['type'],[E_ERROR,E_PARSE,E_CORE_ERROR,E_COMPILE_ERROR],true)) error_log('[MRWCMC FATAL] '.print_r($e,true)); });
  ```

**Woo translation loaded too early**
- Ensure all includes load on `init` (not at file top) and no `__()` / `get_woocommerce_currencies()` is called pre-init.

---

## Developer API

**Filters**
```php
// Force/override current currency (final decision)
add_filter('mrwcmc_current_currency', fn($cur) => $cur, 10, 1);

// Override effective rate per currency (after manual/auto merge)
add_filter('mrwcmc_effective_rate', fn($rate, $cur) => $rate, 10, 2);

// After conversion (last chance to tweak a price)
add_filter('mrwcmc_price_converted', fn($amount, $cur) => $amount, 10, 2);

// Geo country readout
add_filter('mrwcmc_geo_country', fn($country) => $country, 10, 1);
```

**Actions**
```php
// Currency switcher render action
do_action('mrwcmc_switcher', ['type' => 'links']);

// Admin-post handler to set currency cookie
// POST to: /wp-admin/admin-post.php?action=mrwcmc_set_currency
```

**Helpers (from `includes/common.php`)**
```php
mrwcmc_get_option();             // Merged options (request-cached)
mrwcmc_get_supported_currs();    // Uppercased codes, base ensured
mrwcmc_set_currency_cookie('EUR');
```

---

## Security Notes

- The switcher POST endpoint is public and only accepts valid currency codes; it sets a cookie and redirects back. No personal data is stored.
- If you allow external geo providers (ipinfo), ensure your privacy policy reflects IP-based lookups.

---

## Roadmap

- Per-product hard local prices per currency  
- Payment gateways per currency  
- Cash-unit rounding (e.g., CHF 0.05) & psychological endings (x.99)  
- Rate smoothing + daily cutoff  
- WP-CLI commands (`wp mrwcmc rates refresh`, etc.)  
- Subscriptions compatibility (freeze currency across renewals)

---

## Changelog

**0.1.0**
- Initial public version: settings, rates (auto/manual), geo (CF/WC/ipinfo), pricing hooks (simple/variable), switcher (POST + param guard), checkout snapshot, HPOS declaration.

---

## License

GPL-2.0-or-later

---

## Credits

- FX data via **Frankfurter (ECB)** and **exchangerate.host**  
- Geo via **Cloudflare** header, **WooCommerce Geolocation/MaxMind**, or **ipinfo**