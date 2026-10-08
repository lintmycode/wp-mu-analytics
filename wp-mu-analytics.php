<?php
/**
 * Plugin Name: nitida — Google Analytics (consent-gated)
 * Description: Customizer field for the GA4 measurement ID, handed to GDPR Cookie Compliance so Google Analytics only loads once the visitor opts in.
 * Version: 1.0.0
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
