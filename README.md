# my-plugins

Custom WordPress plugins built by **Noman Nadeem** ([dev-noman501](https://github.com/dev-noman501)).

One folder per plugin. Each folder is a complete, self-contained WordPress plugin — copy it into `wp-content/plugins/` and activate.

## Plugins

| Plugin | Description | Docs |
|---|---|---|
| [`casa-prime-core`](casa-prime-core/) | Headless e-commerce engine for WooCommerce — JWT auth, server-side cart, weight-based pricing, distance-based delivery, rider management, Stripe payments, Firebase push, and a 60+ endpoint REST API powering the Casa Prime mobile apps. | [README](casa-prime-core/README.md) |
| [`casa-prime-elementor-addon`](casa-prime-elementor-addon/) | Update-safe custom styling layer for Elementor and WooCommerce widgets on the Casa Prime storefront. | [README](casa-prime-elementor-addon/README.md) |
| [`ai-support-chat`](ai-support-chat/) | AI chatbot trained on your own site content and uploaded documents (RAG), with human handoff to a built-in ticket system. Multi-provider (OpenAI / Gemini / OpenRouter), Shadow DOM widget, also embeddable on external sites. | [README](ai-support-chat/README.md) |
| [`tgm-voucher`](tgm-voucher/) | Umrah / Hajj travel voucher generator — six-step wizard, voucher list table, QR code, and a print-ready A4 document with an Approved / Unapproved watermark. | [README](tgm-voucher/README.md) |
| [`referral-tracker-pro`](referral-tracker-pro/) | Referral link tracking and analytics — visits, `tel:` call clicks and form submissions attributed to per-referrer links, with lead capture, printable lead sheets and a CallRail integration for verified calls. | [README](referral-tracker-pro/README.md) |
| [`scrubs-ghl-proxy`](scrubs-ghl-proxy/) | One-route server-side proxy to the GoHighLevel Contacts API — fixes browser CORS errors, keeps the API token off the page source, and translates the legacy v1 payload to v2. | [README](scrubs-ghl-proxy/README.md) |
| [`app-auth-api`](app-auth-api/) | A ready-made REST API for mobile apps on WordPress + WooCommerce — registration, login, password reset, products, categories, content, cart, vouchers and checkout. Delivery zones and fees are configurable, so it works on any store. | [README](app-auth-api/README.md) |
| [`redirection-urls`](redirection-urls/) | Manage 301/302/307 redirects one at a time or in bulk from a CSV. Built for SEO migrations. | [README](redirection-urls/README.md) |
| [`pagespeed-audit-plugin`](pagespeed-audit-plugin/) | Front-end site-audit tool — runs any URL through the Google PageSpeed Insights API, shows Lighthouse scores and Core Web Vitals for desktop and mobile, and emails a PDF report. | [README](pagespeed-audit-plugin/README.md) |
| [`youtube-reels-carousel`](youtube-reels-carousel/) | Full-width carousel of vertical YouTube videos that autoplay muted in the grid and open in a centre-mode lightbox. | [README](youtube-reels-carousel/README.md) |

## Documentation

Each plugin documents itself inside its own folder — feature list, setup steps, admin screens, database schema, REST/AJAX surface, developer hooks, screenshots, known limitations, and what to expect when running it on a different website.

## API keys and secrets

No plugin in this repository stores a credential in its code. Keys are read from a
constant in `wp-config.php`, falling back to the plugin's own settings screen.
See **[SECURITY.md](SECURITY.md)** for where each plugin's key goes, how to restrict
it at the provider, and what to do if one ever gets committed.

## Installation

```bash
# from wp-content/plugins/
git clone https://github.com/dev-noman501/my-plugins.git
```

Or download a single plugin folder and drop it into `wp-content/plugins/`, then activate it from **Plugins** in wp-admin. Requirements differ per plugin — see the plugin's own README.
