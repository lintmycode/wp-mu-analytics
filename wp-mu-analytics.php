<?php
/**
 * Plugin Name: nitida — Google Analytics (consent-gated)
 * Description: Customizer field for the GA4 measurement ID, handed to GDPR Cookie Compliance so Google Analytics only loads once the visitor opts in; plus the nitida standard lead events.
 * Version: 1.1.0
 * Author: nitida
 * License: MIT
 *
 * Installed as a Composer package (type wordpress-muplugin) into web/app/mu-plugins/,
 * loaded by roots/bedrock-autoloader. Port of cinca's analytics.php v2.0.0.
 *
 * This plugin never prints a tag. The hand-copied analytics.php v1.0.0 (still on several
 * sites as of 2026-10-08) echoed gtag.js into wp_head on every request, which set the _ga
 * cookie before the visitor had agreed to anything, i.e. exactly what the RGPD/ePrivacy
 * consent banner exists to prevent. GDPR Cookie Compliance (Moove) already emits an
 * identical gtag snippet, holds it back until the analytics category is accepted, and
 * injects it the moment the visitor accepts, with no reload. So the tag belongs to that
 * plugin, and this one only feeds it the ID from the Customizer field `nitida_ga_id`.
 *
 * Why a write-through sync rather than the `gdpr_integration_modules` filter, which looks
 * like the obvious hook: Moove's gdpr_extend_integration_snippets() returns early when the
 * saved `gdin_values` option is empty, and that check happens BEFORE the module list (and
 * therefore the filter) is ever built. Filtering the modules alone silently does nothing.
 * Writing the value into the option is what actually reaches the front end.
 *
 * Write-through (it clears the plugin's value when the field is emptied) rather than a
 * read-time filter on the option, because Moove's own settings screens read the option,
 * modify it and save it back, so anything injected at read time would be persisted and
 * keep firing after the Customizer field was cleared.
 *
 * Fails closed: no consent plugin means no analytics, never analytics with no way to refuse.
 */

namespace Nitida\Analytics;

defined('ABSPATH') || exit;

const SETTING = 'nitida_ga_id';
const MOOVE_OPTION = 'moove_gdpr_plugin_settings';

// Late priority, and only if nothing registered it first: sites still carrying the
// hand-copied contact-customizer.php register the same field in their own section.
add_action('customize_register', function (\WP_Customize_Manager $wp_customize): void {
    if ($wp_customize->get_setting(SETTING)) {
        return;
    }
    if (! $wp_customize->get_section('nitida_analytics')) {
        $wp_customize->add_section('nitida_analytics', [
            'title'    => __('Analytics', 'nitida'),
            'priority' => 31,
        ]);
    }
    $wp_customize->add_setting(SETTING, [
        'default'           => '',
        'sanitize_callback' => 'sanitize_text_field',
    ]);
    $wp_customize->add_control(SETTING, [
        'label'       => __('Google Analytics ID', 'nitida'),
        'section'     => 'nitida_analytics',
        'type'        => 'text',
        'description' => __('Ex: G-XXXXXXXXXX. Carregado só depois do consentimento (GDPR Cookie Compliance).', 'nitida'),
    ]);
}, 20);

add_action('customize_save_after', __NAMESPACE__ . '\\sync');

/**
 * Copies the Customizer's GA4 ID into GDPR Cookie Compliance's integration settings, so
 * the tag is emitted under analytics consent. Clears it there when the field is empty.
 *
 * @return string|null The ID now configured ('' for none), or null if the consent plugin
 *                     has never been configured and nothing was written.
 */
function sync(): ?string
{
    $options = get_option(MOOVE_OPTION);

    // Consent plugin not installed or never configured: write nothing (fail closed).
    if (! is_array($options)) {
        return null;
    }

    $ga_id = trim((string) get_theme_mod(SETTING));

    $gdin = isset($options['gdin_values']) ? json_decode($options['gdin_values'], true) : [];
    $gdin = is_array($gdin) ? $gdin : [];

    if ($ga_id === '') {
        unset($gdin['ga4'], $gdin['ga4_id']);
    } else {
        // 2 = the "thirdparty" consent bucket, shown to visitors as "Cookies analíticos".
        // See Moove's gdpr_insert_integration_ga_snippet().
        $gdin['ga4']    = 2;
        $gdin['ga4_id'] = $ga_id;
    }

    $options['gdin_values'] = $gdin ? wp_json_encode($gdin) : '';
    update_option(MOOVE_OPTION, $options);

    flush_consent_script_cache();
    return $ga_id;
}

/**
 * Drops Moove's cached consent-script payload.
 *
 * The payload is a transient keyed by language, and Moove's own cache-clearing action only
 * clears the language of the current request, which, saving from wp-admin, is not the
 * language most visitors get. So every Polylang language is cleared explicitly; otherwise a
 * changed ID keeps serving the stale payload until the transient expires.
 */
function flush_consent_script_cache(): void
{
    do_action('gdpr_cookie_filter_settings');

    if (! defined('MOOVE_GDPR_VERSION')) {
        return;
    }

    $suffixes = [''];
    if (function_exists('pll_languages_list')) {
        foreach (pll_languages_list() as $slug) {
            $suffixes[] = '_' . $slug;
        }
    }
    foreach ($suffixes as $suffix) {
        delete_transient('gdpr_cookie_cache' . $suffix . MOOVE_GDPR_VERSION);
    }
}

/*
 * Lead events (1.1.0) — the nitida tracking standard (google-analytics skill §2), same names on
 * every site so monthly reports read alike:
 *   generate_lead  {method: 'contact_form', form_plugin, form_id}  a form actually SENT
 *   whatsapp_click                                                  wa.me / api.whatsapp.com / whatsapp:
 *   contact_click  {method: 'phone' | 'email'}                      tel: / mailto:
 *
 * Why custom events: GA's automatic form_start/form_submit (enhanced measurement) fire on
 * every <form>, including site search and catalogue filters (on real sites they outnumber
 * actual enquiries many times over), and they miss AJAX success.
 * So "a lead" is the form plugin's own success event, never a submit:
 *   Kadence Blocks  kb-form-success / kb-advanced-form-success  CustomEvent on document.body
 *   Ninja Forms     jQuery(document) 'nfFormSubmitResponse'      success = response has no errors
 *   Contact Form 7  wpcf7mailsent                                bubbling CustomEvent
 * (checked in the plugins' own JS, 2026-11-27).
 *
 * Consent: this script never loads GA. It only calls window.gtag when it exists, and on these
 * sites gtag only exists after the visitor accepted analytics (GDPR Cookie Compliance injects
 * it), so before consent the events are simply dropped. Filter `nitida_analytics_lead_events`
 * (return false) turns it off for a site.
 */
add_action('wp_footer', function (): void {
    if (! apply_filters('nitida_analytics_lead_events', true) || is_admin()) {
        return;
    }
    ?>
<script id="nitida-lead-events">
(function () {
  function send(name, params) { if (typeof window.gtag === 'function') window.gtag('event', name, params || {}); }
  document.addEventListener('click', function (e) {
    var a = e.target && e.target.closest ? e.target.closest('a[href]') : null;
    if (!a) return;
    var h = (a.getAttribute('href') || '').trim().toLowerCase();
    if (/^(https?:\/\/)?(wa\.me|api\.whatsapp\.com|web\.whatsapp\.com)\b|^whatsapp:/.test(h)) send('whatsapp_click');
    else if (h.indexOf('tel:') === 0) send('contact_click', { method: 'phone' });
    else if (h.indexOf('mailto:') === 0) send('contact_click', { method: 'email' });
  }, true);
  function lead(plugin, id) { send('generate_lead', { method: 'contact_form', form_plugin: plugin, form_id: String(id || '') }); }
  ['kb-form-success', 'kb-advanced-form-success'].forEach(function (ev) {
    document.body.addEventListener(ev, function (e) { lead('kadence', e.detail && (e.detail.uniqueId || e.detail)); });
  });
  document.addEventListener('wpcf7mailsent', function (e) { lead('cf7', e.detail && e.detail.contactFormId); });
  if (window.jQuery) {
    window.jQuery(document).on('nfFormSubmitResponse', function (_e, r) {
      var errs = r && r.response && r.response.errors;
      if (!errs || (Array.isArray(errs) ? errs.length === 0 : Object.keys(errs).length === 0)) lead('ninja_forms', r && r.id);
    });
  }
})();
</script>
    <?php
}, 100);

if (defined('WP_CLI') && WP_CLI) {
    \WP_CLI::add_command('nitida analytics', new class {
        /**
         * Show the Customizer ID and what the consent plugin will load.
         */
        public function status(): void
        {
            $options = get_option(MOOVE_OPTION);
            $gdin = is_array($options) && ! empty($options['gdin_values']) ? json_decode($options['gdin_values'], true) : [];
            \WP_CLI::log('Customizer nitida_ga_id: ' . ((string) get_theme_mod(SETTING) ?: '(empty)'));
            \WP_CLI::log('Consent plugin configured: ' . (is_array($options) ? 'yes' : 'no'));
            \WP_CLI::log('Consent plugin GA4 ID: ' . (($gdin['ga4_id'] ?? '') ?: '(none)'));
        }

        /**
         * Push the Customizer ID into the consent plugin now. Run once after installing on a
         * site whose ID was already set, since the sync otherwise only runs on Customizer save.
         */
        public function sync(): void
        {
            $result = sync();
            if ($result === null) {
                \WP_CLI::error('GDPR Cookie Compliance is not configured; nothing written.');
            }
            \WP_CLI::success($result === '' ? 'No ID set; GA4 removed from the consent plugin.' : "GA4 {$result} loads after consent.");
        }
    });
}
