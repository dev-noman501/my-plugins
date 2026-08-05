# Handling API keys and secrets

Several plugins in this repository talk to paid third-party APIs. **No key, token or password is stored in the code here, and none ever has been.** This page explains where they go instead, so nothing leaks when a plugin is published, cloned or copied between sites.

---

## The rule

> A credential never lives in a plugin file.

A key written into a `.php` file travels with every copy of that plugin. It ends up in git history, in a zip you email a client, in a backup, and on any machine that clones the repo. Once that happens the key must be treated as burned even if you delete the line, because git keeps the old version.

Every plugin here reads its credentials from one of two places, and looks in this order:

| Order | Where | Best for |
|---|---|---|
| 1 | A constant in `wp-config.php` | Production. Keeps the key out of the database, out of DB exports, and out of anything committed |
| 2 | A field on the plugin's settings screen | Convenience, when a non-developer has to paste the key themselves |

If the constant is defined it wins and the settings field is ignored. The settings screen tells you when that is happening, so nobody wastes time editing a field that has no effect.

---

## Where each plugin's key goes

Add whichever lines you need to `wp-config.php`, **above** the line that reads `/* That's all, stop editing! Happy publishing. */`:

```php
/* ---- AI Support Chat ---- */
define( 'ASC_API_KEY',       'sk-...' );   // OpenAI, Gemini or OpenRouter key
define( 'ASC_EMBED_API_KEY', 'sk-...' );   // only if embeddings use a different provider

/* ---- PageSpeed Audit ---- */
define( 'PSA_API_KEY', 'AIza...' );        // Google PageSpeed Insights

/* ---- Referral Tracker Pro ---- */
define( 'RTP_CALLRAIL_API_KEY',    'ctrk_...' );
define( 'RTP_CALLRAIL_ACCOUNT_ID', '000000000' );

/* ---- Scrubs GHL Proxy ---- */
define( 'SCRUBS_GHL_TOKEN',       'pit-...' );   // GoHighLevel Private Integration Token
define( 'SCRUBS_GHL_LOCATION_ID', '...' );

/* ---- Casa Prime Core ---- */
define( 'CPC_STRIPE_SECRET_KEY',      'sk_live_...' );
define( 'CPC_STRIPE_PUBLISHABLE_KEY', 'pk_live_...' );
define( 'CPC_STRIPE_WEBHOOK_SECRET',  'whsec_...' );
define( 'CPC_JWT_SECRET',             'a long random string' );
```

| Plugin | Constant | Settings screen |
|---|---|---|
| AI Support Chat | `ASC_API_KEY`, `ASC_EMBED_API_KEY` | AI Support Chat → General |
| PageSpeed Audit | `PSA_API_KEY` | Settings → PageSpeed Audit |
| Referral Tracker Pro | `RTP_CALLRAIL_API_KEY`, `RTP_CALLRAIL_ACCOUNT_ID` | Referrals → Settings |
| Scrubs GHL Proxy | `SCRUBS_GHL_TOKEN`, `SCRUBS_GHL_LOCATION_ID` | none, constants only |
| Casa Prime Core | `CPC_STRIPE_*`, `CPC_JWT_SECRET` | none, constants only |

> ⚠️ Referral Tracker Pro's five-minute CallRail cron reads the settings row rather than the constant. If you configure it by constant alone, the manual sync and the webhook work but automatic polling does not. Put the value in the settings screen as well.

---

## Restrict the key at the provider, not just in code

A key that only works for one API and one server is worth far less to whoever finds it.

- **Google (PageSpeed)** — on the key, set *API restrictions → Restrict key → PageSpeed Insights API*. HTTP referrer restrictions do **not** work here because the call is made server side; use an IP restriction with the server's outbound IP, or rely on the API restriction plus a quota cap.
- **OpenAI / OpenRouter** — create a project-scoped key per site and set a monthly spend limit, so a leak cannot run up an unbounded bill.
- **CallRail** — a read-only key is enough for this plugin. Do not issue a read-write one.
- **Stripe** — never put a `sk_live_` key on a staging site. Use test keys everywhere except production.
- **GoHighLevel** — grant only the `contacts.write` scope.

---

## Before you push

```bash
# The pattern below matches a real key, not the "sk_live_..." style
# placeholders used in documentation.
KEYS='AIzaSy[0-9A-Za-z_-]{25,}|sk-[A-Za-z0-9]{30,}|sk_live_[A-Za-z0-9]{20,}|whsec_[A-Za-z0-9]{20,}|pit-[0-9a-f]{8}-[0-9a-f]{4}|ctrk_[A-Za-z0-9]{20,}'

# 1. Nothing credential-shaped anywhere in the working tree
grep -rInE "$KEYS" . --include=*.php --include=*.js --include=*.json --include=*.md

# 2. Nothing credential-shaped anywhere in history either
git log --all -p -G"$KEYS" --oneline
```

Both should print nothing. Run them before every push. As of the latest commit both are clean, and no credential has ever been committed to this repository.

The `.gitignore` already blocks `wp-config.php`, `.env`, key files, database dumps and zip archives, which is where credentials usually sneak in.

---

## If a key does get committed

Deleting the line is **not** enough. The old version stays in git history and, on a public repo, has almost certainly been scraped within minutes.

Do it in this order:

1. **Rotate the key at the provider first.** Revoke the old one. This is the only step that actually stops the leak, and it works immediately.
2. Remove the key from the code and replace it with a constant lookup.
3. Only then consider rewriting history with `git filter-repo` or the BFG. Note this **rewrites every commit hash** and needs a force push, so anyone else with a clone has to re-clone.
4. Check the provider's usage logs for calls you did not make.

Step 1 is the one that matters. Steps 3 and 4 are cleanup.

---

## Reporting a problem

Found something in this repository that looks like a credential, or a security hole in one of the plugins? Open an issue with the file and line, but **do not paste the key itself** into the issue.
