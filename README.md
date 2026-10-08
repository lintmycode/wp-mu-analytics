# nitida/wp-mu-analytics

WordPress must-use plugin, installed with Composer, that loads Google
Analytics 4 **only after the visitor consents**. It never prints a tag. It
adds a Customizer field (`nitida_ga_id`, Appearance → Customize → Analytics)
and copies its value into
[GDPR Cookie Compliance](https://wordpress.org/plugins/gdpr-cookie-compliance/),
which holds gtag.js back until the analytics category is accepted.

Port of cinca's `analytics.php` v2.0.0.

## Why

The hand-copied `analytics.php` v1.0.0 (still on gkm, oneforma, vikingcars
and borealis as of 2026-10-08) echoes gtag.js into `wp_head` on every page.
That sets the `_ga` cookie before the visitor has agreed to anything, which
is what the consent banner is there to prevent (RGPD/ePrivacy).

## Requirements

GDPR Cookie Compliance installed and configured once (its settings saved at
least once). Without it the plugin writes nothing, and **no analytics load at
all**. That's deliberate: no consent layer means no tracking.

## Install (Bedrock)

```bash
composer config repositories.wp-mu-analytics vcs https://github.com/lintmycode/wp-mu-analytics.git
composer require nitida/wp-mu-analytics:^1.0
```

In the same commit:

- **Delete the hand-copied `analytics.php`.** With v1 still there, GA keeps
  loading before consent.
- Keep or remove the `nitida_ga_id` field in `contact-customizer.php`, either
  works. If another file already registers the field, this plugin leaves it
  alone; otherwise it adds its own Analytics section.

After deploying, push the existing ID into the consent plugin once (the sync
otherwise runs only when the Customizer is saved):

```bash
wp nitida analytics sync      # copies nitida_ga_id into GDPR Cookie Compliance
wp nitida analytics status    # Customizer ID vs what the consent plugin will load
```

Then purge the page cache.

## Verify

In a private window, before clicking the banner: no request to
`googletagmanager.com` and no `_ga` cookie. Accept analytics: gtag.js loads
without a reload.

Polylang sites: the consent plugin caches its script per language; `sync`
clears every language, not just the admin's.
