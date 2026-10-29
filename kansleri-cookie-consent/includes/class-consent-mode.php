<?php

if (!defined('ABSPATH')) {
  exit;
}

class KNCC_Consent_Mode {

  public function __construct() {
    // Register the wp_head hooks unconditionally — the enabled check happens
    // inside the callbacks. Doing it here would call kncc_get_settings()
    // → kncc_get_default_settings() → __() at plugins_loaded time, which trips
    // WordPress 6.7+ "translation loading triggered too early" notice.
    add_action('wp_head', array($this, 'output_defaults'), 1);
    // Site Kit prints its consent default on wp_head priority 1 too, but adds that hook after
    // this plugin has added its own. Google requires every default before the first update,
    // so with Site Kit's consent mode on, the stored choice is restored one priority later.
    add_action('wp_head', array($this, 'output_stored_consent_after_site_kit'), 2);
  }

  /**
   * Whether Google Site Kit is active and managing consent mode,
   * meaning it prints its own (regional) consent default.
   */
  public static function site_kit_handles_consent() {
    if (!defined('GOOGLESITEKIT_VERSION')) {
      return false;
    }
    $sitekit_consent = get_option('googlesitekit_consent_mode', array());
    if (!empty($sitekit_consent['enabled'])) {
      return true;
    }
    // Site Kit always registers this filter and returns 'enabled' or 'disabled' (its default),
    // so anything but 'enabled' means it is not printing consent defaults.
    return apply_filters('googlesitekit_consent_mode_status', 'disabled') === 'enabled'; // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- Google Site Kit API hook.
  }

  /**
   * Whether this plugin sends Consent Mode signals. The Integration tab shows the setting as
   * forced on while Site Kit is active, so honour that even when the stored value is still
   * false (e.g. after the pre-1.8.3 bug where saving the General tab switched it off).
   */
  public static function is_enabled($settings) {
    return !empty($settings['consent_mode']) || defined('GOOGLESITEKIT_VERSION');
  }

  /**
   * Whether Google tags load before consent (Advanced Consent Mode). Never without Consent
   * Mode itself: with no denied default in place, an unblocked Google tag would set its
   * cookies before the visitor has chosen anything.
   */
  public static function is_advanced($settings) {
    return self::is_enabled($settings) && !empty($settings['consent_mode_advanced']);
  }

  /**
   * Outputs Consent Mode v2 defaults before any other scripts (GTM, analytics, etc.)
   * and restores previously saved consent if the cookie exists.
   *
   * The default has no region, so it covers every visitor. Site Kit's own default only covers
   * its region list; there its regional default takes precedence, and everywhere else this one
   * keeps Google tags from setting cookies before consent once Advanced Consent Mode loads them.
   *
   * The script ids start with kncc-consent-mode, which matches the WP Rocket Delay JS
   * exclusion, so the consent state is in place before any Google tag runs.
   */
  public function output_defaults() {
    if (is_admin()) {
      return;
    }

    if (!self::is_enabled(kncc_get_settings())) {
      return;
    }

    $js = "window.dataLayer=window.dataLayer||[];function gtag(){dataLayer.push(arguments);}"
      . "gtag('consent','default',{"
      . "ad_storage:'denied',"
      . "ad_user_data:'denied',"
      . "ad_personalization:'denied',"
      . "analytics_storage:'denied',"
      . "functionality_storage:'denied',"
      . "personalization_storage:'denied',"
      . "security_storage:'granted',"
      . "wait_for_update:500"
      . "});";

    if (!self::site_kit_handles_consent()) {
      $js .= self::get_stored_consent_js();
    }

    wp_print_inline_script_tag($js, array('id' => 'kncc-consent-mode'));
  }

  /**
   * With Site Kit's consent mode on, restores the visitor's saved consent after Site Kit's
   * consent default instead of before it (see the constructor).
   */
  public function output_stored_consent_after_site_kit() {
    if (is_admin() || !self::site_kit_handles_consent()) {
      return;
    }

    wp_print_inline_script_tag(self::get_stored_consent_js(), array('id' => 'kncc-consent-mode-update'));
  }

  /**
   * Inline JS that sends a consent update for the choice saved in the kncc_consent cookie.
   * It reads the cookie in the browser, so the markup stays identical for every visitor and
   * is safe to store in a page cache.
   */
  private static function get_stored_consent_js() {
    return "(function(){"
      . "var m=document.cookie.match(/(?:^|; )kncc_consent=([^;]*)/);"
      . "if(!m)return;"
      . "window.dataLayer=window.dataLayer||[];function gtag(){dataLayer.push(arguments);}"
      . "try{var c=JSON.parse(decodeURIComponent(m[1]));"
      . "gtag('consent','update',{"
      . "analytics_storage:c.analytics?'granted':'denied',"
      . "ad_storage:c.marketing?'granted':'denied',"
      . "ad_user_data:c.marketing?'granted':'denied',"
      . "ad_personalization:c.marketing?'granted':'denied',"
      . "functionality_storage:c.preferences?'granted':'denied',"
      . "personalization_storage:c.preferences?'granted':'denied',"
      . "security_storage:'granted'"
      . "});"
      . "}catch(e){}"
      . "})();";
  }
}
