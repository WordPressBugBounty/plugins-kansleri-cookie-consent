<?php
/**
 * Plugin Name: Kansleri Cookie Consent
 * Description: Free, no-ads GDPR cookie consent with script blocking, consent logging, Google Consent Mode v2, and AI-assisted setup. Perfect for personal sites and small businesses.
 * Version: 1.8.12
 * Author: Kansleri.fi
 * Author URI: https://kansleri.fi
 * License: GPLv2 or later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain: kansleri-cookie-consent
 * Domain Path: /languages
 * Requires at least: 6.0
 * Requires PHP: 7.4
 *
 * @package Kansleri_Cookie_Consent
 */

if (!defined('ABSPATH')) {
  exit;
}

define('KNCC_VERSION', '1.8.12');
// Bump whenever the consent log schema changes so dbDelta runs on upgrade.
define('KNCC_DB_VERSION', '1.3.0');
define('KNCC_PLUGIN_DIR', plugin_dir_path(__FILE__));
define('KNCC_PLUGIN_URL', plugin_dir_url(__FILE__));
define('KNCC_BASENAME', plugin_basename(__FILE__));

if (!function_exists('kncc_asset_version')) :
/**
 * Cache-busting version for a plugin asset based on its file modification time.
 * Ensures browsers re-download JS/CSS whenever a file changes, instead of
 * relying on the static plugin version (which does not change between edits).
 *
 * @param string $relative Path relative to the plugin root, e.g. 'assets/admin.js'.
 * @return string filemtime as a string, or KNCC_VERSION if the file is missing.
 */
function kncc_asset_version($relative) {
  $path = KNCC_PLUGIN_DIR . ltrim($relative, '/');
  $mtime = @filemtime($path); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- missing file falls back to plugin version.
  return $mtime ? (string) $mtime : KNCC_VERSION;
}
endif;

require_once KNCC_PLUGIN_DIR . 'includes/class-admin.php';
require_once KNCC_PLUGIN_DIR . 'includes/class-scanner.php';
require_once KNCC_PLUGIN_DIR . 'includes/class-service-cookies.php';
require_once KNCC_PLUGIN_DIR . 'includes/class-consent.php';
require_once KNCC_PLUGIN_DIR . 'includes/class-policy-page.php';
require_once KNCC_PLUGIN_DIR . 'includes/class-consent-mode.php';
require_once KNCC_PLUGIN_DIR . 'includes/class-wp-consent-api.php';
require_once KNCC_PLUGIN_DIR . 'includes/class-stats.php';
require_once KNCC_PLUGIN_DIR . 'includes/class-script-blocker.php';
require_once KNCC_PLUGIN_DIR . 'includes/class-consent-log.php';
require_once KNCC_PLUGIN_DIR . 'includes/class-wp-rocket.php';
require_once KNCC_PLUGIN_DIR . 'includes/class-polylang.php';

// Ensure daily cron is always scheduled (survives updates without reactivation).
add_action('init', function () {
  if (!wp_next_scheduled('kncc_daily_cookie_check')) {
    wp_schedule_event(time(), 'daily', 'kncc_daily_cookie_check');
  }
});

if (!function_exists('kncc_get_default_settings')) :
function kncc_get_default_settings() {
  return array(
    'banner_style'         => 'corner',
    'banner_position'      => 'bottom',
    'text_style_mode'      => 'plugin',
    'policy_style_mode'    => 'theme',
    'banner_bg_color'      => '#ffffff',
    'heading_color'        => '#1f2937',
    'body_color'           => '#4b5563',
    'primary_color'        => '#3D4872',
    'primary_text_color'   => '#ffffff',
    'secondary_color'      => '#f3f4f6',
    'secondary_text_color' => '#4b5563',
    'heading_text'             => __('We use cookies', 'kansleri-cookie-consent'),
    'description_text'         => __('We use cookies to improve your experience and analyze site traffic. Choose which cookies you allow.', 'kansleri-cookie-consent'),
    'accept_text'              => __('Accept all', 'kansleri-cookie-consent'),
    'reject_text'              => __('Reject all', 'kansleri-cookie-consent'),
    'customize_text'           => __('Customize', 'kansleri-cookie-consent'),
    'save_text'                => __('Save preferences', 'kansleri-cookie-consent'),
    'close_text'               => __('Close', 'kansleri-cookie-consent'),
    'iframe_placeholder_text'  => __('This content is blocked because it requires marketing cookies.', 'kansleri-cookie-consent'),
    'iframe_accept_text'       => __('Accept marketing cookies', 'kansleri-cookie-consent'),
    'consent_mode'             => true,
    'consent_mode_advanced'    => false,
    'policy_page_id'     => 0,
    'show_floating_btn'  => true,
    'empty_category_mode' => 'disable',
    'block_html_scripts' => true,
    'block_iframes'      => true,
  ) + array_fill_keys(kncc_get_builtin_text_keys(), '');
}
endif;

if (!function_exists('kncc_frontend_html_snapshot')) :
/**
 * Shared cached HTML snapshot of the site's homepage. Used by the GTM detector and the cookie scanner
 * to answer "is service X actually running on this site?" questions without making an HTTP request
 * on every check.
 *
 * Returns the homepage body on success, empty string on failure. Cached 30 min on success, 5 min on failure.
 */
function kncc_frontend_html_snapshot() {
  $cache_key = 'kncc_frontend_html';
  $cached = get_transient($cache_key);
  if ($cached !== false) {
    return $cached;
  }
  $response = wp_remote_get(home_url('/'), array(
    'timeout'     => 5,
    'redirection' => 2,
    'sslverify'   => apply_filters('https_local_ssl_verify', false, home_url('/')), // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- WordPress core filter from class-wp-http.php.
    'user-agent'  => 'Mozilla/5.0 (Kansleri Cookie Consent detector)',
  ));
  $body = (!is_wp_error($response) && wp_remote_retrieve_response_code($response) === 200)
    ? (string) KNCC_Service_Cookies::strip_own_scripts(wp_remote_retrieve_body($response))
    : '';
  set_transient($cache_key, $body, $body ? 30 * MINUTE_IN_SECONDS : 5 * MINUTE_IN_SECONDS);
  return $body;
}
endif;

if (!function_exists('kncc_service_runs_on_site')) :
/**
 * Check whether a cookie provider's actual tracking script/endpoint is referenced on this site's homepage.
 * Used to filter out "leaked" third-party cookies that appear in the admin's browser due to subdomain
 * or past-visit cookie scope but are not actually set by this site (e.g. a parent domain's _ga cookie).
 *
 * Unknown providers and first-party/plugin providers return true (trust). HTTP failure also returns
 * true (conservative) so the scanner doesn't silently drop cookies when the site is unreachable.
 */
function kncc_service_runs_on_site($provider) {
  return KNCC_Service_Cookies::provider_runs_on_site($provider);
}
endif;

if (!function_exists('kncc_get_settings')) :
function kncc_get_settings() {
  $defaults = kncc_get_default_settings();
  $saved = get_option('kncc_settings', array());
  return wp_parse_args($saved, $defaults);
}
endif;

if (!function_exists('kncc_get_locale_for_slug')) :
/**
 * Resolve a multilingual language slug (e.g. 'fi', 'en') to a WP locale (e.g. 'fi', 'en_US').
 * Returns empty string if the slug cannot be resolved.
 */
function kncc_get_locale_for_slug($slug) {
  if (!$slug) {
    return '';
  }
  if (function_exists('pll_languages_list')) {
    $slugs   = pll_languages_list(array('fields' => 'slug'));
    $locales = pll_languages_list(array('fields' => 'locale'));
    if (is_array($slugs) && is_array($locales)) {
      $i = array_search($slug, $slugs, true);
      if ($i !== false && !empty($locales[$i])) {
        return $locales[$i];
      }
    }
  }
  $active = apply_filters('wpml_active_languages', null); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- WPML API hook.
  if (is_array($active) && isset($active[$slug]['default_locale'])) {
    return $active[$slug]['default_locale'];
  }
  return '';
}
endif;

if (!function_exists('kncc_get_default_language_locale')) :
/**
 * Locale of the multilingual default language, or the site locale when no multilingual plugin is active.
 */
function kncc_get_default_language_locale() {
  if (function_exists('pll_default_language')) {
    $slug = pll_default_language('slug');
    $locale = kncc_get_locale_for_slug($slug);
    if ($locale) {
      return $locale;
    }
  }
  $wpml_default = apply_filters('wpml_default_language', ''); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- WPML API hook.
  if ($wpml_default) {
    $locale = kncc_get_locale_for_slug($wpml_default);
    if ($locale) {
      return $locale;
    }
  }
  return get_locale();
}
endif;

if (!function_exists('kncc_plugin_supports_locale')) :
/**
 * Whether the plugin ships a translation for the given locale (or its language code).
 * English is always supported (source strings).
 */
function kncc_plugin_supports_locale($locale) {
  if (!$locale) {
    return false;
  }
  if (strpos($locale, 'en') === 0) {
    return true;
  }

  $candidates = array($locale);
  $lang_only = strtok($locale, '_-');
  if ($lang_only && $lang_only !== $locale) {
    $candidates[] = $lang_only;
  }

  $dirs = array(
    KNCC_PLUGIN_DIR . 'languages/',
    trailingslashit(WP_LANG_DIR) . 'plugins/',
  );

  // Only .mo and .l10n.php are loadable by WordPress. Counting a .po here would make
  // switch_to_locale() silently return English while the caller believed the locale
  // was supported.
  foreach ($candidates as $code) {
    foreach ($dirs as $dir) {
      $base = $dir . 'kansleri-cookie-consent-' . $code;
      if (file_exists($base . '.mo') || file_exists($base . '.l10n.php')) {
        return true;
      }
    }
  }

  // Another variant of the same language (es_MX with the plugin's es_ES): see kncc_language_variant_mofile().
  return $lang_only && kncc_language_file($lang_only) !== '';
}
endif;

if (!function_exists('kncc_language_file')) :
/**
 * The plugin's own translation file (.mo) for a language code ("es" → languages/kansleri-cookie-consent-es_ES.mo),
 * or '' when the plugin has none. The main variant (es_ES, de_DE) is preferred when there are several.
 */
function kncc_language_file($lang) {
  static $found = array();
  if (!isset($found[$lang])) {
    $files = glob(KNCC_PLUGIN_DIR . 'languages/kansleri-cookie-consent-' . $lang . '_*.mo');
    $main = KNCC_PLUGIN_DIR . 'languages/kansleri-cookie-consent-' . $lang . '_' . strtoupper($lang) . '.mo';
    $found[$lang] = in_array($main, (array) $files, true) ? $main : (empty($files) ? '' : $files[0]);
  }
  return $found[$lang];
}
endif;

if (!function_exists('kncc_language_variant_mofile')) :
/**
 * The plugin ships one translation per language (de_DE, es_ES, fr_FR …). WordPress asks for the exact locale, so a
 * site in another variant of the language (de_AT, de_CH, es_MX, fr_CA, nl_BE …) would fall back to English: give it
 * the plugin's translation of that language instead. A translation for the exact locale, such as a language pack
 * from translate.wordpress.org, always wins.
 */
function kncc_language_variant_mofile($mofile, $domain) {
  if ($domain !== 'kansleri-cookie-consent' || file_exists($mofile) || file_exists(substr_replace($mofile, '.l10n.php', -strlen('.mo')))) {
    return $mofile;
  }
  if (!preg_match('/kansleri-cookie-consent-([a-z]{2,3})_[A-Za-z_]+\.mo$/', $mofile, $m)) {
    return $mofile;
  }
  $own = kncc_language_file($m[1]);
  return $own !== '' ? $own : $mofile;
}
endif;
add_filter('load_textdomain_mofile', 'kncc_language_variant_mofile', 10, 2);

if (!function_exists('kncc_get_content_locale')) :
/**
 * Locale that the plugin's own texts for a language are shown in: the language's locale,
 * or en_US when the plugin has no translation for it (e.g. Greek with no .mo).
 *
 * @param string|null $lang Language slug, '' for the default language, null for the current language.
 * @return string
 */
function kncc_get_content_locale($lang = null) {
  if ($lang === null) {
    $lang = kncc_get_current_language();
  }

  $locale = $lang ? kncc_get_locale_for_slug($lang) : kncc_get_default_language_locale();
  // Missing locale mapping or no plugin translation → English source strings.
  if (!$locale || !kncc_plugin_supports_locale($locale)) {
    $locale = 'en_US';
  }
  return $locale;
}
endif;

if (!function_exists('kncc_get_default_settings_for_language')) :
/**
 * Return kncc_get_default_settings() with translatable strings in the target language.
 *
 * Secondary languages never inherit the default language's saved copy — they use this
 * language's plugin defaults. Unsupported locales (e.g. Greek with no .mo) fall back to English.
 */
function kncc_get_default_settings_for_language($lang = null) {
  $target_locale = kncc_get_content_locale($lang);

  if ($target_locale === determine_locale()) {
    return kncc_get_default_settings();
  }

  switch_to_locale($target_locale);
  $defaults = kncc_get_default_settings();
  restore_previous_locale();
  return $defaults;
}
endif;

if (!function_exists('kncc_get_builtin_texts')) :
/**
 * Visitor-facing texts the site owner can replace, with their built-in wording.
 *
 * These settings are stored empty until the owner types their own text, and an empty value
 * shows the built-in text in the visitor's language. Keeping the built-in wording out of the
 * saved settings means a site never gets stuck with the admin's language.
 */
function kncc_get_builtin_texts() {
  $categories = kncc_get_categories();
  $texts = array(
    'policy_link_text'   => __('Cookie Policy', 'kansleri-cookie-consent'),
    'settings_link_text' => __('Cookie settings', 'kansleri-cookie-consent'),
    'banner_label'       => __('Cookie consent', 'kansleri-cookie-consent'),
  );
  foreach ($categories as $key => $data) {
    $texts['category_name_' . $key] = $data['label'];
    $texts['category_desc_' . $key] = $data['description'];
  }
  return $texts + array(
    'empty_note_analytics'           => __('No analytics cookies are used on this site.', 'kansleri-cookie-consent'),
    'empty_note_marketing'           => __('No marketing cookies are used on this site.', 'kansleri-cookie-consent'),
    'empty_note_preferences'         => __('No preference cookies are used on this site.', 'kansleri-cookie-consent'),
    'placeholder_text_analytics'     => __('This content is blocked because it requires analytics cookies.', 'kansleri-cookie-consent'),
    'placeholder_accept_analytics'   => __('Accept analytics cookies', 'kansleri-cookie-consent'),
    'placeholder_text_preferences'   => __('This content is blocked because it requires preference cookies.', 'kansleri-cookie-consent'),
    'placeholder_accept_preferences' => __('Accept preference cookies', 'kansleri-cookie-consent'),
    'policy_desc_necessary'          => __('These cookies are essential for the website to function and cannot be switched off.', 'kansleri-cookie-consent'),
    'policy_desc_analytics'          => __('These cookies help us understand how visitors interact with the website by collecting and reporting information anonymously.', 'kansleri-cookie-consent'),
    'policy_desc_marketing'          => __('These cookies are used to deliver relevant advertisements and track ad campaign performance.', 'kansleri-cookie-consent'),
    'policy_desc_preferences'        => __('These cookies allow the website to remember choices you make and provide enhanced functionality and personalisation.', 'kansleri-cookie-consent'),
    'policy_no_cookies'              => __('No cookies have been registered yet.', 'kansleri-cookie-consent'),
    'policy_status_heading'          => __('Your current cookie preferences', 'kansleri-cookie-consent'),
    'policy_status_note'             => __('These choices are stored technically as wp_consent_functional, wp_consent_statistics, wp_consent_marketing, and wp_consent_preferences for compatibility with WordPress plugins.', 'kansleri-cookie-consent'),
    'policy_edit_button'             => __('Edit cookie preferences', 'kansleri-cookie-consent'),
    'policy_col_cookie'              => __('Cookie', 'kansleri-cookie-consent'),
    'policy_col_provider'            => __('Provider', 'kansleri-cookie-consent'),
    'policy_col_purpose'             => __('Purpose', 'kansleri-cookie-consent'),
    'policy_col_duration'            => __('Duration', 'kansleri-cookie-consent'),
    'status_no_choice'               => __('You have not made a choice yet.', 'kansleri-cookie-consent'),
    'status_allowed'                 => __('Allowed', 'kansleri-cookie-consent'),
    'status_denied'                  => __('Denied', 'kansleri-cookie-consent'),
    'status_always_active'           => __('Always active', 'kansleri-cookie-consent'),
  );
}
endif;

if (!function_exists('kncc_get_builtin_text_keys')) :
/**
 * Keys of kncc_get_builtin_texts(). Cached because they never depend on the language and
 * every settings read needs them (through kncc_get_default_settings()).
 */
function kncc_get_builtin_text_keys() {
  static $keys = null;
  if ($keys === null) {
    $keys = array_keys(kncc_get_builtin_texts());
  }
  return $keys;
}
endif;

if (!function_exists('kncc_get_builtin_texts_for_language')) :
/**
 * kncc_get_builtin_texts() in the given language (the admin shows them as placeholders).
 */
function kncc_get_builtin_texts_for_language($lang = null) {
  $target_locale = kncc_get_content_locale($lang);

  if ($target_locale === determine_locale()) {
    return kncc_get_builtin_texts();
  }

  switch_to_locale($target_locale);
  $texts = kncc_get_builtin_texts();
  restore_previous_locale();
  return $texts;
}
endif;

if (!function_exists('kncc_text')) :
/**
 * A visitor-facing text: the site owner's own wording when set, otherwise the built-in text
 * in the current language. $settings comes from kncc_get_localized_texts() or similar.
 */
function kncc_text($key, $settings) {
  if (isset($settings[$key]) && is_string($settings[$key]) && trim($settings[$key]) !== '') {
    return $settings[$key];
  }

  static $builtin = array();
  $locale = determine_locale();
  if (!isset($builtin[$locale])) {
    $builtin[$locale] = kncc_get_builtin_texts();
  }
  return isset($builtin[$locale][$key]) ? $builtin[$locale][$key] : '';
}
endif;

if (!function_exists('kncc_get_current_language')) :
/**
 * Detect the current language code from WPML or Polylang.
 * Returns empty string if no multilingual plugin is active or if default language.
 */
function kncc_get_current_language() {
  // During AJAX, the client passes the language explicitly because
  // Polylang (and sometimes WPML) does not set the language context.
  // phpcs:ignore WordPress.Security.NonceVerification.Missing -- language hint only, nonce checked by the calling handler.
  if (wp_doing_ajax() && isset($_POST['kncc_lang']) && is_string($_POST['kncc_lang'])) {
    $lang = sanitize_key(wp_unslash($_POST['kncc_lang'])); // phpcs:ignore WordPress.Security.NonceVerification.Missing
    // Polylang's "Show all languages" admin filter surfaces as the slug "all" — treat it as the default language.
    return $lang === 'all' ? '' : $lang;
  }

  // WPML (also triggered by Polylang's WPML compatibility mode, which can surface the slug "all" for the "Show all languages" filter).
  if (defined('ICL_LANGUAGE_CODE')) {
    $default = apply_filters('wpml_default_language', ''); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- WPML API hook.
    if (ICL_LANGUAGE_CODE && ICL_LANGUAGE_CODE !== 'all' && ICL_LANGUAGE_CODE !== $default) {
      return ICL_LANGUAGE_CODE;
    }
    return '';
  }
  // Polylang.
  if (function_exists('pll_current_language') && function_exists('pll_default_language')) {
    $current = pll_current_language('slug');
    $default = pll_default_language('slug');
    if ($current && $current !== 'all' && $current !== $default) {
      return $current;
    }
  }
  return '';
}
endif;

if (!function_exists('kncc_get_language_slugs')) :
/**
 * Return registered language slugs from WPML or Polylang.
 */
function kncc_get_language_slugs() {
  if (function_exists('pll_languages_list')) {
    $slugs = pll_languages_list(array('fields' => 'slug'));
    return is_array($slugs) ? $slugs : array();
  }
  $active = apply_filters('wpml_active_languages', null); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- WPML API hook.
  if (is_array($active)) {
    return array_keys($active);
  }
  return array();
}
endif;

if (!function_exists('kncc_translate_text_via_wpml')) :
/**
 * Translate a kncc_settings text field via WPML String Translation (admin_texts).
 *
 * Returns the translated string when WPML has a real translation for $lang,
 * otherwise an empty string so callers can fall through to language defaults.
 *
 * @param string $name  Option field key (e.g. heading_text).
 * @param string $value Default-language source string from kncc_settings.
 * @param string $lang  Target language slug.
 * @return string
 */
function kncc_translate_text_via_wpml($name, $value, $lang) {
  if ($value === '' || $lang === '' || !has_filter('wpml_translate_single_string')) {
    return '';
  }

  $translated = apply_filters(
    'wpml_translate_single_string', // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- WPML API hook.
    $value,
    'admin_texts_kncc_settings',
    '[kncc_settings]' . $name,
    $lang
  );

  if (!is_string($translated) || $translated === '') {
    return '';
  }

  // Same as source → no ST translation registered for this language.
  if ($translated === $value) {
    return '';
  }

  return $translated;
}
endif;

if (!function_exists('kncc_get_localized_settings')) :
/**
 * Get settings for the current language.
 *
 * Non-text settings (colors, style, policy page, …) inherit from the default language.
 * Banner/iframe text fields use, in order:
 * 1) kncc_texts_{lang} overrides
 * 2) WPML String Translation of kncc_settings (when present)
 * 3) plugin defaults for that language (English when unsupported)
 */
function kncc_get_localized_settings() {
  $settings = kncc_get_settings();
  $lang = kncc_get_current_language();
  if (!$lang) {
    return $settings;
  }

  $overrides = get_option('kncc_texts_' . $lang, array());
  if (!is_array($overrides)) {
    $overrides = array();
  }

  // A policy page of 0 is what the Policy tab posts when its page list (filtered to this
  // language) cannot show the default-language page. It means "not chosen here": inherit the
  // base page, whose translation the banner links, instead of dropping the policy link.
  if (empty($overrides['policy_page_id'])) {
    unset($overrides['policy_page_id']);
  }

  $merged = array_merge($settings, $overrides);
  $lang_defaults = kncc_get_default_settings_for_language($lang);

  foreach (kncc_get_text_field_keys() as $field) {
    if (isset($overrides[$field]) && $overrides[$field] !== '') {
      $merged[$field] = $overrides[$field];
      continue;
    }

    $source = isset($settings[$field]) ? (string) $settings[$field] : '';
    $wpml_text = kncc_translate_text_via_wpml($field, $source, $lang);
    if ($wpml_text !== '') {
      $merged[$field] = $wpml_text;
      continue;
    }

    if (!empty($lang_defaults[$field])) {
      $merged[$field] = $lang_defaults[$field];
    } else {
      $merged[$field] = '';
    }
  }

  return $merged;
}
endif;

if (!function_exists('kncc_get_localized_texts')) :
/**
 * Display-ready settings for the frontend banner: empty text fields are filled with
 * this language's plugin defaults (English if unsupported), never the default language's copy.
 */
function kncc_get_localized_texts() {
  $settings = kncc_get_localized_settings();
  $lang = kncc_get_current_language();
  $defaults = null;
  foreach (kncc_get_text_field_keys() as $field) {
    if (!isset($settings[$field]) || $settings[$field] === '') {
      if ($defaults === null) {
        $defaults = kncc_get_default_settings_for_language($lang ? $lang : null);
      }
      if (!empty($defaults[$field])) {
        $settings[$field] = $defaults[$field];
      }
    }
  }
  return $settings;
}
endif;

if (!function_exists('kncc_get_localized_cookies')) :
/**
 * Get cookies with per-language descriptions merged in.
 * Cookie names, categories, providers, and durations are always shared.
 * Only descriptions can differ per language. Built-in descriptions and durations
 * are shown in the current language (see kncc_translate_cookie_texts()).
 */
function kncc_get_localized_cookies() {
  $cookies = kncc_get_cookies();
  $lang = kncc_get_current_language();
  $desc_map = $lang ? get_option('kncc_cookie_descriptions_' . $lang, array()) : array();
  if (!empty($desc_map)) {
    foreach ($cookies as &$cookie) {
      if (isset($desc_map[$cookie['name']])) {
        $cookie['description'] = $desc_map[$cookie['name']];
      }
    }
    unset($cookie);
  }
  return kncc_translate_cookie_texts($cookies, $lang);
}
endif;

if (!function_exists('kncc_get_cookie_text_translations')) :
/**
 * Built-in cookie descriptions and durations, as English source => text in the given language.
 *
 * @param string|null $lang Language slug, '' for the default language, null for the current language.
 * @return array<string, string>
 */
function kncc_get_cookie_text_translations($lang = null) {
  $locale = kncc_get_content_locale($lang);
  if ($locale === 'en_US') {
    // English is the source language, so skip the locale switch.
    $sources = array_keys(KNCC_Scanner::get_cookie_texts());
    return array_combine($sources, $sources);
  }

  // On admin requests __() follows the admin's profile language; translate in the language's locale.
  $switched = $locale !== determine_locale() && switch_to_locale($locale);
  $texts = KNCC_Scanner::get_cookie_texts();
  if ($switched) {
    restore_previous_locale();
  }
  return $texts;
}
endif;

if (!function_exists('kncc_translate_cookie_texts')) :
/**
 * Show built-in cookie descriptions and durations in the given language.
 *
 * The scanner and service templates store them in English. Only values that still equal
 * an English default are translated, so anything the admin wrote is shown as written.
 *
 * @param array       $cookies Cookie rows.
 * @param string|null $lang    Language slug, '' for the default language, null for the current language.
 * @return array
 */
function kncc_translate_cookie_texts($cookies, $lang = null) {
  if (empty($cookies) || !is_array($cookies)) {
    return $cookies;
  }

  $texts = kncc_get_cookie_text_translations($lang);
  foreach ($cookies as &$cookie) {
    foreach (array('description', 'duration') as $field) {
      if (isset($cookie[$field]) && is_string($cookie[$field]) && isset($texts[$cookie[$field]])) {
        $cookie[$field] = $texts[$cookie[$field]];
      }
    }
  }
  unset($cookie);
  return $cookies;
}
endif;

if (!function_exists('kncc_untranslate_cookie_texts')) :
/**
 * Reverse of kncc_translate_cookie_texts(), used before saving the Cookies tab: built-in
 * descriptions and durations shown in the given language are stored as their English source,
 * so they keep following the language they are shown in. Durations are shared by all
 * languages, so a stored translation would show up on every language.
 *
 * @param array       $cookies Cookie rows.
 * @param string|null $lang    Language slug, '' for the default language, null for the current language.
 * @return array
 */
function kncc_untranslate_cookie_texts($cookies, $lang = null) {
  if (empty($cookies) || !is_array($cookies)) {
    return $cookies;
  }

  $sources = array();
  foreach (kncc_get_cookie_text_translations($lang) as $source => $translated) {
    if ($translated !== $source) {
      $sources[$translated] = $source;
    }
  }
  if (empty($sources)) {
    return $cookies;
  }

  foreach ($cookies as &$cookie) {
    foreach (array('description', 'duration') as $field) {
      if (isset($cookie[$field]) && is_string($cookie[$field]) && isset($sources[$cookie[$field]])) {
        $cookie[$field] = $sources[$cookie[$field]];
      }
    }
  }
  unset($cookie);
  return $cookies;
}
endif;

if (!function_exists('kncc_get_cookies')) :
function kncc_get_cookies() {
  return get_option('kncc_cookies', array());
}
endif;

if (!function_exists('kncc_regex_to_clear_pattern')) :
/**
 * Convert a scanner regex (e.g. '/^_ga_/') to a frontend clear pattern ('_ga_*' or exact name).
 */
function kncc_regex_to_clear_pattern($regex) {
  if (!is_string($regex) || !preg_match('#^/(.+)/$#', $regex, $matches)) {
    return null;
  }

  $body = $matches[1];
  // A trailing "$" anchors the name, unless it was escaped as "\$".
  $exact = (strlen($body) > 1 && $body[strlen($body) - 1] === '$' && $body[strlen($body) - 2] !== '\\');
  if ($exact) {
    $body = substr($body, 0, -1);
  }
  if (strlen($body) > 0 && $body[0] === '^') {
    $body = substr($body, 1);
  }

  $body = str_replace('\\', '', $body);
  if ($body === '') {
    return null;
  }

  return $exact ? $body : $body . '*';
}
endif;

if (!function_exists('kncc_get_cookie_clearing_patterns')) :
/**
 * Cookie name patterns grouped by consent category for frontend removal when a category is denied.
 * Merges registered cookies and built-in scanner patterns. Patterns ending in * match by prefix.
 */
function kncc_get_cookie_clearing_patterns() {
  $categories = array('analytics', 'marketing', 'preferences');
  $patterns = array_fill_keys($categories, array());

  foreach (kncc_get_cookies() as $cookie) {
    if (!is_array($cookie)) {
      continue;
    }
    $cat = isset($cookie['category']) ? $cookie['category'] : '';
    $name = isset($cookie['name']) ? trim($cookie['name']) : '';
    if ($name === '' || $cat === 'necessary' || !isset($patterns[$cat])) {
      continue;
    }
    $patterns[$cat][] = $name;
  }

  foreach (KNCC_Scanner::get_known_cookies() as $entry) {
    $cat = isset($entry['category']) ? $entry['category'] : '';
    if ($cat === 'necessary' || !isset($patterns[$cat])) {
      continue;
    }
    $converted = kncc_regex_to_clear_pattern($entry['pattern']);
    if ($converted) {
      $patterns[$cat][] = $converted;
    }
  }

  foreach ($patterns as $cat => $list) {
    $patterns[$cat] = array_values(array_unique($list));
  }

  return $patterns;
}
endif;

if (!function_exists('kncc_get_categories_in_use')) :
/**
 * Categories that have at least one registered cookie. "necessary" is always in use
 * because the plugin's own consent cookie is necessary.
 *
 * Returns an associative array: ['necessary' => true, 'analytics' => true, ...] for fast lookup.
 */
function kncc_get_categories_in_use() {
  $in_use = array('necessary' => true);
  foreach (kncc_get_cookies() as $cookie) {
    $cat = isset($cookie['category']) ? $cookie['category'] : '';
    if ($cat) {
      $in_use[$cat] = true;
    }
  }
  // A category that holds back a script by address must be selectable in the banner,
  // even when none of its cookies are registered yet.
  if (class_exists('KNCC_Script_Blocker')) {
    foreach (KNCC_Script_Blocker::get_url_rules() as $rule) {
      $in_use[$rule['category']] = true;
    }
    // Likewise a category in which a script was recently held back: the cookie list may not
    // have been scanned yet, and without this the visitor could not switch that category on.
    foreach (KNCC_Script_Blocker::get_recently_blocked_categories() as $category) {
      $in_use[$category] = true;
    }
  }
  return $in_use;
}
endif;

if (!function_exists('kncc_get_categories')) :
function kncc_get_categories() {
  return array(
    'necessary'   => array(
      'label'       => __('Necessary', 'kansleri-cookie-consent'),
      'description' => __('Required for the website to function. Cannot be disabled.', 'kansleri-cookie-consent'),
    ),
    'analytics'   => array(
      'label'       => __('Analytics', 'kansleri-cookie-consent'),
      'description' => __('Help us understand how visitors interact with the website.', 'kansleri-cookie-consent'),
    ),
    'marketing'   => array(
      'label'       => __('Marketing', 'kansleri-cookie-consent'),
      'description' => __('Used to deliver relevant advertisements and track campaigns.', 'kansleri-cookie-consent'),
    ),
    'preferences' => array(
      'label'       => __('Preferences', 'kansleri-cookie-consent'),
      'description' => __('Remember your settings and preferences for a better experience.', 'kansleri-cookie-consent'),
    ),
  );
}
endif;

if (!function_exists('kncc_activate')) :
function kncc_activate() {
  $settings = kncc_get_settings();
  if (empty($settings) || !get_option('kncc_settings')) {
    // Activation runs in the admin's profile language; store the texts in the site's language.
    update_option('kncc_settings', kncc_get_default_settings_for_language(''), false);
  }
  if (!get_option('kncc_cookies')) {
    update_option('kncc_cookies', array(), false);
  }
  KNCC_Policy_Page::maybe_create_page();
  KNCC_Consent_Log::create_table();
  update_option('kncc_db_version', KNCC_DB_VERSION, false);
  update_option('kncc_show_activation_notice', 1, false);
  delete_option('kncc_cron_error');
  if (!wp_next_scheduled('kncc_daily_cookie_check')) {
    wp_schedule_event(time(), 'daily', 'kncc_daily_cookie_check');
  }
}
endif;
register_activation_hook(__FILE__, 'kncc_activate');

if (!function_exists('kncc_deactivate')) :
function kncc_deactivate() {
  wp_clear_scheduled_hook('kncc_daily_cookie_check');
}
endif;
register_deactivation_hook(__FILE__, 'kncc_deactivate');

if (!function_exists('kncc_maybe_localize_defaults')) :
/**
 * Migration: if the saved banner texts are still the English defaults and the site's language is not English,
 * update them to the translated defaults. Version 2 runs it once more on every site, because 1.8.12 added German,
 * French, Spanish, Italian and Dutch: a site in one of them kept the English texts it got at activation.
 */
function kncc_maybe_localize_defaults() {
  if ((int) get_option('kncc_defaults_localized') >= 2) {
    return;
  }
  // Only in wp-admin and WP-CLI: on the front end a language plugin (TranslatePress) switches the locale to the
  // language of the page, and the saved texts would be translated into that language.
  if (!(is_admin() && !wp_doing_ajax()) && !(defined('WP_CLI') && WP_CLI)) {
    return;
  }

  // The language of the saved texts: the default language of a Polylang or WPML site.
  $locale = kncc_get_default_language_locale();
  if (strpos($locale, 'en') === 0) {
    update_option('kncc_defaults_localized', 2, false);
    return;
  }

  $saved = get_option('kncc_settings', array());
  if (empty($saved)) {
    update_option('kncc_defaults_localized', 2, false);
    return;
  }

  // On admin requests __() follows the admin's profile language; translate in the site locale. When WordPress cannot
  // switch to it (its core language files are missing), try again on a later request.
  $switched = switch_to_locale($locale);
  if (!$switched && determine_locale() !== $locale) {
    return;
  }

  $translations = array(
    'heading_text'     => array( 'We use cookies', __( 'We use cookies', 'kansleri-cookie-consent' ) ),
    'description_text' => array( 'We use cookies to improve your experience and analyze site traffic. Choose which cookies you allow.', __( 'We use cookies to improve your experience and analyze site traffic. Choose which cookies you allow.', 'kansleri-cookie-consent' ) ),
    'accept_text'      => array( 'Accept all', __( 'Accept all', 'kansleri-cookie-consent' ) ),
    'reject_text'      => array( 'Reject all', __( 'Reject all', 'kansleri-cookie-consent' ) ),
    'customize_text'   => array( 'Customize', __( 'Customize', 'kansleri-cookie-consent' ) ),
    'save_text'        => array( 'Save preferences', __( 'Save preferences', 'kansleri-cookie-consent' ) ),
    'close_text'       => array( 'Close', __( 'Close', 'kansleri-cookie-consent' ) ),
    'iframe_placeholder_text' => array( 'This content is blocked because it requires marketing cookies.', __( 'This content is blocked because it requires marketing cookies.', 'kansleri-cookie-consent' ) ),
    'iframe_accept_text'      => array( 'Accept marketing cookies', __( 'Accept marketing cookies', 'kansleri-cookie-consent' ) ),
  );

  if ($switched) {
    restore_previous_locale();
  }

  $changed = false;
  foreach ( $translations as $key => $pair ) {
    $english    = $pair[0];
    $translated = $pair[1];
    if ( isset( $saved[ $key ] ) && $saved[ $key ] === $english && $translated !== $english ) {
      $saved[ $key ] = $translated;
      $changed = true;
    }
  }

  if ($changed) {
    update_option('kncc_settings', $saved, false);
  }
  update_option('kncc_defaults_localized', 2, false);
}
endif;
// Hooked on init (not plugins_loaded) because the migration map below contains
// inline __() calls — running these before WordPress's `init` action trips the
// "translation loading triggered too early" notice introduced in WP 6.7.
add_action('init', 'kncc_maybe_localize_defaults', 5);

if (!function_exists('kncc_migrate_floating_btn_default')) :
/**
 * One-time migration: enable floating button for existing installs
 * that never explicitly set the option.
 */
function kncc_migrate_floating_btn_default() {
  if (get_option('kncc_floating_btn_migrated')) {
    return;
  }
  $saved = get_option('kncc_settings', array());
  if (!empty($saved) && !isset($saved['show_floating_btn'])) {
    $saved['show_floating_btn'] = true;
    update_option('kncc_settings', $saved, false);
  }
  update_option('kncc_floating_btn_migrated', 1, false);
}
endif;
add_action('plugins_loaded', 'kncc_migrate_floating_btn_default', 21);

if (!function_exists('kncc_migrate_block_html_scripts_default')) :
/**
 * One-time migration: enable HTML script blocking for existing installs
 * that never explicitly set the option.
 */
function kncc_migrate_block_html_scripts_default() {
  if (get_option('kncc_block_html_scripts_migrated')) {
    return;
  }
  $saved = get_option('kncc_settings', array());
  if (!empty($saved) && !isset($saved['block_html_scripts'])) {
    $saved['block_html_scripts'] = true;
    update_option('kncc_settings', $saved, false);
  }
  update_option('kncc_block_html_scripts_migrated', 1, false);
}
endif;
add_action('plugins_loaded', 'kncc_migrate_block_html_scripts_default', 21);

if (!function_exists('kncc_migrate_guard_detection')) :
/**
 * One-time migration: 1.8.10 cached homepage HTML that still held the injection guard's
 * tracker patterns and added cookies for services the site does not use. Drop that cache
 * and let the next cookie sync remove those cookies.
 */
function kncc_migrate_guard_detection() {
  if (get_option('kncc_guard_cleanup')) {
    return;
  }
  delete_transient('kncc_frontend_html');
  update_option('kncc_guard_cleanup', 'pending', false);
}
endif;
add_action('plugins_loaded', 'kncc_migrate_guard_detection', 21);

if (!function_exists('kncc_maybe_create_consent_log_table')) :
/**
 * Create or upgrade the consent log table whenever the stored schema version
 * is behind KNCC_DB_VERSION.
 */
function kncc_maybe_create_consent_log_table() {
  $db_version = get_option('kncc_db_version', '0');
  if (version_compare($db_version, KNCC_DB_VERSION, '<')) {
    KNCC_Consent_Log::create_table();
    update_option('kncc_db_version', KNCC_DB_VERSION, false);
  }
}
endif;
add_action('plugins_loaded', 'kncc_maybe_create_consent_log_table', 22);

if (!function_exists('kncc_get_text_field_keys')) :
/**
 * Return the setting keys that are translatable text fields.
 */
function kncc_get_text_field_keys() {
  return array_merge(array(
    'heading_text',
    'description_text',
    'accept_text',
    'reject_text',
    'customize_text',
    'save_text',
    'close_text',
    'iframe_placeholder_text',
    'iframe_accept_text',
  ), kncc_get_builtin_text_keys());
}
endif;

if (!function_exists('kncc_init')) :
function kncc_init() {
  new KNCC_Admin();
  new KNCC_Scanner();
  new KNCC_Consent();
  new KNCC_Policy_Page();
  new KNCC_Consent_Mode();
  new KNCC_WP_Consent_API();
  new KNCC_Stats();
  new KNCC_Script_Blocker();
  new KNCC_Consent_Log();
  new KNCC_WP_Rocket();
  new KNCC_Polylang();
}
endif;
add_action('plugins_loaded', 'kncc_init');

if (!function_exists('kncc_plugin_action_links')) :
function kncc_plugin_action_links($links) {
  $url = admin_url('options-general.php?page=kansleri-cookie-consent');
  $settings_link = '<a href="' . esc_url($url) . '">' . esc_html__('Settings', 'kansleri-cookie-consent') . '</a>';
  array_unshift($links, $settings_link);
  return $links;
}
endif;
add_filter('plugin_action_links_' . KNCC_BASENAME, 'kncc_plugin_action_links');

if (!function_exists('kncc_render_activation_notice')) :
function kncc_render_activation_notice() {
  if (!is_admin() || !current_user_can('manage_options')) {
    return;
  }
  if (!get_option('kncc_show_activation_notice')) {
    return;
  }

  delete_option('kncc_show_activation_notice');

  $cookies_url = admin_url('options-general.php?page=kansleri-cookie-consent&tab=cookies');
  $general_url = admin_url('options-general.php?page=kansleri-cookie-consent&tab=general');

  $message = sprintf(
    /* translators: 1: Cookies tab link, 2: General tab link */
    __('Kansleri Cookie Consent is now active. Next steps: %1$s to discover cookies and %2$s to review banner and consent settings.', 'kansleri-cookie-consent'),
    '<a href="' . esc_url($cookies_url) . '">' . esc_html__('Scan cookies', 'kansleri-cookie-consent') . '</a>',
    '<a href="' . esc_url($general_url) . '">' . esc_html__('review settings', 'kansleri-cookie-consent') . '</a>'
  );
  ?>
  <div class="notice notice-success is-dismissible">
    <p><?php echo wp_kses($message, array('a' => array('href' => array()))); ?></p>
  </div>
  <?php
}
endif;
add_action('admin_notices', 'kncc_render_activation_notice');

if (!function_exists('kncc_render_incomplete_cookies_notice')) :
function kncc_render_incomplete_cookies_notice() {
  if (!is_admin() || !current_user_can('manage_options')) {
    return;
  }

  $incomplete = get_option('kncc_incomplete_cookies');
  if (empty($incomplete) || !is_array($incomplete)) {
    return;
  }

  $cookies_url = admin_url('options-general.php?page=kansleri-cookie-consent&tab=cookies');
  $count = count($incomplete);
  $names = implode(', ', array_slice($incomplete, 0, 5));
  if ($count > 5) {
    $names .= sprintf(' (+%d more)', $count - 5);
  }

  $message = sprintf(
    /* translators: 1: count, 2: cookie names, 3: cookies tab link */
    __('Cookie Consent: %1$d cookie(s) are missing descriptions: %2$s. %3$s to add them.', 'kansleri-cookie-consent'),
    $count,
    '<code>' . esc_html($names) . '</code>',
    '<a href="' . esc_url($cookies_url) . '">' . esc_html__('Go to Cookies tab', 'kansleri-cookie-consent') . '</a>'
  );
  ?>
  <div class="notice notice-warning is-dismissible">
    <p><?php echo wp_kses($message, array('a' => array('href' => array()), 'code' => array())); ?></p>
  </div>
  <?php
}
endif;
add_action('admin_notices', 'kncc_render_incomplete_cookies_notice');

if (!function_exists('kncc_privacy_policy_content')) :
function kncc_privacy_policy_content() {
  $content = '<h2>' . __('Cookies', 'kansleri-cookie-consent') . '</h2>' .
    '<p>' . __('This site uses a cookie consent banner powered by Kansleri Cookie Consent. When you interact with the banner, a cookie named <code>kncc_consent</code> is stored in your browser for up to 1 year. It records your consent preferences (which cookie categories you accepted or rejected) so the site can remember your choice on future visits. No personal data is stored in this cookie.', 'kansleri-cookie-consent') . '</p>' .
    '<p>' . __('The plugin also records anonymous, aggregated consent statistics (how many visitors accepted, rejected, or customized cookies each day) and individual consent log entries for GDPR audit compliance. Consent log entries contain a hashed (non-reversible) visitor identifier, the consent choice, which categories were accepted, and the policy version. No IP addresses, user identifiers, or personal data are stored in plain text. Consent log records are automatically deleted after 13 months.', 'kansleri-cookie-consent') . '</p>';

  wp_add_privacy_policy_content(
    'Kansleri Cookie Consent',
    wp_kses_post($content)
  );
}
endif;
add_action('admin_init', 'kncc_privacy_policy_content');

if (!function_exists('kncc_cookie_settings_shortcode')) :
/**
 * Shortcode [kncc_cookie_settings] — renders a link that opens the consent banner.
 * Usage: [kncc_cookie_settings] or [kncc_cookie_settings text="Manage cookies"]
 */
function kncc_cookie_settings_shortcode($atts) {
  $atts = shortcode_atts(array(
    'text' => kncc_text('settings_link_text', kncc_get_localized_texts()),
  ), $atts, 'kncc_cookie_settings');

  // consent.js binds delegated click and Space key handlers to .kncc-open-settings. The link opens the
  // banner, not a page, so assistive technology announces it as a button.
  return '<a href="#" class="kncc-open-settings" role="button">'
    . esc_html($atts['text'])
    . '</a>';
}
endif;
add_shortcode('kncc_cookie_settings', 'kncc_cookie_settings_shortcode');
