# Changelog

## 1.0.0 — 2026-10-08

First release. Ports cinca's `analytics.php` v2.0.0 (Customizer GA4 ID synced
into GDPR Cookie Compliance, per-language cache clear). New: the plugin
registers the `nitida_ga_id` Customizer field itself when nothing else does,
and `wp nitida analytics sync|status` for the one-off sync after install.
Tested on cinca's local stack (Moove 5.1.0, Polylang pt/en/fr/it): sync, clear,
Customizer registration both standalone and alongside contact-customizer.php.
