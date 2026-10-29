<?php

if (!defined('ABSPATH')) {
  exit;
}

class KNCC_WP_Consent_API {

  public function __construct() {
    add_filter('wp_consent_api_registered_' . KNCC_BASENAME, '__return_true');
    add_filter('wp_get_consent_type', array($this, 'declare_consent_type'));
    add_action('init', array($this, 'sync_from_kncc_consent'), 20);
    add_action('wp_head', array($this, 'output_early_consent_sync'), 2);
  }

  /**
   * Declare ourselves as the active opt-in consent manager so WP Consent API
   * sets window.wp_fallback_consent_type='optin'. Without this, integrators
   * like Google Site Kit's Consent Mode short-circuit and never update gtag.
   * Yields to any other plugin that already filtered the type.
   */
  public function declare_consent_type($type) {
    return $type ? $type : 'optin';
  }

  /**
   * Whether the WP Consent API plugin is active and functional.
   */
  public static function is_active() {
    return function_exists('wp_has_consent');
  }

  /**
   * Decode a raw kncc_consent cookie value into an array.
   *
   * The frontend stores the cookie URL-encoded (encodeURIComponent), so a plain
   * json_decode() on the raw PHP cookie value fails. Try the raw value first,
   * then progressively URL-decode so the server sync never silently skips.
   *
   * @param string $raw Raw cookie value from $_COOKIE.
   * @return array|null Decoded consent array, or null if it cannot be parsed.
   */
  public static function parse_kncc_consent_cookie($raw) {
    if (!is_string($raw) || $raw === '') {
      return null;
    }

    $candidates = array(
      $raw,
      rawurldecode($raw),
      urldecode($raw),
    );

    foreach ($candidates as $candidate) {
      $decoded = json_decode($candidate, true);
      if (is_array($decoded)) {
        return $decoded;
      }
    }

    return null;
  }

  /**
   * Map kncc_consent category flags to WP Consent API allow/deny values.
   *
   * Honors the stored choice (accept/reject/custom) so a legacy cookie that is
   * missing an individual category key cannot leave a category stuck on deny.
   *
   * @param array $consent Decoded kncc_consent cookie.
   * @return array<string, string>
   */
  public static function get_wp_consent_values($consent) {
    if (!is_array($consent)) {
      return array();
    }

    $choice = isset($consent['choice']) ? $consent['choice'] : '';

    if ($choice === 'accept') {
      $analytics = true;
      $marketing = true;
      $preferences = true;
    } elseif ($choice === 'reject') {
      $analytics = false;
      $marketing = false;
      $preferences = false;
    } else {
      $analytics = !empty($consent['analytics']);
      $marketing = !empty($consent['marketing']);
      $preferences = !empty($consent['preferences']);
    }

    return array(
      'functional'            => 'allow',
      'statistics'            => $analytics ? 'allow' : 'deny',
      'statistics-anonymous'  => $analytics ? 'allow' : 'deny',
      'marketing'             => $marketing ? 'allow' : 'deny',
      'preferences'           => $preferences ? 'allow' : 'deny',
    );
  }

  /**
   * Sync WP Consent API cookies from the kncc_consent cookie on each request.
   * Server-side sync avoids JS timing races with Google Site Kit and Delay JS.
   */
  public function sync_from_kncc_consent() {
    if (is_admin() || wp_doing_ajax() || wp_doing_cron() || !self::is_active()) {
      return;
    }

    if (empty($_COOKIE['kncc_consent'])) {
      return;
    }

    $raw = wp_unslash($_COOKIE['kncc_consent']); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- JSON-decoded and validated below.
    $consent = self::parse_kncc_consent_cookie($raw);
    if (!is_array($consent)) {
      return;
    }

    foreach (self::get_wp_consent_values($consent) as $category => $value) {
      wp_set_consent($category, $value);
    }
  }

  /**
   * Early inline sync in wp_head so wp_consent cookies exist before footer scripts.
   */
  public function output_early_consent_sync() {
    if (is_admin() || !self::is_active()) {
      return;
    }

    $js = "(function(){"
      . "var m=document.cookie.match(/(?:^|; )kncc_consent=([^;]*)/);"
      . "if(!m)return;"
      . "try{"
        . "var c=JSON.parse(decodeURIComponent(m[1]));"
        . "var a=c.choice==='accept'?true:(c.choice==='reject'?false:!!c.analytics);"
        . "var mk=c.choice==='accept'?true:(c.choice==='reject'?false:!!c.marketing);"
        . "var pr=c.choice==='accept'?true:(c.choice==='reject'?false:!!c.preferences);"
        . "var days=(window.consent_api&&consent_api.cookie_expiration)||30;"
        . "var exp=';expires='+new Date(Date.now()+days*864e5).toUTCString();"
        . "var sec=location.protocol==='https:'?';secure':'';"
        . "var p=(window.consent_api&&consent_api.cookie_prefix)||'wp_consent';"
        . "function sc(cat,val){document.cookie=p+'_'+cat+'='+val+sec+exp+';path=/';}"
        . "sc('functional','allow');"
        . "sc('statistics',a?'allow':'deny');"
        . "sc('statistics-anonymous',a?'allow':'deny');"
        . "sc('marketing',mk?'allow':'deny');"
        . "sc('preferences',pr?'allow':'deny');"
      . "}catch(e){}"
      . "})();";

    // The id matches the WP Rocket Delay JS exclusion ('kncc-consent'), so the sync is not delayed.
    wp_print_inline_script_tag($js, array('id' => 'kncc-consent-wp-sync'));
  }
}
