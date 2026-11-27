# Changelog

## 1.1.0 — 2026-11-27

Lead events, the nitida tracking standard: `generate_lead` on the form plugin's own
success event (Kadence Blocks, Ninja Forms, Contact Form 7), `whatsapp_click`,
`contact_click` (phone/email). GA's automatic form events count search and filter forms,
so they are not leads. Events only fire when gtag exists, i.e. after consent. Filter
`nitida_analytics_lead_events` turns it off.

## 1.0.0 — 2026-10-08

First release. Ports cinca's `analytics.php` v2.0.0 (Customizer GA4 ID synced
into GDPR Cookie Compliance, per-language cache clear). New: the plugin
registers the `nitida_ga_id` Customizer field itself when nothing else does,
and `wp nitida analytics sync|status` for the one-off sync after install.
Tested on cinca's local stack (Moove 5.1.0, Polylang pt/en/fr/it): sync, clear,
Customizer registration both standalone and alongside contact-customizer.php.
