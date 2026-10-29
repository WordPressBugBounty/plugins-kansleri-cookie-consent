<?php

if (!defined('ABSPATH')) {
  exit;
}

class KNCC_WP_Rocket {

  public function __construct() {
    add_filter('rocket_cache_dynamic_cookies', array($this, 'add_dynamic_cookie'));
    add_filter('rocket_delay_js_exclusions', array($this, 'delay_js_exclusions'));
  }

  /**
   * Cache separate versions per consent choice so iframe blocking stays accurate.
   */
  public function add_dynamic_cookie($cookies) {
    if (!is_array($cookies)) {
      $cookies = array();
    }

    if (!in_array('kncc_consent', $cookies, true)) {
      $cookies[] = 'kncc_consent';
    }

    return $cookies;
  }

  /**
   * Prevent WP Rocket Delay JS from rewriting consent-blocked or consent-critical scripts.
   */
  public function delay_js_exclusions($exclusions) {
    if (!is_array($exclusions)) {
      $exclusions = array();
    }

    $patterns = array(
      'google_gtagjs',
      'googlesitekit-consent-mode',
      'google_gtagjs-js-consent-mode',
      'kansleri-cookie-consent',
      'kncc-consent',
      'knccGuard',
      'wp-consent-api',
      'clarity.ms',
    );

    foreach ($patterns as $pattern) {
      if (!in_array($pattern, $exclusions, true)) {
        $exclusions[] = $pattern;
      }
    }

    return $exclusions;
  }
}
