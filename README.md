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

## Documentation

Each plugin documents itself inside its own folder — feature list, setup steps, admin screens, database schema, REST/AJAX surface, developer hooks, screenshots, known limitations, and what to expect when running it on a different website.

## Installation

```bash
# from wp-content/plugins/
git clone https://github.com/dev-noman501/my-plugins.git
```

Or download a single plugin folder and drop it into `wp-content/plugins/`, then activate it from **Plugins** in wp-admin. Requirements differ per plugin — see the plugin's own README.
