<?php

if (!defined('ABSPATH')) {
  exit;
}

/**
 * Polylang remembers the visitor's language in the pll_language cookie and sets it from PHP
 * on the first page view, before the banner has even shown. The cookie is listed as
 * necessary by default. When the site owner moves it to another category, Polylang may set
 * it only after the visitor allows that category.
 */
class KNCC_Polylang {

  public function __construct() {
    add_filter('pll_cookie_expiration', array($this, 'filter_cookie_expiration'));
    add_filter('wp_inline_script_attributes', array($this, 'hold_cookie_script'));
  }

  /**
   * The category saved for pll_language, or 'necessary' when the cookie is not listed.
   */
  private static function cookie_category() {
    foreach (kncc_get_cookies() as $cookie) {
      if (isset($cookie['name']) && $cookie['name'] === 'pll_language') {
        return !empty($cookie['category']) ? $cookie['category'] : 'necessary';
      }
    }
    return 'necessary';
  }

  /**
   * Whether the visitor's saved choice allows a category, as isCategoryGranted() in consent.js.
   */
  private static function visitor_allows($category) {
    if (empty($_COOKIE['kncc_consent'])) {
      return false;
    }
    $raw = wp_unslash($_COOKIE['kncc_consent']); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- JSON-decoded and validated below.
    $consent = KNCC_WP_Consent_API::parse_kncc_consent_cookie($raw);
    if (!is_array($consent)) {
      return false;
    }
    $choice = isset($consent['choice']) ? $consent['choice'] : '';
    if ($choice === 'accept') {
      return true;
    }
    if ($choice === 'reject') {
      return false;
    }
    return !empty($consent[$category]);
  }

  /**
   * Polylang removes its cookie instead of setting it when the duration is negative.
   */
  public function filter_cookie_expiration($expiration) {
    $category = self::cookie_category();
    if ($category === 'necessary' || self::visitor_allows($category)) {
      return $expiration;
    }
    // With a page cache Polylang sets the cookie from a script that every visitor gets
    // from the cache; hold_cookie_script() holds that script back instead.
    if (function_exists('pll_is_cache_active') && pll_is_cache_active()) {
      return $expiration;
    }
    return -1;
  }

  /**
   * Hold Polylang's cookie script (used when a page cache is active) like a blocked tracker:
   * consent.js runs it once the visitor allows the category. The filter exists since
   * WordPress 6.3.
   */
  public function hold_cookie_script($attributes) {
    if (!isset($attributes['id']) || $attributes['id'] !== 'pll_cookie_script-js-after') {
      return $attributes;
    }
    $category = self::cookie_category();
    if ($category === 'necessary') {
      return $attributes;
    }
    $attributes['type'] = 'text/plain';
    $attributes['data-kncc-category'] = $category;
    return $attributes;
  }
}
