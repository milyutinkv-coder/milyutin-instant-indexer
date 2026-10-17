# Milyutin Instant Indexer

[![WordPress](https://img.shields.io/badge/WordPress-5.8%2B-21759B?logo=wordpress&logoColor=white)](https://wordpress.org/)
[![PHP](https://img.shields.io/badge/PHP-7.4%2B-777BB4?logo=php&logoColor=white)](https://www.php.net/)
[![License: GPL v2](https://img.shields.io/badge/License-GPL%20v2-blue.svg)](https://www.gnu.org/licenses/gpl-2.0)

> WordPress plugin for instantly submitting new and updated posts for indexing to **IndexNow** (Yandex, Bing, Naver, Seznam) and **Google Indexing API** (via your own Google Cloud service account). No third-party subscriptions required.

**Author:** Konstantin Milyutin · [milyutin-codes.ru](https://milyutin-codes.ru) · [VK Group](https://vk.com/milyutin_codes)

---

## 📋 Requirements

- WordPress 5.8 or higher
- PHP 7.4 or higher
- Pretty permalinks enabled (or write access to the site root for a physical key file)
- OpenSSL extension (required for encrypting the Google JSON key)

## 🚀 Installation

1. Upload the `milyutin-instant-indexer` folder to `wp-content/plugins/` (or upload a ZIP file via **Plugins → Add New → Upload Plugin**).
2. Activate the plugin. Upon activation, it automatically generates an IndexNow key, registers rewrite rules, and schedules WP-Cron tasks.
3. Go to the **Instant Indexer** menu and follow the on-screen instructions (the "Setup Instructions" section covers both IndexNow and Google).

## 📂 File Structure

```text
milyutin-instant-indexer/
├── milyutin-instant-indexer.php   # Entry point, auto-submit hooks, WP-Cron
├── uninstall.php                  # Complete data cleanup upon uninstall
├── includes/
│   ├── class-mii-settings.php     # Settings page, Settings API, sanitization
│   ├── class-mii-ajax.php         # AJAX handlers (nonce + capability checks)
│   ├── class-mii-indexnow.php     # IndexNow module + virtual key file
│   ├── class-mii-google.php       # Google Indexing API, JWT, daily quota, queue
│   ├── class-mii-logger.php       # File logging + recent 20 entries table
│   └── class-mii-crypto.php       # Google JSON service account encryption
└── assets/
    ├── css/admin.css              # Admin settings page styles
    └── js/admin.js                # AJAX, progress bar, check buttons
