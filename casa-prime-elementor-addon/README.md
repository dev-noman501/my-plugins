# Casa Prime Elementor Addon

Custom UI/styling layer for the Casa Prime storefront's **Elementor + WooCommerce** widgets.

Keeping these overrides in a small standalone plugin (instead of the theme or the template kit) means **theme, kit and Elementor updates never wipe out the custom work**.

> **Author:** Noman Nadeem ([dev-noman501](https://github.com/dev-noman501))
> **Requires:** WordPress 6.0+, PHP 7.4+, Elementor (for the styles to apply)
> **Version:** 1.5.0

## What it does

- Registers and enqueues the plugin's stylesheets (`assets/css/widgets.css`) on the front end **and inside the Elementor editor preview**, so the canvas always matches the live page while building.
- **Smart cache-busting** — asset versions come from the CSS file's own modification time, so every edit shows up immediately without clearing any cache.
- Shows an admin notice if Elementor is missing.

## Installation

1. Copy the folder into `wp-content/plugins/` and activate.
2. Edit/add styles in `assets/css/widgets.css` — changes go live instantly.

## Reusing in another project

This is a clean template for any "site-specific styles" plugin:

1. Rename the folder, plugin header and `CPEA_` prefix.
2. Add stylesheets to `assets/css/` and register them in the `cpea_stylesheets()` map (`handle => file`).
3. Everything else (front-end + editor-preview enqueue, mtime cache-busting) works as-is.
