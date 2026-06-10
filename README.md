# EBIT OLX eXchange

**WooCommerce → OLX.ba synchronization plugin** powered by the EBIT Sync service.

Sync your WooCommerce products to [OLX.ba](https://olx.ba) directly from the WordPress admin — including categories, brands, attributes, images, prices, and descriptions — with automation features like scheduled sync, mass bump, sponsoring, and AI-generated titles.

> Plugin admin interface is in Bosnian/Croatian/Serbian (BHS), targeting OLX.ba sellers.

---

## Features

- **Product sync** — publish, update, hide/unhide, and delete WooCommerce products on OLX.ba, individually or in bulk
- **Mass sync** — queue-based batch synchronization of your entire catalog with retry handling
- **Auto sync (cron)** — background worker keeps OLX listings in sync with WooCommerce changes
- **Category, brand & attribute mapping** — map WooCommerce taxonomy to OLX.ba categories, brands, and category attributes
- **Price rules** — flexible price calculation rules (markup/adjustment) between shop price and OLX price
- **Smart Image Cache** — processed images cached for fast re-sync
- **Dynamic badge & frame rendering** — automatically overlay promotional badges/frames on product images
- **Description builder** — global prefix/suffix and templated OLX descriptions
- **AI titles** — AI-assisted listing title generation
- **VIP articles** — manage OLX VIP listings
- **Sponsoring** — sponsor listings from the WordPress admin
- **Mass BUMP** — bump (refresh) listings in bulk, respecting OLX daily limits
- **Duplicate check & daily populator** — optional housekeeping automations
- **Product list integration** — OLX status columns, filters, and bulk actions on the WooCommerce *Products* screen, plus a per-product metabox on the edit screen
- **Security-first** — OLX password is never stored locally; sessions use tokens, credentials are encrypted, all AJAX is nonce- and capability-protected

## Requirements

| Requirement | Version |
|---|---|
| WordPress | 5.8 or newer |
| WooCommerce | 5.0 or newer (active) |
| PHP | 7.4 or newer (8.x supported) |
| PHP extensions | `gd` (image badge/frame rendering), `openssl`, `json` |
| EBIT Sync server | Active license key and server URL (provided by EBIT Marketing) |
| OLX.ba account | Valid seller account |

The plugin is a **SaaS client**: all communication with OLX.ba goes through your EBIT Sync server endpoint. Outbound HTTPS requests from your WordPress host must be allowed.

## Installation

### A) Upload via WordPress admin (recommended)

1. Download the latest release ZIP of the plugin (`ebit-olx-exchange.zip`).
2. In your WordPress admin, go to **Plugins → Add New → Upload Plugin**.
3. Choose the ZIP file and click **Install Now**.
4. Click **Activate**. WooCommerce must already be installed and active.

### B) Manual FTP/SSH install

1. Extract the ZIP so you have an `ebit-olx-exchange/` folder containing `ebit-olx-exchange.php`.
2. Upload the folder to `wp-content/plugins/` on your server.
3. In WordPress admin, go to **Plugins** and activate **EBIT OLX eXchange**.

### C) From source (developers)

```bash
cd wp-content/plugins
git clone https://github.com/ebitmarketing/ebit-olx-exchange.git
cd ebit-olx-exchange
composer install   # optional — a fallback PSR-4 autoloader is built in
```

Then activate the plugin from the WordPress admin. Composer is only needed for the dev dependencies (PHPUnit, Brain Monkey, Mockery); in production the plugin autoloads `src/` on its own.

## Setup & Configuration

After activation, a new **OLX eXchange** menu item appears in the WordPress admin sidebar.

1. **Connect to the EBIT Sync server** — open **OLX eXchange → Postavke (Settings)** and enter:
   - **Server URL** — your EBIT Sync endpoint
   - **License key** — your EBIT license key, then refresh/validate the license
2. **Log in to OLX.ba** — enter your OLX username and password on the settings tab. The password is sent to the sync server for authentication only and is **never stored** in WordPress; the plugin keeps a session token. When the session expires you will be prompted to log in again.
3. **Map categories** — on the *Mapiranje (Mapping)* tab, map your WooCommerce product categories to OLX.ba categories, then set your country/city.
4. **Map brands and attributes** — match WooCommerce brands/attributes to OLX equivalents and set default attribute values where needed.
5. **Configure pricing & descriptions** — set price rules, a global title prefix, and global description template as needed.
6. **Image settings** — optionally enable the dynamic badge/frame overlay and image cache options.
7. **Enable automation (optional)** — turn on auto sync, set the cron batch size, and enable hide/unhide, duplicate check, or the daily populator.

### Syncing products

- **Single product**: open a product in WooCommerce and use the **OLX** metabox to sync, hide, or remove the listing.
- **Bulk**: select products on the *Products* list and use the OLX bulk actions, or run a full **Mass sync** from the plugin page.
- **Bump / Sponsor / VIP**: use the dedicated tabs on the OLX eXchange admin page (availability depends on your license tier).

## Uninstall

Deactivating the plugin clears its scheduled cron events. Deleting the plugin via **Plugins → Delete** runs `uninstall.php`, which removes plugin options and database tables.

## Development

```bash
composer install
vendor/bin/phpunit
```

- PSR-4 namespace `EbitOlx\` maps to [src/](src/); tests live under `EbitOlx\Tests\` in `tests/`.
- Legacy admin page and tab templates live in [includes/](includes/); the thin legacy shell is in [ebit-olx-exchange.php](ebit-olx-exchange.php).
- Unit tests use PHPUnit 9.6 with Brain Monkey and Mockery for WordPress function mocking.

### Project structure

```
ebit-olx-exchange.php   Plugin bootstrap + legacy shell (menu, settings, server proxy)
src/
  Plugin.php            Service container / boot sequence
  Admin/                Asset manager, product list columns, bulk actions, metabox
  Ajax/                 AJAX handlers (sync, mass sync, bump, sponsor, AI titles, license…)
  Api/                  OLX API client + EBIT Sync server client
  Cron/                 Background sync workers (batch worker, daily populator, sponsor)
  Database/             Migrations + product queue repository
  Image/                Image processor, badge & frame renderers
  License/              License client + feature gating
  Security/             Credential encryption, nonces, input validation
  Sync/                 Product sync service, payload/description builders, price calculator
includes/               Admin page + settings tab templates
assets/                 CSS/JS (Select2, admin UI)
```

## License

GPL — see [LICENSE.txt](LICENSE.txt).

---

**Autor:** Denis Nurboja · EBIT Marketing
kontakt 063-710-710
