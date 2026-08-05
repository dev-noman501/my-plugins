# Scrubs GHL Proxy

> A one-route server-side proxy to the GoHighLevel Contacts API. It fixes the CORS error caused by calling GHL directly from the browser, and keeps the API token out of the page source.

**Version:** 1.0.0
**Author:** Noman Nadeem
**Requires:** WordPress 5.6+, PHP 7.2+
**License:** GPL-2.0-or-later
**Dependencies:** none — a single PHP file, no build step, no libraries

---

## The problem it solves

A front-end pricing calculator was POSTing leads straight from the browser to GoHighLevel. Two things broke:

1. **CORS.** `services.leadconnectorhq.com` does not send `Access-Control-Allow-Origin` for arbitrary site origins, so the browser blocked every response.
2. **An exposed token.** The GHL bearer token had to sit in the page's JavaScript, readable by anyone.

On top of that, the existing calculator JS spoke the **GHL v1 payload shape** (a `customField` object, no `locationId`), which the **v2 API rejects**.

This plugin registers one same-origin WordPress REST route. The browser posts the *unchanged v1 payload* to WordPress; PHP validates it, converts v1 → v2, calls GHL server-to-server with the token in an `Authorization` header, and hands GHL's answer back.

**The design goal was zero front-end rewriting** — the payload going in and the error shape coming out both match what the old calculator already spoke. Only the fetch URL changes.

---

## Screenshots

There are none, and there is nothing to show: **this plugin has no admin screen, no settings page, no shortcode and no front-end output.** It is a single REST route configured entirely through PHP constants. Activating it is the whole installation.

The only visible sign it is working is the JSON your calculator receives — see [Verifying it works](#verifying-it-works).

---

## What it does, end to end

```
Browser ──POST /wp-json/scrubs/v1/ghl-contact──▶ WordPress
                                                    │  origin check
                                                    │  rate limit (20/min/IP)
                                                    │  require email
                                                    │  require pit- token
                                                    │  transform v1 → v2
                                                    ▼
                            POST https://services.leadconnectorhq.com/contacts/
                                                    │  Authorization: Bearer pit-…
                                                    │  Version: 2021-07-28
                                                    ▼
Browser ◀────────── GHL's JSON + status code ──────┘
```

**The v1 → v2 transformation**

| v1 in | v2 out |
|---|---|
| *(nothing)* | `locationId` — always injected from `SCRUBS_GHL_LOCATION_ID` |
| `firstName`, `lastName`, `email`, `phone` | copied when non-empty, each truncated to 191 chars |
| `source` | trimmed; defaults to `Website Calculator`; if `customField.total_estimate` exists its digits are appended as ` \| Est. £<amount>`; truncated to 250 chars |
| *(nothing)* | `tags` — always exactly `["Website Calculator"]`, the GHL workflow trigger |
| `customField: { key: value }` | `customFields: [{ key, field_value }]` — keys truncated to 100 chars, values to 500; empty values and non-scalars dropped |

---

## Installation

1. Copy the `scrubs-ghl-proxy` folder into `wp-content/plugins/` (or upload the zip via **Plugins → Add New → Upload Plugin**).
2. **Plugins → Activate** "Scrubs GHL Proxy". That registers the route — there is nothing else to click.
3. Configure the credentials below. **Until you do, every submission returns HTTP 500** — this is deliberate, see the security note.

---

## Configuration

All configuration is done with PHP constants in `wp-config.php`, **above** the `/* That's all, stop editing! */` line:

```php
define( 'SCRUBS_GHL_TOKEN',       'pit-xxxxxxxx-xxxx-xxxx-xxxx-xxxxxxxxxxxx' );
define( 'SCRUBS_GHL_LOCATION_ID', 'yourLocationIdHere' );

// Optional — the defaults are already correct for GHL v2:
// define( 'SCRUBS_GHL_ENDPOINT',    'https://services.leadconnectorhq.com/contacts/' );
// define( 'SCRUBS_GHL_API_VERSION', '2021-07-28' );
```

| Constant | Default | Purpose |
|---|---|---|
| `SCRUBS_GHL_TOKEN` | *empty* | GHL v2 Private Integration Token, sent as `Authorization: Bearer …` |
| `SCRUBS_GHL_LOCATION_ID` | *empty* | The GHL sub-account that receives the contacts |
| `SCRUBS_GHL_ENDPOINT` | `https://services.leadconnectorhq.com/contacts/` | Upstream URL |
| `SCRUBS_GHL_API_VERSION` | `2021-07-28` | Value of the GHL `Version` header |

> 🔐 **The token and location default to empty on purpose.** An unconfigured install fails loudly with a 500 rather than quietly writing leads into somebody else's CRM. Put the real values in `wp-config.php`, never in the plugin file — that keeps the secret out of version control and it survives plugin updates.

### Getting a Private Integration Token

1. In GoHighLevel, switch into the **sub-account (Location)** that should receive the leads.
2. **Settings → Private Integrations** (on some accounts: Settings → Integrations → Private Integrations).
3. **Create new integration**, name it e.g. "Website Calculator Proxy".
4. Grant at least the **`contacts.write`** scope.
5. Copy the token immediately — it starts with **`pit-`** and is shown only once.

> ⚠️ **Only `pit-` tokens work.** The plugin rejects anything else with a 500. v1 API keys and OAuth access tokens (which start `eyJ`) are not supported.

### Getting the Location ID

**Settings → Business Profile → Location ID**, or read it out of the CRM URL:
`https://app.gohighlevel.com/v2/location/<THIS_IS_THE_LOCATION_ID>/dashboard`

### Custom fields and the trigger tag

In **Settings → Custom Fields**, create one field for each key your form sends inside `customField` — e.g. `bedrooms`, `bathrooms`, `property_type`, `clean_type`, `total_estimate`.

> ⚠️ **The GHL field *key* must match your JSON key exactly.** GHL silently discards unknown keys — no error, no warning, the data simply never arrives. This is the single most common reason a field "doesn't come through".

Every submission is tagged **`Website Calculator`**, so build your GHL workflow trigger on that tag.

---

## The API

### `POST /wp-json/scrubs/v1/ghl-contact`

Public and unauthenticated — no nonce, no login, no API key. With plain permalinks use `?rest_route=/scrubs/v1/ghl-contact`.

**Request** — `Content-Type: application/json` is required. A form-urlencoded body parses to `null` and returns 400.

```jsonc
{
  "firstName": "Jane",                 // optional, max 191
  "lastName":  "Doe",                  // optional, max 191
  "email":     "jane@example.com",     // REQUIRED (only "not empty" is checked)
  "phone":     "+447700900000",        // optional
  "source":    "Website Calculator",   // optional, defaults to "Website Calculator"
  "customField": {                     // optional; keys must match GHL field keys
    "clean_type":     "Deep Clean",
    "property_type":  "Flat",
    "bedrooms":       "3",
    "bathrooms":      "2",
    "total_estimate": "£245.00"
  }
}
```

**Responses**

| Situation | Status | Body |
|---|---|---|
| Success | GHL's own (`201`/`200`) | GHL's decoded JSON, e.g. `{"contact":{"id":"…"}}`; non-JSON becomes `{"raw":"…"}` |
| `Origin` header host ≠ site host | `403` | `{"error":"Forbidden origin"}` |
| More than 20 requests/min from this IP | `429` | `{"error":"Too many requests"}` |
| Body not an object, or `email` missing | `400` | `{"error":"Invalid payload (email required)"}` |
| Token missing or not starting `pit-` | `500` | `{"error":"GHL Private Integration Token not configured…"}` |
| DNS / TLS / 20s timeout reaching GHL | `502` | `{"error":"<WP_Error message>"}` |
| GHL returned ≥400 | GHL's own (401/422/…) | `{"error":{"message":"<GHL message>"}}` |

> ⚠️ **Two different error shapes.** Local failures give a flat string (`error: "…"`), upstream failures give a nested object (`error: { message: "…" }`). Your front end must handle both — the example below does.

### Calling it from the front end

```js
const res = await fetch('/wp-json/scrubs/v1/ghl-contact', {
  method: 'POST',
  headers: { 'Content-Type': 'application/json' },
  body: JSON.stringify({
    firstName: 'Jane',
    lastName:  'Doe',
    email:     'jane@example.com',
    phone:     '+447700900000',
    customField: { bedrooms: '3', property_type: 'Flat', total_estimate: '£245.00' }
  })
});

const data = await res.json();

if (!res.ok) {
  const msg = data?.error?.message
           || (typeof data?.error === 'string' ? data.error : null)
           || 'Submission failed. Please try again.';
  showError(msg);
  return;
}

console.log(data.contact?.id);
```

Migrating an existing calculator is two edits: point the fetch at `/wp-json/scrubs/v1/ghl-contact`, and **delete the `Authorization` header from the JS**. The payload stays exactly as it was.

For an absolute URL inside an Elementor HTML widget, render it server-side so it survives plain permalinks and staging domains:

```php
<?php echo esc_url( rest_url( 'scrubs/v1/ghl-contact' ) ); ?>
```

---

## Verifying it works

1. Visit `https://<site>/wp-json/scrubs/v1` — `ghl-contact` should be listed in the routes.
2. Submit the real form once. The contact should appear in GHL with the tag `Website Calculator`, the right `source` (including `| Est. £…`) and populated custom fields.
3. Submit again with the same email — you should see GHL's duplicate-contact message surface in your UI, which proves error pass-through works.

---

## Using it on a different website

| Concern | What to expect |
|---|---|
| Credentials | **Must** be set per site in `wp-config.php`. There are no working defaults |
| Rename | Nothing is hard-coded to one domain. The constant prefix `SCRUBS_GHL_` and the route namespace `scrubs/v1` are cosmetic — rename both if you fork it |
| Currency | The `£` in the `source` string is hard-coded. Change it in `scrubs_ghl_proxy_transform_to_v2()` for other currencies |
| Tag | `Website Calculator` is hard-coded and not filterable — edit the function to change it |
| Permalinks | Pretty permalinks give `/wp-json/…`; plain permalinks need `?rest_route=` |
| Security plugins | Wordfence, MalCare and similar can block or throttle unauthenticated REST routes. If submissions 403 with no plugin error, check there first |
| Host firewall | The server must be allowed to make outbound HTTPS calls. Some shared hosts set `WP_HTTP_BLOCK_EXTERNAL` |
| Reverse proxy / CDN | The rate limiter reads `REMOTE_ADDR` only. Behind Cloudflare without `X-Forwarded-For` handling, every visitor shares one 20/min bucket |

---

## Security notes

**What protects the endpoint**

- **Origin check** — if an `Origin` header is present, its host must match the site's host, else 403.
- **Rate limit** — 20 requests per minute per IP, stored in a transient keyed `sghp_<md5(ip)>`.
- **Token never reaches the browser** — it lives in `wp-config.php` and is only ever attached server-side.
- Standard hygiene: `ABSPATH` guard, `wp_unslash()` on superglobals, `wp_json_encode()`, `wp_remote_post()` (so WP's TLS verification and HTTP filters apply). No SQL, no HTML output.

**What it does not do — know this before going live**

- **The origin check is skipped entirely when there is no `Origin` header.** Every `curl`, Postman call, bot and server-side script omits it, so this stops browsers only, never scripted abuse. It also compares host only, so `www.` vs apex differences will produce a spurious 403.
- **The rate limit is the only real defence,** and it is trivially defeated by rotating IPs.
- **There is no captcha, honeypot or nonce.** This is a free, unauthenticated write path into your CRM — spam contacts will fire your `Website Calculator` workflow and can cost you SMS/email credits. If the form is public and busy, put a captcha in front of it.
- **GHL's response is passed through verbatim,** which exposes internal CRM ids and lets someone probe whether an email already exists (the duplicate-contact message). That reflection is intentional so the calculator can show a useful message, but it is an information-disclosure trade-off.
- **The email is only checked for "not empty"** — there is no `is_email()` validation, so junk reaches GHL and is rejected there.

---

## Known limitations

- **Create-only.** It always calls `POST /contacts/`, never the upsert endpoint — so a returning visitor's second submission fails with GHL's duplicate-contact error instead of updating the record.
- **Nothing is stored in WordPress.** No custom table, no post type, no log. If GHL is down or the 20-second timeout trips, **the lead is lost permanently** — there is no retry and no email fallback.
- **No logging at all** — no `error_log`, no admin notice. Debugging means reproducing the failure in the browser and reading the JSON.
- Custom fields fail silently when key names don't match on the GHL side.
- No attribution data (`utm_source`, `gclid`, referrer, page URL) is forwarded to GHL.
- Hard-coded GBP symbol and hard-coded trigger tag.
- **No hooks.** The plugin fires no actions or filters, so the payload, headers, endpoint, rate limit and response cannot be changed without editing the file.
- No i18n — a text domain is declared but never used; all strings are hard-coded English.
- Functions are global (`scrubs_ghl_proxy_handle`, `scrubs_ghl_proxy_transform_to_v2`) with no `function_exists()` guard, so a name collision would be fatal.
- No `uninstall.php`. The only leftovers are the rate-limit transients, which expire on their own.

---

## Technical reference

**Files** — one: `scrubs-ghl-proxy.php` (~220 lines).

**Route registration**

```php
register_rest_route( 'scrubs/v1', '/ghl-contact', array(
    'methods'             => 'POST',
    'callback'            => 'scrubs_ghl_proxy_handle',
    'permission_callback' => '__return_true',
) );
```

No `args` schema is declared, so WordPress performs no validation of its own — everything is checked manually in the callback.

**Upstream request**

```
POST https://services.leadconnectorhq.com/contacts/
Authorization: Bearer <SCRUBS_GHL_TOKEN>
Version: 2021-07-28
Accept: application/json
Content-Type: application/json
```

Sent with `wp_remote_post()`, 20-second timeout, no retry.

**Storage** — no options, no tables, no post meta. Only transients named `sghp_<md5(ip)>` with a 60-second TTL (these land in `wp_options` on sites without a persistent object cache).

**WordPress hooks consumed** — `rest_api_init` only.
