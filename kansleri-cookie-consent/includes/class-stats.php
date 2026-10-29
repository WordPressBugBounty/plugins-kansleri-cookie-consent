<?php

if (!defined('ABSPATH')) {
  exit;
}

class KNCC_Stats {

  const OPTION_KEY = 'kncc_consent_stats';
  const MAX_DAYS = 90;

  /**
   * Minimum seconds between two identical consent choices recorded from the same visitor.
   * Stops the public endpoint being used to inflate statistics or fill the log table.
   */
  const THROTTLE_SECONDS = 60;

  public function __construct() {
    add_action('wp_ajax_kncc_record_consent', array($this, 'record_consent'));
    add_action('wp_ajax_nopriv_kncc_record_consent', array($this, 'record_consent'));
  }

  /**
   * Fingerprint of the current visitor, used for throttling only.
   */
  private static function visitor_key() {
    $ip = isset($_SERVER['REMOTE_ADDR']) ? sanitize_text_field(wp_unslash($_SERVER['REMOTE_ADDR'])) : '';
    $ua = isset($_SERVER['HTTP_USER_AGENT']) ? sanitize_text_field(wp_unslash($_SERVER['HTTP_USER_AGENT'])) : '';
    return substr(hash_hmac('sha256', $ip . '|' . $ua, wp_salt('nonce')), 0, 32);
  }

  /**
   * Whether the request's Origin or Referer belongs to this site.
   *
   * Used as a fallback when the nonce is missing or stale: the stats nonce is the
   * same for every logged-out visitor and gets baked into full-page-cached HTML,
   * so it silently expires after a day of caching and statistics stop recording.
   */
  private static function request_origin_matches_site() {
    $origin = '';
    if (!empty($_SERVER['HTTP_ORIGIN'])) {
      $origin = esc_url_raw(wp_unslash($_SERVER['HTTP_ORIGIN']));
    } elseif (!empty($_SERVER['HTTP_REFERER'])) {
      $origin = esc_url_raw(wp_unslash($_SERVER['HTTP_REFERER']));
    }

    if ($origin === '') {
      return false;
    }

    $origin_host = wp_parse_url($origin, PHP_URL_HOST);
    $home_host   = wp_parse_url(home_url('/'), PHP_URL_HOST);

    return $origin_host && $home_host && strtolower($origin_host) === strtolower($home_host);
  }

  /**
   * Reduce a client-supplied categories payload to the known keys as booleans.
   *
   * The raw POST value must never reach the database: it ends up in the consent log
   * and in the admin CSV export.
   *
   * @param string $raw JSON string from the client.
   * @return string Re-encoded JSON containing only known keys.
   */
  public static function sanitize_categories($raw) {
    $decoded = json_decode((string) $raw, true);
    $clean = array('necessary' => true);

    foreach (array('analytics', 'marketing', 'preferences') as $category) {
      $clean[$category] = is_array($decoded) && !empty($decoded[$category]);
    }

    return wp_json_encode($clean);
  }

  public function record_consent() {
    // Verified inline rather than in a helper so the nonce check is visible to
    // readers and to static analysis. A missing or expired nonce falls back to a
    // same-origin check, because cached pages serve a nonce that expires after a day.
    if (!check_ajax_referer('kncc_stats', 'nonce', false) && !self::request_origin_matches_site()) {
      wp_send_json_error('Invalid request');
    }

    $choice = isset($_POST['choice']) ? sanitize_key(wp_unslash($_POST['choice'])) : '';
    if (!in_array($choice, array('accept', 'reject', 'custom'), true)) {
      wp_send_json_error('Invalid choice');
    }

    // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- reduced to known boolean keys below.
    $raw_categories = isset($_POST['categories']) ? wp_unslash($_POST['categories']) : '{}';
    $categories = self::sanitize_categories(is_string($raw_categories) ? $raw_categories : '{}');

    // Only a repeat of the same decision is dropped: a visitor who changes their mind
    // (e.g. Accept, then Reject a moment later) must still end up in the log.
    $throttle_key = 'kncc_rate_' . self::visitor_key();
    $decision = $choice . '|' . $categories;
    if (get_transient($throttle_key) === $decision) {
      // Report success so a throttled visitor's banner behaves normally.
      wp_send_json_success();
    }
    set_transient($throttle_key, $decision, self::THROTTLE_SECONDS);

    $stats = get_option(self::OPTION_KEY, array());
    $today = wp_date('Y-m-d');

    if (!isset($stats[$today])) {
      $stats[$today] = array('accept' => 0, 'reject' => 0, 'custom' => 0);
    }
    $stats[$today][$choice]++;

    $cutoff = wp_date('Y-m-d', strtotime('-' . self::MAX_DAYS . ' days'));
    foreach (array_keys($stats) as $date) {
      if ($date < $cutoff) {
        unset($stats[$date]);
      }
    }

    update_option(self::OPTION_KEY, $stats, false);

    if (class_exists('KNCC_Consent_Log')) {
      KNCC_Consent_Log::record($choice, $categories);
    }

    wp_send_json_success();
  }

  public static function get_stats($days = 30) {
    $stats = get_option(self::OPTION_KEY, array());
    $result = array();

    for ($i = $days - 1; $i >= 0; $i--) {
      $date = wp_date('Y-m-d', strtotime("-{$i} days"));
      $result[$date] = isset($stats[$date])
        ? $stats[$date]
        : array('accept' => 0, 'reject' => 0, 'custom' => 0);
    }

    return $result;
  }

  public static function get_totals($days = 30) {
    $stats = self::get_stats($days);
    $totals = array('accept' => 0, 'reject' => 0, 'custom' => 0, 'total' => 0);

    foreach ($stats as $day) {
      $totals['accept'] += $day['accept'];
      $totals['reject'] += $day['reject'];
      $totals['custom'] += $day['custom'];
    }
    $totals['total'] = $totals['accept'] + $totals['reject'] + $totals['custom'];

    return $totals;
  }
}
